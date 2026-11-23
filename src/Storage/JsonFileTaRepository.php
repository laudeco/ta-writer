<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\Exception\DocumentNotFoundException;
use App\Storage\Exception\StaleVersionException;
use App\Storage\History\VersionMeta;
use App\Storage\History\VersionStore;

/**
 * Stores each analysis as `<storage>/<YYYYMMDD>/<slug>.json`, with its history in `<slug>.versions/`.
 *
 * Document id: `<YYYYMMDD>_<slug>`. It is validated before any path is built.
 */
final class JsonFileTaRepository implements TaRepositoryInterface
{
    private const ID_PATTERN = '/^(\d{8})_([a-z0-9][a-z0-9-]*)$/';

    public function __construct(
        private readonly string $storageDir,
        private readonly AtomicFileWriter $writer,
        private readonly SlugGenerator $slugger,
        private readonly VersionStore $versions,
    ) {
    }

    public function list(): array
    {
        $rows = [];
        foreach (glob($this->storageDir.'/*/*.json') ?: [] as $file) {
            $id = $this->idFromFile($file);
            if ($id === null) {
                continue;
            }
            $data = $this->decode($file);
            $rows[] = [
                'id' => $id,
                'title' => (string) ($data['project']['name'] ?? ''),
                'reference' => (string) ($data['project']['reference'] ?? ''),
                'version' => $this->versions->currentNumber($this->versionsDir($id)),
                'updatedAt' => (new \DateTimeImmutable('@'.(int) filemtime($file)))->format(\DateTimeInterface::ATOM),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['updatedAt'], $a['updatedAt']));

        return $rows;
    }

    public function get(string $id): StoredDocument
    {
        $file = $this->fileOf($id);
        if (!is_file($file)) {
            throw DocumentNotFoundException::forId($id);
        }

        return new StoredDocument(
            $id,
            $this->decode($file),
            $this->versions->currentNumber($this->versionsDir($id)),
            (new \DateTimeImmutable('@'.(int) filemtime($file))),
        );
    }

    public function save(?string $id, array $data, string $origin, ?int $expectedVersion = null, ?string $author = null): StoredDocument
    {
        $id ??= $this->newId($data);
        $file = $this->fileOf($id);
        $versionsDir = $this->versionsDir($id);
        $lock = $this->lock($file);

        try {
            $current = $this->versions->currentNumber($versionsDir);
            if ($expectedVersion !== null && $expectedVersion !== $current) {
                throw new StaleVersionException($expectedVersion, $current);
            }

            // history first: the file never holds a version the history does not know
            $meta = $this->versions->snapshot($versionsDir, $data, $origin, $author);
            $this->writer->write($file, $this->encode($data));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return new StoredDocument(
            $id,
            $data,
            $meta?->number ?? $current,
            new \DateTimeImmutable('now'),
        );
    }

    public function delete(string $id): void
    {
        $file = $this->fileOf($id);
        if (!is_file($file)) {
            throw DocumentNotFoundException::forId($id);
        }
        // the history folder is kept on purpose: a deleted analysis stays recoverable by hand
        unlink($file);
    }

    public function restore(string $id, int $version, string $origin = 'restore'): StoredDocument
    {
        $data = $this->getVersionData($id, $version);
        $current = $this->versions->currentNumber($this->versionsDir($id));

        return $this->save($id, $data, $origin, $current);
    }

    public function versions(string $id): array
    {
        $this->get($id);

        return $this->versions->list($this->versionsDir($id));
    }

    public function getVersionData(string $id, int $number): array
    {
        $this->get($id);

        return $this->versions->read($this->versionsDir($id), $number);
    }

    private function fileOf(string $id): string
    {
        [$day, $slug] = $this->split($id);

        return sprintf('%s/%s/%s.json', $this->storageDir, $day, $slug);
    }

    private function versionsDir(string $id): string
    {
        return substr($this->fileOf($id), 0, -5).'.versions';
    }

    /**
     * @return array{string, string}
     */
    private function split(string $id): array
    {
        if (!preg_match(self::ID_PATTERN, $id, $m)) {
            throw DocumentNotFoundException::forId($id);
        }

        return [$m[1], $m[2]];
    }

    private function idFromFile(string $file): ?string
    {
        $day = basename(\dirname($file));
        $slug = basename($file, '.json');
        $id = $day.'_'.$slug;

        return preg_match(self::ID_PATTERN, $id) ? $id : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function newId(array $data): string
    {
        // the project reference alone names the file (the name would repeat it); the name is the fallback
        $base = $this->slugger->generate((string) ($data['project']['reference'] ?? '') ?: (string) ($data['project']['name'] ?? ''), '');
        $day = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd');

        $slug = $base;
        for ($n = 2; is_file(sprintf('%s/%s/%s.json', $this->storageDir, $day, $slug)); ++$n) {
            $slug = sprintf('%s-%d', $base, $n);
        }

        return $day.'_'.$slug;
    }

    /**
     * Exclusive lock per document, held for the whole read-check-write of a save.
     *
     * @return resource
     */
    private function lock(string $file)
    {
        $dir = \dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $dir));
        }
        $handle = fopen($file.'.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Cannot lock %s', $file));
        }

        return $handle;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $file): array
    {
        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function encode(array $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }
}
