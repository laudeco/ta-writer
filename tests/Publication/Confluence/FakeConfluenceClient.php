<?php

declare(strict_types=1);

namespace App\Tests\Publication\Confluence;

use App\Publication\Confluence\ConfluenceClientInterface;
use App\Publication\Confluence\ConfluenceResponse;

/**
 * Scripted Confluence: the handler answers each request, every request is recorded.
 */
final class FakeConfluenceClient implements ConfluenceClientInterface
{
    /** @var list<array{method: string, path: string, json: array<string, mixed>|null}> */
    public array $log = [];

    /** @param callable(string, string, array<string, mixed>|null): ConfluenceResponse $handler */
    public function __construct(private $handler)
    {
    }

    public function request(string $method, string $path, ?array $json = null): ConfluenceResponse
    {
        $this->log[] = ['method' => $method, 'path' => $path, 'json' => $json];

        return ($this->handler)($method, $path, $json);
    }

    public function uploadAttachment(string $pageId, string $filename, string $bytes): ConfluenceResponse
    {
        $this->log[] = ['method' => 'UPLOAD', 'path' => $pageId.'/'.$filename, 'json' => null];

        return new ConfluenceResponse(200, ['results' => []]);
    }

    /**
     * @return list<string> methods of the requests, in order
     */
    public function methods(): array
    {
        return array_column($this->log, 'method');
    }
}
