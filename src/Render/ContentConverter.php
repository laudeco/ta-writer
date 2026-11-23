<?php

declare(strict_types=1);

namespace App\Render;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts text fields between the three formats of a document (markdown, html, text).
 *
 * Quill edits HTML. The editor HTML (toEditorHtml) carries checklists as `li[data-list]` and tables with the `data-row`
 * attributes Quill's table module reads; toMarkdown turns those back into GFM checklists and tables.
 */
final class ContentConverter
{
    private GithubFlavoredMarkdownConverter $markdown;
    private HtmlConverter $htmlToMarkdown;

    public function __construct()
    {
        // raw HTML typed in a markdown field is escaped, not rendered
        $this->markdown = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $this->htmlToMarkdown = new HtmlConverter(['strip_tags' => true, 'hard_break' => true, 'header_style' => 'atx']);
        // tables are GFM tables only when this converter is active (it is not part of the default set)
        $this->htmlToMarkdown->getEnvironment()->addConverter(new TableConverter());
    }

    /**
     * Rendered HTML of a value in its format (used by the PDF, the Confluence pages and the editors' preview).
     */
    public function toHtml(string $content, string $format): string
    {
        return match ($format) {
            'html' => $this->sanitizeHtml($content),
            'text' => nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false),
            default => trim((string) $this->markdown->convert($content)),
        };
    }

    /**
     * HTML that Quill can load: checklists as `li[data-list]`, tables with row and cell `data-row` ids.
     */
    public function toEditorHtml(string $content, string $format): string
    {
        $dom = $this->dom($this->toHtml($content, $format));
        $this->prepareChecklists($dom, true);
        $this->prepareTables($dom);
        $this->softBreaksToSpaces($dom);

        return $this->inner($dom);
    }

    /**
     * Markdown (GFM) of an HTML fragment, checklists and tables included.
     */
    public function toMarkdown(string $content, string $format): string
    {
        if ($format !== 'html') {
            return trim($content);
        }
        $dom = $this->dom($this->sanitizeHtml($content));
        $this->prepareChecklists($dom, false);

        $markdown = trim((string) $this->htmlToMarkdown->convert($this->inner($dom)));

        // the bracket escapes are removed for the checkbox prefix only: '\\[ \\]' -> '[ ]' at the start of a list item
        $escapedBox = '/^(\s*(?:[-*]|\d+\.)\s+)\\\\\\[( |x)\\\\\\] /m';
        $markdown = (string) preg_replace($escapedBox, '$1[$2] ', $markdown);

        // HTML escapes of the text (a '>' typed in a sentence) are plain characters in Markdown
        return strtr($markdown, ['&gt;' => '>', '&lt;' => '<', '&amp;' => '&']);
    }

    /**
     * Plain text of an HTML fragment: a new line at each block, list items prefixed with a dash, inline text joined.
     */
    public function toText(string $content, string $format): string
    {
        if ($format === 'text') {
            return trim($content);
        }
        $dom = $this->dom($this->toHtml($content, $format));
        $text = '';
        foreach ($dom->getElementsByTagName('body')->item(0)->childNodes ?? [] as $node) {
            $text = $this->appendText($node, $text);
        }
        $lines = array_map(static fn (string $line): string => trim($line), explode("\n", $text));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));
    }

    /**
     * Converts a value from one document format to another. Used when the format of a document changes: a field that
     * was not touched is converted from its stored value; an edited one is converted from what the editor sent.
     */
    public function convert(string $value, string $from, string $to): string
    {
        if ($from === $to || trim($value) === '') {
            return $value;
        }

        return match ($to) {
            'markdown' => $from === 'text' ? trim($value) : $this->toMarkdown($this->toHtml($value, $from), 'html'),
            'html' => $this->toHtml($value, $from) === '' ? '' : $this->sanitizeHtml($this->toHtml($value, $from)),
            'text' => $this->toText($value, $from),
            default => $value,
        };
    }

    /**
     * Removes the elements that can run code or load external content. Keeps the rest of the markup.
     */
    private function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $dom = $this->dom($html);
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; --$i) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        return $this->inner($dom);
    }

    /**
     * Quill writes checklists as `<li data-list="checked|unchecked">`; GFM writes `<li><input type="checkbox">`.
     * Going to Quill: GFM checkboxes become data-list items in an ordered list. Going to Markdown: data-list items become
     * "[ ]" / "[x]" prefixes in an unordered list.
     */
    private function prepareChecklists(\DOMDocument $dom, bool $toEditor): void
    {
        $items = [];
        foreach ($dom->getElementsByTagName('li') as $li) {
            $items[] = $li;
        }
        foreach ($items as $li) {
            if ($toEditor) {
                $box = null;
                foreach ($li->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->tagName === 'input' && $child->getAttribute('type') === 'checkbox') {
                        $box = $child;
                    }
                }
                if ($box === null) {
                    continue;
                }
                $li->setAttribute('data-list', $box->hasAttribute('checked') ? 'checked' : 'unchecked');
                $li->removeChild($box);
                if ($li->parentNode instanceof \DOMElement && $li->parentNode->tagName === 'ul') {
                    $this->renameTag($dom, $li->parentNode, 'ol');
                }
            } elseif ($li->hasAttribute('data-list')) {
                $checked = $li->getAttribute('data-list') === 'checked';
                $li->removeAttribute('data-list');
                $li->insertBefore($dom->createTextNode($checked ? '[x] ' : '[ ] '), $li->firstChild);
                if ($li->parentNode instanceof \DOMElement && $li->parentNode->tagName === 'ol') {
                    $this->renameTag($dom, $li->parentNode, 'ul');
                }
            }
        }
    }

    /**
     * A line break inside a paragraph is a soft break of the Markdown: Quill would show it as a new line.
     * Code blocks keep their line breaks.
     */
    private function softBreaksToSpaces(\DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//text()[not(ancestor::pre) and not(ancestor::code)]') ?: [] as $text) {
            if ($text instanceof \DOMText && str_contains((string) $text->nodeValue, "\n")) {
                $text->nodeValue = (string) preg_replace('/\s*\n\s*/', ' ', (string) $text->nodeValue);
            }
        }
    }

    /**
     * Quill's table module needs a tbody, a data-row on every row and the same id on the row's cells.
     */
    private function prepareTables(\DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('table') as $table) {
            $rows = [];
            foreach ($table->getElementsByTagName('tr') as $tr) {
                $rows[] = $tr;
            }
            foreach ($rows as $i => $tr) {
                $rowId = 'row-'.($i + 1);
                $tr->setAttribute('data-row', $rowId);
                foreach ($tr->childNodes as $cell) {
                    if ($cell instanceof \DOMElement && in_array($cell->tagName, ['td', 'th'], true)) {
                        if ($cell->tagName === 'th') {
                            $this->renameTag($dom, $cell, 'td');
                        }
                        $cell->setAttribute('data-row', $rowId);
                    }
                }
            }
        }
    }

    private function renameTag(\DOMDocument $dom, \DOMElement $element, string $tag): void
    {
        $replacement = $dom->createElement($tag);
        foreach ($element->attributes ?? [] as $attr) {
            $replacement->setAttribute($attr->name, $attr->value);
        }
        while ($element->firstChild !== null) {
            $replacement->appendChild($element->firstChild);
        }
        $element->parentNode?->replaceChild($replacement, $element);
    }

    private const BLOCK_TAGS = ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'pre', 'tr', 'table', 'ul', 'ol'];

    private function appendText(\DOMNode $node, string $text, string $listPrefix = ''): string
    {
        if ($node instanceof \DOMText) {
            return $text.preg_replace('/\s+/', ' ', $node->nodeValue ?? '');
        }
        if (!$node instanceof \DOMElement) {
            return $text;
        }
        $tag = strtolower($node->tagName);
        if ($tag === 'br') {
            return $text."\n";
        }
        $block = in_array($tag, self::BLOCK_TAGS, true) || $tag === 'li';
        if ($block && $text !== '' && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }
        if ($tag === 'li') {
            $text .= '- ';
        }
        if (in_array($tag, ['td', 'th'], true)) {
            $text .= ' | ';
        }
        foreach ($node->childNodes as $child) {
            $text = $this->appendText($child, $text, $listPrefix);
        }
        if ($block && !str_ends_with($text, "\n")) {
            $text .= "\n";
        }

        return $text;
    }

    private function dom(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>'.$html.'</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom;
    }

    private function inner(\DOMDocument $dom): string
    {
        $body = $dom->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }
}
