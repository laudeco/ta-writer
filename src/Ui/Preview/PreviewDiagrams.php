<?php

declare(strict_types=1);

namespace App\Ui\Preview;

/**
 * Replaces the Mermaid blocks of a rendered preview by placeholders. The browser fills them with the images that the
 * server renders in the background (see PreviewController::diagram), so typing never waits for a diagram.
 */
final class PreviewDiagrams
{
    public static function placeholders(string $html): string
    {
        return preg_replace_callback(
            '#<pre><code class="language-mermaid">(.*?)</code></pre>#s',
            static function (array $match): string {
                $source = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return sprintf(
                    '<figure class="preview-diagram is-pending" data-source-hash="%s"><code class="language-mermaid">%s</code><span class="muted">Diagram…</span></figure>',
                    hash('sha256', trim($source)),
                    $match[1],
                );
            },
            $html,
        ) ?? $html;
    }
}
