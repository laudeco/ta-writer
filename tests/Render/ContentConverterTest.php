<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

final class ContentConverterTest extends TestCase
{
    public function testMarkdownBecomesHtmlAndRawHtmlIsEscaped(): void
    {
        $html = (new ContentConverter())->toHtml("# Title\n\n<script>alert(1)</script> text", 'markdown');

        self::assertStringContainsString('<h1>Title</h1>', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testHtmlIsSanitised(): void
    {
        $html = (new ContentConverter())->toHtml('<p>ok</p><script>bad()</script><iframe src="x"></iframe>', 'html');

        self::assertStringContainsString('<p>ok</p>', $html);
        self::assertStringNotContainsString('script', $html);
        self::assertStringNotContainsString('iframe', $html);
    }

    public function testTextIsEscaped(): void
    {
        self::assertSame('a &lt;b&gt; c', (new ContentConverter())->toHtml('a <b> c', 'text'));
    }

    public function testHtmlConvertsToMarkdownForTheMarkdownExport(): void
    {
        $markdown = (new ContentConverter())->toMarkdown('<p><strong>bold</strong> and <a href="https://example.test">link</a></p>', 'html');

        self::assertStringContainsString('**bold**', $markdown);
        self::assertStringContainsString('[link](https://example.test)', $markdown);
    }
}
