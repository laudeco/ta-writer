<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * A business rule the JSON schema cannot express. Services implementing it are tagged `app.validation_rule`.
 */
interface RuleInterface
{
    /**
     * @param array<string, mixed> $document
     *
     * @return list<Violation>
     */
    public function check(array $document): array;
}
