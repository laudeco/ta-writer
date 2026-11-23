<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\DocumentWriteBack;
use PHPUnit\Framework\TestCase;

final class DocumentWriteBackTest extends TestCase
{
    public function testFieldsAreSetAtTheirNodeAndNothingElseChanges(): void
    {
        $doc = ['dependencies' => [], 'epics' => [['title' => 'e', 'technicalStories' => [['title' => 't', 'subtasks' => [['title' => 's', 'what' => 'keep']]]]]]];

        $out = (new DocumentWriteBack())->apply($doc, [
            '/epics/0/technicalStories/0/subtasks/0' => ['ticket' => 'CFPOBT-1', 'pageLink' => 'https://x/pages/1'],
            '' => ['dependenciesPageLink' => 'https://x/pages/9'],
        ]);

        self::assertSame('CFPOBT-1', $out['epics'][0]['technicalStories'][0]['subtasks'][0]['ticket']);
        self::assertSame('keep', $out['epics'][0]['technicalStories'][0]['subtasks'][0]['what']);
        self::assertSame('https://x/pages/9', $out['dependenciesPageLink']);
    }

    public function testUnknownPathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DocumentWriteBack())->apply(['epics' => []], ['/epics/3' => ['ticket' => 'X-1']]);
    }
}
