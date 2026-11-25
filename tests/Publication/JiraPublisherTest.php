<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Jira\JiraDescriptionBuilder;
use App\Jira\MarkdownToJiraWiki;
use App\Publication\Adapter\JiraPublisher;
use App\Publication\Confluence\DiagramAttachments;
use App\Publication\DocumentWriteBack;
use App\Publication\PublishRequest;
use App\Reference\ReferenceResolver;
use App\Render\ContentConverter;
use App\Render\DocumentModelBuilder;
use App\Render\Mermaid\MermaidRenderer;
use App\Storage\StoredDocument;
use App\Storage\TaRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JiraPublisherTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'format' => 'markdown',
            'project' => ['name' => 'Demo', 'reference' => 'DEMO'],
            'author' => ['name' => 'A', 'email' => 'a@example.test'],
            'dependencies' => [],
            'epics' => [[
                'title' => 'Epic one', 'reference' => 'E1', 'what' => 'The context.',
                'resources' => [], 'diagrams' => [], 'userStories' => [],
                'technicalStories' => [[
                    'title' => 'Storage', 'reference' => 'TS1', 'what' => 'Store it.', 'why' => 'Because.',
                    'resources' => [], 'diagrams' => [], 'acceptanceCriteriaCovered' => [],
                    'subtasks' => [[
                        'title' => 'Create the class', 'reference' => 'Subtask#1', 'what' => 'Do it.', 'why' => 'Needed.',
                        'where' => 'src/X.php', 'how' => "- [ ] step one\n- [x] step two", 'resources' => [], 'diagrams' => [],
                    ]],
                ]],
            ]],
        ];
    }

    private function publisher(MockHttpClient $http, string $token = 'secret-token'): JiraPublisher
    {
        return new JiraPublisher(
            new DocumentModelBuilder(new ReferenceResolver()),
            new DiagramAttachments(new MermaidRenderer(sys_get_temp_dir().'/ta-writer-test-mermaid')),
            new JiraDescriptionBuilder(new MarkdownToJiraWiki(new ContentConverter())),
            new DocumentWriteBack(),
            $this->repositoryHolding($this->document()),
            'https://jira.example',
            $token,
            $http,
        );
    }

    /**
     * A repository holding the document; the publication reads it back from here.
     *
     * @param array<string, mixed> $document
     */
    private function repositoryHolding(array $document): TaRepositoryInterface
    {
        $repository = $this->createStub(TaRepositoryInterface::class);
        $repository->method('get')->willReturn(new StoredDocument('20261123_demo', $document, 3, new \DateTimeImmutable()));

        return $repository;
    }

    /**
     * Jira answers: the token is accepted, searches find nothing, issues are created in order, updates are accepted.
     *
     * @param list<array{method: string, url: string, body: array<string, mixed>}> $log
     */
    private function jira(array &$log, int $stories = 1): MockHttpClient
    {
        $next = 0;

        return new MockHttpClient(function (string $method, string $url, array $options) use (&$log, &$next): MockResponse {
            $body = isset($options['body']) ? json_decode((string) $options['body'], true) : [];
            $log[] = ['method' => $method, 'url' => $url, 'body' => is_array($body) ? $body : []];
            if (str_contains($url, '/rest/api/2/myself')) {
                return new MockResponse('{"name":"jira-user"}');
            }
            if (str_contains($url, '/rest/api/2/search')) {
                return new MockResponse('{"issues":[]}');
            }
            if ($method === 'POST' && str_contains($url, '/rest/api/2/issue')) {
                $project = (string) ($body['fields']['project']['key'] ?? '');
                $key = $project === 'CFPOPR' ? 'CFPOPR-1' : sprintf('CFPOBT-%d', ++$next + 1);

                return new MockResponse((string) json_encode(['key' => $key]), ['http_code' => 201]);
            }
            if ($method === 'PUT') {
                return new MockResponse('', ['http_code' => 204]);
            }
            if ($method === 'GET' && str_contains($url, '?fields=issuelinks')) {
                return new MockResponse('{"fields":{"issuelinks":[]}}');
            }

            return new MockResponse('{"errorMessages":["unexpected call"]}', ['http_code' => 500]);
        });
    }

    private function request(bool $dryRun, array $document): PublishRequest
    {
        return new PublishRequest('20261123_demo', $document, ['epicProject' => 'CFPOPR', 'storyProject' => 'CFPOBT'], $dryRun);
    }

    public function testADryRunSendsNothing(): void
    {
        $log = [];
        $result = $this->publisher($this->jira($log))->publish($this->request(true, $this->document()));

        self::assertSame('dry-run', $result->status);
        self::assertSame([], $log, 'a dry run makes no request at all');
        self::assertStringContainsString('would create', implode("\n", $result->messages));
    }

    public function testARunCreatesTheTicketsWithTheirContentAndWritesTheKeysBack(): void
    {
        $log = [];
        $saved = null;
        $repository = $this->createMock(TaRepositoryInterface::class);
        $repository->expects(self::any())->method('get')->willReturn(new StoredDocument('20261123_demo', $this->document(), 3, new \DateTimeImmutable()));
        $repository->expects(self::once())->method('save')->willReturnCallback(function (?string $id, array $data, string $origin) use (&$saved): StoredDocument {
            $saved = ['data' => $data, 'origin' => $origin];

            return new StoredDocument('20261123_demo', $data, 4, new \DateTimeImmutable());
        });
        $publisher = new JiraPublisher(
            new DocumentModelBuilder(new ReferenceResolver()),
            new DiagramAttachments(new MermaidRenderer(sys_get_temp_dir().'/ta-writer-test-mermaid')),
            new JiraDescriptionBuilder(new MarkdownToJiraWiki(new ContentConverter())),
            new DocumentWriteBack(),
            $repository,
            'https://jira.example',
            'secret-token',
            $this->jira($log),
        );

        $result = $publisher->publish($this->request(false, $this->document()));

        self::assertSame('done', $result->status, implode("\n", $result->messages));
        self::assertCount(3, array_filter($log, static fn (array $c): bool => $c['method'] === 'POST'), 'one epic, one story, one sub-task created');
        $puts = array_values(array_filter($log, static fn (array $c): bool => $c['method'] === 'PUT'));
        self::assertCount(3, $puts);
        self::assertSame('CFPOPR-1', basename($puts[0]['url']));
        self::assertStringContainsString('h2. Context', (string) $puts[0]['body']['fields']['description']);
        self::assertStringContainsString('h2. How', (string) $puts[2]['body']['fields']['description']);
        self::assertStringContainsString('☐ step one', (string) $puts[2]['body']['fields']['description']);

        self::assertSame('jira', $saved['origin']);
        $epic = $saved['data']['epics'][0];
        self::assertSame('CFPOPR-1', $epic['ticket']);
        self::assertSame('CFPOBT-2', $epic['technicalStories'][0]['ticket']);
        self::assertSame('CFPOBT-3', $epic['technicalStories'][0]['subtasks'][0]['ticket']);
    }

    public function testAgainTheTicketsAreRewrittenNotRecreated(): void
    {
        $document = $this->document();
        $document['epics'][0]['ticket'] = 'CFPOPR-1';
        $document['epics'][0]['technicalStories'][0]['ticket'] = 'CFPOBT-2';
        $document['epics'][0]['technicalStories'][0]['subtasks'][0]['ticket'] = 'CFPOBT-3';

        $log = [];
        $result = $this->publisher($this->jira($log))->publish($this->request(false, $document));

        self::assertSame('done', $result->status, implode("\n", $result->messages));
        self::assertCount(0, array_filter($log, static fn (array $c): bool => $c['method'] === 'POST'), 'known tickets are not created again');
        self::assertCount(3, array_filter($log, static fn (array $c): bool => $c['method'] === 'PUT'), 'every known ticket gets its content rewritten');
    }

    public function testAJiraThatRejectsTheTokenWritesNothing(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse('{"errorMessages":["Unauthorized"]}', ['http_code' => 401]));

        $result = $this->publisher($http)->publish($this->request(false, $this->document()));

        self::assertSame('failed', $result->status);
        self::assertStringContainsString('rejected the token', implode("\n", $result->messages));
        self::assertStringNotContainsString('secret-token', implode("\n", $result->messages));
    }

    public function testAMissingTokenStopsBeforeAnyRequest(): void
    {
        $log = [];
        $result = $this->publisher($this->jira($log), '')->publish($this->request(false, $this->document()));

        self::assertSame('failed', $result->status);
        self::assertSame([], $log);
    }
}
