<?php

declare(strict_types=1);

namespace App\Ui\Form;

use App\Schema\SchemaProvider;

/**
 * Builds the form tree of the Document from the OpenAPI schema.
 *
 * A property added to the YAML shows up in the editor without any code change. The widget comes from the schema:
 * `enum` -> select, `format: uri|email` -> url|email input, `type: integer` -> number, `x-widget` -> textarea, code or reference (a dropdown of the document's references),
 * `type: object` -> a fieldset, `type: array` -> a repeatable list.
 */
final class SchemaFormBuilder
{
    private const MAX_DEPTH = 12;

    public function __construct(private readonly SchemaProvider $provider)
    {
    }

    public function build(): FieldNode
    {
        return $this->node('data', $this->provider->getDocumentFormSchema(), true, 0);
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function node(string $name, array $schema, bool $required, int $depth): FieldNode
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \LogicException(sprintf('Schema nested deeper than %d levels at "%s".', self::MAX_DEPTH, $name));
        }

        $description = isset($schema['description']) ? (string) $schema['description'] : null;
        $readOnly = (bool) ($schema['readOnly'] ?? false);
        $richWhen = isset($schema['x-richtext-when']) ? ['field' => (string) $schema['x-richtext-when']['field'], 'equals' => (string) $schema['x-richtext-when']['equals']] : null;
        $placeholder = isset($schema['examples'][0]) ? (string) $schema['examples'][0] : null;
        $type = $schema['type'] ?? 'string';

        // an author is chosen in the list of authors, not typed
        if ($type === 'object' && ($schema['x-widget'] ?? '') === 'author') {
            return new FieldNode($name, 'author', $required, $description, null, [], [], null, $readOnly);
        }

        if ($type === 'object') {
            $requiredChildren = $schema['required'] ?? [];
            $children = [];
            foreach ($schema['properties'] ?? [] as $childName => $childSchema) {
                $children[] = $this->node((string) $childName, $childSchema, \in_array($childName, $requiredChildren, true), $depth + 1);
            }

            return new FieldNode($name, 'object', $required, $description, null, [], $children);
        }

        if ($type === 'array') {
            $item = $this->node('item', $schema['items'] ?? ['type' => 'string'], false, $depth + 1);

            return new FieldNode($name, 'array', $required, $description, null, [], [], $item);
        }

        if (isset($schema['enum'])) {
            return new FieldNode($name, 'select', $required, $description, $placeholder, array_map('strval', $schema['enum']), [], null, $readOnly);
        }

        $widget = match (true) {
            $type === 'integer' || $type === 'number' => 'integer',
            ($schema['format'] ?? '') === 'uri' => 'url',
            ($schema['format'] ?? '') === 'email' => 'email',
            ($schema['x-widget'] ?? '') === 'code' => 'code',
            ($schema['x-widget'] ?? '') === 'richtext' => 'richtext',
            ($schema['x-widget'] ?? '') === 'textarea' => 'textarea',
            ($schema['x-widget'] ?? '') === 'reference' => 'reference',
            default => 'text',
        };

        return new FieldNode($name, $widget, $required, $description, $placeholder, [], [], null, $readOnly, $richWhen);
    }
}
