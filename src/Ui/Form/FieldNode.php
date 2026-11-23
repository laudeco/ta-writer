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

    public function label(): string
    {
        return ucfirst(preg_replace('/(?<!^)([A-Z])/', ' $1', $this->name) ?? $this->name);
    }
}
