<?php

declare(strict_types=1);

namespace App\Publication;

final class PublishResult
{
    /**
     * @param 'done'|'dry-run'|'failed' $status
     * @param list<string>              $messages  one line per object
     * @param list<string>              $artifacts files written (or that would be written)
     */
    public function __construct(
        public readonly string $status,
        public readonly array $messages = [],
        public readonly array $artifacts = [],
    ) {
    }
}
