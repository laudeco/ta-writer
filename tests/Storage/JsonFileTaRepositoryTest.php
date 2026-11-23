<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\AtomicFileWriter;
use App\Storage\Exception\DocumentNotFoundException;
use App\Storage\Exception\StaleVersionException;
use App\Storage\History\DocumentDiffer;
use App\Storage\History\VersionStore;
use App\Storage\JsonFileTaRepository;
use App\Storage\SlugGenerator;
use PHPUnit\Framework\TestCase;

final class JsonFileTaRepositoryTest extends TestCase
{
    private string $dir;
    private JsonFileTaRepository $repository;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ta-writer-test-'.bin2hex(random_bytes(4));
        $writer = new AtomicFileWriter();
        $this->repository = new JsonFileTaRepository($this->dir, $writer, new SlugGenerator(), new VersionStore($writer, new DocumentDiffer()));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            $this->removeTree($this->dir);
        }
    }

    /** @return array<string, mixed> */
    private function doc(string $what = 'first'): array
    {
        return ['format' => 'markdown', 'project' => ['name' => 'Demo', 'reference' => 'DEMO1'], 'epics' => [['what' => $what]]];
    }

    public function testCreateStoresFileUnderDateAndSlug(): void
    {
        $stored = $this->repository->save(null, $this->doc(), 'api');

        self::assertMatchesRegularExpression('/^\d{8}_demo1$/', $stored->id);
        self::assertFileExists($this->dir.'/'.substr($stored->id, 0, 8).'/demo1.json');
        self::assertSame(1, $stored->version);
    }

    public function testCollidingCreateGetsASuffix(): void
    {
        $first = $this->repository->save(null, $this->doc(), 'api');
        $second = $this->repository->save(null, $this->doc(), 'api');

        self::assertNotSame($first->id, $second->id);
        self::assertStringEndsWith('_demo1-2', $second->id);
    }

    public function testIdenticalSaveAddsNoVersion(): void
    {
        $stored = $this->repository->save(null, $this->doc(), 'api');
        $again = $this->repository->save($stored->id, $this->doc(), 'api', 1);

        self::assertSame(1, $again->version);
        self::assertCount(1, $this->repository->versions($stored->id));
    }

    public function testChangeAddsAVersionAndKeepsTheOldOne(): void
    {
        $stored = $this->repository->save(null, $this->doc('first'), 'api');
        $this->repository->save($stored->id, $this->doc('second'), 'ui', 1);

        $versions = $this->repository->versions($stored->id);
        self::assertSame([1, 2], array_map(static fn ($v) => $v->number, $versions));
        self::assertSame(['ui'], [$versions[1]->origin]);
        self::assertSame('first', $this->repository->getVersionData($stored->id, 1)['epics'][0]['what']);
        self::assertSame(['/epics/0/what'], $versions[1]->changedPaths);
    }

    public function testStaleWriteIsRefusedAndNothingIsOverwritten(): void
    {
        $stored = $this->repository->save(null, $this->doc('first'), 'api');
        $this->repository->save($stored->id, $this->doc('second'), 'api', 1);

        try {
            $this->repository->save($stored->id, $this->doc('third'), 'api', 1);
            self::fail('A stale write must be refused.');
        } catch (StaleVersionException $e) {
            self::assertSame(1, $e->expected);
            self::assertSame(2, $e->current);
        }
        self::assertSame('second', $this->repository->get($stored->id)->data['epics'][0]['what']);
    }

    public function testRestoreCreatesANewVersion(): void
    {
        $stored = $this->repository->save(null, $this->doc('first'), 'api');
        $this->repository->save($stored->id, $this->doc('second'), 'api', 1);

        $restored = $this->repository->restore($stored->id, 1);

        self::assertSame(3, $restored->version);
        self::assertSame('first', $this->repository->get($stored->id)->data['epics'][0]['what']);
        self::assertSame('restore', $this->repository->versions($stored->id)[2]->origin);
    }

    public function testDeleteKeepsTheHistory(): void
    {
        $stored = $this->repository->save(null, $this->doc(), 'api');
        $this->repository->delete($stored->id);

        $this->expectException(DocumentNotFoundException::class);
        try {
            $this->repository->get($stored->id);
        } finally {
            self::assertDirectoryExists($this->dir.'/'.substr($stored->id, 0, 8).'/demo1.versions');
        }
    }

    public function testMalformedIdIsRejectedBeforeAnyPathIsBuilt(): void
    {
        $this->expectException(DocumentNotFoundException::class);
        $this->repository->get('../../etc/passwd');
    }

    public function testListIsNewestFirstAndIgnoresHistoryFiles(): void
    {
        $this->repository->save(null, $this->doc('a'), 'api');
        $this->repository->save(null, ['project' => ['name' => 'Other', 'reference' => 'OTHER']], 'api');
        $rows = $this->repository->list();

        $references = array_column($rows, 'reference');
        sort($references);

        self::assertCount(2, $rows);
        self::assertSame(['DEMO1', 'OTHER'], $references);
        self::assertGreaterThanOrEqual($rows[1]['updatedAt'], $rows[0]['updatedAt']);
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }
}
