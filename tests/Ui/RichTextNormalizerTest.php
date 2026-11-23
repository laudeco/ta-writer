<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Render\ContentConverter;
use App\Schema\SchemaProvider;
use App\Ui\Form\FieldNode;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The three states of a rich field: untouched, edited in Quill, edited in the Source view.
 */
final class RichTextNormalizerTest extends TestCase
{
    private FieldNode $root;
    private FormDataNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->root = (new SchemaFormBuilder(new SchemaProvider(__DIR__.'/../../config/schema/document.openapi.yaml')))->build();
        $this->normalizer = new FormDataNormalizer(new ContentConverter());
    }

    /** @return array<string, mixed> */
    private function submitted(string $format, array $what): array
    {
        return ['format' => $format, 'epics' => [['title' => 'E', 'what' => $what]]];
    }

    public function testUntouchedFieldKeepsItsStoredValueWhenTheFormatDoesNotChange(): void
    {
        $stored = "Line one\n\n- [ ] step with `code`";
        $out = $this->normalizer->normalize(
            $this->submitted('markdown', ['original' => $stored, 'mode' => 'raw', 'html' => '', 'source' => $stored]),
            $this->root,
            'markdown',
        );

        self::assertSame($stored, $out['epics'][0]['what'], 'byte for byte');
    }

    public function testBrowserLineBreaksAreNotAnEdit(): void
    {
        $stored = "one\ntwo";
        $out = $this->normalizer->normalize(
            $this->submitted('markdown', ['original' => "one\r\ntwo", 'mode' => 'raw', 'html' => '', 'source' => '']),
            $this->root,
            'markdown',
        );

        self::assertSame($stored, $out['epics'][0]['what']);
    }

    public function testUntouchedFieldIsConvertedWhenTheFormatChanges(): void
    {
        $out = $this->normalizer->normalize(
            $this->submitted('html', ['original' => '**bold**', 'mode' => 'raw', 'html' => '', 'source' => '']),
            $this->root,
            'markdown',
        );

        self::assertStringContainsString('<strong>bold</strong>', $out['epics'][0]['what']);
    }

    public function testEditedFieldIsConvertedFromTheEditorHtml(): void
    {
        $out = $this->normalizer->normalize(
            $this->submitted('markdown', ['original' => 'old', 'mode' => 'html', 'html' => '<p><strong>new</strong> text</p>', 'source' => '']),
            $this->root,
            'markdown',
        );

        self::assertSame('**new** text', $out['epics'][0]['what']);
    }

    public function testEditorSpacesAreNoBreakSpacesAndStoredAsPlainSpaces(): void
    {
        $out = $this->normalizer->normalize(
            $this->submitted('markdown', ['original' => '', 'mode' => 'html', 'html' => "<p>a\u{00A0}b&nbsp;c</p>", 'source' => '']),
            $this->root,
            'markdown',
        );

        self::assertSame('a b c', $out['epics'][0]['what']);
    }

    public function testSourceViewIsConvertedFromTheOriginalFormat(): void
    {
        $out = $this->normalizer->normalize(
            $this->submitted('text', ['original' => 'old', 'mode' => 'source', 'html' => '', 'source' => '**typed** here']),
            $this->root,
            'markdown',
        );

        self::assertSame('typed here', $out['epics'][0]['what']);
    }

    public function testEmptyRichFieldIsDropped(): void
    {
        $out = $this->normalizer->normalize(
            $this->submitted('markdown', ['original' => '', 'mode' => 'html', 'html' => '<p><br></p>', 'source' => '']),
            $this->root,
            'markdown',
        );

        self::assertArrayNotHasKey('what', $out['epics'][0]);
    }
}
