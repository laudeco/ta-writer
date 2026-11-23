<?php

declare(strict_types=1);

namespace App\Storage\History;

use App\Storage\AtomicFileWriter;
use App\Storage\Exception\DocumentNotFoundException;

/**
 * Full snapshot of every version of a document, in `<slug>.versions/`:
 * one immutable `NNNN-<UTC timestamp>.json` per version and an `index.json`.
 *
 * Versions are never rewritten. A restore is a new version (origin `restore`).
 */
final class VersionStore
{
    private const INDEX = 'index.json';

    public function __construct(
        private readonly AtomicFileWriter $writer,
        private readonly DocumentDiffer $differ,
        private readonly int $historyMax = 0,
    ) {
    }

    /**
     * @return list<VersionMeta> oldest first
     */
    public function list(string $versionsDir): array
    {
        return array_map(
            static fn (array $row): VersionMeta => VersionMeta::fromArray($row),
            $this->readIndex($versionsDir),
        );
    }

    public function currentNumber(string $versionsDir): int
    {
        $index = $this->readIndex($versionsDir);

        return $index === [] ? 0 : (int) end($index)['number'];
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $versionsDir, int $number): array
    {
        foreach ($this->readIndex($versionsDir) as $row) {
            if ((int) $row['number'] === $number) {
                $file = $this->fileName($versionsDir, $row);
                $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

                return $data;
            }
        }

        throw DocumentNotFoundException::forVersion(basename(\dirname($versionsDir)), $number);
    }

    /**
     * Stores $data as the next version, or returns null when it equals the latest version.
     *
     * @param array<string, mixed> $data
     */
    public function snapshot(string $versionsDir, array $data, string $origin, ?string $author): ?VersionMeta
    {
        $index = $this->readIndex($versionsDir);
        $previous = $index === [] ? [] : $this->read($versionsDir, (int) end($index)['number']);
        if ($index !== [] && $previous === $data) {
            return null;
        }

        $number = $index === [] ? 1 : (int) end($index)['number'] + 1;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $meta = new VersionMeta(
            $number,
            $now,
            $origin,
            $author,
            $this->differ->changedPaths($previous, $data),
        );

        $name = sprintf('%04d-%s.json', $number, $now->format('Ymd\THis\Z'));
        $this->writer->write(
            $versionsDir.'/'.$name,
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );

        $index[] = [...$meta->toArray(), 'file' => $name];
        $index = $this->purge($versionsDir, $index);
        $this->writeIndex($versionsDir, $index);

        return $meta;
    }

    /**
     * Drops the oldest snapshots beyond the configured limit (0 keeps everything).
     *
     * @param list<array<string, mixed>> $index
     *
     * @return list<array<string, mixed>>
     */
    private function purge(string $versionsDir, array $index): array
    {
        if ($this->historyMax <= 0 || \count($index) <= $this->historyMax) {
            return $index;
        }
        $drop = \array_slice($index, 0, \count($index) - $this->historyMax);
        foreach ($drop as $row) {
            @unlink($this->fileName($versionsDir, $row));
        }

        return \array_slice($index, \count($drop));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readIndex(string $versionsDir): array
    {
        $file = $versionsDir.'/'.self::INDEX;
        if (!is_file($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<array<string, mixed>> $index
     */
    private function writeIndex(string $versionsDir, array $index): void
    {
        $this->writer->write(
            $versionsDir.'/'.self::INDEX,
            (string) json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n",
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fileName(string $versionsDir, array $row): string
    {
        return $versionsDir.'/'.$row['file'];
    }
}
