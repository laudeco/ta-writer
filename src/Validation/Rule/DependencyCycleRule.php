<?php

declare(strict_types=1);

namespace App\Validation\Rule;

use App\Validation\RuleInterface;
use App\Validation\Violation;

/**
 * `dependencies[]`: `to` is blocked by `from`. Both ends must name an existing item, and the graph must be acyclic.
 */
final class DependencyCycleRule implements RuleInterface
{
    public function check(array $document): array
    {
        $known = [];
        foreach ($document['epics'] ?? [] as $epic) {
            $known[$epic['reference'] ?? ''] = true;
            foreach ($epic['userStories'] ?? [] as $us) {
                $known[$us['reference'] ?? ''] = true;
            }
            foreach ($epic['technicalStories'] ?? [] as $ts) {
                $known[$ts['reference'] ?? ''] = true;
            }
        }
        unset($known['']);

        $violations = [];
        $graph = [];
        foreach ($document['dependencies'] ?? [] as $i => $dep) {
            $from = (string) ($dep['from'] ?? '');
            $to = (string) ($dep['to'] ?? '');
            foreach (['from' => $from, 'to' => $to] as $end => $ref) {
                if (!isset($known[$ref])) {
                    $violations[] = new Violation('dependency-reference', "/dependencies/$i/$end", sprintf('"%s" is not an epic, user story or technical story of the document.', $ref));
                }
            }
            if ($from === $to) {
                $violations[] = new Violation('dependency-cycle', "/dependencies/$i", sprintf('"%s" cannot block itself.', $from));
                continue;
            }
            $graph[$from][] = $to;
        }

        foreach ($this->findCycles($graph) as $cycle) {
            $violations[] = new Violation('dependency-cycle', '/dependencies', 'Dependency cycle: '.implode(' → ', $cycle).'.');
        }

        return $violations;
    }

    /**
     * @param array<string, list<string>> $graph
     *
     * @return list<list<string>>
     */
    private function findCycles(array $graph): array
    {
        $cycles = [];
        $state = [];
        $stack = [];
        $visit = function (string $node) use (&$visit, &$state, &$stack, &$cycles, $graph): void {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 1) {
                    $cycles[] = [...\array_slice($stack, array_search($next, $stack, true)), $next];
                } elseif (!isset($state[$next])) {
                    $visit($next);
                }
            }
            array_pop($stack);
            $state[$node] = 2;
        };
        foreach (array_keys($graph) as $node) {
            if (!isset($state[$node])) {
                $visit($node);
            }
        }

        return $cycles;
    }
}
