<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

final class ConfluenceResponse
{
    /**
     * @param array<string, mixed>|list<mixed>|null $body decoded JSON, null when the body is not JSON
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
    ) {
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
