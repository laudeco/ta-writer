<?php

declare(strict_types=1);

namespace App\Storage\History;

/**
 * Structural diff between two JSON documents, reported with JSON Pointers.
 */
final class DocumentDiffer
{
    public const MAX_CHANGED_PATHS = 20;

    /**
     * @return list<array{path: string, type: 'added'|'removed'|'changed', before: mixed, after: mixed}>
     */
    public function diff(mixed $before, mixed $after, string $path = ''): array
    {
        if ($before === $after) {
            return [];
        }
        if (\is_array($before) && \is_array($after)) {
            $entries = [];
            foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
                $child = $path.'/'.self::escape((string) $key);
                if (!\array_key_exists($key, $before)) {
                    $entries[] = ['path' => $child, 'type' => 'added', 'before' => null, 'after' => $after[$key]];
                } elseif (!\array_key_exists($key, $after)) {
                    $entries[] = ['path' => $child, 'type' => 'removed', 'before' => $before[$key], 'after' => null];
                } else {
                    array_push($entries, ...$this->diff($before[$key], $after[$key], $child));
                }
            }

            return $entries;
        }

        return [['path' => $path === '' ? '/' : $path, 'type' => 'changed', 'before' => $before, 'after' => $after]];
    }

    /**
     * @return list<string>
     */
    public function changedPaths(mixed $before, mixed $after): array
    {
        $paths = array_values(array_unique(array_column($this->diff($before, $after), 'path')));

        if (\count($paths) > self::MAX_CHANGED_PATHS) {
            $paths = [...\array_slice($paths, 0, self::MAX_CHANGED_PATHS), '…'];
        }

        return $paths;
    }

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
