<?php

declare(strict_types=1);

namespace App\Schema;

final class SchemaValidationException extends \RuntimeException
{
    /**
     * @param list<array{path: string, message: string}> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(sprintf('Document does not respect the schema (%d error(s)).', \count($errors)));
    }

    /**
     * @return list<array{path: string, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
