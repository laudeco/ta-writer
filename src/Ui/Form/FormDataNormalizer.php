<?php

declare(strict_types=1);

namespace App\Ui\Form;

/**
 * Turns the submitted form into a document: integers are typed, empty optional fields are dropped, list items are
 * re-indexed, and an object left empty is removed. Walks the same FieldNode tree as the form.
 */
final class FormDataNormalizer
{
    /**
     * @param array<string, mixed> $submitted the `data` part of the request
     *
     * @return array<string, mixed>
     */
    public function normalize(array $submitted, FieldNode $root): array
    {
        $result = $this->value($submitted, $root);

        return \is_array($result) ? $result : [];
    }

    private function value(mixed $raw, FieldNode $node): mixed
    {
        switch ($node->widget) {
            case 'object':
                $out = [];
                foreach ($node->children as $child) {
                    $value = $this->value(\is_array($raw) ? ($raw[$child->name] ?? null) : null, $child);
                    if ($value !== null) {
                        $out[$child->name] = $value;
                    }
                }

                // an object with nothing but empty lists is an empty item
                $filled = array_filter($out, static fn (mixed $v): bool => $v !== []);

                return $filled === [] ? null : $out;

            case 'array':
                $out = [];
                foreach (\is_array($raw) ? $raw : [] as $item) {
                    $value = $this->value($item, $node->item ?? throw new \LogicException('Array without item.'));
                    if ($value !== null) {
                        $out[] = $value;
                    }
                }

                return $out === [] ? [] : $out;

            case 'integer':
                $text = trim((string) (\is_scalar($raw) ? $raw : ''));

                return $text === '' ? null : (int) $text;

            default:
                $text = trim((string) (\is_scalar($raw) ? $raw : ''));

                return $text === '' ? null : $text;
        }
    }
}
