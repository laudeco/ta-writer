<?php

declare(strict_types=1);

namespace App\Render;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Converts a text field to the format a renderer needs, according to the document `format` (html, text, markdown).
 */
final class ContentConverter
{
    private GithubFlavoredMarkdownConverter $markdown;
    private HtmlConverter $htmlToMarkdown;

    public function __construct()
    {
        // raw HTML typed in a markdown field is escaped, not rendered
        $this->markdown = new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);
        $this->htmlToMarkdown = new HtmlConverter(['strip_tags' => true, 'hard_break' => true]);
    }

    public function toHtml(string $content, string $format): string
    {
        return match ($format) {
            'html' => $this->sanitizeHtml($content),
            'text' => nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false),
            default => trim((string) $this->markdown->convert($content)),
        };
    }

    public function toMarkdown(string $content, string $format): string
    {
        return match ($format) {
            'html' => trim((string) $this->htmlToMarkdown->convert($content)),
            default => trim($content),
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
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (['script', 'style', 'iframe', 'object', 'embed', 'form'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; --$i) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $root = $dom->getElementsByTagName('div')->item(0);
        $inner = '';
        foreach ($root instanceof \DOMElement ? $root->childNodes : [] as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return trim($inner);
    }
}
