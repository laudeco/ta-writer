<?php

declare(strict_types=1);

namespace App\Publication;

final class OptionDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly bool $required = false,
        public readonly ?string $default = null,
    ) {
    }
}
