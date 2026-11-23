<?php

declare(strict_types=1);

namespace App\Publication;

/**
 * Writes the links and keys created on the outside world (pageLink, ticket, dependenciesPageLink) back into the JSON,
 * at the node the path points to. Nothing else is touched.
 */
final class DocumentWriteBack
{
    /**
     * @param array<string, mixed>                       $document
     * @param array<string, array<string, mixed>>        $updates JSON Pointer of a node => fields to set ('' = document root)
     *
     * @return array<string, mixed>
     */
    public function apply(array $document, array $updates): array
    {
        foreach ($updates as $pointer => $fields) {
            $node = &$this->node($document, $pointer);
            foreach ($fields as $field => $value) {
                $node[$field] = $value;
            }
            unset($node);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function &node(array &$document, string $pointer): array
    {
        $node = &$document;
        if ($pointer === '') {
            return $node;
        }
        foreach (explode('/', substr($pointer, 1)) as $segment) {
            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                throw new \InvalidArgumentException(sprintf('No node at %s.', $pointer));
            }
            $node = &$node[$key];
        }

        return $node;
    }
}
