<?php

declare(strict_types=1);

namespace App\Tests\Jira;

use App\Jira\MarkdownToJiraWiki;
use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

final class MarkdownToJiraWikiTest extends TestCase
{
    private MarkdownToJiraWiki $wiki;

    protected function setUp(): void
    {
        $this->wiki = new MarkdownToJiraWiki(new ContentConverter());
    }

    public function testHeadingsEmphasisAndCode(): void
    {
        $out = $this->wiki->convert("## Title\n\nSome **bold**, *em*, ~~gone~~ and `code`.");

        self::assertStringContainsString('h2. Title', $out);
        self::assertStringContainsString('Some *bold*, _em_, -gone- and {{code}}.', $out);
    }

    public function testLinksAndImages(): void
    {
        $out = $this->wiki->convert('A [site](https://example.test/x) and ![pic](https://example.test/a.png).');

        self::assertStringContainsString('[site|https://example.test/x]', $out);
        self::assertStringContainsString('!https://example.test/a.png!', $out);
    }

    public function testNestedBulletsAndNumbers(): void
    {
        $out = $this->wiki->convert("- one\n  - nested\n- two\n\n1. first\n2. second");

        self::assertStringContainsString("* one\n** nested\n* two", $out);
        self::assertStringContainsString("# first\n# second", $out);
    }

    public function testChecklistUsesBoxesNotBrackets(): void
    {
        $out = $this->wiki->convert("- [ ] open\n- [x] done");

        self::assertStringContainsString('☐ open', $out);
        self::assertStringContainsString('☑ done', $out);
        self::assertStringNotContainsString('[ ]', $out, 'a bracket pair would read as a Jira link');
    }

    public function testTablesCodeBlocksAndQuotes(): void
    {
        $out = $this->wiki->convert("| A | B |\n|---|---|\n| 1 | 2 |\n\n```php\necho 1;\n```\n\n> quoted");

        self::assertStringContainsString("||A||B||\n|1|2|", $out);
        self::assertStringContainsString("{code:php}\necho 1;\n{code}", $out);
        self::assertStringContainsString("{quote}\nquoted\n{quote}", $out);
    }

    public function testMarkupCharactersOfTheTextAreEscaped(): void
    {
        $out = $this->wiki->convert('Use {braces} and [brackets] and a | pipe.');

        self::assertStringContainsString('\\{braces\\}', $out);
        self::assertStringContainsString('\\[brackets\\]', $out);
        self::assertStringContainsString('\\|', $out);
    }

    public function testATextValueIsEscapedNeverParsed(): void
    {
        $out = $this->wiki->fromFormat("# not a heading\n\n**not bold**", 'text');

        self::assertSame("# not a heading\n\n**not bold**", $out);
        self::assertStringNotContainsString('h1.', $out);
    }

    public function testAnEmptyValueGivesNothing(): void
    {
        self::assertSame('', $this->wiki->fromFormat('   ', 'markdown'));
    }
}
