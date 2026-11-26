<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Author\AuthorRepository;
use App\Render\ContentConverter;
use App\Schema\SchemaProvider;
use App\Schema\SchemaValidationException;
use App\Schema\SchemaValidator;
use App\Storage\AtomicFileWriter;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use PHPUnit\Framework\TestCase;

/**
 * A block added in the form and left empty is kept where the person put it, so the validation reports what it lacks:
 * nothing is removed in silence, and the error names the field.
 */
final class EmptyBlockTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../config/schema/document.openapi.yaml';

    /**
     * @return array<string, mixed>
     */
    private function submitted(): array
    {
        $doc = json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
        $titled = $doc['epics'][0]['technicalStories'][0]['subtasks'][0];
        // what the browser sends for a subtask added and never filled: its reference is the default, the rest is empty
        $empty = ['reference' => 'Subtask#2', 'title' => '', 'what' => ['original' => '', 'mode' => 'raw', 'html' => '', 'source' => ''], 'resources' => [], 'diagrams' => []];
        $doc['epics'][0]['technicalStories'][0]['subtasks'] = [$titled, $empty];

        return $doc;
    }

    public function testAnEmptyBlockKeepsItsPosition(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(self::SCHEMA)))->build();
        $normalizer = new FormDataNormalizer(new ContentConverter(), new AuthorRepository(sys_get_temp_dir().'/ta-empty-block.json', new AtomicFileWriter()));
        $out = $normalizer->normalize($this->submitted(), $root, 'markdown');

        $subtasks = $out['epics'][0]['technicalStories'][0]['subtasks'];
        self::assertCount(2, $subtasks, 'the empty block is still there');
        self::assertSame('Create the class', $subtasks[0]['title']);
        self::assertSame('Subtask#2', $subtasks[1]['reference']);
    }

    public function testTheMissingTitleOfTheEmptyBlockIsReportedOnTheField(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(self::SCHEMA)))->build();
        $normalizer = new FormDataNormalizer(new ContentConverter(), new AuthorRepository(sys_get_temp_dir().'/ta-empty-block.json', new AtomicFileWriter()));
        $doc = $normalizer->normalize($this->submitted(), $root, 'markdown');

        try {
            (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
            self::fail('an empty subtask title must be refused');
        } catch (SchemaValidationException $e) {
            $paths = array_column($e->getErrors(), 'path');
            self::assertContains('/epics/0/technicalStories/0/subtasks/1/title', $paths);
            self::assertNotContains('/epics/0/technicalStories/0/subtasks', $paths, 'the block itself is not the error');
        }
    }
}
