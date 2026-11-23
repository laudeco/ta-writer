<?php

declare(strict_types=1);

namespace App\Tests\Publication\Confluence;

use App\Publication\Confluence\PageBodyBuilder;
use App\Publication\Confluence\StorageFormatBuilder;
use App\Render\ContentConverter;
use PHPUnit\Framework\TestCase;

final class PageBodyBuilderTest extends TestCase
{
    public function testSubtaskPageUsesTheTemplateLayoutAndIsWellFormed(): void
    {
        $sub = [
            'displayTitle' => 'DEMO1 E1 TS1 Subtask#1 - Create the class', 'what' => 'Create it.', 'why' => 'Needed. Because of the storage.',
            'where' => 'src/X.php', 'how' => "- [ ] step one", 'featureToggle' => 'demo', 'sqlImpact' => ['nbColumns' => 2, 'nbRows' => 5],
            'criterion' => 'AC1', 'diagrams' => [], 'resources' => [], 'ticket' => 'CFPOBT-7',
        ];
        $builder = new PageBodyBuilder(new StorageFormatBuilder(new ContentConverter()));
        $storage = $builder->subtask($sub, 'markdown', [], 'DEMO1 E1 TS1 - Storage', 'CFPOBT-6');

        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadXML('<root xmlns:ac="urn:ac" xmlns:ri="urn:ri">'.$storage.'</root>'));
        self::assertStringContainsString('ac:name="panel"', $storage);
        self::assertStringContainsString('darkorange', $storage, 'the Why panel is added because the template has none');
        self::assertStringContainsString('ac:layout-section ac:type="two_left_sidebar"', $storage);
        self::assertStringContainsString('CFPOBT-6', $storage);
    }

    public function testDependenciesLinkToPagesAndPlainTextWithoutOne(): void
    {
        $builder = new PageBodyBuilder(new StorageFormatBuilder(new ContentConverter()));
        $html = $builder->dependencies([
            ['fromTitle' => 'A', 'toTitle' => 'B', 'fromLink' => 'https://x/a', 'toLink' => null],
        ]);

        self::assertStringContainsString('<a href="https://x/a">A</a>', $html);
        self::assertStringContainsString('B is blocked by', $html);
    }
}
