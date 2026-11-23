<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Versioning of the document content (the `version` field), applied by the repository on every write.
 *
 * - creation: 1.0.0;
 * - an edit by a person or a script (ui, api, restore) that changes the content: the patch number goes up (1.0.0 -> 1.0.1);
 * - a write that does not change the content keeps the version;
 * - an automatic write-back of the adapters (ticket keys, page links) keeps the version: it is not an edit.
 *
 * `structureVersion` is never taken from a client: it is the version of the OpenAPI file.
 */
final class DocumentVersion
{
    public const INITIAL = '1.0.0';

    /** origins of a write made by a person or a script, as opposed to the adapters' write-back */
    public const EDIT_ORIGINS = ['ui', 'api', 'restore'];

    /**
     * @param array<string, mixed>|null $current the stored document, null at creation
     * @param array<string, mixed>      $data    the document to store
     *
     * @return array<string, mixed>
     */
    public static function prepare(?array $current, array $data, string $origin, string $structureVersion): array
    {
        $data['structureVersion'] = $structureVersion;

        if ($current === null) {
            $data['version'] = self::INITIAL;

            return $data;
        }

        $stored = (string) ($current['version'] ?? self::INITIAL);
        $editing = \in_array($origin, self::EDIT_ORIGINS, true);
        $data['version'] = $editing && !self::sameContent($current, $data) ? self::bumpPatch($stored) : $stored;

        return $data;
    }

    public static function bumpPatch(string $version): string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $version, $m)) {
            return self::INITIAL;
        }

        return sprintf('%d.%d.%d', (int) $m[1], (int) $m[2], (int) $m[3] + 1);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameContent(array $a, array $b): bool
    {
        $ignored = ['version', 'structureVersion'];

        return array_diff_key($a, array_flip($ignored)) == array_diff_key($b, array_flip($ignored));
    }
}
