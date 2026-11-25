<?php

declare(strict_types=1);

namespace App\Render;

use App\Reference\DependencyRef;
use App\Reference\ReferenceResolver;

/**
 * Turns a validated document into the one model every renderer (Markdown, PDF, Confluence) consumes.
 *
 * Every node keeps its JSON `path` (for write-back of ticket / pageLink) and its display title (reference first).
 * The calculated sections (dependencies, criteria coverage, SQL impact) are computed here, once.
 */
final class DocumentModelBuilder
{
    public function __construct(private readonly ReferenceResolver $references)
    {
    }

    /**
     * @param array<string, mixed> $doc
     *
     * @return array<string, mixed>
     */
    public function build(array $doc): array
    {
        $epics = [];
        $nodes = [];
        foreach ($doc['epics'] ?? [] as $e => $epic) {
            $epicModel = $this->node($epic, "/epics/$e", $this->references->title($this->references->epic($doc, $e), (string) $epic['title']));
            $epicModel += ['what' => (string) ($epic['what'] ?? ''), 'resources' => $epic['resources'] ?? [], 'diagrams' => $epic['diagrams'] ?? [], 'userStories' => [], 'technicalStories' => []];
            $nodes[] = $epicModel;

            foreach ($epic['userStories'] ?? [] as $u => $us) {
                $usModel = $this->node($us, "/epics/$e/userStories/$u", $this->references->title($this->references->userStory($doc, $e, $u), (string) $us['title']));
                $usModel += ['acceptanceCriteria' => array_values($us['acceptanceCriteria'] ?? []), 'resources' => $us['resources'] ?? [], 'diagrams' => $us['diagrams'] ?? []];
                $epicModel['userStories'][] = $usModel;
                $nodes[] = $usModel;
            }

            foreach ($epic['technicalStories'] ?? [] as $t => $ts) {
                $tsModel = $this->node($ts, "/epics/$e/technicalStories/$t", $this->references->title($this->references->technicalStory($doc, $e, $t), (string) $ts['title']));
                $tsModel += [
                    'what' => (string) ($ts['what'] ?? ''), 'why' => (string) ($ts['why'] ?? ''),
                    'resources' => $ts['resources'] ?? [], 'diagrams' => $ts['diagrams'] ?? [],
                    'acceptanceCriteriaCovered' => array_values($ts['acceptanceCriteriaCovered'] ?? []),
                    'subtasks' => [], 'featureToggles' => [], 'sqlImpact' => null,
                ];
                $sqlColumns = 0;
                $sqlRows = 0;
                $hasSql = false;
                foreach ($ts['subtasks'] ?? [] as $s => $sub) {
                    $subModel = $this->node($sub, "/epics/$e/technicalStories/$t/subtasks/$s", $this->references->title($this->references->subtask($doc, $e, $t, $s), (string) $sub['title']));
                    $subModel += [
                        'dependencyRef' => ($ts['reference'] ?? '') !== '' && ($sub['reference'] ?? '') !== '' ? DependencyRef::subtask((string) $ts['reference'], (string) $sub['reference']) : null,
                        'what' => (string) ($sub['what'] ?? ''), 'why' => (string) ($sub['why'] ?? ''),
                        'where' => (string) ($sub['where'] ?? ''), 'how' => (string) ($sub['how'] ?? ''),
                        'criterion' => $sub['criterion'] ?? null, 'featureToggle' => $sub['featureToggle'] ?? null,
                        'sqlImpact' => $sub['sqlImpact'] ?? null, 'diagrams' => $sub['diagrams'] ?? [], 'resources' => $sub['resources'] ?? [],
                    ];
                    if ($subModel['sqlImpact'] !== null) {
                        $hasSql = true;
                        $sqlColumns += (int) $subModel['sqlImpact']['nbColumns'];
                        $sqlRows += (int) $subModel['sqlImpact']['nbRows'];
                    }
                    if ($subModel['featureToggle'] !== null && !\in_array($subModel['featureToggle'], $tsModel['featureToggles'], true)) {
                        $tsModel['featureToggles'][] = $subModel['featureToggle'];
                    }
                    $tsModel['subtasks'][] = $subModel;
                    $nodes[] = $subModel;
                }
                $tsModel['sqlImpact'] = $hasSql ? ['nbColumns' => $sqlColumns, 'nbRows' => $sqlRows] : null;
                $epicModel['technicalStories'][] = $tsModel;
                $nodes[] = $tsModel;
            }
            $epics[] = $epicModel;
        }

        // a sub-task is found by its TS/Subtask name; the other items by their reference
        $index = [];
        foreach ($nodes as $node) {
            $key = array_key_exists('dependencyRef', $node) ? $node['dependencyRef'] : ($node['reference'] ?? '');
            if (is_string($key) && $key !== '' && !isset($index[$key])) {
                $index[$key] = $node;
            }
        }

        return [
            'project' => $doc['project'] ?? [],
            'author' => $doc['author'] ?? [],
            'version' => (string) ($doc['version'] ?? ''),
            'structureVersion' => (string) ($doc['structureVersion'] ?? ''),
            'format' => (string) ($doc['format'] ?? 'markdown'),
            'dependenciesPageLink' => $doc['dependenciesPageLink'] ?? null,
            'epics' => $epics,
            'dependencies' => $this->dependencies($doc['dependencies'] ?? [], $index),
            'acCoverage' => $this->acCoverage($epics),
            'sqlImpact' => $this->sqlImpact($epics),
        ];
    }

