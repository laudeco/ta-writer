<?php

declare(strict_types=1);

namespace App\Jira;

/**
 * The description of each Jira issue, in wiki markup: the content of the node the pages of AGIR carry, here carried by the
 * ticket itself. A section without content is left out. A diagram is shown by the name of its PNG attachment; a diagram
 * that did not render is shown as its source.
 */
final class JiraDescriptionBuilder
{
    public function __construct(private readonly MarkdownToJiraWiki $wiki)
    {
    }

    /**
     * @param array<string, mixed>  $epic      model node of the epic (user stories included)
     * @param array<string, string> $diagrams  diagram source => attachment name
     */
    public function epic(array $epic, string $format, array $diagrams): string
    {
        $sections = [
            $this->section('Context', $this->rich((string) ($epic['what'] ?? ''), $format)),
            $this->section('User stories', $this->userStories($epic['userStories'] ?? [], $format)),
            $this->section('Resources', $this->resources($epic['resources'] ?? [], $format)),
            $this->section('Diagrams', $this->diagrams($epic['diagrams'] ?? [], $diagrams)),
        ];

        return $this->join($sections);
    }

    /**
     * @param array<string, mixed>  $ts       model node of the technical story
     * @param array<string, string> $diagrams diagram source => attachment name
     */
    public function technicalStory(array $ts, string $format, array $diagrams): string
    {
        $sections = [
            $this->section('What', $this->rich((string) ($ts['what'] ?? ''), $format)),
            $this->section('Why', $this->rich((string) ($ts['why'] ?? ''), $format)),
            $this->section('Acceptance criteria covered', $this->bullets($ts['acceptanceCriteriaCovered'] ?? [])),
            $this->section('Feature toggles', $this->bullets($ts['featureToggles'] ?? [])),
            $this->section('SQL impact', $this->sqlImpact($ts['sqlImpact'] ?? null)),
            $this->section('Resources', $this->resources($ts['resources'] ?? [], $format)),
            $this->section('Diagrams', $this->diagrams($ts['diagrams'] ?? [], $diagrams)),
        ];

        return $this->join($sections);
    }

    /**
     * @param array<string, mixed>  $sub      model node of the sub-task
     * @param array<string, string> $diagrams diagram source => attachment name
     */
    public function subtask(array $sub, string $format, array $diagrams): string
    {
        $sections = [
            $this->section('What', $this->rich((string) ($sub['what'] ?? ''), $format)),
            $this->section('Why', $this->rich((string) ($sub['why'] ?? ''), $format)),
            $this->section('Where', $this->rich((string) ($sub['where'] ?? ''), $format)),
            $this->section('How', $this->rich((string) ($sub['how'] ?? ''), $format)),
            $this->section('Criterion', $this->plain((string) ($sub['criterion'] ?? ''))),
            $this->section('Feature toggle', $this->plain((string) ($sub['featureToggle'] ?? ''))),
            $this->section('SQL impact', $this->sqlImpact($sub['sqlImpact'] ?? null)),
            $this->section('Resources', $this->resources($sub['resources'] ?? [], $format)),
            $this->section('Diagrams', $this->diagrams($sub['diagrams'] ?? [], $diagrams)),
        ];

        return $this->join($sections);
    }

    /**
     * @param list<array<string, mixed>> $stories
     */
    private function userStories(array $stories, string $format): string
    {
        $out = [];
        foreach ($stories as $story) {
            $block = '* *'.$this->plain((string) ($story['displayTitle'] ?? '')).'*';
            foreach (array_values($story['acceptanceCriteria'] ?? []) as $criterion) {
                $block .= "\n** ".$this->plain((string) $criterion);
            }
            $resources = $this->resources($story['resources'] ?? [], $format);
            $out[] = $block.($resources !== '' ? "\n".$resources : '');
        }

        return implode("\n", $out);
    }

    /**
     * @param list<array<string, mixed>> $resources
     */
    private function resources(array $resources, string $format): string
    {
        $lines = [];
        foreach ($resources as $resource) {
            $type = (string) ($resource['type'] ?? '');
            $content = (string) ($resource['content'] ?? '');
            // only a note is rich text; a link, an image or a ticket is one plain value
            $value = $type === 'note' ? $this->rich($content, $format) : $this->plain($content);
            $lines[] = '* *'.$this->plain($type).'*: '.$value;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $diagrams
     * @param array<string, string>      $names   diagram source => attachment name
     */
    private function diagrams(array $diagrams, array $names): string
    {
        $out = [];
        foreach ($diagrams as $diagram) {
            $source = rtrim((string) ($diagram['content'] ?? ''));
            if (isset($names[$source])) {
                $out[] = '!'.$names[$source].'!';
            } else {
                $out[] = "(i) The diagram could not be rendered: its source follows.\n{code}\n".$source."\n{code}";
            }
        }

        return implode("\n\n", $out);
    }

    private function sqlImpact(mixed $impact): string
    {
        if (!is_array($impact)) {
            return '';
        }

        return sprintf('Columns: %d, rows: %d.', (int) ($impact['nbColumns'] ?? 0), (int) ($impact['nbRows'] ?? 0));
    }

    /**
     * @param list<string> $items
     */
    private function bullets(array $items): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = '* '.$this->plain((string) $item);
        }

        return implode("\n", $lines);
    }

    private function rich(string $content, string $format): string
    {
        return $this->wiki->fromFormat($content, $format);
    }

    private function plain(string $text): string
    {
        return $this->wiki->fromFormat($text, 'text');
    }

    private function section(string $heading, string $body): string
    {
        return trim($body) === '' ? '' : "h2. {$heading}\n\n".trim($body);
    }

    /**
     * @param list<string> $sections
     */
    private function join(array $sections): string
    {
        return implode("\n\n", array_values(array_filter($sections, static fn (string $s): bool => $s !== '')))."\n";
    }
}
