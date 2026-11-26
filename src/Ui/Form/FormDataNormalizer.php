<?php

declare(strict_types=1);

namespace App\Ui\Form;

use App\Author\AuthorRepository;
use App\Render\ContentConverter;

/**
 * Turns the submitted form into a document: integers are typed, empty optional fields are dropped, list items are
 * re-indexed, and an object left empty is removed. Walks the same FieldNode tree as the form.
 *
 * Rich text fields come with three states, so a field nobody touched is never rewritten:
 *  - mode `raw`: the stored value, kept as it is when the format did not change, converted when it did;
 *  - mode `html`: what the Quill editor produced, converted from HTML to the target format;
 *  - mode `source`: what the person typed in the Source view, converted from the original format.
 */
final class FormDataNormalizer
{
    private string $originalFormat = 'markdown';
    private string $targetFormat = 'markdown';

    public function __construct(
        private readonly ContentConverter $converter,
        private readonly AuthorRepository $authors,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted      the `data` part of the request
     * @param string               $originalFormat the format the stored values are written in
     *
     * @return array<string, mixed>
     */
    public function normalize(array $submitted, FieldNode $root, string $originalFormat): array
    {
        $this->originalFormat = $originalFormat;
        $this->targetFormat = is_string($submitted['format'] ?? null) && $submitted['format'] !== '' ? $submitted['format'] : $originalFormat;

        $result = $this->value($submitted, $root, null);

        return \is_array($result) ? $result : [];
    }

    /**
     * @param array<array-key, mixed>|null $siblings the object holding this node: used by richWhen conditions
     */
    private function value(mixed $raw, FieldNode $node, ?array $siblings, bool $listItem = false): mixed
    {
        switch ($node->widget) {
            case 'richtext':
                if (!$this->richActive($node, $siblings)) {
                    return $this->exactText($raw);
                }

                return \is_array($raw) ? $this->rich($raw) : $this->exactText($raw);

            case 'object':
                $out = [];
                $values = \is_array($raw) ? $raw : null;
                foreach ($node->children as $child) {
                    $value = $this->value($values[$child->name] ?? null, $child, $values);
                    if ($value !== null) {
                        $out[$child->name] = $value;
                    }
                }

                // an item of a list is kept even when it is empty: the validation then reports what it lacks (its title),
                // and its position stays the one the person sees in the form
                if ($listItem) {
                    return $out;
                }

                // an optional object with nothing but empty lists is absent
                $filled = array_filter($out, static fn (mixed $v): bool => $v !== []);

                return $filled === [] ? null : $out;

            case 'array':
                $out = [];
                foreach (\is_array($raw) ? $raw : [] as $item) {
                    $value = $this->value($item, $node->item ?? throw new \LogicException('Array without item.'), null, true);
                    if ($value !== null) {
                        $out[] = $value;
                    }
                }

                // an empty list is kept as it is: documents carry empty lists on purpose (the version check ignores them)
                return $out === [] ? [] : $out;

            case 'author':
                return $this->author($raw);

            case 'integer':
                $text = trim((string) (\is_scalar($raw) ? $raw : ''));

                return $text === '' ? null : (int) $text;

            case 'textarea':
            case 'code':
                return $this->exactText($raw);

            default:
                return $this->text($raw);
        }
    }

    /**
     * Rich fields that depend on a sibling (a resource is rich only when its type is `note`) follow that sibling.
     *
     * @param array<array-key, mixed>|null $siblings
     */
    private function richActive(FieldNode $node, ?array $siblings): bool
    {
        if ($node->richWhen === null) {
            return true;
        }

        return ($siblings[$node->richWhen['field']] ?? null) === $node->richWhen['equals'];
    }

    /**
     * @param array<array-key, mixed> $raw {original, mode, html, source}
     */
    private function rich(array $raw): ?string
    {
        // a browser sends every line break of a form value as CRLF: the stored text is LF
        $original = is_scalar($raw['original'] ?? null) ? str_replace("\r\n", "\n", (string) $raw['original']) : '';
        $mode = is_string($raw['mode'] ?? null) ? $raw['mode'] : 'raw';

        $value = match ($mode) {
            'html' => $this->htmlToTarget(is_scalar($raw['html'] ?? null) ? (string) $raw['html'] : ''),
            'source' => $this->converter->convert(is_scalar($raw['source'] ?? null) ? (string) $raw['source'] : '', $this->originalFormat, $this->targetFormat),
            default => $this->targetFormat === $this->originalFormat ? $original : $this->converter->convert($original, $this->originalFormat, $this->targetFormat),
        };

        return trim($value) === '' ? null : $value;
    }

    private function htmlToTarget(string $html): string
    {
        // Quill types spaces as no-break spaces: they are plain spaces in the stored text
        $html = strtr($html, ["\u{00A0}" => ' ', '&nbsp;' => ' ']);

        // to the html target the editor HTML is only sanitised; to the other formats it is converted
        return $this->targetFormat === 'html'
            ? $this->converter->toHtml($html, 'html')
            : $this->converter->convert($html, 'html', $this->targetFormat);
    }

    /**
     * The author chosen in the list is copied from the list. An email that is no longer in the list keeps the copy that the
     * form carries; no email at all leaves the author out (the schema then refuses the document).
     *
     * @return array<string, string>|null
     */
    private function author(mixed $raw): ?array
    {
        $raw = \is_array($raw) ? $raw : [];
        $email = trim(\is_scalar($raw['email'] ?? null) ? (string) $raw['email'] : '');
        if ($email === '') {
            return null;
        }
        $listed = $this->authors->find($email);
        if ($listed !== null) {
            return $listed->toArray();
        }

        $copy = ['name' => trim(\is_scalar($raw['name'] ?? null) ? (string) $raw['name'] : ''), 'email' => $email];
        $username = trim(\is_scalar($raw['username'] ?? null) ? (string) $raw['username'] : '');
        if ($username !== '') {
            $copy['username'] = $username;
        }

        return $copy;
    }

    /**
     * A multi-line value is kept exactly as stored (diagram sources, code): the form only changes its line endings.
     */
    private function exactText(mixed $raw): ?string
    {
        if (!\is_scalar($raw)) {
            return null;
        }
        $text = str_replace("\r\n", "\n", (string) $raw);

        return trim($text) === '' ? null : $text;
    }

    private function text(mixed $raw): ?string
    {
        $text = trim((string) (\is_scalar($raw) ? $raw : ''));

        return $text === '' ? null : $text;
    }
}
