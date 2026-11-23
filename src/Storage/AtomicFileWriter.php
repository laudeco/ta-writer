<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * Writes a file through a temporary sibling and a rename, so a reader never sees a half-written JSON.
 */
final class AtomicFileWriter
{
    public function write(string $path, string $contents): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create directory %s', $dir));
        }

        $tmp = sprintf('%s.tmp.%d', $path, getmypid());
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Cannot write %s', $tmp));
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Cannot replace %s', $path));
        }
    }
}
