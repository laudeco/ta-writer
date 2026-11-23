<?php

declare(strict_types=1);

namespace App\Ui\Preview;

/**
 * The part of a submitted (not yet validated) document that a preview shows: the whole document, its header only, or
 * one section with the chain of items leading to it. Positions are the order of the submitted form.
 */
final class SectionScope
{
    /**
     * @param array<array-key, mixed> $raw the `data` of the form
     *
     * @return array<array-key, mixed>|null null when the section is not in the document any more
     */
    public static function for(array $raw, string $path): ?array
    {
        return match (true) {
            $path === 'document' => $raw,
            $path === 'header' => self::header($raw),
            default => self::section($raw, $path),
        };
    }

    /**
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function header(array $raw): array
    {
        return [
            'format' => $raw['format'] ?? 'markdown',
            'project' => $raw['project'] ?? [],
            'author' => $raw['author'] ?? [],
            'dependencies' => [],
            'epics' => [],
        ];
    }

    /**
     * The document shell with only the chain of items leading to the section (its epic, its technical story, ...), the
     * other children of those items being left out. Positions are the order of the submitted form.
     *
     * @param array<array-key, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private static function section(array $raw, string $path): ?array
    {
        $segments = explode('.', $path);
        if (count($segments) < 2 || count($segments) % 2 !== 0) {
            return null;
        }

        // the chain: [kind, submitted item] from the top (an epic) to the section
        $chain = [];
        $node = $raw;
        for ($i = 0; $i < count($segments); $i += 2) {
            $kind = $segments[$i];
            $position = (int) $segments[$i + 1];
            $items = array_values(is_array($node[$kind] ?? null) ? $node[$kind] : []);
            if (!isset($items[$position]) || !is_array($items[$position])) {
                return null;
            }
            $chain[] = [$kind, $items[$position]];
            $node = $items[$position];
        }

        // rebuilt from the bottom: each item keeps only the child on the chain
        $current = null;
        for ($k = count($chain) - 1; $k >= 0; --$k) {
            [, $item] = $chain[$k];
            foreach (['epics', 'technicalStories', 'userStories', 'subtasks'] as $childKind) {
                if (array_key_exists($childKind, $item)) {
                    $item[$childKind] = [];
                }
            }
            if ($current !== null) {
                $item[$chain[$k + 1][0]] = [$current];
            }
            $current = $item;
        }

        return [
            'format' => $raw['format'] ?? 'markdown',
            'project' => $raw['project'] ?? [],
            'author' => $raw['author'] ?? [],
            'dependencies' => [],
            'epics' => [$current],
        ];
    }
}
