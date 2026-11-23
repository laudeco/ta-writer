<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\JsonPatchApplier;
use PHPUnit\Framework\TestCase;

final class JsonPatchApplierTest extends TestCase
{
    /**
     * @param array<string, mixed> $doc
     * @param list<array<string, mixed>> $ops
     *
     * @return array<string, mixed>
     */
    private function apply(array $doc, array $ops): array
    {
        return (new JsonPatchApplier())->apply($doc, $ops);
    }

    public function testAddInsertsAndAppendsInLists(): void
    {
        $doc = ['items' => ['a', 'c']];

        self::assertSame(['items' => ['a', 'b', 'c']], $this->apply($doc, [['op' => 'add', 'path' => '/items/1', 'value' => 'b']]));
        self::assertSame(['items' => ['a', 'c', 'd']], $this->apply($doc, [['op' => 'add', 'path' => '/items/-', 'value' => 'd']]));
    }

    public function testReplaceRequiresAnExistingTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->apply(['a' => 1], [['op' => 'replace', 'path' => '/b', 'value' => 2]]);
    }

    public function testRemoveAndMoveReorderLists(): void
    {
        $doc = ['items' => ['a', 'b', 'c']];

        self::assertSame(['items' => ['a', 'c']], $this->apply($doc, [['op' => 'remove', 'path' => '/items/1']]));
        self::assertSame(['items' => ['c', 'a', 'b']], $this->apply($doc, [['op' => 'move', 'from' => '/items/2', 'path' => '/items/0']]));
    }

    public function testCopyAndTest(): void
    {
        $doc = ['a' => ['x' => 1]];

        self::assertSame(['a' => ['x' => 1], 'b' => ['x' => 1]], $this->apply($doc, [['op' => 'copy', 'from' => '/a', 'path' => '/b']]));
        $this->expectException(\InvalidArgumentException::class);
        $this->apply($doc, [['op' => 'test', 'path' => '/a/x', 'value' => 2]]);
    }

    public function testEscapedSegmentsAndInvalidPointers(): void
    {
        self::assertSame(['a/b' => 2], $this->apply(['a/b' => 1], [['op' => 'replace', 'path' => '/a~1b', 'value' => 2]]));

        $this->expectException(\InvalidArgumentException::class);
        $this->apply(['a' => 1], [['op' => 'add', 'path' => 'no-slash', 'value' => 1]]);
    }
}
