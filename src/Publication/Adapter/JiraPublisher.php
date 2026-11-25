<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Jira\JiraClient;
use App\Jira\JiraDescriptionBuilder;
use App\Jira\JiraSyncOptions;
use App\Jira\JiraTicketSynchronizer;
use App\Publication\Confluence\DiagramAttachments;
use App\Publication\DocumentWriteBack;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Render\DocumentModelBuilder;
use App\Storage\TaRepositoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Jira only, through its REST API: the epics, technical stories and sub-tasks of the document become tickets that carry
 * their own content (summary and description, diagrams as attachments). No Confluence page, no browser.
 *
 *  1. the token is checked; a rejected token writes nothing;
 *  2. per epic, then per technical story, then per sub-task: the ticket is created, or the one already there is adopted;
 *     its summary, description and diagram attachments are then replaced (a re-run rewrites them);
 *  3. Blocks links between the technical stories (and epics) of the dependencies;
 *  4. the keys are written back into the JSON in one new version, even when the run stopped halfway.
 *
 * User stories have no ticket: they are listed, with their acceptance criteria, in the description of their epic.
 * Dry-run: no request is sent; the plan of what would be created or updated is returned.
 */
final class JiraPublisher implements PublisherInterface
{
    public function __construct(
        private readonly DocumentModelBuilder $builder,
        private readonly DiagramAttachments $diagrams,
        private readonly JiraDescriptionBuilder $descriptions,
        private readonly DocumentWriteBack $writeBack,
        private readonly TaRepositoryInterface $repository,
        private readonly string $jiraBaseUrl,
        private readonly ?string $jiraToken,
        private readonly ?HttpClientInterface $http = null,
    ) {
    }

    public function getName(): string
    {
        return 'jira';
    }

    public function getLabel(): string
    {
        return 'Jira API (tickets only: epics, technical stories, sub-tasks)';
    }

    public function getOptionDefinitions(): array
    {
        return [
            new OptionDefinition('epicProject', 'Jira project of the epics', false, 'CFPOPR'),
            new OptionDefinition('storyProject', 'Jira project of the technical stories and sub-tasks', false, 'CFPOBT'),
            new OptionDefinition('fixVersion', 'Jira fix version (e.g. 2026-R12)', false),
            new OptionDefinition('epicType', 'Issue type of an epic', false, 'Epic'),
            new OptionDefinition('storyType', 'Issue type of a technical story', false, 'Technical Story'),
            new OptionDefinition('subtaskType', 'Issue type of a sub-task', false, 'Sub-task'),
            new OptionDefinition('epicLinkField', 'Custom field that links a story to its epic', false, 'customfield_10000'),
            new OptionDefinition('epicNameField', 'Custom field "Epic Name", when the project requires it (empty = not sent)', false),
        ];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $options = $this->syncOptions($request->options);
        $model = $this->builder->build($request->document);
        $stored = $this->repository->get($request->documentId);

        if ($request->dryRun) {
            return new PublishResult('dry-run', $this->plan($model, $options));
        }
        if ((string) $this->jiraToken === '') {
            return new PublishResult('failed', ['JIRA_PAT is not set. Add it to .env.local (never commit it).']);
        }
        $jira = new JiraClient($this->jiraBaseUrl, (string) $this->jiraToken, $this->http);
        if (!$jira->checkToken()) {
            return new PublishResult('failed', ['Jira rejected the token (JIRA_PAT). Nothing was written.']);
        }

        $format = (string) ($model['format'] ?? 'markdown');
        $sync = new JiraTicketSynchronizer($jira);
        $messages = [];
        $updates = [];
        $ticketByRef = [];
        $status = 'done';
        try {
            foreach ($model['epics'] as $e => $epic) {
                $epicPrefix = 'epic'.($e + 1);
                // the user stories have no ticket: their diagrams go with the epic
                $epicDiagrams = $this->diagrams->collect($epic['diagrams'], $epicPrefix);
                foreach ($epic['userStories'] as $u => $us) {
                    $usFiles = $this->diagrams->collect($us['diagrams'], $epicPrefix.'-us'.($u + 1));
                    $epicDiagrams['files'] += $usFiles['files'];
                    $epicDiagrams['attachments'] = array_merge($epicDiagrams['attachments'], $usFiles['attachments']);
                    $epicDiagrams['warnings'] = array_merge($epicDiagrams['warnings'], $usFiles['warnings']);
                }
                $epicKey = $this->ensureTicket(
                    $jira, $sync, 'epic', $epic, '', $options,
                    $this->descriptions->epic($epic, $format, $epicDiagrams['files']), $epicDiagrams,
                    "/epics/$e", $updates, $messages,
                );
                $ticketByRef[$epic['reference']] = $epicKey;

                foreach ($epic['technicalStories'] as $t => $ts) {
                    $tsPrefix = sprintf('epic%d-ts%d', $e + 1, $t + 1);
                    $tsFiles = $this->diagrams->collect($ts['diagrams'], $tsPrefix);
                    $tsKey = $this->ensureTicket(
                        $jira, $sync, 'story', $ts, $epicKey, $options,
                        $this->descriptions->technicalStory($ts, $format, $tsFiles['files']), $tsFiles,
                        "/epics/$e/technicalStories/$t", $updates, $messages,
                    );
                    $ticketByRef[$ts['reference']] = $tsKey;

                    foreach ($ts['subtasks'] as $s => $sub) {
                        $subFiles = $this->diagrams->collect($sub['diagrams'], $tsPrefix.'-st'.($s + 1));
                        $this->ensureTicket(
                            $jira, $sync, 'sub', $sub, $tsKey, $options,
                            $this->descriptions->subtask($sub, $format, $subFiles['files']), $subFiles,
                            "/epics/$e/technicalStories/$t/subtasks/$s", $updates, $messages,
                        );
                    }
                }
            }

            $pairs = [];
            foreach ($model['dependencies'] as $dep) {
                $pairs[] = ['from' => $ticketByRef[$dep['from']] ?? '', 'to' => $ticketByRef[$dep['to']] ?? ''];
            }
            array_push($messages, ...$sync->linkBlocks($pairs, false));
        } catch (\RuntimeException $e) {
            // the message comes from Jira (never the token); what was created before is kept by the write-back below
            $messages[] = 'STOP: '.$e->getMessage();
            $status = 'failed';
        } finally {
            $this->save($request, $stored, $updates);
        }

        return new PublishResult($status, $messages);
    }

