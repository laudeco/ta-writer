<?php

declare(strict_types=1);

namespace App\Publication\Adapter;

use App\Publication\ExportPath;
use App\Publication\OptionDefinition;
use App\Publication\PublisherInterface;
use App\Publication\PublishRequest;
use App\Publication\PublishResult;
use App\Schema\SchemaProvider;
use App\Storage\AtomicFileWriter;

/**
 * Raw copy of the stored document, optionally with its JSON Schema next to it.
 */
final class JsonPublisher implements PublisherInterface
{
    public function __construct(
        private readonly SchemaProvider $schema,
        private readonly AtomicFileWriter $writer,
        private readonly string $exportDir,
    ) {
    }

    public function getName(): string
    {
        return 'json';
    }

    public function getLabel(): string
    {
        return 'Raw JSON (+ JSON Schema)';
    }

    public function getOptionDefinitions(): array
    {
        return [
            new OptionDefinition('output', 'Output file', false, null),
            new OptionDefinition('withSchema', 'Also write the JSON Schema (yes/no)', false, 'yes'),
        ];
    }

    public function publish(PublishRequest $request): PublishResult
    {
        $output = ExportPath::for($this->exportDir, $request->documentId, $request->document, 'json',$request->options['output'] ?? null);
        $files = [$output];
        $withSchema = ($request->options['withSchema'] ?? 'yes') !== 'no';
        if ($withSchema) {
            $files[] = preg_replace('/\.json$/', '.schema.json', $output) ?? $output.'.schema.json';
        }

        if ($request->dryRun) {
            return new PublishResult('dry-run', array_map(static fn (string $f): string => "would write $f", $files), $files);
        }

        $this->writer->write($output, (string) json_encode($request->document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        if ($withSchema) {
            $this->writer->write($files[1], (string) json_encode($this->schema->getDocumentJsonSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }

        return new PublishResult('done', array_map(static fn (string $f): string => "wrote $f", $files), $files);
    }
}
