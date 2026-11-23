<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Render\ContentConverter;
use App\Ui\Preview\PreviewDiagrams;
use PHPUnit\Framework\TestCase;

final class PreviewDiagramsTest extends TestCase
{
    public function testAMermaidBlockBecomesAPendingPlaceholderWithTheHashOfItsSource(): void
    {
        $source = "flowchart TD\n    A --> B & C";
        $html = (new ContentConverter())->toHtml("Intro\n\n```mermaid\n".$source."\n```", 'markdown');

        $out = PreviewDiagrams::placeholders($html);

        $this->assertSame(1, substr_count($out, 'is-pending'));
        $this->assertStringContainsString('data-source-hash="'.hash('sha256', trim($source)).'"', $out);
        // the source stays in the placeholder, escaped, so the browser can ask for its image
        $this->assertStringContainsString('<code class="language-mermaid">', $out);
        $this->assertStringNotContainsString('<pre><code class="language-mermaid">', $out);
    }

    public function testOtherCodeBlocksAreLeftAlone(): void
    {
        $html = (new ContentConverter())->toHtml("```php\necho 1;\n```", 'markdown');

        $this->assertSame($html, PreviewDiagrams::placeholders($html));
    }
}
