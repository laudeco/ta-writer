<?php

declare(strict_types=1);

namespace App\Jira;

/**
 * Creates the Jira epic, technical stories and sub-tasks of the model, then the Blocks links. Idempotent: a node that
 * already has a ticket is kept, and a node whose summary already exists under its parent is adopted, not duplicated.
 */
final class JiraTicketSynchronizer
{
    public function __construct(private readonly JiraClient $jira)
    {
    }

    /**
     * @param array<string, mixed> $epic model node
     *
     * @return array{key: string, created: bool}
     */
    public function epic(array $epic, JiraSyncOptions $options, bool $dryRun): array
    {
        if (!empty($epic['ticket'])) {
            return ['key' => (string) $epic['ticket'], 'created' => false];
        }
        $summary = (string) $epic['displayTitle'];
        $found = $this->findExact(sprintf('project = %s AND issuetype = Epic AND summary ~ "\\"%s\\""', $options->epicProject, $this->escape($summary)), $summary);
        if ($found !== null) {
            return ['key' => $found, 'created' => false];
        }
        if ($dryRun) {
            return ['key' => '', 'created' => true];
        }

        $fields = $this->fields($options->epicProject, $options->epicType, $summary, $options);
        if ($options->epicNameField !== null) {
            $fields[$options->epicNameField] = mb_substr($summary, 0, 255);
        }

        return ['key' => $this->jira->createIssue($fields), 'created' => true];
    }

    /**
     * @param array<string, mixed> $ts model node
     *
     * @return array{key: string, created: bool}
     */
    public function technicalStory(array $ts, string $epicKey, JiraSyncOptions $options, bool $dryRun): array
    {
        if (!empty($ts['ticket'])) {
            return ['key' => (string) $ts['ticket'], 'created' => false];
        }
        $summary = (string) $ts['displayTitle'];
        $found = $epicKey === '' ? null : $this->findExact(sprintf('"Epic Link" = %s', $epicKey), $summary);
        if ($found !== null) {
            return ['key' => $found, 'created' => false];
        }
        if ($dryRun) {
            return ['key' => '', 'created' => true];
        }
        $fields = $this->fields($options->storyProject, $options->storyType, $summary, $options);
        $fields[$options->epicLinkField] = $epicKey;

        return ['key' => $this->jira->createIssue($fields), 'created' => true];
    }

    /**
     * @param array<string, mixed> $sub model node
     *
     * @return array{key: string, created: bool}
     */
    public function subtask(array $sub, string $tsKey, JiraSyncOptions $options, bool $dryRun): array
    {
        if (!empty($sub['ticket'])) {
            return ['key' => (string) $sub['ticket'], 'created' => false];
        }
        $summary = (string) $sub['displayTitle'];
        $found = $tsKey === '' ? null : $this->findExact(sprintf('parent = %s', $tsKey), $summary);
        if ($found !== null) {
            return ['key' => $found, 'created' => false];
        }
        if ($dryRun) {
            return ['key' => '', 'created' => true];
        }
        $fields = $this->fields($options->storyProject, $options->subtaskType, $summary, $options);
        $fields['parent'] = ['key' => $tsKey];

        return ['key' => $this->jira->createIssue($fields), 'created' => true];
    }

    /**
     * Creates the Blocks links that do not exist yet. Pairs with an unknown ticket are reported, never guessed.
     *
     * @param list<array{from: string, to: string}> $pairs ticket keys, blocker first
     *
     * @return list<string> one message per pair
     */
    public function linkBlocks(array $pairs, bool $dryRun): array
    {
        $messages = [];
        foreach ($pairs as $pair) {
            if ($pair['from'] === '' || $pair['to'] === '') {
                $messages[] = sprintf('link skipped: a ticket is missing (%s -> %s)', $pair['from'] ?: '?', $pair['to'] ?: '?');
                continue;
            }
            if (\in_array($pair['from'], $this->jira->linkedKeys($pair['to']), true)) {
                $messages[] = sprintf('%s blocks %s: already linked', $pair['from'], $pair['to']);
                continue;
            }
            if (!$dryRun) {
                $this->jira->createBlocksLink($pair['from'], $pair['to']);
            }
            $messages[] = sprintf('%s blocks %s: %s', $pair['from'], $pair['to'], $dryRun ? 'would link' : 'linked');
        }

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function fields(string $project, string $type, string $summary, JiraSyncOptions $options): array
    {
        $fields = [
            'project' => ['key' => $project],
            'summary' => mb_substr($summary, 0, 255),
            'issuetype' => ['name' => $type],
            'labels' => ['bulk_insert'],
            'priority' => ['name' => 'Medium'],
        ];
        if ($options->fixVersion !== null && $options->fixVersion !== '') {
            $fields['versions'] = [['name' => $options->fixVersion]];
            $fields['fixVersions'] = [['name' => $options->fixVersion]];
        }

        return $fields;
    }

    /**
     * Key of the issue whose summary is exactly $summary among the results of $jql.
     */
    private function findExact(string $jql, string $summary): ?string
    {
        foreach ($this->jira->search($jql) as $issue) {
            if ($issue['summary'] === mb_substr($summary, 0, 255)) {
                return $issue['key'];
            }
        }

        return null;
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $text);
    }
}
