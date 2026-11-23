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
        $html = $this->twig->render('export/document.html.twig', ['doc' => $model, 'stats' => $this->stats($model)]);
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
        // the contents lists the epics (H2) and the technical stories (H3); user stories and subtasks are not in it
        $mpdf->h2toc = ['H2' => 0, 'H3' => 1];
        $version = htmlspecialchars((string) ($model['version'] ?: '-'));
        $mpdf->SetHTMLFooter(sprintf(
            '<table style="width:100%%;font-size:7.5pt;color:#64748b;border-collapse:collapse"><tr>'
            .'<td style="text-align:left">%s &middot; v%s</td><td style="text-align:right">{PAGENO} / {nbpg}</td></tr></table>',
            htmlspecialchars($project),
            $version,
        ));
        $mpdf->WriteHTML($css, HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);
        $mpdf->Output($output, \Mpdf\Output\Destination::FILE);

        return new PublishResult('done', ["wrote $output"], [$output]);
    }

    /**
     * Figures of the cover: epics, technical stories, subtasks and diagrams of the analysis.
     *
     * @param array<string, mixed> $model
     *
     * @return array{epics: int, stories: int, subtasks: int, diagrams: int}
     */
    private function stats(array $model): array
    {
        $stats = ['epics' => count($model['epics']), 'stories' => 0, 'subtasks' => 0, 'diagrams' => 0];
        foreach ($model['epics'] as $epic) {
            $stats['diagrams'] += count($epic['diagrams']);
            foreach ($epic['technicalStories'] as $ts) {
                ++$stats['stories'];
                $stats['diagrams'] += count($ts['diagrams']);
                foreach ($ts['subtasks'] as $sub) {
                    ++$stats['subtasks'];
                    $stats['diagrams'] += count($sub['diagrams']);
                }
            }
        }

        return $stats;
    }
}