    /**
     * Creates the ticket of a node, or adopts the one already there; then writes its summary, description and diagram
     * attachments. Returns the key.
     *
     * @param array<string, mixed>                                                                                                       $node
     * @param array{files: array<string, string>, attachments: list<array{filename: string, bytes: string}>, warnings: list<string>} $files
     * @param array<string, array<string, mixed>>                                                                                        $updates
     * @param list<string>                                                                                                               $messages
     */
    private function ensureTicket(JiraClient $jira, JiraTicketSynchronizer $sync, string $kind, array $node, string $parentKey, JiraSyncOptions $options, string $description, array $files, string $path, array &$updates, array &$messages): string
    {
        $result = match ($kind) {
            'epic' => $sync->epic($node, $options, false),
            'story' => $sync->technicalStory($node, $parentKey, $options, false),
            default => $sync->subtask($node, $parentKey, $options, false),
        };
        $key = $result['key'];
        if ($result['created']) {
            $messages[] = sprintf('Jira %s created for %s', $key, $node['displayTitle']);
        }
        if (empty($node['ticket'])) {
            $updates[$path] = ($updates[$path] ?? []) + ['ticket' => $key];
        }

        $jira->updateIssue($key, ['summary' => mb_substr((string) $node['displayTitle'], 0, 255), 'description' => $description]);
        $this->attach($jira, $key, $files, $messages);

        return $key;
    }

    /**
     * Replaces the diagram attachments of an issue: an attachment with the same name is deleted, then the new one uploaded.
     *
     * @param array{files: array<string, string>, attachments: list<array{filename: string, bytes: string}>, warnings: list<string>} $files
     * @param list<string>                                                                                                             $messages
     */
    private function attach(JiraClient $jira, string $key, array $files, array &$messages): void
    {
        foreach ($files['warnings'] as $warning) {
            $messages[] = 'warning: '.$warning;
        }
        if ($files['attachments'] === []) {
            return;
        }
        $existing = $jira->attachments($key);
        foreach ($files['attachments'] as $attachment) {
            foreach ($existing as $old) {
                if ($old['filename'] === $attachment['filename']) {
                    $jira->deleteAttachment($old['id']);
                }
            }
            $jira->addAttachment($key, $attachment['filename'], $attachment['bytes']);
        }
    }

    /**
     * @param array<string, string> $answers
     */
    private function syncOptions(array $answers): JiraSyncOptions
    {
        $value = static fn (string $name, string $default): string => ($answers[$name] ?? '') !== '' ? (string) $answers[$name] : $default;

        return new JiraSyncOptions(
            $value('epicProject', 'CFPOPR'),
            $value('storyProject', 'CFPOBT'),
            ($answers['fixVersion'] ?? '') !== '' ? (string) $answers['fixVersion'] : null,
            $value('epicLinkField', 'customfield_10000'),
            $value('epicType', 'Epic'),
            $value('storyType', 'Technical Story'),
            $value('subtaskType', 'Sub-task'),
            ($answers['epicNameField'] ?? '') !== '' ? (string) $answers['epicNameField'] : null,
        );
    }

    /**
     * @param array<string, mixed> $model
     *
     * @return list<string>
     */
    private function plan(array $model, JiraSyncOptions $options): array
    {
        $lines = [];
        foreach ($model['epics'] as $epic) {
            $lines[] = sprintf('%s: %s (%s)', $epic['displayTitle'], $epic['ticket'] ? 'known: '.$epic['ticket'] : 'would create', $options->epicProject);
            foreach ($epic['userStories'] as $us) {
                $lines[] = sprintf('  %s: no ticket, listed in the epic description', $us['displayTitle']);
            }
            foreach ($epic['technicalStories'] as $ts) {
                $lines[] = sprintf('  %s: %s (%s)', $ts['displayTitle'], $ts['ticket'] ? 'known: '.$ts['ticket'] : 'would create', $options->storyType);
                foreach ($ts['subtasks'] as $sub) {
                    $lines[] = sprintf('    %s: %s (%s)', $sub['displayTitle'], $sub['ticket'] ? 'known: '.$sub['ticket'] : 'would create', $options->subtaskType);
                }
            }
        }
        $lines[] = sprintf('Blocks links: %d dependency(ies) to check', count($model['dependencies']));
        $lines[] = sprintf('Jira projects %s / %s, fix version %s', $options->epicProject, $options->storyProject, $options->fixVersion ?? '-');

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $updates
     */
    private function save(PublishRequest $request, \App\Storage\StoredDocument $stored, array $updates): void
    {
        if ($request->dryRun || $updates === []) {
            return;
        }
        // one version for the whole run, even when it stopped halfway: what was created keeps its key
        $this->repository->save($request->documentId, $this->writeBack->apply($stored->data, $updates), 'jira', $stored->version);
    }
}
