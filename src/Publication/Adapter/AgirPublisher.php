<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Browser\AgirAuthenticator;
use App\Browser\BrowserSessionInterface;
use App\Browser\NotAuthenticatedException;
use App\Jira\JiraClient;
use App\Jira\JiraSyncOptions;
use App\Jira\JiraTicketSynchronizer;
use App\Publication\Agir\ReferencePageCloner;
use App\Publication\Confluence\BrowserConfluenceClient;
use App\Publication\Confluence\ConfluencePagePublisher;
use App\Publication\Confluence\DiagramAttachments;
use App\Publication\Confluence\PageBodyBuilder;
use App\Publication\Confluence\PageSpec;
use App\Publication\DocumentWriteBack;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Render\DocumentModelBuilder;
use App\Storage\StoredDocument;
use App\Storage\TaRepositoryInterface;

/**
 * AGIR publication, after the technical-analysis-agir-writer skill, driven from PHP:
 *  1. Jira token checked, then the AGIR browser session (the user logs in when needed, through noVNC);
 *  2. per epic: Jira epic, then its epic page (reference page cloned, cells replaced by label); per technical story:
 *     Jira story and page (cloned); per subtask: Jira sub-task and the generated Sub-Task page;
 *  3. Blocks links between the Jira stories, then the dependencies page;
 *  4. every key and link is written back into the JSON in one new version, even when the run stopped halfway.
 *
 * Dry-run: no browser and no Jira write; it reports what would be created or updated.
 */
final class AgirPublisher implements PublisherInterface
{
    public function __construct(
        private readonly DocumentModelBuilder $builder,
        private readonly PageBodyBuilder $bodies,
        private readonly DiagramAttachments $diagrams,
        private readonly DocumentWriteBack $writeBack,
        private readonly TaRepositoryInterface $repository,
        private readonly BrowserSessionInterface $session,
        private readonly string $confluenceOrigin,
        private readonly string $jiraBaseUrl,
        private readonly ?string $jiraToken,
    ) {
    }

    public function getName(): string
    {
        return 'agir';
    }

    public function getLabel(): string
    {
        return 'Confluence AGIR (browser session, Jira tickets, pages)';
    }

