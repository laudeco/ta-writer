<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Default location of an export: `<exportDir>/<YYYYMMDD>/<REFERENCE>_v<version>.<extension>`.
 *
 * The prefix is the project reference as written (its case is kept); characters outside `[A-Za-z0-9._-]` become `-`.
 * Without a reference, the slug of the document id is used. Without a version, the suffix is left out.
 */
final class ExportPath
{
    /**
     * @param array<string, mixed> $document the document the export is made from (its project reference and version)
     */
    public static function for(string $exportDir, string $documentId, array $document, string $extension, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        [$day, $slug] = explode('_', $documentId, 2) + [1 => $documentId];

        $reference = is_array($document['project'] ?? null) ? ($document['project']['reference'] ?? null) : null;
        $prefix = self::clean(is_string($reference) && trim($reference) !== '' ? trim($reference) : $slug);

        $version = is_string($document['version'] ?? null) ? trim($document['version']) : '';
        $name = $version !== '' ? sprintf('%s_v%s', $prefix, self::clean($version)) : $prefix;

        return sprintf('%s/%s/%s.%s', rtrim($exportDir, '/'), $day, $name, $extension);
    }

    private static function clean(string $value): string
    {
        return (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    }
}
