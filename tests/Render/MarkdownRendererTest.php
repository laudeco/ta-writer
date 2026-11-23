<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Reference\ReferenceResolver;
use App\Render\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testTitlesCarryTheirReferenceAndDependenciesLinkToPages(): void
    {
        $doc = json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
        $doc['epics'][0]['technicalStories'][0]['pageLink'] = 'https://wiki.example/pages/1';
        $doc['epics'][0]['technicalStories'][] = ['title' => 'Other', 'reference' => 'TS2', 'subtasks' => []];
        $doc['dependencies'] = [['from' => 'TS1', 'to' => 'TS2']];

        $markdown = (new MarkdownRenderer(new ReferenceResolver()))->render($doc);

        self::assertStringContainsString('## DEMO1 E1 - Epic one', $markdown);
        self::assertStringContainsString('#### DEMO1 E1 TS1 Subtask#1 - Create the class', $markdown);
        self::assertStringContainsString('```mermaid', $markdown);
        self::assertStringContainsString('## Dependencies', $markdown);
        self::assertStringContainsString('is blocked by [DEMO1 E1 TS1 - Storage](https://wiki.example/pages/1)', $markdown);
    }

    public function testDependencyWithoutPageLinkIsPlainText(): void
    {
        $doc = ['project' => ['name' => 'P', 'reference' => 'P'], 'epics' => [['title' => 'e', 'reference' => 'E1', 'technicalStories' => [['title' => 't', 'reference' => 'TS1']]]], 'dependencies' => [['from' => 'TS1', 'to' => 'TS1']]];
        $lines = (new MarkdownRenderer(new ReferenceResolver()))->dependencyLines($doc);

        self::assertSame(['- P E1 TS1 - t is blocked by P E1 TS1 - t'], $lines);
    }
}
