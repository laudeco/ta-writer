<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

final class PageSpec
{
    /**
     * @param list<array{filename: string, bytes: string}> $attachments diagrams referenced by the body
     */
    public function __construct(
        public readonly string $space,
        public readonly string $parentId,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $knownPageId,
        public readonly array $attachments = [],
    ) {
    }
}
