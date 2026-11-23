<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Publication\ExportPath;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Render\MarkdownRenderer;
use App\Storage\AtomicFileWriter;

final class MarkdownPublisher implements PublisherInterface
{
    public function __construct(
        private readonly MarkdownRenderer $renderer,
        private readonly AtomicFileWriter $writer,
        private readonly string $exportDir,
    ) {
    }

    public function getName(): string
    {
        return 'markdown';
    }

    public function getLabel(): string
    {
        return 'Markdown';
    }

    public function getOptionDefinitions(): array
    {
        return [new OptionDefinition('output', 'Output file', false, null)];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $output = ExportPath::for($this->exportDir, $request->documentId, 'md', $request->options['output'] ?? null);
        if ($request->dryRun) {
            return new PublishResult('dry-run', ["would write $output"], [$output]);
        }

        $this->writer->write($output, $this->renderer->render($request->document));

        return new PublishResult('done', ["wrote $output"], [$output]);
    }
}
