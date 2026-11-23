<?php

declare(strict_types=1);

namespace App\Api;

/**
 * RFC 6902 JSON Patch over the decoded document (associative arrays for objects, lists for arrays).
 * Supports add, remove, replace, move, copy and test. An invalid pointer or a failed test is an InvalidArgumentException.
 */
final class JsonPatchApplier
{
    /**
     * @param array<string, mixed> $document
     * @param list<array<string, mixed>> $operations as received from the client, not yet checked
     *
     * @return array<string, mixed>
     */
    public function apply(array $document, array $operations): array
    {
        foreach ($operations as $i => $op) {
            if (!array_key_exists('op', $op) || !array_key_exists('path', $op)) {
                throw new \InvalidArgumentException(sprintf('Operation %d needs "op" and "path".', $i));
            }
            $document = $this->applyOne($document, $op);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $op
     *
     * @return array<string, mixed>
     */
    private function applyOne(array $document, array $op): array
    {
        $path = $this->segments((string) $op['path']);
        switch ($op['op']) {
            case 'add':
                $this->write($document, $path, $op['value'] ?? null, true);
                break;
            case 'replace':
                $this->read($document, $path); // must exist
                $this->write($document, $path, $op['value'] ?? null, false);
                break;
            case 'remove':
                $this->remove($document, $path);
                break;
            case 'move':
                $from = $this->segments((string) ($op['from'] ?? ''));
                $value = $this->remove($document, $from);
                $this->write($document, $path, $value, true);
                break;
            case 'copy':
                $value = $this->read($document, $this->segments((string) ($op['from'] ?? '')));
                $this->write($document, $path, $value, true);
                break;
            case 'test':
                if ($this->read($document, $path) !== ($op['value'] ?? null)) {
                    throw new \InvalidArgumentException(sprintf('Test failed at %s.', $op['path']));
                }
                break;
            default:
                throw new \InvalidArgumentException(sprintf('Unsupported operation "%s".', $op['op']));
        }

        return $document;
    }

    /**
     * @return list<string>
     */
    private function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }
        if ($pointer[0] !== '/') {
            throw new \InvalidArgumentException(sprintf('Invalid JSON Pointer "%s".', $pointer));
        }

        return array_map(static fn (string $s): string => str_replace(['~1', '~0'], ['/', '~'], $s), explode('/', substr($pointer, 1)));
    }

    /**
     * @param list<string> $path
     */
    private function read(mixed $node, array $path): mixed
    {
        foreach ($path as $seg) {
            if (!\is_array($node) || !$this->exists($node, $seg)) {
                throw new \InvalidArgumentException(sprintf('Path /%s does not exist.', implode('/', $path)));
            }
            $node = $node[$this->key($node, $seg)];
        }

        return $node;
    }

    /**
     * @param list<string> $path
     */
    private function write(mixed &$node, array $path, mixed $value, bool $insert): void
    {
        if ($path === []) {
            $node = $value;

            return;
        }
        if (!\is_array($node)) {
            throw new \InvalidArgumentException(sprintf('Cannot write below a scalar at /%s.', implode('/', $path)));
        }

        $seg = array_shift($path);
        if ($path === []) {
            if (array_is_list($node)) {
                if ($seg === '-' && $insert) {
                    $node[] = $value;

                    return;
                }
                $index = $this->index($seg, \count($node), $insert);
                if ($insert) {
                    array_splice($node, $index, 0, [$value]);
                } else {
                    $node[$index] = $value;
                }

                return;
            }
            $node[$seg] = $value;

            return;
        }

        $key = array_is_list($node) ? $this->index($seg, \count($node), false) : $seg;
        if (!array_key_exists($key, $node)) {
            throw new \InvalidArgumentException(sprintf('Path segment "%s" does not exist.', $seg));
        }
        $this->write($node[$key], $path, $value, $insert);
    }

    /**
     * @param list<string> $path
     */
    private function remove(mixed &$node, array $path): mixed
    {
        if ($path === []) {
            throw new \InvalidArgumentException('The root cannot be removed.');
        }
        if (!\is_array($node)) {
            throw new \InvalidArgumentException('Cannot remove below a scalar.');
        }

        $seg = array_shift($path);
        if ($path === []) {
            if (array_is_list($node)) {
                $index = $this->index($seg, \count($node), false);
                $value = $node[$index];
                array_splice($node, $index, 1);

                return $value;
            }
            if (!array_key_exists($seg, $node)) {
                throw new \InvalidArgumentException(sprintf('Path segment "%s" does not exist.', $seg));
            }
            $value = $node[$seg];
            unset($node[$seg]);

            return $value;
        }

        $key = array_is_list($node) ? $this->index($seg, \count($node), false) : $seg;
        if (!array_key_exists($key, $node)) {
            throw new \InvalidArgumentException(sprintf('Path segment "%s" does not exist.', $seg));
        }

        return $this->remove($node[$key], $path);
    }

    private function index(string $seg, int $count, bool $allowEnd): int
    {
        if (!ctype_digit($seg)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an array index.', $seg));
        }
        $i = (int) $seg;
        if ($i > $count || (!$allowEnd && $i === $count)) {
            throw new \InvalidArgumentException(sprintf('Index %d is out of range.', $i));
        }

        return $i;
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function exists(array $node, string $seg): bool
    {
        return array_is_list($node) ? ctype_digit($seg) && (int) $seg < \count($node) : array_key_exists($seg, $node);
    }

    /**
     * @param array<array-key, mixed> $node
     */
    private function key(array $node, string $seg): int|string
    {
        return array_is_list($node) ? (int) $seg : $seg;
    }
}
