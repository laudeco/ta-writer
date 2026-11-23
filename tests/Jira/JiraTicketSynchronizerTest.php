<?php

declare(strict_types=1);

namespace App\Tests\Jira;

use App\Jira\JiraClient;
use App\Jira\JiraSyncOptions;
use App\Jira\JiraTicketSynchronizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JiraTicketSynchronizerTest extends TestCase
{
    /**
     * @param list<MockResponse> $responses
     */
    private function jira(array $responses): JiraClient
    {
        return new JiraClient('https://jira.example', 'token', new MockHttpClient($responses));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    public function testAnExistingEpicWithTheSameSummaryIsAdoptedNotDuplicated(): void
    {
        $jira = $this->jira([$this->json(['issues' => [['key' => 'CFPOPR-9', 'fields' => ['summary' => 'DEMO1 E1 - Epic one']]]])]);
        $result = (new JiraTicketSynchronizer($jira))->epic(['displayTitle' => 'DEMO1 E1 - Epic one', 'ticket' => null], new JiraSyncOptions('CFPOPR', 'CFPOBT'), false);

        self::assertSame(['key' => 'CFPOPR-9', 'created' => false], $result);
    }

    public function testAMissingStoryIsCreatedWithItsEpicLink(): void
    {
        $posted = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$posted): MockResponse {
            if ($method === 'GET') {
                return new MockResponse('{"issues":[]}');
            }
            $posted = json_decode((string) $options['body'], true);

            return new MockResponse('{"key":"CFPOBT-11"}', ['http_code' => 201]);
        });
        $sync = new JiraTicketSynchronizer(new JiraClient('https://jira.example', 'token', $http));
        $result = $sync->technicalStory(['displayTitle' => 'DEMO1 E1 TS1 - Storage', 'ticket' => null], 'CFPOPR-9', new JiraSyncOptions('CFPOPR', 'CFPOBT', '2026-R12'), false);

        self::assertSame(['key' => 'CFPOBT-11', 'created' => true], $result);
        self::assertSame('Technical Story', $posted['fields']['issuetype']['name']);
        self::assertSame('CFPOPR-9', $posted['fields']['customfield_10000']);
        self::assertSame([['name' => '2026-R12']], $posted['fields']['fixVersions']);
    }

    public function testDryRunCreatesNothing(): void
    {
        $sync = new JiraTicketSynchronizer($this->jira([$this->json(['issues' => []])]));
        $result = $sync->subtask(['displayTitle' => 'S', 'ticket' => null], 'CFPOBT-1', new JiraSyncOptions('CFPOPR', 'CFPOBT'), true);

        self::assertSame(['key' => '', 'created' => true], $result);
    }

    public function testAKnownTicketIsKept(): void
    {
        $sync = new JiraTicketSynchronizer($this->jira([]));
        $result = $sync->epic(['displayTitle' => 'E', 'ticket' => 'CFPOPR-3'], new JiraSyncOptions('CFPOPR', 'CFPOBT'), false);

        self::assertSame(['key' => 'CFPOPR-3', 'created' => false], $result);
    }

    public function testAFailedCreationStopsWithoutReplay(): void
    {
        $posts = 0;
        $http = new MockHttpClient(function (string $method) use (&$posts): MockResponse {
            if ($method === 'POST') {
                ++$posts;

                return new MockResponse('{"errorMessages":["boom"]}', ['http_code' => 400]);
            }

            return new MockResponse('{"issues":[]}');
        });
        $sync = new JiraTicketSynchronizer(new JiraClient('https://jira.example', 'token', $http));

        try {
            $sync->epic(['displayTitle' => 'E', 'ticket' => null], new JiraSyncOptions('CFPOPR', 'CFPOBT'), false);
            self::fail('a refused creation must raise');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('refused', $e->getMessage());
        }
        self::assertSame(1, $posts, 'a POST is never replayed');
    }
}
