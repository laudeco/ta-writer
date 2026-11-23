<?php

declare(strict_types=1);

namespace App\Storage\Exception;

final class DocumentNotFoundException extends \RuntimeException
{
    public static function forId(string $id): self
    {
        return new self(sprintf('Analysis "%s" does not exist.', $id));
    }

    public static function forVersion(string $id, int $version): self
    {
        return new self(sprintf('Analysis "%s" has no version %d.', $id, $version));
    }
}
