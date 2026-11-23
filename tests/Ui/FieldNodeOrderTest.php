<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Form\FieldNode;
use PHPUnit\Framework\TestCase;

final class FieldNodeOrderTest extends TestCase
{
    /** @param list<string> $names */
    private function object(array $names): FieldNode
    {
        $children = array_map(static fn (string $name): FieldNode => new FieldNode($name, 'text', false, null, null), $names);

        return new FieldNode('item', 'object', false, null, null, [], $children);
    }

    /**
     * @param list<FieldNode> $nodes
     *
     * @return list<string>
     */
    private function names(array $nodes): array
    {
        return array_map(static fn (FieldNode $node): string => $node->name, $nodes);
    }

    public function testTheReferenceComesRightUnderTheTitle(): void
    {
        // the subtask of the schema: the reference is last
        $node = $this->object(['title', 'resources', 'what', 'how', 'ticket', 'reference', 'criterion']);

        $this->assertSame(['title', 'reference', 'resources', 'what', 'how', 'ticket', 'criterion'], $this->names($node->displayChildren()));
    }

    public function testAReferenceAboveTheTitleIsMovedBelowIt(): void
    {
        $node = $this->object(['reference', 'title', 'what']);

        $this->assertSame(['title', 'reference', 'what'], $this->names($node->displayChildren()));
    }

    public function testAnObjectAlreadyInOrderIsUnchanged(): void
    {
        $node = $this->object(['title', 'reference', 'what']);

        $this->assertSame(['title', 'reference', 'what'], $this->names($node->displayChildren()));
    }

    public function testTheProjectNameIsUsedAsTheTitle(): void
    {
        $node = $this->object(['name', 'link', 'reference']);

        $this->assertSame(['name', 'reference', 'link'], $this->names($node->displayChildren()));
    }

    public function testWithoutAReferenceOrATitleNothingChanges(): void
    {
        $this->assertSame(['what', 'title'], $this->names($this->object(['what', 'title'])->displayChildren()));
        $this->assertSame(['title', 'what'], $this->names($this->object(['title', 'what'])->displayChildren()));
    }

    public function testTheSchemaOrderOfTheChildrenIsKept(): void
    {
        $node = $this->object(['title', 'resources', 'reference']);
        $node->displayChildren();

        $this->assertSame(['title', 'resources', 'reference'], $this->names($node->children));
    }
}
