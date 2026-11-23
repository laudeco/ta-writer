<?php

declare(strict_types=1);

namespace App\Tests\Publication\Confluence;

use App\Publication\Confluence\StorageFormatBuilder;
use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

final class StorageFormatBuilderTest extends TestCase
{
    private function assertWellFormed(string $storage): void
    {
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadXML('<root xmlns:ac="urn:ac" xmlns:ri="urn:ri">'.$storage.'</root>'), 'storage must be well-formed XML');
    }

    public function testCodeTasksAndDiagramsBecomeConfluenceMacros(): void
    {
        $markdown = "Intro paragraph.\n\n```php\necho 'x';\n```\n\n- [ ] first step\n- [x] second step\n\n```mermaid\nclassDiagram\n    class A\n```";
        $storage = (new StorageFormatBuilder(new ContentConverter()))->content($markdown, 'markdown', ["classDiagram\n    class A" => 'st1-1.png']);

        $this->assertWellFormed($storage);
        self::assertStringContainsString('<ac:parameter ac:name="language">php</ac:parameter>', $storage);
        self::assertStringContainsString('<ac:task-status>incomplete</ac:task-status>', $storage);
        self::assertStringContainsString('<ac:task-status>complete</ac:task-status>', $storage);
        self::assertStringContainsString('<ri:attachment ri:filename="st1-1.png" />', $storage);
    }

    public function testMermaidWithoutAnAttachmentIsSaidSo(): void
    {
        $storage = (new StorageFormatBuilder(new ContentConverter()))->content("```mermaid\nflowchart LR\n```", 'markdown');

        self::assertStringContainsString('Diagram not attached', $storage);
    }

    public function testCdataEndMarkerInCodeIsSplit(): void
    {
        $storage = (new StorageFormatBuilder(new ContentConverter()))->content("```sql\nSELECT ']]>';\n```", 'markdown');

        $this->assertWellFormed($storage);
    }
}
