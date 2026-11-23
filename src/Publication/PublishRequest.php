<?php

declare(strict_types=1);

namespace App\Publication;

final class PublishRequest
{
    /**
     * @param array<string, mixed>  $document the validated document
     * @param array<string, string> $options  answers to the format's OptionDefinition list
     */
    public function __construct(
        public readonly string $documentId,
        public readonly array $document,
        public readonly array $options,
        public readonly bool $dryRun,
    ) {
    }
}
