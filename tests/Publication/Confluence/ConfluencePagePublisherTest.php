<?php

declare(strict_types=1);

namespace App\Tests\Publication\Confluence;

use App\Publication\Confluence\ConfluencePagePublisher;
use App\Publication\Confluence\ConfluenceResponse;
use App\Publication\Confluence\ConfluenceTitleCollisionException;
use App\Publication\Confluence\PageSpec;
use PHPUnit\Framework\TestCase;

final class ConfluencePagePublisherTest extends TestCase
{
    private const BASE = 'https://confluence.example';

    /**
     * @param array<string, mixed>|null $found page returned by the title search, or null
     */
    private function client(?array $found, string $currentBody = '<p>old</p>', int $version = 3): FakeConfluenceClient
    {
        return new FakeConfluenceClient(function (string $method, string $path, ?array $json) use ($found, $currentBody, $version): ConfluenceResponse {
            if ($method === 'GET' && str_contains($path, '/rest/api/content?spaceKey')) {
                return new ConfluenceResponse(200, ['results' => $found === null ? [] : [$found]]);
            }
            if ($method === 'GET' && str_contains($path, 'expand=body.storage,version') || ($method === 'GET' && str_contains($path, 'expand=body.storage') && !str_contains($path, 'ancestors'))) {
                return new ConfluenceResponse(200, ['version' => ['number' => $version], 'body' => ['storage' => ['value' => $currentBody]]]);
            }
            if ($method === 'POST') {
                return new ConfluenceResponse(200, ['id' => '42']);
            }
            if ($method === 'PUT') {
                return new ConfluenceResponse(200, ['id' => '7']);
            }

            return new ConfluenceResponse(404, null);
        });
    }

    public function testMissingPageIsCreatedUnderTheParent(): void
    {
        $client = $this->client(null);
        $result = (new ConfluencePagePublisher($client, self::BASE))->upsert(new PageSpec('CFDITS', '1', 'DEMO1 E1 - Epic', '<p>new</p>', null));

        self::assertSame('created', $result->status);
        self::assertSame('42', $result->id);
        self::assertSame(self::BASE.'/pages/viewpage.action?pageId=42', $result->url);
        $post = array_values(array_filter($client->log, static fn (array $r): bool => $r['method'] === 'POST'))[0];
        self::assertSame('1', $post['json']['ancestors'][0]['id']);
    }

    public function testExistingPageIsRewrittenWithTheNextVersion(): void
    {
        $client = $this->client(['id' => '7', 'title' => 'x', 'ancestors' => [['id' => '1']]], '<p>earlier edit</p>', 3);
        $result = (new ConfluencePagePublisher($client, self::BASE))->upsert(new PageSpec('CFDITS', '1', 'DEMO1 E1 - Epic', '<p>generated</p>', null));

        self::assertSame('updated', $result->status);
        $put = array_values(array_filter($client->log, static fn (array $r): bool => $r['method'] === 'PUT'))[0];
        self::assertSame(4, $put['json']['version']['number']);
        self::assertSame('<p>generated</p>', $put['json']['body']['storage']['value'], 'the whole body is replaced');
    }

    public function testUnchangedBodyIsNotWrittenAgain(): void
    {
        $client = $this->client(['id' => '7', 'title' => 'x', 'ancestors' => [['id' => '1']]], '<p>same</p>');
        $result = (new ConfluencePagePublisher($client, self::BASE))->upsert(new PageSpec('CFDITS', '1', 'T', '<p>same</p>', null));

        self::assertSame('unchanged', $result->status);
        self::assertNotContains('PUT', $client->methods());
    }

    public function testSamePageTitleUnderAnotherParentIsACollision(): void
    {
        $client = $this->client(['id' => '7', 'title' => 'x', 'ancestors' => [['id' => '99']]]);

        $this->expectException(ConfluenceTitleCollisionException::class);
        (new ConfluencePagePublisher($client, self::BASE))->upsert(new PageSpec('CFDITS', '1', 'T', '<p>x</p>', null));
    }

    public function testPlanWritesNothing(): void
    {
        $client = $this->client(null);
        $result = (new ConfluencePagePublisher($client, self::BASE))->plan(new PageSpec('CFDITS', '1', 'T', '<p>x</p>', null));

        self::assertSame('would-create', $result->status);
        self::assertSame(['GET'], array_values(array_unique($client->methods())));
    }
}