    public function getOptionDefinitions(): array
    {
        return [
            new OptionDefinition('space', 'Confluence space key', true, 'CFDITS'),
            new OptionDefinition('rootPage', 'Root page: URL or page id under which the epics are written', true),
            new OptionDefinition('fixVersion', 'Jira fix version (e.g. 2026-R12)', false),
            new OptionDefinition('epicProject', 'Jira project of the epics', false, 'CFPOPR'),
            new OptionDefinition('storyProject', 'Jira project of the stories and sub-tasks', false, 'CFPOBT'),
            new OptionDefinition('analystUserKey', 'Technical Analyst userkey (32 hex, from the AGIR user profile)', false),
            new OptionDefinition('refEpicPageId', 'Reference page of an epic (its body and cells are cloned)', false, '3398621794'),
            new OptionDefinition('refTsPageId', 'Reference page of a technical story (its body and cells are cloned)', false, '3399544715'),
        ];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $space = (string) ($request->options['space'] ?? 'CFDITS');
        $rootId = $this->pageId((string) ($request->options['rootPage'] ?? ''));
        if ($rootId === null) {
            return new PublishResult('failed', ['The root page (URL or id) is required.']);
        }
        $options = new JiraSyncOptions(
            (string) ($request->options['epicProject'] ?? 'CFPOPR'),
            (string) ($request->options['storyProject'] ?? 'CFPOBT'),
            ($request->options['fixVersion'] ?? '') !== '' ? (string) $request->options['fixVersion'] : null,
        );
        $format = (string) ($request->document['format'] ?? 'markdown');
        $model = $this->builder->build($request->document);
        $stored = $this->repository->get($request->documentId);

        if ($request->dryRun) {
            return new PublishResult('dry-run', $this->plan($model, $options));
        }
        if ((string) $this->jiraToken === '') {
            return new PublishResult('failed', ['JIRA_PAT is not set. Add it to .env.local (never commit it).']);
        }
        $jira = new JiraClient($this->jiraBaseUrl, (string) $this->jiraToken);
        if (!$jira->checkToken()) {
            return new PublishResult('failed', ['Jira rejected the token (JIRA_PAT). Nothing was written.']);
        }

        $messages = [];
        $updates = [];
        $ticketByRef = [];
        try {
            $auth = new AgirAuthenticator($this->session, $this->confluenceOrigin);
            $auth->ensureLoggedIn(static function (string $instruction) use (&$messages): void {
                $messages[] = $instruction;
            });

            $client = new BrowserConfluenceClient($this->session, $this->confluenceOrigin);
            $pages = new ConfluencePagePublisher($client, $this->confluenceOrigin);
            $cloner = new ReferencePageCloner($client);
            $sync = new JiraTicketSynchronizer($jira);
            $analyst = (string) ($request->options['analystUserKey'] ?? '');
            $epicBase = $cloner->fetchBody((string) ($request->options['refEpicPageId'] ?? '3398621794'));
            $tsBase = $cloner->fetchBody((string) ($request->options['refTsPageId'] ?? '3399544715'));

            foreach ($model['epics'] as $e => $epic) {
                $epicKey = $this->ensureTicket($sync, 'epic', $epic, $options, '', "/epics/$e", $updates, $messages);
                $ticketByRef[$epic['reference']] = $epicKey;

                $epicPageId = $this->publishPage(
                    $pages, $space, $rootId, $epic['displayTitle'], $this->pageId((string) ($epic['pageLink'] ?? '')),
                    $cloner->apply($epicBase, [
                        'Epic Jira' => $this->jiraMacro($epicKey),
                        'E US / AC' => $this->e((string) $epic['reference']),
                        'Context' => $this->e($this->firstSentence($epic['what'])),
                    ], ['Context']).$this->diagramSection($epic['diagrams'], "epic".($e + 1)),
                    $this->diagrams->collect($epic['diagrams'], 'epic'.($e + 1)),
                    $messages, $updates, "/epics/$e",
                );

                foreach ($epic['technicalStories'] as $t => $ts) {
                    $prefix = sprintf('epic%d-ts%d', $e + 1, $t + 1);
                    $tsKey = $this->ensureTicket($sync, 'story', $ts, $options, $epicKey, "/epics/$e/technicalStories/$t", $updates, $messages);
                    $ticketByRef[$ts['reference']] = $tsKey;

                    $cells = [
                        'Epic Jira' => $this->jiraMacro($epicKey),
                        'Story Jira' => $this->jiraMacro($tsKey),
                        'E US / AC' => $this->e(trim($epic['reference'].' '.$ts['reference'])),
                        'Context' => $this->e($this->firstSentence($ts['what'])),
                        'Feature Toggle' => $this->e(implode(', ', $ts['featureToggles']) ?: '-'),
                    ];
                    if ($analyst !== '') {
                        $cells['Technical Analyst'] = sprintf('<ac:link><ri:user ri:userkey="%s" /></ac:link>', $this->e($analyst));
                    }
                    $tsPageId = $this->publishPage(
                        $pages, $space, (string) $epicPageId, $ts['displayTitle'], $this->pageId((string) ($ts['pageLink'] ?? '')),
                        $cloner->apply($tsBase, $cells, ['Context', 'Feature Toggle']).$this->diagramSection($ts['diagrams'], $prefix),
                        $this->diagrams->collect($ts['diagrams'], $prefix),
                        $messages, $updates, "/epics/$e/technicalStories/$t",
                    );

                    foreach ($ts['subtasks'] as $s => $sub) {
                        $path = "/epics/$e/technicalStories/$t/subtasks/$s";
                        $this->ensureTicket($sync, 'sub', $sub, $options, $tsKey, $path, $updates, $messages);
                        $files = $this->diagrams->collect($sub['diagrams'], $prefix.'-st'.($s + 1));
                        $this->publishPage(
                            $pages, $space, (string) $tsPageId, $sub['displayTitle'], $this->pageId((string) ($sub['pageLink'] ?? '')),
                            $this->bodies->subtask($sub, $format, $files['files'], $ts['displayTitle'], $tsKey),
                            $files, $messages, $updates, $path,
                        );
                    }
                }
            }

            $pairs = [];
            foreach ($model['dependencies'] as $dep) {
                $pairs[] = ['from' => $ticketByRef[$dep['from']] ?? '', 'to' => $ticketByRef[$dep['to']] ?? ''];
            }
            array_push($messages, ...$sync->linkBlocks($pairs, false));

            $linked = $this->builder->build($this->writeBack->apply($request->document, $updates));
            $depsTitle = sprintf('%s - Dependencies', (string) ($model['project']['reference'] ?? 'Analysis'));
            $deps = $pages->upsert(new PageSpec(
                $space,
                $rootId,
                $depsTitle,
                $this->bodies->dependencies($linked['dependencies']),
                $this->pageId((string) ($request->document['dependenciesPageLink'] ?? '')),
            ));
            $updates[''] = ['dependenciesPageLink' => $deps->url];
            $messages[] = sprintf('%s: page %s', $depsTitle, $deps->status);
            $status = 'done';
        } catch (NotAuthenticatedException $e) {
            $messages[] = 'STOP: '.$e->getMessage();
            $status = 'failed';
        } finally {
            $this->session->close();
            $this->save($request, $stored, $updates);
        }

        return new PublishResult($status, $messages);
    }

