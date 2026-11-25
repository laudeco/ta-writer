<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Jira\JiraDescriptionBuilder;
use App\Jira\MarkdownToJiraWiki;
use App\Render\ContentConverter;
use App\Render\DocumentModelBuilder;
use App\Render\MarkdownRenderer;
use App\Reference\ReferenceResolver;
use App\Schema\SchemaProvider;
use App\Schema\SchemaValidationException;
use App\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The optional `context` of an epic, a technical story and a sub-task: a one-line summary of its What, shown above it.
 */
final class ContextSummaryTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../config/schema/document.openapi.yaml';

    /** @return array<string, mixed> */
    private function document(): array
    {
        $doc = json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
        $doc['epics'][0]['context'] = 'The epic in short.';
        $doc['epics'][0]['technicalStories'][0]['context'] = 'The story in short.';
        $doc['epics'][0]['technicalStories'][0]['subtasks'][0]['context'] = 'The subtask in short.';

        return $doc;
    }

    public function testTheModelCarriesTheContextOfEveryLevel(): void
    {
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($this->document());
        $epic = $model['epics'][0];

        self::assertSame('The epic in short.', $epic['context']);
        self::assertSame('The story in short.', $epic['technicalStories'][0]['context']);
        self::assertSame('The subtask in short.', $epic['technicalStories'][0]['subtasks'][0]['context']);
    }

    public function testAMissingContextIsAnEmptyString(): void
    {
        $doc = $this->document();
        unset($doc['epics'][0]['context']);
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($doc);

        self::assertSame('', $model['epics'][0]['context']);
    }

    public function testTheMarkdownShowsTheSummaryAboveTheWhat(): void
    {
        $markdown = (new MarkdownRenderer(new ContentConverter()))->render((new DocumentModelBuilder(new ReferenceResolver()))->build($this->document()));

        self::assertStringContainsString("*The epic in short.*\n\n**Context**", $markdown);
        self::assertStringContainsString('*The story in short.*', $markdown);
        self::assertStringContainsString('*The subtask in short.*', $markdown);
    }

    public function testASpecialCharacterOfTheSummaryIsEscaped(): void
    {
        $doc = $this->document();
        $doc['epics'][0]['context'] = 'Use *this* and _that_';
        $markdown = (new MarkdownRenderer(new ContentConverter()))->render((new DocumentModelBuilder(new ReferenceResolver()))->build($doc));

        self::assertStringContainsString('*Use \\*this\\* and \\_that\\_*', $markdown);
    }

    public function testTheJiraDescriptionStartsWithTheSummary(): void
    {
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($this->document());
        $description = (new JiraDescriptionBuilder(new MarkdownToJiraWiki(new ContentConverter())))->epic($model['epics'][0], 'markdown', []);

        self::assertStringStartsWith('_The epic in short._', $description);
    }

    public function testTheSchemaAcceptsAStringAndRefusesAnotherType(): void
    {
        $validator = new SchemaValidator(new SchemaProvider(self::SCHEMA));
        $validator->validate($this->document());

        $doc = $this->document();
        $doc['epics'][0]['context'] = 42;
        $this->expectException(SchemaValidationException::class);
        $validator->validate($doc);
    }

    public function testADocumentWithoutContextStaysValid(): void
    {
        $doc = $this->document();
        unset($doc['epics'][0]['context'], $doc['epics'][0]['technicalStories'][0]['context']);
        (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
        $this->addToAssertionCount(1);
    }

    public function testTheSchemaVersionIsTheNewOne(): void
    {
        self::assertSame('1.1.0', (new SchemaProvider(self::SCHEMA))->getOpenApi()['info']['version']);
    }
}
