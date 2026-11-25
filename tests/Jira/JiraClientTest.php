<?php

declare(strict_types=1);

namespace App\Tests\Jira;

use App\Jira\JiraClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class JiraClientTest extends TestCase
{
    public function testUpdateReplacesTheFieldsOfAnIssue(): void
    {
        $sent = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = ['method' => $method, 'url' => $url, 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse('', ['http_code' => 204]);
        });
        (new JiraClient('https://jira.example', 'token', $http))->updateIssue('CFPOBT-7', ['summary' => 'New', 'description' => 'Body']);

        self::assertSame('PUT', $sent['method']);
        self::assertSame('https://jira.example/rest/api/2/issue/CFPOBT-7', $sent['url']);
        self::assertSame(['summary' => 'New', 'description' => 'Body'], $sent['body']['fields']);
    }

    public function testAttachmentsAreListedAndDeleted(): void
    {
        $calls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$calls): MockResponse {
            $calls[] = $method.' '.$url;
            if ($method === 'GET') {
                return new MockResponse('{"fields":{"attachment":[{"id":"10001","filename":"epic1-1.png"}]}}');
            }

            return new MockResponse('', ['http_code' => 204]);
        });
        $jira = new JiraClient('https://jira.example', 'token', $http);

        self::assertSame([['id' => '10001', 'filename' => 'epic1-1.png']], $jira->attachments('CFPOPR-1'));
        $jira->deleteAttachment('10001');

        self::assertSame(['GET https://jira.example/rest/api/2/issue/CFPOPR-1?fields=attachment', 'DELETE https://jira.example/rest/api/2/attachment/10001'], $calls);
    }

    public function testAnAttachmentIsUploadedWithTheXsrfBypassHeader(): void
    {
        $headers = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$headers): MockResponse {
            $headers = $options['headers'] ?? [];

            return new MockResponse('[]', ['http_code' => 200]);
        });
        (new JiraClient('https://jira.example', 'secret-token', $http))->addAttachment('CFPOBT-7', 'epic1-1.png', 'PNGBYTES');

        $flat = implode("\n", array_map(static fn ($h): string => is_array($h) ? implode("\n", $h) : (string) $h, $headers));
        self::assertStringContainsString('X-Atlassian-Token: no-check', $flat);
        self::assertStringContainsString('multipart/form-data', $flat);
    }

    public function testAFailureNeverShowsTheToken(): void
    {
        $http = new MockHttpClient(fn (): MockResponse => new MockResponse('{"errorMessages":["Issue does not exist"]}', ['http_code' => 404]));

        try {
            (new JiraClient('https://jira.example', 'secret-token', $http))->updateIssue('CFPOBT-404', ['summary' => 'x']);
            self::fail('a refused update must raise');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Issue does not exist', $e->getMessage());
            self::assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }
}