    /**
     * Creates the Jira issue of a node when it has none, or adopts the one already there, and returns its key.
     *
     * @param array<string, mixed>                $node
     * @param array<string, array<string, mixed>> $updates
     * @param list<string>                        $messages
     */
    private function ensureTicket(JiraTicketSynchronizer $sync, string $kind, array $node, JiraSyncOptions $options, string $parentKey, string $path, array &$updates, array &$messages): string
    {
        $result = match ($kind) {
            'epic' => $sync->epic($node, $options, false),
            'story' => $sync->technicalStory($node, $parentKey, $options, false),
            default => $sync->subtask($node, $parentKey, $options, false),
        };
        if ($result['created']) {
            $messages[] = sprintf('Jira %s created for %s', $result['key'], $node['displayTitle']);
        }
        if ($result['key'] !== '' && empty($node['ticket'])) {
            $updates[$path] = ($updates[$path] ?? []) + ['ticket' => $result['key']];
        }

        return $result['key'];
    }

    /**
     * @param array{files: array<string, string>, attachments: list<array{filename: string, bytes: string}>, warnings: list<string>} $files
     * @param list<string>                                                                                                             $messages
     * @param array<string, array<string, mixed>>                                                                                     $updates
     *
     * @return string the page id
     */
    private function publishPage(ConfluencePagePublisher $pages, string $space, string $parentId, string $title, ?string $knownId, string $body, array $files, array &$messages, array &$updates, string $path): string
    {
        foreach ($files['warnings'] as $warning) {
            $messages[] = 'warning: '.$warning;
        }
        $result = $pages->upsert(new PageSpec($space, $parentId, $title, $body, $knownId, $files['attachments']));
        $messages[] = sprintf('%s: page %s', $title, $result->status);
        if ($result->url !== '') {
            $updates[$path] = ($updates[$path] ?? []) + ['pageLink' => $result->url];
        }

        return $result->id;
    }

    /**
     * Diagrams are attached to the page, and also shown in the body: AGIR's reference pages have no slot for them.
     *
     * @param list<array{type: string, content: string}> $diagrams
     */
    private function diagramSection(array $diagrams, string $prefix): string
    {
        if ($diagrams === []) {
            return '';
        }
        $out = '<h2>Diagrams</h2>';
        foreach ($diagrams as $i => $diagram) {
            $out .= sprintf('<p><ac:image ac:align="center"><ri:attachment ri:filename="%s-%d.png" /></ac:image></p>', $this->e($prefix), $i + 1);
        }

        return $out;
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
            $lines[] = sprintf('%s: %s, page %s', $epic['displayTitle'], $epic['ticket'] ? 'Jira known' : 'would create Jira epic', $epic['pageLink'] ? 'would update' : 'would create');
            foreach ($epic['technicalStories'] as $ts) {
                $lines[] = sprintf('  %s: %s, page %s', $ts['displayTitle'], $ts['ticket'] ? 'Jira known' : 'would create Jira story', $ts['pageLink'] ? 'would update' : 'would create');
                foreach ($ts['subtasks'] as $sub) {
                    $lines[] = sprintf('    %s: %s, page %s', $sub['displayTitle'], $sub['ticket'] ? 'Jira known' : 'would create sub-task', $sub['pageLink'] ? 'would update' : 'would create');
                }
            }
        }
        $lines[] = sprintf('Blocks links: %d dependency(ies) to check; the dependencies page would be written', count($model['dependencies']));
        $lines[] = sprintf('Jira projects %s / %s, fix version %s', $options->epicProject, $options->storyProject, $options->fixVersion ?? '-');

        return $lines;
    }

    /**
     * @param array<string, array<string, mixed>> $updates
     */
    private function save(PublishRequest $request, StoredDocument $stored, array $updates): void
    {
        if ($request->dryRun || $updates === []) {
            return;
        }
        // one version for the whole run, even when it stopped halfway: what was written keeps its link
        $this->repository->save($request->documentId, $this->writeBack->apply($stored->data, $updates), 'agir', $stored->version);
    }

    private function jiraMacro(?string $key): string
    {
        if ($key === null || $key === '') {
            return '-';
        }

        return sprintf('<ac:structured-macro ac:name="jira" ac:schema-version="1"><ac:parameter ac:name="key">%s</ac:parameter></ac:structured-macro>', $this->e($key));
    }

    private function firstSentence(string $text): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', $text));

        return preg_match('/^(.+?[.!?])(\s|$)/u', $flat, $m) ? $m[1] : $flat;
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function pageId(string $value): ?string
    {
        if (preg_match('/pageId=(\d+)|\/pages\/(\d+)/', $value, $m)) {
            return $m[1] !== '' ? $m[1] : $m[2];
        }

        return preg_match('/^\s*(\d+)\s*$/', $value, $m) ? $m[1] : null;
    }
}
