<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

/**
 * Transport to Confluence. Two implementations: an HTTP client with a Personal Access Token (Confluence API adapter)
 * and a browser client that runs the same requests inside the logged-in AGIR session (AGIR adapter).
 */
interface ConfluenceClientInterface
{
    /**
     * @param array<string, mixed>|null $json
     */
    public function request(string $method, string $path, ?array $json = null): ConfluenceResponse;

    /**
     * Creates the attachment, or replaces its content when one with the same file name already exists.
     */
    public function uploadAttachment(string $pageId, string $filename, string $bytes): ConfluenceResponse;
}
