<?php

declare(strict_types=1);

namespace App\Jira;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jira Data Center REST with a Personal Access Token. Only GET is retried; a failed POST is never replayed, because the
 * issue may already exist: the caller checks Jira before writing again.
 */
final class JiraClient
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 30]);
    }

    public function checkToken(): bool
    {
        return $this->call('GET', '/rest/api/2/myself')['status'] === 200;
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return string the new issue key
     */
    public function createIssue(array $fields): string
    {
        $response = $this->call('POST', '/rest/api/2/issue', ['fields' => $fields]);
        if ($response['status'] !== 201 || !isset($response['body']['key'])) {
            throw new \RuntimeException(sprintf('Jira refused "%s": HTTP %d %s', $fields['summary'] ?? '?', $response['status'], $this->message($response['body'])));
        }

        return (string) $response['body']['key'];
    }

    /**
     * @return list<array{key: string, summary: string}>
     */
    public function search(string $jql): array
    {
        $response = $this->call('GET', '/rest/api/2/search?'.http_build_query(['jql' => $jql, 'fields' => 'summary', 'maxResults' => 200]));
        if ($response['status'] !== 200) {
            throw new \RuntimeException(sprintf('Jira search failed: HTTP %d %s', $response['status'], $this->message($response['body'])));
        }

        return array_map(
            static fn (array $issue): array => ['key' => (string) $issue['key'], 'summary' => (string) ($issue['fields']['summary'] ?? '')],
            $response['body']['issues'] ?? [],
        );
    }

    /**
     * Replaces the summary and the fields given, on an existing issue.
     *
     * @param array<string, mixed> $fields
     */
    public function updateIssue(string $key, array $fields): void
    {
        $response = $this->call('PUT', '/rest/api/2/issue/'.$key, ['fields' => $fields]);
        if ($response['status'] !== 204) {
            throw new \RuntimeException(sprintf('Jira %s not updated: HTTP %d %s', $key, $response['status'], $this->message($response['body'])));
        }
    }

    /**
     * @return list<array{id: string, filename: string}>
     */
    public function attachments(string $key): array
    {
        $response = $this->call('GET', sprintf('/rest/api/2/issue/%s?fields=attachment', $key));
        if ($response['status'] !== 200) {
            throw new \RuntimeException(sprintf('Jira %s attachments: HTTP %d', $key, $response['status']));
        }

        return array_map(
            static fn (array $file): array => ['id' => (string) $file['id'], 'filename' => (string) $file['filename']],
            $response['body']['fields']['attachment'] ?? [],
        );
    }

    /**
     * Uploads a file to the issue. Jira refuses uploads without the XSRF bypass header, hence the header.
     */
    public function addAttachment(string $key, string $filename, string $bytes): void
    {
        $form = new FormDataPart(['file' => new DataPart($bytes, $filename, 'image/png')]);
        $response = $this->http->request('POST', rtrim($this->baseUrl, '/').sprintf('/rest/api/2/issue/%s/attachments', $key), [
            'headers' => [
                'Authorization' => 'Bearer '.$this->token,
                'Accept' => 'application/json',
                'X-Atlassian-Token' => 'no-check',
            ] + $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToString(),
        ]);
        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Jira %s attachment %s: HTTP %d', $key, $filename, $status));
        }
    }

    public function deleteAttachment(string $id): void
    {
        $response = $this->call('DELETE', '/rest/api/2/attachment/'.$id);
        if ($response['status'] !== 204) {
            throw new \RuntimeException(sprintf('Jira attachment %s not deleted: HTTP %d', $id, $response['status']));
        }
    }

    /**
     * Keys already linked to $key, in either direction.
     *
     * @return list<string>
     */
    public function linkedKeys(string $key): array
    {
        $response = $this->call('GET', sprintf('/rest/api/2/issue/%s?fields=issuelinks', $key));
        if ($response['status'] !== 200) {
            throw new \RuntimeException(sprintf('Jira issue %s: HTTP %d', $key, $response['status']));
        }
        $keys = [];
        foreach ($response['body']['fields']['issuelinks'] ?? [] as $link) {
            $other = $link['inwardIssue'] ?? $link['outwardIssue'] ?? null;
            if ($other !== null) {
                $keys[] = (string) $other['key'];
            }
        }

        return $keys;
    }

    /**
     * Blocks link. Jira's REST API takes the issue carrying the outward verb ("blocks") as `inwardIssue`: the blocker.
     */
    public function createBlocksLink(string $blocker, string $blocked): void
    {
        $response = $this->call('POST', '/rest/api/2/issueLink', [
            'type' => ['name' => 'Blocks'],
            'inwardIssue' => ['key' => $blocker],
            'outwardIssue' => ['key' => $blocked],
        ]);
        if ($response['status'] !== 201) {
            throw new \RuntimeException(sprintf('Jira link %s blocks %s refused: HTTP %d', $blocker, $blocked, $response['status']));
        }
    }

    /**
     * @param array<string, mixed>|null $json
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function call(string $method, string $path, ?array $json = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']];
        if ($json !== null) {
            $options['json'] = $json;
        }
        $attempts = strtoupper($method) === 'GET' ? 3 : 1;
        for ($try = 1;; ++$try) {
            try {
                $response = $this->http->request($method, rtrim($this->baseUrl, '/').$path, $options);
                $status = $response->getStatusCode();
                if ($status >= 500 && $try < $attempts) {
                    usleep(500000 * $try);
                    continue;
                }
                $body = json_decode($response->getContent(false), true);

                return ['status' => $status, 'body' => is_array($body) ? $body : []];
            } catch (TransportException $e) {
                if ($try >= $attempts) {
                    throw new \RuntimeException(sprintf('Jira %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
                }
                usleep(500000 * $try);
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function message(array $body): string
    {
        $first = $body['errorMessages'][0] ?? null;
        if (is_string($first) && $first !== '') {
            return $first;
        }

        return (string) json_encode($body['errors'] ?? $body);
    }
}
