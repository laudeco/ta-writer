<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

use App\Browser\BrowserSessionInterface;

/**
 * Confluence requests sent from the logged-in AGIR tab (same-origin fetch, X-Atlassian-Token: no-check).
 * Only status and body come back: cookies and headers never leave the browser.
 */
final class BrowserConfluenceClient implements ConfluenceClientInterface
{
    public function __construct(
        private readonly BrowserSessionInterface $session,
        private readonly string $origin,
    ) {
    }

    public function request(string $method, string $path, ?array $json = null): ConfluenceResponse
    {
        $init = [
            'method' => strtoupper($method),
            'credentials' => 'same-origin',
            'redirect' => 'manual',
            'headers' => ['X-Atlassian-Token' => 'no-check', 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
        ];
        if ($json !== null) {
            $init['body'] = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $js = sprintf(
            "(async()=>{const r=await fetch(%s,%s);return {status:r.status,body:await r.text()};})()",
            json_encode($path, JSON_THROW_ON_ERROR),
            json_encode($init, JSON_THROW_ON_ERROR),
        );

        return $this->result($this->run($js));
    }

    public function uploadAttachment(string $pageId, string $filename, string $bytes): ConfluenceResponse
    {
        $existing = $this->request('GET', sprintf('/rest/api/content/%s/child/attachment?filename=%s', $pageId, rawurlencode($filename)));
        $attachmentId = $existing->body['results'][0]['id'] ?? null;
        $path = $attachmentId === null
            ? sprintf('/rest/api/content/%s/child/attachment', $pageId)
            : sprintf('/rest/api/content/%s/child/attachment/%s/data', $pageId, $attachmentId);

        // the whole PNG goes in one evaluation: diagrams are small; a larger file would need chunks
        $js = sprintf(
            "(async()=>{const bin=Uint8Array.from(atob(%s),c=>c.charCodeAt(0));const fd=new FormData();"
            ."fd.append('file',new Blob([bin],{type:'image/png'}),%s);"
            ."const r=await fetch(%s,{method:'POST',credentials:'same-origin',headers:{'X-Atlassian-Token':'no-check'},body:fd});"
            ."return {status:r.status,body:await r.text()};})()",
            json_encode(base64_encode($bytes), JSON_THROW_ON_ERROR),
            json_encode($filename, JSON_THROW_ON_ERROR),
            json_encode($path, JSON_THROW_ON_ERROR),
        );

        return $this->result($this->run($js));
    }

    /**
     * @return array{status?: int, body?: string}
     */
    private function run(string $js): array
    {
        $this->session->open(rtrim($this->origin, '/').'/');
        $value = $this->session->evaluate($js);

        return is_array($value) ? $value : [];
    }

    /**
     * @param array{status?: int, body?: string} $value
     */
    private function result(array $value): ConfluenceResponse
    {
        $body = json_decode((string) ($value['body'] ?? ''), true);

        return new ConfluenceResponse((int) ($value['status'] ?? 0), is_array($body) ? $body : null);
    }
}
