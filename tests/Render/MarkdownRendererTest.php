<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Reference\ReferenceResolver;
use App\Render\ContentConverter;
use App\Render\DocumentModelBuilder;
use App\Render\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function render(array $document): string
    {
        return (new MarkdownRenderer(new ContentConverter()))->render((new DocumentModelBuilder(new ReferenceResolver()))->build($document));
    }

    public function testTitlesCarryTheirReferenceAndDependenciesLinkToPages(): void
    {
        $doc = $this->fixture();
        $doc['epics'][0]['technicalStories'][0]['pageLink'] = 'https://wiki.example/pages/1';
        $doc['epics'][0]['technicalStories'][] = ['title' => 'Other', 'reference' => 'TS2', 'subtasks' => []];
        $doc['dependencies'] = [['from' => 'TS1', 'to' => 'TS2']];

        $markdown = $this->render($doc);

        self::assertStringContainsString('## DEMO1 E1 - Epic one', $markdown);
        self::assertStringContainsString('#### DEMO1 E1 TS1 Subtask#1 - Create the class', $markdown);
        self::assertStringContainsString('```mermaid', $markdown);
        self::assertStringContainsString('is blocked by [DEMO1 E1 TS1 - Storage](https://wiki.example/pages/1)', $markdown);
    }

    public function testCalculatedSectionsArePresent(): void
    {
        $markdown = $this->render($this->fixture());

        self::assertStringContainsString('## Acceptance criteria coverage', $markdown);
        self::assertStringContainsString('| AC1 — First criterion | DEMO1 E1 TS1 - Storage |', $markdown);
        self::assertStringContainsString('| AC2 — Second criterion | **not covered** |', $markdown);
        self::assertStringContainsString('## SQL impact', $markdown);
        self::assertStringContainsString('## Dependencies', $markdown);
    }

    public function testDependencyWithoutPageLinkIsPlainText(): void
    {
        $doc = $this->fixture();
        $doc['epics'][0]['technicalStories'][] = ['title' => 'Other', 'reference' => 'TS2', 'subtasks' => []];
        $doc['dependencies'] = [['from' => 'TS1', 'to' => 'TS2']];

        self::assertStringContainsString('- DEMO1 E1 TS2 - Other is blocked by DEMO1 E1 TS1 - Storage', $this->render($doc));
    }

    public function testAnchorsFollowGithubRules(): void
    {
        $renderer = new MarkdownRenderer(new ContentConverter());

        // same rules as GitHub: the ' - ' separator leaves three hyphens
        self::assertSame('demo1-e1-ts1---storage', $renderer->anchor('DEMO1 E1 TS1 - Storage'));
        self::assertSame('demo1-e1-ts1-subtask1---create-the-class', $renderer->anchor('DEMO1 E1 TS1 Subtask#1 - Create the class'));
    }
}
