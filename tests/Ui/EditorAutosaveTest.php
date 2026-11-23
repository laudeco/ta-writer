<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The autosave contract of the editor (JSON answers of the form endpoints), through the kernel.
 */
final class EditorAutosaveTest extends TestCase
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
     * @param array<string, mixed> $form
     */
    private function post(string $uri, array $form): Response
    {
        $request = Request::create($uri, 'POST', $form, [], [], ['HTTP_ACCEPT' => 'application/json']);

        return $this->kernel->handle($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * The stored document, read through the API as any client does.
     *
     * @return array<string, mixed>
     */
    private function stored(string $id): array
    {
        $response = $this->kernel->handle(Request::create('/api/documents/'.$id, 'GET'));

        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheFirstAutosaveCreatesTheDocument(): void
    {
        $response = $this->post('/documents', ['data' => $this->fixture()]);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['ok']);
        self::assertTrue($body['changed']);
        self::assertSame('1.0.0', $body['documentVersion']);
        self::assertNotSame('', $body['id']);
    }

    public function testASaveWithoutChangeCreatesNoVersion(): void
    {
        $created = $this->json($this->post('/documents', ['data' => $this->fixture()]));
        $form = ['data' => $this->fixture(), '_version' => $created['version'], '_format_original' => 'markdown'];

        $body = $this->json($this->post('/documents/'.$created['id'], $form));

        self::assertTrue($body['ok']);
        self::assertFalse($body['changed']);
        self::assertSame('1.0.0', $body['documentVersion']);
    }

    public function testAChangeCreatesThePatchVersion(): void
    {
        $created = $this->json($this->post('/documents', ['data' => $this->fixture()]));
        $changed = $this->fixture();
        $changed['epics'][0]['what'] = 'Changed by the autosave.';

        $body = $this->json($this->post('/documents/'.$created['id'], [
            'data' => $changed,
            '_version' => $created['version'],
            '_format_original' => 'markdown',
        ]));

        self::assertTrue($body['changed']);
        self::assertSame('1.0.1', $body['documentVersion']);
        self::assertSame(2, $body['history']);
    }

    public function testAnInvalidDocumentIsNotSaved(): void
    {
        $created = $this->json($this->post('/documents', ['data' => $this->fixture()]));
        $invalid = $this->fixture();
        $invalid['project']['reference'] = '';

        $response = $this->post('/documents/'.$created['id'], [
            'data' => $invalid,
            '_version' => $created['version'],
            '_format_original' => 'markdown',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['ok']);
        self::assertNotEmpty($body['errors']);
        // nothing was written: the stored document is still the first version
        self::assertSame('1.0.0', $this->stored($created['id'])['version']);
    }

    public function testAStaleVersionIsAConflictAndNothingIsOverwritten(): void
    {
        $created = $this->json($this->post('/documents', ['data' => $this->fixture()]));
        $changed = $this->fixture();
        $changed['epics'][0]['what'] = 'First tab.';
        $this->post('/documents/'.$created['id'], ['data' => $changed, '_version' => $created['version'], '_format_original' => 'markdown']);

        $other = $this->fixture();
        $other['epics'][0]['what'] = 'Second tab, on the old version.';
        $response = $this->post('/documents/'.$created['id'], ['data' => $other, '_version' => $created['version'], '_format_original' => 'markdown']);

        self::assertSame(409, $response->getStatusCode());
        self::assertTrue($this->json($response)['conflict']);
        self::assertSame('First tab.', $this->stored($created['id'])['epics'][0]['what']);
    }
}
