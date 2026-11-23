<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Default location of an export: `<exportDir>/<YYYYMMDD>/<slug>.<extension>`, from the document id `<YYYYMMDD>_<slug>`.
 */
final class ExportPath
{
    public static function for(string $exportDir, string $documentId, string $extension, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            return $override;
        }
        [$day, $slug] = explode('_', $documentId, 2) + [1 => $documentId];

        return sprintf('%s/%s/%s.%s', rtrim($exportDir, '/'), $day, $slug, $extension);
    }
}
