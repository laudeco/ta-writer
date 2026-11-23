<?php

declare(strict_types=1);

namespace App\Validation\Rule;

use App\Validation\RuleInterface;
use App\Validation\Violation;

/**
 * Warnings only: a criterion no technical story covers, or a covered criterion no user story declares.
 */
final class AcceptanceCriteriaCoverageRule implements RuleInterface
{
    public function check(array $document): array
    {
        $declared = [];
        $covered = [];
        foreach ($document['epics'] ?? [] as $epic) {
            foreach ($epic['userStories'] ?? [] as $us) {
                foreach ($us['acceptanceCriteria'] ?? [] as $ac) {
                    $declared[$this->normalise($ac)] = true;
                }
            }
            foreach ($epic['technicalStories'] ?? [] as $ts) {
                foreach ($ts['acceptanceCriteriaCovered'] ?? [] as $ac) {
                    $covered[$this->normalise($ac)] = true;
                }
            }
        }

        $violations = [];
        foreach (array_keys($declared) as $ac) {
            if (!isset($covered[$ac])) {
                $violations[] = new Violation('ac-coverage', '/epics', sprintf('Criterion not covered by any technical story: %s', $ac), 'warning');
            }
        }
        foreach (array_keys($covered) as $ac) {
            if (!isset($declared[$ac])) {
                $violations[] = new Violation('ac-coverage', '/epics', sprintf('Covered criterion matches no user story: %s', $ac), 'warning');
            }
        }

        return $violations;
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
