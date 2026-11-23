<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

/**
 * Creates a page, or rewrites the whole body of an existing one. An existing page is never skipped: its body is
 * replaced by the body generated from the analysis (earlier edits are overwritten, Confluence keeps its own history).
 */
final class ConfluencePagePublisher
{
    public function __construct(
        private readonly ConfluenceClientInterface $client,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Read-only: what upsert() would do.
     */
    public function plan(PageSpec $spec): PageResult
    {
        $existing = $this->find($spec);
        if ($existing === null) {
            return new PageResult('would-create', '', '');
        }
        $current = $this->client->request('GET', sprintf('/rest/api/content/%s?expand=body.storage', $existing['id']));
        $this->expectOk($current->status, 'read', $spec->title, $current->body);
        $unchanged = $this->normalise((string) ($current->body['body']['storage']['value'] ?? '')) === $this->normalise($spec->body);

        return new PageResult($unchanged ? 'unchanged' : 'would-update', $existing['id'], $this->url($existing['id']));
    }

    public function upsert(PageSpec $spec): PageResult
    {
        $existing = $this->find($spec);
        if ($existing !== null && $existing['parent'] !== $spec->parentId) {
            throw ConfluenceTitleCollisionException::under($spec->title, $spec->parentId, $existing['id']);
        }

        if ($existing === null) {
            $response = $this->client->request('POST', '/rest/api/content', [
                'type' => 'page',
                'title' => $spec->title,
                'space' => ['key' => $spec->space],
                'ancestors' => [['id' => $spec->parentId]],
                'body' => ['storage' => ['value' => $spec->body, 'representation' => 'storage']],
            ]);
            $this->expectOk($response->status, 'create', $spec->title, $response->body);
            $id = (string) ($response->body['id'] ?? '');
            $this->attach($id, $spec);

            return new PageResult('created', $id, $this->url($id));
        }

        $current = $this->client->request('GET', sprintf('/rest/api/content/%s?expand=body.storage,version', $existing['id']));
        $this->expectOk($current->status, 'read', $spec->title, $current->body);
        $number = (int) ($current->body['version']['number'] ?? 1);
        $unchanged = $this->normalise((string) ($current->body['body']['storage']['value'] ?? '')) === $this->normalise($spec->body);

        if (!$unchanged) {
            $response = $this->client->request('PUT', sprintf('/rest/api/content/%s', $existing['id']), [
                'id' => $existing['id'],
                'type' => 'page',
                'title' => $spec->title,
                'version' => ['number' => $number + 1],
                'ancestors' => [['id' => $spec->parentId]],
                'body' => ['storage' => ['value' => $spec->body, 'representation' => 'storage']],
            ]);
            $this->expectOk($response->status, 'update', $spec->title, $response->body);
        }
        $this->attach($existing['id'], $spec);

        return new PageResult($unchanged ? 'unchanged' : 'updated', $existing['id'], $this->url($existing['id']));
    }

    public function url(string $pageId): string
    {
        return sprintf('%s/pages/viewpage.action?pageId=%s', rtrim($this->baseUrl, '/'), $pageId);
    }

    /**
     * Finds the page through its stored id, else by exact title in the space.
     *
     * @return array{id: string, parent: string}|null
     */
    private function find(PageSpec $spec): ?array
    {
        if ($spec->knownPageId !== null && $spec->knownPageId !== '') {
            $response = $this->client->request('GET', sprintf('/rest/api/content/%s?expand=ancestors', $spec->knownPageId));
            if ($response->status === 200) {
                return ['id' => $spec->knownPageId, 'parent' => $this->parentOf($response->body)];
            }
        }

        $query = sprintf('/rest/api/content?spaceKey=%s&title=%s&expand=ancestors', rawurlencode($spec->space), rawurlencode($spec->title));
        $response = $this->client->request('GET', $query);
        $this->expectOk($response->status, 'search', $spec->title, $response->body);
        $hit = $response->body['results'][0] ?? null;
        if ($hit === null) {
            return null;
        }

        return ['id' => (string) $hit['id'], 'parent' => $this->parentOf($hit)];
    }

    /**
     * @param array<string, mixed>|null $page
     */
    private function parentOf(?array $page): string
    {
        $ancestors = $page['ancestors'] ?? [];

        return $ancestors === [] ? '' : (string) end($ancestors)['id'];
    }

    private function attach(string $pageId, PageSpec $spec): void
    {
        foreach ($spec->attachments as $file) {
            $response = $this->client->uploadAttachment($pageId, $file['filename'], $file['bytes']);
            $this->expectOk($response->status, 'attachment '.$file['filename'], $spec->title, $response->body);
        }
    }

    /**
     * @param array<string, mixed>|list<mixed>|null $body
     */
    private function expectOk(int $status, string $action, string $title, ?array $body): void
    {
        if ($status < 200 || $status >= 300) {
            $message = is_array($body) ? (string) ($body['message'] ?? json_encode($body)) : 'no JSON body';
            throw new \RuntimeException(sprintf('Confluence %s "%s": HTTP %d, %s', $action, $title, $status, mb_substr($message, 0, 200)));
        }
    }

    private function normalise(string $storage): string
    {
        // Confluence re-serialises the markup on save: compare the content, not the bytes
        return preg_replace('/\s+/', ' ', trim(preg_replace('/\s+ac:macro-id="[^"]*"/', '', $storage) ?? '')) ?? '';
    }
}
