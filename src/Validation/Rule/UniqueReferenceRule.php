<?php

declare(strict_types=1);

namespace App\Validation\Rule;

use App\Validation\RuleInterface;
use App\Validation\Violation;

/**
 * Two epics, or two user stories / technical stories of one epic, or two subtasks of one technical story,
 * must not share a reference: references become Confluence and Jira titles.
 */
final class UniqueReferenceRule implements RuleInterface
{
    public function check(array $document): array
    {
        $violations = [];
        $epics = [];
        foreach ($document['epics'] ?? [] as $e => $epic) {
            $this->duplicates($violations, $epics, $epic['reference'] ?? '', "/epics/$e/reference", 'epic');

            $seen = [];
            foreach ($epic['userStories'] ?? [] as $u => $us) {
                $this->duplicates($violations, $seen, $us['reference'] ?? '', "/epics/$e/userStories/$u/reference", 'user story');
            }

            $tsSeen = [];
            foreach ($epic['technicalStories'] ?? [] as $t => $ts) {
                $this->duplicates($violations, $tsSeen, $ts['reference'] ?? '', "/epics/$e/technicalStories/$t/reference", 'technical story');

                $subSeen = [];
                foreach ($ts['subtasks'] ?? [] as $s => $sub) {
                    $this->duplicates($violations, $subSeen, $sub['reference'] ?? '', "/epics/$e/technicalStories/$t/subtasks/$s/reference", 'subtask');
                }
            }
        }

        return $violations;
    }

    /**
     * @param list<Violation>      $violations
     * @param array<string, true>  $seen
     */
    private function duplicates(array &$violations, array &$seen, string $reference, string $path, string $kind): void
    {
        if ($reference === '') {
            return;
        }
        if (isset($seen[$reference])) {
            $violations[] = new Violation('unique-reference', $path, sprintf('Duplicated %s reference "%s".', $kind, $reference));

            return;
        }
        $seen[$reference] = true;
    }
}