    /**
     * Fields every node shares, keeping the JSON path and the display title.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function node(array $source, string $path, string $displayTitle): array
    {
        return [
            'path' => $path,
            'displayTitle' => $displayTitle,
            'reference' => (string) ($source['reference'] ?? ''),
            'title' => (string) ($source['title'] ?? ''),
            'ticket' => $source['ticket'] ?? null,
            'pageLink' => $source['pageLink'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>>        $dependencies
     * @param array<string, array<string, mixed>> $index
     *
     * @return list<array<string, mixed>>
     */
    private function dependencies(array $dependencies, array $index): array
    {
        $rows = [];
        foreach ($dependencies as $dep) {
            $from = $index[$dep['from']] ?? null;
            $to = $index[$dep['to']] ?? null;
            $rows[] = [
                'from' => (string) $dep['from'],
                'to' => (string) $dep['to'],
                'fromTitle' => $from['displayTitle'] ?? (string) $dep['from'],
                'toTitle' => $to['displayTitle'] ?? (string) $dep['to'],
                'fromLink' => $from['pageLink'] ?? null,
                'toLink' => $to['pageLink'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * One row per criterion of each user story, with the technical stories covering it.
     *
     * @param list<array<string, mixed>> $epics
     *
     * @return list<array{criterion: string, declared: bool, coveredBy: list<string>}>
     */
    private function acCoverage(array $epics): array
    {
        $declared = [];
        $covered = [];
        foreach ($epics as $epic) {
            foreach ($epic['userStories'] as $us) {
                foreach ($us['acceptanceCriteria'] as $ac) {
                    $declared[$ac] = true;
                }
            }
            foreach ($epic['technicalStories'] as $ts) {
                foreach ($ts['acceptanceCriteriaCovered'] as $ac) {
                    $covered[$ac][] = $ts['displayTitle'];
                }
            }
        }

        $rows = [];
        foreach (array_keys($declared) as $ac) {
            $rows[] = ['criterion' => (string) $ac, 'declared' => true, 'coveredBy' => $covered[$ac] ?? []];
        }
        foreach ($covered as $ac => $by) {
            if (!isset($declared[$ac])) {
                $rows[] = ['criterion' => (string) $ac, 'declared' => false, 'coveredBy' => $by];
            }
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $epics
     *
     * @return array{rows: list<array{title: string, sqlImpact: ?array{nbColumns: int, nbRows: int}}>, total: array{nbColumns: int, nbRows: int}}
     */
    private function sqlImpact(array $epics): array
    {
        $rows = [];
        $total = ['nbColumns' => 0, 'nbRows' => 0];
        foreach ($epics as $epic) {
            foreach ($epic['technicalStories'] as $ts) {
                $rows[] = ['title' => $ts['displayTitle'], 'sqlImpact' => $ts['sqlImpact']];
                if ($ts['sqlImpact'] !== null) {
                    $total['nbColumns'] += $ts['sqlImpact']['nbColumns'];
                    $total['nbRows'] += $ts['sqlImpact']['nbRows'];
                }
            }
        }

        return ['rows' => $rows, 'total' => $total];
    }
}
