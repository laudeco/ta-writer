<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Publication\Confluence\ConfluencePagePublisher;
use App\Publication\Confluence\DiagramAttachments;
use App\Publication\Confluence\HttpConfluenceClient;
use App\Publication\Confluence\PageBodyBuilder;
use App\Publication\Confluence\PageSpec;
use App\Publication\DocumentWriteBack;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Render\DocumentModelBuilder;
use App\Storage\TaRepositoryInterface;

/**
 * Publishes the analysis as a Confluence page tree: epic > technical story > subtask, then a dependencies page.
 * Existing pages are rewritten. Every created link is written back into the JSON in one new version, at the end.
 */
final class ConfluenceApiPublisher implements PublisherInterface
{
    public function __construct(
        private readonly DocumentModelBuilder $builder,
        private readonly PageBodyBuilder $bodies,
        private readonly DiagramAttachments $diagrams,
        private readonly DocumentWriteBack $writeBack,
        private readonly TaRepositoryInterface $repository,
        private readonly string $confluenceBaseUrl,
        private readonly ?string $confluenceToken,
    ) {
    }

    public function getName(): string
    {
        return 'confluence';
    }

    public function getLabel(): string
    {
        return 'Confluence API (page tree, Personal Access Token)';
    }

    public function getOptionDefinitions(): array
    {
        return [
            new OptionDefinition('space', 'Confluence space key (e.g. CFDITS)', true),
            new OptionDefinition('rootPage', 'Root page: URL or page id under which the tree is written', true),
            new OptionDefinition('baseUrl', 'Confluence base URL', false, $this->confluenceBaseUrl),
        ];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $space = $request->options['space'] ?? '';
        $rootId = $this->pageId($request->options['rootPage'] ?? '');
        $baseUrl = $request->options['baseUrl'] ?? $this->confluenceBaseUrl;
        if ($space === '' || $rootId === null) {
            return new PublishResult('failed', ['Missing option: space and a root page (URL or id) are required.']);
        }
        // a dry run reads the pages too, so it needs the token as well
        if ((string) $this->confluenceToken === '') {
            return new PublishResult('failed', ['CONFLUENCE_PAT is not set. Add it to .env.local (never commit it).']);
        }

        $client = new HttpConfluenceClient($baseUrl, (string) $this->confluenceToken);
        $pages = new ConfluencePagePublisher($client, $baseUrl);
        $format = (string) ($request->document['format'] ?? 'markdown');
        $stored = $this->repository->get($request->documentId);
        $model = $this->builder->build($request->document);
        $messages = [];
        $updates = [];

        try {
            foreach ($model['epics'] as $e => $epic) {
                $epicPage = $this->publishNode($pages, $request->dryRun, $space, $rootId, $epic, $this->diagrams->collect($epic['diagrams'], "epic".($e + 1)), $format, ['epicTicket' => $epic['ticket']], $messages);
                $updates["/epics/$e"] = ['pageLink' => $epicPage['url']];
                foreach ($epic['technicalStories'] as $t => $ts) {
                    $prefix = sprintf('epic%d-ts%d', $e + 1, $t + 1);
                    $tsPage = $this->publishNode($pages, $request->dryRun, $space, $epicPage['id'] ?? '', $ts, $this->diagrams->collect($ts['diagrams'], $prefix), $format, ['epicTicket' => $epic['ticket']], $messages);
                    $updates["/epics/$e/technicalStories/$t"] = ['pageLink' => $tsPage['url']];
                    foreach ($ts['subtasks'] as $s => $sub) {
                        $subPrefix = sprintf('%s-st%d', $prefix, $s + 1);
                        $subPage = $this->publishNode($pages, $request->dryRun, $space, $tsPage['id'] ?? '', $sub, $this->diagrams->collect($sub['diagrams'], $subPrefix), $format, ['tsTitle' => $ts['displayTitle'], 'tsTicket' => $ts['ticket']], $messages);
                        $updates["/epics/$e/technicalStories/$t/subtasks/$s"] = ['pageLink' => $subPage['url']];
                    }
                }
            }

            $linkedModel = $this->builder->build($this->writeBack->apply($request->document, $updates));
            $depsTitle = sprintf('%s - Dependencies', (string) ($model['project']['reference'] ?? 'Analysis'));
            $depsSpec = new PageSpec($space, $rootId, $depsTitle, $this->bodies->dependencies($linkedModel['dependencies']), $this->pageId((string) ($request->document['dependenciesPageLink'] ?? '')));
            $deps = $request->dryRun ? $pages->plan($depsSpec) : $pages->upsert($depsSpec);
            if (!$request->dryRun) {
                $updates[''] = ['dependenciesPageLink' => $deps->url];
            }
            $messages[] = sprintf('%s: %s', $depsTitle, $deps->status);
        } finally {
            if (!$request->dryRun && $updates !== []) {
                // one version for the whole run, even when it stopped halfway: the pages already written keep their links
                $this->repository->save($request->documentId, $this->writeBack->apply($stored->data, $updates), 'confluence', $stored->version);
            }
        }

        return new PublishResult($request->dryRun ? 'dry-run' : 'done', $messages);
    }

    /**
     * @param array<string, mixed>                                                                    $node    model node
     * @param array{files: array<string, string>, attachments: list<array{filename: string, bytes: string}>, warnings: list<string>} $att
     * @param array<string, mixed>                                                                    $context parent information for the body
     * @param list<string>                                                                            $messages
     *
     * @return array{id: ?string, url: ?string}
     */
    private function publishNode(ConfluencePagePublisher $pages, bool $dryRun, string $space, string $parentId, array $node, array $att, string $format, array $context, array &$messages): array
    {
        $body = $this->body($node, $att['files'], $format, $context);
        $spec = new PageSpec($space, $parentId, (string) $node['displayTitle'], $body, $this->pageId((string) ($node['pageLink'] ?? '')), $att['attachments']);
        foreach ($att['warnings'] as $warning) {
            $messages[] = 'warning: '.$warning;
        }
        $result = $dryRun ? $pages->plan($spec) : $pages->upsert($spec);
        $messages[] = sprintf('%s: %s', $node['displayTitle'], $result->status);

        return ['id' => $result->id !== '' ? $result->id : null, 'url' => $result->url !== '' ? $result->url : null];
    }

    /**
     * @param array<string, mixed>  $node
     * @param array<string, string> $files
     * @param array<string, mixed>  $context
     */
    private function body(array $node, array $files, string $format, array $context): string
    {
        if (isset($node['userStories'])) {
            return $this->bodies->epic($node, $format, $files);
        }
        if (isset($node['subtasks'])) {
            return $this->bodies->technicalStory($node, $format, $files, $context['epicTicket'] ?? null);
        }

        return $this->bodies->subtask($node, $format, $files, (string) ($context['tsTitle'] ?? ''), $context['tsTicket'] ?? null);
    }

    private function pageId(string $value): ?string
    {
        if (preg_match('/pageId=(\d+)|\/pages\/(\d+)/', $value, $m)) {
            return $m[1] !== '' ? $m[1] : $m[2];
        }

        return preg_match('/^\s*(\d+)\s*$/', $value, $m) ? $m[1] : null;
    }
}
