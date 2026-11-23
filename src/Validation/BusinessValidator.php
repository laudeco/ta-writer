<?php

declare(strict_types=1);

namespace App\Validation;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class BusinessValidator
{
    /**
     * @param iterable<RuleInterface> $rules
     */
    public function __construct(
        #[AutowireIterator('app.validation_rule')] private readonly iterable $rules,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<Violation> errors and warnings
     */
    public function validate(array $document): array
    {
        $violations = [];
        foreach ($this->rules as $rule) {
            array_push($violations, ...$rule->check($document));
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @throws BusinessValidationException when at least one error (warnings do not block)
     */
    public function assertValid(array $document): void
    {
        $violations = $this->validate($document);
        $errors = array_filter($violations, static fn (Violation $v): bool => $v->severity === 'error');
        if ($errors !== []) {
            throw new BusinessValidationException($violations);
        }
    }
}
