<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

use App\Render\Mermaid\MermaidRenderException;
use App\Render\Mermaid\MermaidRenderer;

/**
 * Renders the diagrams of a node to PNG attachments. A diagram that does not render is reported, not fatal.
 */
final class DiagramAttachments
{
    public function __construct(private readonly MermaidRenderer $mermaid)
    {
    }

    /**
     * @param list<array{type: string, content: string}> $diagrams
     *
     * @return array{files: array<string, string>, attachments: list<array{filename: string, bytes: string}>, warnings: list<string>}
     */
    public function collect(array $diagrams, string $prefix): array
    {
        $files = [];
        $attachments = [];
        $warnings = [];
        foreach ($diagrams as $i => $diagram) {
            $filename = sprintf('%s-%d.png', $prefix, $i + 1);
            try {
                $png = $this->mermaid->render($diagram['content']);
                $files[rtrim($diagram['content'])] = $filename;
                $attachments[] = ['filename' => $filename, 'bytes' => (string) file_get_contents($png)];
            } catch (MermaidRenderException $e) {
                $warnings[] = sprintf('%s: %s', $filename, $e->getMessage());
            }
        }

        return ['files' => $files, 'attachments' => $attachments, 'warnings' => $warnings];
    }
}
