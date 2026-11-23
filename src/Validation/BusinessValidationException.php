<?php

declare(strict_types=1);

namespace App\Validation;

final class BusinessValidationException extends \RuntimeException
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(private readonly array $violations)
    {
        $errors = \count(array_filter($violations, static fn (Violation $v): bool => $v->severity === 'error'));
        parent::__construct(sprintf('Document breaks %d business rule(s).', $errors));
    }

    /**
     * @return list<Violation>
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
