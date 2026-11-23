<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Publication\PublicationService;
use App\Storage\JsonFileTaRepository;
use PHPUnit\Framework\TestCase;

/**
 * Publications through the real container: the same service the command and the UI use.
 */
final class PublicationKernelTest extends TestCase
{
    private Kernel $kernel;
    private string $documentId;

    protected function setUp(): void
    {
        $this->kernel = new Kernel('test', false);
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        $document = json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->documentId = $container->get(JsonFileTaRepository::class)->save(null, $document, 'api')->id;
    }

    protected function tearDown(): void
    {
        $this->kernel->getContainer()->get(JsonFileTaRepository::class)->delete($this->documentId);
        $this->kernel->shutdown();
    }

    private function service(): PublicationService
    {
        return $this->kernel->getContainer()->get(PublicationService::class);
    }

    public function testMarkdownAndJsonAreWrittenToTheExportDirectory(): void
    {
        $markdown = $this->service()->publish($this->documentId, 'markdown', [], false);
        $json = $this->service()->publish($this->documentId, 'json', ['withSchema' => 'yes'], false);

        self::assertSame('done', $markdown->status);
        self::assertFileExists($markdown->artifacts[0]);
        self::assertStringContainsString('DEMO1 E1 TS1 Subtask#1', (string) file_get_contents($markdown->artifacts[0]));
        self::assertFileExists($json->artifacts[1]);
    }

    public function testPdfIsAPdfFile(): void
    {
        $result = $this->service()->publish($this->documentId, 'pdf', [], false);

        self::assertSame('done', $result->status);
        self::assertStringStartsWith('%PDF', (string) file_get_contents($result->artifacts[0]));
    }

    public function testDryRunWritesNothing(): void
    {
        $target = sys_get_temp_dir().'/ta-writer-dryrun-'.bin2hex(random_bytes(4)).'.pdf';
        $result = $this->service()->publish($this->documentId, 'pdf', ['output' => $target], true);

        self::assertSame('dry-run', $result->status);
        self::assertFileDoesNotExist($target);
    }

    public function testRemoteFormatWithoutItsOptionIsRefused(): void
    {
        $result = $this->service()->publish($this->documentId, 'confluence', [], true);

        self::assertSame('failed', $result->status);
    }
}
