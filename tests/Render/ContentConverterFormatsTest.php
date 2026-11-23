<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

final class ContentConverterFormatsTest extends TestCase
{
    public function testChecklistsBecomeQuillCheckItemsAndBack(): void
    {
        $c = new ContentConverter();
        $editor = $c->toEditorHtml("- [ ] open\n- [x] done", 'markdown');

        self::assertStringContainsString('data-list="unchecked"', $editor);
        self::assertStringContainsString('data-list="checked"', $editor);
        self::assertSame("- [ ] open\n- [x] done", $c->toMarkdown($editor, 'html'));
    }

    public function testTablesGetQuillRowIdsAndBackToGfm(): void
    {
        $c = new ContentConverter();
        $markdown = "| Method | IN |\n|---|---|\n| `a()` | — |";
        $editor = $c->toEditorHtml($markdown, 'markdown');

        self::assertStringContainsString('data-row="row-1"', $editor);
        self::assertStringContainsString('data-row="row-2"', $editor);
        self::assertStringContainsString('<td', $editor);
        self::assertStringContainsString('| Method | IN |', $c->toMarkdown($editor, 'html'));
    }

    public function testConvertBetweenTheThreeFormats(): void
    {
        $c = new ContentConverter();

        self::assertSame('same text', $c->convert('same text', 'markdown', 'markdown'));
        self::assertStringContainsString('<strong>bold</strong>', $c->convert('**bold**', 'markdown', 'html'));
        self::assertSame('bold', $c->convert('**bold**', 'markdown', 'text'));
        self::assertSame('**bold**', $c->convert('<p><strong>bold</strong></p>', 'html', 'markdown'));
        self::assertSame('bold', $c->convert('<p><strong>bold</strong></p>', 'html', 'text'));
    }

    public function testTextKeepsOneLinePerBlockAndListItemsAsDashes(): void
    {
        $text = (new ContentConverter())->toText('<p>First</p><ul><li>one</li><li>two</li></ul>', 'html');

        self::assertSame("First\n- one\n- two", $text);
    }

    public function testEmptyValuesStayEmpty(): void
    {
        // a blank value is not an edit: it comes back as it was stored
        self::assertSame('   ', (new ContentConverter())->convert('   ', 'markdown', 'html'));
    }
}
