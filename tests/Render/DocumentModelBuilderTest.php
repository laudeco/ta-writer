<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Reference\ReferenceResolver;
use App\Render\DocumentModelBuilder;
use PHPUnit\Framework\TestCase;

final class DocumentModelBuilderTest extends TestCase
{
    public function testSqlImpactIsSummedPerTechnicalStoryAndInTotal(): void
    {
        $doc = [
            'project' => ['name' => 'P', 'reference' => 'P'],
            'epics' => [['title' => 'e', 'reference' => 'E1', 'technicalStories' => [[
                'title' => 't', 'reference' => 'TS1', 'subtasks' => [
                    ['title' => 'a', 'sqlImpact' => ['nbColumns' => 2, 'nbRows' => 3]],
                    ['title' => 'b', 'sqlImpact' => ['nbColumns' => 1, 'nbRows' => 4]],
                    ['title' => 'c'],
                ],
            ], ['title' => 'empty', 'reference' => 'TS2', 'subtasks' => []]]]],
        ];
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($doc);

        self::assertSame(['nbColumns' => 3, 'nbRows' => 7], $model['epics'][0]['technicalStories'][0]['sqlImpact']);
        self::assertNull($model['epics'][0]['technicalStories'][1]['sqlImpact']);
        self::assertSame(['nbColumns' => 3, 'nbRows' => 7], $model['sqlImpact']['total']);
    }

    public function testFeatureTogglesAreDistinctPerTechnicalStory(): void
    {
        $doc = [
            'project' => ['name' => 'P', 'reference' => 'P'],
            'epics' => [['title' => 'e', 'reference' => 'E1', 'technicalStories' => [[
                'title' => 't', 'reference' => 'TS1', 'subtasks' => [
                    ['title' => 'a', 'featureToggle' => 'x'],
                    ['title' => 'b', 'featureToggle' => 'x'],
                    ['title' => 'c', 'featureToggle' => 'y'],
                ],
            ]]]],
        ];
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($doc);

        self::assertSame(['x', 'y'], $model['epics'][0]['technicalStories'][0]['featureToggles']);
    }

    public function testEveryNodeKeepsItsJsonPathForWriteBack(): void
    {
        $doc = ['project' => ['name' => 'P', 'reference' => 'P'], 'epics' => [['title' => 'e', 'reference' => 'E1', 'technicalStories' => [['title' => 't', 'reference' => 'TS1', 'subtasks' => [['title' => 's']]]]]]];
        $model = (new DocumentModelBuilder(new ReferenceResolver()))->build($doc);

        self::assertSame('/epics/0', $model['epics'][0]['path']);
        self::assertSame('/epics/0/technicalStories/0/subtasks/0', $model['epics'][0]['technicalStories'][0]['subtasks'][0]['path']);
    }
}
