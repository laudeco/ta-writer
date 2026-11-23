<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * End to end through the kernel: the same calls the UI and external tools make.
 */
final class ApiLifecycleTest extends TestCase
{
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $storage = $this->kernel->getProjectDir().'/var/test-data';
        if (is_dir($storage)) {
            exec('rm -rf '.escapeshellarg($storage));
        }
    }

    protected function tearDown(): void
    {
        $this->kernel->shutdown();
    }

    /**
     * @param array<string, string> $headers
     */
    private function call(string $method, string $uri, ?string $body = null, array $headers = []): Response
    {
        $request = Request::create($uri, $method, [], [], [], [], $body);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $this->kernel->handle($request);
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json');
    }

    public function testCreateReadUpdateRestoreDelete(): void
    {
        $created = $this->call('POST', '/api/documents', $this->fixture(), ['Content-Type' => 'application/json']);
        self::assertSame(201, $created->getStatusCode());
        $location = (string) $created->headers->get('Location');
        self::assertSame('"1"', $created->headers->get('ETag'));

        $shown = $this->call('GET', $location);
        self::assertSame(200, $shown->getStatusCode());

        $missingIfMatch = $this->call('PUT', $location, $this->fixture(), ['Content-Type' => 'application/json']);
        self::assertSame(428, $missingIfMatch->getStatusCode());

        $changed = json_decode($this->fixture(), true);
        $changed['epics'][0]['what'] = 'Changed through the test.';
        $updated = $this->call('PUT', $location, json_encode($changed), ['Content-Type' => 'application/json', 'If-Match' => '"1"']);
        self::assertSame(200, $updated->getStatusCode());
        self::assertSame('"2"', $updated->headers->get('ETag'));

        $stale = $this->call('PUT', $location, json_encode($changed), ['Content-Type' => 'application/json', 'If-Match' => '"1"']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertStringContainsString('"current":2', $stale->getContent() ?: '');

        $patched = $this->call('PATCH', $location, '[{"op":"replace","path":"/project/name","value":"Renamed"}]', ['Content-Type' => 'application/json-patch+json', 'If-Match' => '"2"']);
        self::assertSame('"3"', $patched->headers->get('ETag'));

        $versions = json_decode((string) $this->call('GET', $location.'/versions')->getContent(), true);
        self::assertCount(3, $versions['items']);

        $restored = $this->call('POST', $location.'/versions/1/restore');
        self::assertSame(200, $restored->getStatusCode());
        self::assertSame('"4"', $restored->headers->get('ETag'));

        self::assertSame(204, $this->call('DELETE', $location)->getStatusCode());
        $gone = $this->call('GET', $location);
        self::assertSame(404, $gone->getStatusCode());
        self::assertSame('application/problem+json', $gone->headers->get('Content-Type'));
    }

    public function testInvalidDocumentIsRefusedWithPathsAndNothingIsStored(): void
    {
        $response = $this->call('POST', '/api/documents', '{"format":"pdf","epics":[]}', ['Content-Type' => 'application/json']);

        self::assertSame(422, $response->getStatusCode());
        $problem = json_decode((string) $response->getContent(), true);
        self::assertNotEmpty($problem['errors']);
        self::assertSame(0, count(json_decode((string) $this->call('GET', '/api/documents')->getContent(), true)['items']));
    }

    public function testSchemaIsServedAsJsonAndOpenApiAsYaml(): void
    {
        $schema = json_decode((string) $this->call('GET', '/api/schema')->getContent(), true);
        self::assertArrayHasKey('epics', $schema['properties']);

        $openapi = $this->call('GET', '/api/openapi');
        self::assertSame('application/yaml', $openapi->headers->get('Content-Type'));
        self::assertStringContainsString('openapi: 3.1.0', (string) $openapi->getContent());
    }

    public function testMalformedIdIsNotFound(): void
    {
        self::assertSame(404, $this->call('GET', '/api/documents/..%2Fetc')->getStatusCode());
    }
}
