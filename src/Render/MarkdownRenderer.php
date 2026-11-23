<?php

declare(strict_types=1);

namespace App\Render;

use App\Reference\ReferenceResolver;

/**
 * Renders a document as one Markdown file: one section per epic, technical story and subtask, titles carrying their
 * reference, and a Dependencies section at the end whose entries link to the stored `pageLink`s.
 */
final class MarkdownRenderer
{
    public function __construct(private readonly ReferenceResolver $references)
    {
    }

    /**
     * @param array<string, mixed> $document
     */
    public function render(array $document): string
    {
        $out = [];
        $project = $document['project'] ?? [];
        $out[] = sprintf('# %s', $project['name'] ?? 'Technical analysis');
        $out[] = '';
        $out[] = sprintf('- Project: %s', $project['reference'] ?? '-');
        if (isset($project['link'])) {
            $out[] = sprintf('- Project link: %s', $project['link']);
        }
        $author = $document['author'] ?? [];
        $out[] = sprintf('- Author: %s%s', $author['name'] ?? '-', isset($author['email']) ? sprintf(' <%s>', $author['email']) : '');
        $out[] = sprintf('- Version: %s (structure %s)', $document['version'] ?? '-', $document['structureVersion'] ?? '-');
        $out[] = '';

        foreach ($document['epics'] ?? [] as $e => $epic) {
            $out[] = '';
            $out[] = sprintf('## %s', $this->references->title($this->references->epic($document, $e), (string) $epic['title']));
            $out[] = '';
            $this->block($out, $document['format'] ?? 'markdown', $epic['what'] ?? '');
            $this->resources($out, $epic);
            $this->diagrams($out, $epic['diagrams'] ?? []);

            foreach ($epic['userStories'] ?? [] as $u => $us) {
                $out[] = '';
                $out[] = sprintf('### %s', $this->references->title($this->references->userStory($document, $e, $u), (string) $us['title']));
                $out[] = '';
                foreach ($us['acceptanceCriteria'] ?? [] as $ac) {
                    $out[] = sprintf('- %s', $ac);
                }
                $this->resources($out, $us);
                $this->diagrams($out, $us['diagrams'] ?? []);
            }

            foreach ($epic['technicalStories'] ?? [] as $t => $ts) {
                $out[] = '';
                $out[] = sprintf('### %s', $this->references->title($this->references->technicalStory($document, $e, $t), (string) $ts['title']));
                $out[] = '';
                $this->block($out, $document['format'] ?? 'markdown', $ts['what'] ?? '', 'What');
                $this->block($out, $document['format'] ?? 'markdown', $ts['why'] ?? '', 'Why');
                if (($ts['acceptanceCriteriaCovered'] ?? []) !== []) {
                    $out[] = '**Acceptance criteria covered**';
                    foreach ($ts['acceptanceCriteriaCovered'] as $ac) {
                        $out[] = sprintf('- %s', $ac);
                    }
                    $out[] = '';
                }
                $this->resources($out, $ts);
                $this->diagrams($out, $ts['diagrams'] ?? []);

                foreach ($ts['subtasks'] ?? [] as $s => $sub) {
                    $out[] = '';
                    $out[] = sprintf('#### %s', $this->references->title($this->references->subtask($document, $e, $t, $s), (string) $sub['title']));
                    $out[] = '';
                    if (isset($sub['featureToggle'])) {
                        $out[] = sprintf('- Feature toggle: `%s`', $sub['featureToggle']);
                    }
                    if (isset($sub['sqlImpact'])) {
                        $out[] = sprintf('- SQL impact: %d column(s), %d row(s)', $sub['sqlImpact']['nbColumns'], $sub['sqlImpact']['nbRows']);
                    }
                    if (isset($sub['criterion'])) {
                        $out[] = sprintf('- Criterion: %s', $sub['criterion']);
                    }
                    $out[] = '';
                    foreach (['what' => 'What', 'why' => 'Why', 'where' => 'Where', 'how' => 'How'] as $key => $label) {
                        $this->block($out, $document['format'] ?? 'markdown', $sub[$key] ?? '', $label);
                    }
                    $this->diagrams($out, $sub['diagrams'] ?? []);
                }
            }
        }

        $out[] = '';
        $out[] = '## Dependencies';
        $out[] = '';
        $out = array_merge($out, $this->dependencyLines($document));

        return implode("\n", $out)."\n";
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<string>
     */
    public function dependencyLines(array $document): array
    {
        $index = $this->index($document);
        $lines = [];
        foreach ($document['dependencies'] ?? [] as $dep) {
            $lines[] = sprintf('- %s is blocked by %s', $this->link($index, $dep['to']), $this->link($index, $dep['from']));
        }
        if ($lines === []) {
            $lines[] = '_No dependency._';
        }

        return $lines;
    }

    /**
     * Display title and stored page link per reference (epics, user stories, technical stories).
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, array{title: string, pageLink: ?string}>
     */
    public function index(array $document): array
    {
        $index = [];
        foreach ($document['epics'] ?? [] as $e => $epic) {
            $index[$epic['reference'] ?? ''] ??= ['title' => $this->references->title($this->references->epic($document, $e), (string) $epic['title']), 'pageLink' => $epic['pageLink'] ?? null];
            foreach ($epic['userStories'] ?? [] as $u => $us) {
                $index[$us['reference'] ?? ''] ??= ['title' => $this->references->title($this->references->userStory($document, $e, $u), (string) $us['title']), 'pageLink' => $us['pageLink'] ?? null];
            }
            foreach ($epic['technicalStories'] ?? [] as $t => $ts) {
                $index[$ts['reference'] ?? ''] ??= ['title' => $this->references->title($this->references->technicalStory($document, $e, $t), (string) $ts['title']), 'pageLink' => $ts['pageLink'] ?? null];
            }
        }

        return $index;
    }

    /**
     * @param array<string, array{title: string, pageLink: ?string}> $index
     */
    private function link(array $index, string $reference): string
    {
        $node = $index[$reference] ?? null;
        if ($node === null) {
            return sprintf('`%s`', $reference);
        }

        return $node['pageLink'] !== null && $node['pageLink'] !== ''
            ? sprintf('[%s](%s)', $node['title'], $node['pageLink'])
            : $node['title'];
    }

    /**
     * @param list<string> $out
     */
    private function block(array &$out, string $format, string $content, ?string $heading = null): void
    {
        if (trim($content) === '') {
            return;
        }
        if ($heading !== null) {
            $out[] = sprintf('**%s**', $heading);
            $out[] = '';
        }
        $out[] = $this->text($format, $content);
        $out[] = '';
    }

    private function text(string $format, string $content): string
    {
        // html is only stripped for now; a real html-to-markdown conversion comes with the PDF adapter
        return $format === 'html' ? trim(strip_tags($content)) : trim($content);
    }

    /**
     * @param list<string> $out
     * @param array<string, mixed> $node
     */
    private function resources(array &$out, array $node): void
    {
        if (($node['resources'] ?? []) === []) {
            return;
        }
        $out[] = '**Resources**';
        foreach ($node['resources'] as $resource) {
            $out[] = sprintf('- (%s) %s', $resource['type'], $resource['content']);
        }
        $out[] = '';
    }

    /**
     * @param list<string> $out
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
