<?php

declare(strict_types=1);

namespace App\Render\Twig;

use App\Render\ContentConverter;
use App\Render\Mermaid\MermaidRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Helpers used by the PDF and UI templates: text in the document format as HTML, mermaid as an image.
 */
final class RenderExtension extends AbstractExtension
{
    public function __construct(
        private readonly ContentConverter $converter,
        private readonly MermaidRenderer $mermaid,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('content_html', fn (?string $content, string $format): string => $this->converter->toHtml((string) $content, $format), ['is_safe' => ['html']])];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mermaid_img', [$this, 'mermaidImage'], ['is_safe' => ['html']]),
            // the HTML a Quill editor starts from: checklists and tables in the form Quill reads
            // not marked safe: it goes into an attribute (data-html), so Twig escapes its quotes
            new TwigFunction('rich_html', fn (?string $content, string $format): string => $this->converter->toEditorHtml((string) $content, $format)),
        ];
    }

    /**
     * An <img> of the rendered diagram, or the source in a block when mermaid is not available.
     */
    public function mermaidImage(string $source): string
    {
        try {
            $png = $this->mermaid->render($source);

            return sprintf('<img class="diagram" src="%s" alt="diagram">', htmlspecialchars($png, ENT_QUOTES));
        } catch (\Throwable $e) {
            return sprintf('<p class="warning">Diagram not rendered: %s</p><pre>%s</pre>', htmlspecialchars($e->getMessage()), htmlspecialchars($source));
        }
    }
}
