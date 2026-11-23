<?php

declare(strict_types=1);

namespace App\Reference;

/**
 * Builds the display references: `<project> <epic> <ts> <subtask> - <title>`, top to bottom.
 *
 * Stored references are used as they are; a missing one is generated from the position (1-based).
 */
final class ReferenceResolver
{
    /**
     * @param array<string, mixed> $document
     */
    public function epic(array $document, int $e): string
    {
        return $this->join([$this->project($document), $this->own($document['epics'][$e] ?? [], 'E', $e)]);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function userStory(array $document, int $e, int $u): string
    {
        $us = $document['epics'][$e]['userStories'][$u] ?? [];

        return $this->join([$this->epic($document, $e), $this->own($us, 'US', $u)]);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function technicalStory(array $document, int $e, int $t): string
    {
        $ts = $document['epics'][$e]['technicalStories'][$t] ?? [];

        return $this->join([$this->epic($document, $e), $this->own($ts, 'TS', $t)]);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function subtask(array $document, int $e, int $t, int $s): string
    {
        $sub = $document['epics'][$e]['technicalStories'][$t]['subtasks'][$s] ?? [];

        return $this->join([$this->technicalStory($document, $e, $t), $this->own($sub, 'Subtask#', $s)]);
    }

    /**
     * `<reference> - <title>`, or the bare title when there is no reference.
     */
    public function title(string $reference, string $title): string
    {
        return $reference === '' ? $title : $reference.' - '.$title;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function project(array $document): string
    {
        return (string) ($document['project']['reference'] ?? '');
    }

    /**
     * @param array<string, mixed> $node
     */
    private function own(array $node, string $prefix, int $index): string
    {
        $reference = (string) ($node['reference'] ?? '');

        return $reference !== '' ? $reference : $prefix.($index + 1);
    }

    /**
     * @param list<string> $parts
     */
    private function join(array $parts): string
    {
        return implode(' ', array_values(array_filter($parts, static fn (string $p): bool => $p !== '')));
    }
}
