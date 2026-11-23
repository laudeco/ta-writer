<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\History\DocumentDiffer;
use PHPUnit\Framework\TestCase;

final class DocumentDifferTest extends TestCase
{
    public function testAddedRemovedAndChangedAreReportedWithPointers(): void
    {
        $diff = (new DocumentDiffer())->diff(
            ['a' => 1, 'b' => ['x' => 'old'], 'gone' => true],
            ['a' => 2, 'b' => ['x' => 'old', 'y' => 'new'], 'added' => 3],
        );
        $byPath = [];
        foreach ($diff as $entry) {
            $byPath[$entry['path']] = $entry['type'];
        }

        ksort($byPath);
        self::assertSame(['/a' => 'changed', '/added' => 'added', '/b/y' => 'added', '/gone' => 'removed'], $byPath);
    }

    public function testSlashAndTildeAreEscapedInPointers(): void
    {
        $paths = (new DocumentDiffer())->changedPaths(['a/b' => 1, 'c~d' => 1], ['a/b' => 2, 'c~d' => 2]);

        self::assertSame(['/a~1b', '/c~0d'], $paths);
    }

    public function testChangedPathsAreCapped(): void
    {
        $before = array_fill_keys(range(1, 30), 'x');
        $after = array_fill_keys(range(1, 30), 'y');

        $paths = (new DocumentDiffer())->changedPaths($before, $after);

        self::assertCount(DocumentDiffer::MAX_CHANGED_PATHS + 1, $paths);
        self::assertSame('…', end($paths));
    }
}
