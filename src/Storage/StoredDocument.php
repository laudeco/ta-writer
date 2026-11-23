<?php

declare(strict_types=1);

namespace App\Storage;

final class StoredDocument
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $id,
        public readonly array $data,
        public readonly int $version,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
