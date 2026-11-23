<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Publication\ExportPath;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Render\DocumentModelBuilder;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Twig\Environment;

/**
 * PDF through mPDF. Same model and layout as the Markdown export; diagrams are rendered by mmdc to PNG first.
 */
final class PdfPublisher implements PublisherInterface
{
    public function __construct(
        private readonly DocumentModelBuilder $builder,
        private readonly Environment $twig,
        private readonly string $exportDir,
        private readonly string $projectDir,
        private readonly string $tempDir,
    ) {
    }

    public function getName(): string
    {
        return 'pdf';
    }

    public function getLabel(): string
    {
        return 'PDF (mPDF, diagrams rendered with mermaid)';
    }

    public function getOptionDefinitions(): array
    {
        return [new OptionDefinition('output', 'Output file', false, null)];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $output = ExportPath::for($this->exportDir, $request->documentId, 'pdf', $request->options['output'] ?? null);
        if ($request->dryRun) {
            return new PublishResult('dry-run', ["would write $output"], [$output]);
        }

        $model = $this->builder->build($request->document);
        $html = $this->twig->render('export/document.html.twig', ['doc' => $model]);
        $css = (string) file_get_contents($this->projectDir.'/assets/pdf/theme.css');

        if (!is_dir($this->tempDir) && !mkdir($this->tempDir, 0775, true) && !is_dir($this->tempDir)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $this->tempDir));
        }
        $dir = \dirname($output);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create %s', $dir));
        }

        $project = (string) ($model['project']['reference'] ?? '');
        $mpdf = new Mpdf([
            'format' => 'A4',
            'tempDir' => $this->tempDir,
            'margin_top' => 18,
            'margin_bottom' => 18,
            'default_font' => 'dejavusans',
        ]);
        $mpdf->SetTitle((string) ($model['project']['name'] ?? 'Technical analysis'));
        $mpdf->SetAuthor((string) ($model['author']['name'] ?? ''));
        $mpdf->h2toc = ['H2' => 0, 'H3' => 1, 'H4' => 2];
        $mpdf->SetHTMLFooter(sprintf('<div style="font-size:8pt;color:#829ab1;text-align:right">%s &middot; {PAGENO} / {nbpg}</div>', htmlspecialchars($project)));
        $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
        $mpdf->Output($output, \Mpdf\Output\Destination::FILE);

        return new PublishResult('done', ["wrote $output"], [$output]);
    }
}
