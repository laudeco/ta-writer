<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * Confluence REST with a Personal Access Token. GET is retried on 429/5xx, POST and PUT are never replayed.
 */
final class HttpConfluenceClient implements ConfluenceClientInterface
{
    private HttpClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        ?HttpClientInterface $http = null,
    ) {
        $this->http = $http ?? HttpClient::create(['timeout' => 30]);
    }

    public function request(string $method, string $path, ?array $json = null): ConfluenceResponse
    {
        $options = ['headers' => $this->headers(['Accept' => 'application/json'])];
        if ($json !== null) {
            $options['json'] = $json;
        }

        $attempts = strtoupper($method) === 'GET' ? 3 : 1;
        for ($try = 1;; ++$try) {
            try {
                $response = $this->http->request($method, $this->url($path), $options);
                $status = $response->getStatusCode();
                if ($status === 401 || $status === 403) {
                    throw ConfluenceAuthNotSupportedException::rejected($status);
                }
                if ($status >= 500 && $try < $attempts) {
                    usleep(500000 * $try);
                    continue;
                }

                return new ConfluenceResponse($status, $this->decode($response->getContent(false)));
            } catch (TransportException $e) {
                if ($try >= $attempts) {
                    throw new \RuntimeException(sprintf('Confluence %s %s: %s', $method, $path, $e->getMessage()), 0, $e);
                }
                usleep(500000 * $try);
            }
        }
    }

    public function uploadAttachment(string $pageId, string $filename, string $bytes): ConfluenceResponse
    {
        $existing = $this->request('GET', sprintf('/rest/api/content/%s/child/attachment?filename=%s', $pageId, rawurlencode($filename)));
        $attachmentId = $existing->body['results'][0]['id'] ?? null;

        $form = new FormDataPart(['file' => new DataPart($bytes, $filename, 'image/png')]);
        $url = $attachmentId === null
            ? sprintf('/rest/api/content/%s/child/attachment', $pageId)
            : sprintf('/rest/api/content/%s/child/attachment/%s/data', $pageId, $attachmentId);
        $response = $this->http->request('POST', $this->url($url), [
            'headers' => $this->headers(['Accept' => 'application/json'] + $form->getPreparedHeaders()->toArray()),
            'body' => $form->bodyToString(),
        ]);
        $status = $response->getStatusCode();
        if ($status === 401 || $status === 403) {
            throw ConfluenceAuthNotSupportedException::rejected($status);
        }

        return new ConfluenceResponse($status, $this->decode($response->getContent(false)));
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private function headers(array $extra): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'X-Atlassian-Token' => 'no-check'] + $extra;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decode(string $content): ?array
    {
        $data = json_decode($content, true);

        return \is_array($data) ? $data : null;
    }
}
