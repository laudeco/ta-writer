<?php

declare(strict_types=1);

namespace App\Validation;

final class Violation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $path,
        public readonly string $message,
        public readonly string $severity = 'error',
    ) {
    }

    /**
     * @return array{rule: string, path: string, message: string, severity: string}
     */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'path' => $this->path, 'message' => $this->message, 'severity' => $this->severity];
    }
}
