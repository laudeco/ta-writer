<?php

declare(strict_types=1);

namespace App\Storage;

use App\Storage\History\VersionMeta;

/**
 * The only way to write an analysis. Every write is versioned, whatever its origin (ui, api, agir, ...).
 */
interface TaRepositoryInterface
{
    /**
     * @return list<array{id: string, title: string, reference: string, version: int, updatedAt: string}> newest first
     */
    public function list(): array;

    /**
     * @throws Exception\DocumentNotFoundException
     */
    public function get(string $id): StoredDocument;

    /**
     * Creates the document when $id is null, replaces it otherwise.
     *
     * @param array<string, mixed> $data
     *
     * @throws Exception\StaleVersionException when $expectedVersion is not the current version
     */
    public function save(?string $id, array $data, string $origin, ?int $expectedVersion = null, ?string $author = null): StoredDocument;

    public function delete(string $id): void;

    /**
     * Saves a past version as a new current version.
     */
    public function restore(string $id, int $version, string $origin = 'restore'): StoredDocument;

    /**
     * @return list<VersionMeta> oldest first
     */
    public function versions(string $id): array;

    /**
     * @return array<string, mixed>
     */
    public function getVersionData(string $id, int $number): array;
}
