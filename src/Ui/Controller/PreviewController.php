<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Render\ContentConverter;
use App\Render\DocumentModelBuilder;
use App\Render\MarkdownRenderer;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use App\Ui\Preview\SectionScope;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Live preview of the editor: the section being written (or the whole document), rendered by the same Markdown renderer
 * as the Markdown export, then turned into HTML. Nothing is validated, nothing is saved.
 */
final class PreviewController extends AbstractController
{
    public function __construct(
        private readonly SchemaFormBuilder $formBuilder,
        private readonly FormDataNormalizer $normalizer,
        private readonly DocumentModelBuilder $builder,
        private readonly MarkdownRenderer $renderer,
        private readonly ContentConverter $converter,
    ) {
    }

    /**
     * Form field `path`: `document`, `header` (project, author, dependencies), or the section as `epics.0`,
     * `epics.0.technicalStories.2`, `epics.0.technicalStories.2.subtasks.1` (kind and position, top to bottom).
     */
    #[Route('/editor/preview', name: 'ui_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $raw = $request->request->all('data');
        $originalFormat = (string) $request->request->get('_format_original', 'markdown');
        $path = (string) $request->request->get('path', 'document');

        $scoped = SectionScope::for($raw, $path);
        if ($scoped === null) {
            return new Response('<p class="muted">This section is not in the document any more.</p>', Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        }

        $document = $this->normalizer->normalize($scoped, $this->formBuilder->build(), $originalFormat);
        $markdown = $this->renderer->render($this->builder->build($document));
        if (!in_array($path, ['document', 'header'], true)) {
            $markdown = $this->withoutHeader($markdown);
        }
        $html = $this->converter->toHtml($markdown, 'markdown');

        return new Response($html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /**
     * A section is shown without the title, the metadata and the table of contents of the document: from its first
     * section heading (the epic of the section comes first, for its context).
     */
    private function withoutHeader(string $markdown): string
    {
        if (preg_match('/^## (?!Contents$)/m', $markdown, $match, PREG_OFFSET_CAPTURE) === 1) {
            return substr($markdown, $match[0][1]);
        }

        return $markdown;
    }
}
