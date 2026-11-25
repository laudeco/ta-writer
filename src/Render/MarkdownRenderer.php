<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Markdown rendering of the document model. Same layout as the PDF: cover, table of contents, epics with their
 * stories, then the calculated sections (criteria coverage, SQL impact, dependencies).
 */
final class MarkdownRenderer
{
    public function __construct(private readonly ContentConverter $converter)
    {
    }

    /**
     * @param array<string, mixed> $model from DocumentModelBuilder::build()
     */
    public function render(array $model): string
    {
        $format = $model['format'];
        $out = [];

        $project = $model['project'];
        $author = $model['author'];
        $out[] = '# '.($project['name'] ?? 'Technical analysis');
        $out[] = '';
        $out[] = '- Project: '.($project['reference'] ?? '-').(isset($project['link']) ? ' ('.$project['link'].')' : '');
        $out[] = '- Author: '.($author['name'] ?? '-').(isset($author['email']) ? ' <'.$author['email'].'>' : '');
        $out[] = '- Version: '.($model['version'] ?: '-').' (structure '.($model['structureVersion'] ?: '-').')';
        $out[] = '';

        $out[] = '## Contents';
        $out[] = '';
        foreach ($model['epics'] as $epic) {
            $out[] = sprintf('- [%s](#%s)', $epic['displayTitle'], $this->anchor($epic['displayTitle']));
            foreach ($epic['technicalStories'] as $ts) {
                $out[] = sprintf('  - [%s](#%s)', $ts['displayTitle'], $this->anchor($ts['displayTitle']));
                foreach ($ts['subtasks'] as $sub) {
                    $out[] = sprintf('    - [%s](#%s)', $sub['displayTitle'], $this->anchor($sub['displayTitle']));
                }
            }
        }
        $out[] = '';

        foreach ($model['epics'] as $epic) {
            $out[] = '';
            $out[] = sprintf('## %s', $epic['displayTitle']);
            $out[] = '';
            $this->summary($out, (string) ($epic['context'] ?? ''));
            $this->text($out, $format, $epic['what'], 'Context');
            $this->resources($out, $epic['resources']);
            $this->diagrams($out, $epic['diagrams']);

            foreach ($epic['userStories'] as $us) {
                $out[] = '';
                $out[] = sprintf('### %s', $us['displayTitle']);
                $out[] = '';
                foreach ($us['acceptanceCriteria'] as $ac) {
                    $out[] = '- '.$ac;
                }
                $out[] = '';
                $this->resources($out, $us['resources']);
                $this->diagrams($out, $us['diagrams']);
            }

            foreach ($epic['technicalStories'] as $ts) {
                $out[] = '';
                $out[] = sprintf('### %s', $ts['displayTitle']);
                $out[] = '';
                $this->summary($out, (string) ($ts['context'] ?? ''));
                $this->text($out, $format, $ts['what'], 'What');
                $this->text($out, $format, $ts['why'], 'Why');
                if ($ts['acceptanceCriteriaCovered'] !== []) {
                    $out[] = '**Acceptance criteria covered**';
                    foreach ($ts['acceptanceCriteriaCovered'] as $ac) {
                        $out[] = '- '.$ac;
                    }
                    $out[] = '';
                }
                $this->resources($out, $ts['resources']);
                $this->diagrams($out, $ts['diagrams']);

                foreach ($ts['subtasks'] as $sub) {
                    $out[] = '';
                    $out[] = sprintf('#### %s', $sub['displayTitle']);
                    $out[] = '';
                    if ($sub['featureToggle'] !== null) {
                        $out[] = sprintf('- Feature toggle: `%s`', $sub['featureToggle']);
                    }
                    if ($sub['sqlImpact'] !== null) {
                        $out[] = sprintf('- SQL impact: %d column(s), %d row(s)', $sub['sqlImpact']['nbColumns'], $sub['sqlImpact']['nbRows']);
                    }
                    if ($sub['criterion'] !== null) {
                        $out[] = sprintf('- Criterion: %s', $sub['criterion']);
                    }
                    $out[] = '';
                    $this->summary($out, (string) ($sub['context'] ?? ''));
                    $this->text($out, $format, $sub['what'], 'What');
                    $this->text($out, $format, $sub['why'], 'Why');
                    $this->text($out, $format, $sub['where'], 'Where');
                    $this->text($out, $format, $sub['how'], 'How');
                    $this->resources($out, $sub['resources']);
                    $this->diagrams($out, $sub['diagrams']);
                }
            }
        }

        $out[] = '';
        $out[] = '## Acceptance criteria coverage';
        $out[] = '';
        if ($model['acCoverage'] === []) {
            $out[] = '_No acceptance criteria._';
        } else {
            $out[] = '| Criterion | Covered by |';
            $out[] = '|---|---|';
            foreach ($model['acCoverage'] as $row) {
                $by = $row['coveredBy'] === [] ? '**not covered**' : implode('<br>', array_map(fn (string $t): string => $this->escapeCell($t), $row['coveredBy']));
                $out[] = sprintf('| %s%s | %s |', $this->escapeCell($row['criterion']), $row['declared'] ? '' : ' _(not declared)_', $by);
            }
        }
        $out[] = '';

        $out[] = '## SQL impact';
        $out[] = '';
        $out[] = '| Technical story | Columns | Rows |';
        $out[] = '|---|---|---|';
        foreach ($model['sqlImpact']['rows'] as $row) {
            $out[] = $row['sqlImpact'] === null
                ? sprintf('| %s | — | — |', $this->escapeCell($row['title']))
                : sprintf('| %s | %d | %d |', $this->escapeCell($row['title']), $row['sqlImpact']['nbColumns'], $row['sqlImpact']['nbRows']);
        }
        $out[] = sprintf('| **Total** | **%d** | **%d** |', $model['sqlImpact']['total']['nbColumns'], $model['sqlImpact']['total']['nbRows']);
        $out[] = '';
        $out[] = '_Only migrations are counted; rows written at runtime by the code are not._';
        $out[] = '';

        $out[] = '## Dependencies';
        $out[] = '';
        if ($model['dependencies'] === []) {
            $out[] = '_No dependency._';
        }
        foreach ($model['dependencies'] as $dep) {
            $out[] = sprintf('- %s is blocked by %s', $this->link($dep['toTitle'], $dep['toLink']), $this->link($dep['fromTitle'], $dep['fromLink']));
        }
        $out[] = '';

        return implode("\n", $out);
    }

