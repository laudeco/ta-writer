<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

/**
 * Every text field of the real technical analysis survives Markdown -> editor HTML -> Markdown.
 */
final class ContentRoundTripTest extends TestCase
{
    public function testEveryTextFieldOfTheTechnicalAnalysisRoundTripsThroughTheEditor(): void
    {
        $document = json_decode((string) file_get_contents(__DIR__.'/../../docs/ta-writer.document.json'), true, 512, JSON_THROW_ON_ERROR);
        $converter = new ContentConverter();
        $normalise = static fn (string $s): string => trim((string) preg_replace('/\s+/', ' ', str_replace(['\\_', '\\*'], ['_', '*'], $s)));

        $checked = 0;
        $different = [];
        foreach ($this->textFields($document) as $path => $markdown) {
            ++$checked;
            $back = $converter->toMarkdown($converter->toEditorHtml($markdown, 'markdown'), 'html');
            if ($normalise($markdown) !== $normalise($back)) {
                $different[] = $path;
            }
        }

        self::assertGreaterThan(200, $checked, 'the document has its text fields');
        self::assertSame([], $different, 'no field may change its content through the editor');
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, string> JSON path => text
     */
    private function textFields(array $document): array
    {
        $out = [];
        foreach ($document['epics'] as $e => $epic) {
            $out["/epics/$e/what"] = (string) ($epic['what'] ?? '');
            foreach ($epic['technicalStories'] as $t => $ts) {
                $out["/epics/$e/technicalStories/$t/what"] = (string) ($ts['what'] ?? '');
                $out["/epics/$e/technicalStories/$t/why"] = (string) ($ts['why'] ?? '');
                foreach ($ts['subtasks'] as $s => $sub) {
                    foreach (['what', 'why', 'where', 'how'] as $field) {
                        $out["/epics/$e/technicalStories/$t/subtasks/$s/$field"] = (string) ($sub[$field] ?? '');
                    }
                }
            }
        }

        return array_filter($out, static fn (string $text): bool => trim($text) !== '');
    }
}
