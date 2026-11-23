<?php

declare(strict_types=1);

namespace App\Ui\Form;

/**
 * One field of the generated form. Built from the OpenAPI schema: nothing about the document is hard-coded.
 */
final class FieldNode
{
    /**
     * @param 'object'|'array'|'text'|'textarea'|'richtext'|'code'|'url'|'email'|'integer'|'select' $widget
     * @param list<string>                                                              $options  enum values for a select
     * @param list<FieldNode>                                                           $children object properties, in schema order
     */
    public function __construct(
        public readonly string $name,
        public readonly string $widget,
        public readonly bool $required,
        public readonly ?string $description,
        public readonly ?string $placeholder,
        public readonly array $options = [],
        public readonly array $children = [],
        public readonly ?FieldNode $item = null,
        public readonly bool $readOnly = false,
        /** @var array{field: string, equals: string}|null shows the rich editor only when a sibling field has this value */
        public readonly ?array $richWhen = null,
    ) {
    }

    /**
     * The children in the order they are shown: a `reference` comes right under the title (or the name) of its object.
     * The other children keep the order of the schema, which is the order of the saved data.
     *
     * @return list<FieldNode>
     */
    public function displayChildren(): array
    {
        $names = array_map(static fn (FieldNode $child): string => $child->name, $this->children);
        $titleIndex = array_search('title', $names, true);
        if ($titleIndex === false) {
            $titleIndex = array_search('name', $names, true);
        }
        $referenceIndex = array_search('reference', $names, true);
        if ($titleIndex === false || $referenceIndex === false || $referenceIndex === $titleIndex + 1) {
            return $this->children;
        }

        $reference = $this->children[$referenceIndex];
        $ordered = $this->children;
        unset($ordered[$referenceIndex]);
        $ordered = array_values($ordered);
        $insertAt = $titleIndex < $referenceIndex ? $titleIndex + 1 : $titleIndex;
        array_splice($ordered, $insertAt, 0, [$reference]);

        return $ordered;
    }

    public function label(): string
    {
        return ucfirst(preg_replace('/(?<!^)([A-Z])/', ' $1', $this->name) ?? $this->name);
    }
}