    /**
     * GitHub-style anchor of a heading.
     */
    public function anchor(string $heading): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^\p{L}\p{N}\s-]/u', '', $heading)));

        return (string) preg_replace('/\s+/', '-', $slug);
    }

    private function link(string $title, ?string $pageLink): string
    {
        return $pageLink !== null && $pageLink !== '' ? sprintf('[%s](%s)', $title, $pageLink) : $title;
    }

    private function escapeCell(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }

    /**
     * The context of a node: a one-line summary, in italics, above its What. Nothing is written when it is empty.
     *
     * @param list<string> $out
     */
    private function summary(array &$out, string $context): void
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $context));
        if ($text === '') {
            return;
        }
        $out[] = '*'.str_replace(['\\', '*', '_', '`', '[', ']'], ['\\\\', '\\*', '\\_', '\\`', '\\[', '\\]'], $text).'*';
        $out[] = '';
    }

    /**
     * @param list<string> $out
     */
    private function text(array &$out, string $format, string $content, string $heading): void
    {
        if (trim($content) === '') {
            return;
        }
        $out[] = sprintf('**%s**', $heading);
        $out[] = '';
        $out[] = $this->converter->toMarkdown($content, $format);
        $out[] = '';
    }

    /**
     * @param list<string>                  $out
     * @param list<array{type: string, content: string}> $resources
     */
    private function resources(array &$out, array $resources): void
    {
        if ($resources === []) {
            return;
        }
        $out[] = '**Resources**';
        foreach ($resources as $resource) {
            $out[] = sprintf('- (%s) %s', $resource['type'], $resource['content']);
        }
        $out[] = '';
    }

    /**
     * @param list<string>                       $out
     * @param list<array{type: string, content: string}> $diagrams
     */
    private function diagrams(array &$out, array $diagrams): void
    {
        foreach ($diagrams as $diagram) {
            $out[] = '```mermaid';
            $out[] = rtrim($diagram['content']);
            $out[] = '```';
            $out[] = '';
        }
    }
}
