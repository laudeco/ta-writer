<?php

declare(strict_types=1);

namespace App\Tests\Reference;

use App\Reference\ReferenceResolver;
use PHPUnit\Framework\TestCase;

final class ReferenceResolverTest extends TestCase
{
    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'project' => ['name' => 'Demo', 'reference' => 'ITC1615'],
            'epics' => [[
                'reference' => 'E3', 'title' => 'Happy flow',
                'userStories' => [['reference' => 'US1', 'title' => 'Customer']],
                'technicalStories' => [[
                    'reference' => 'TS3', 'title' => 'Radius polling',
                    'subtasks' => [['title' => 'Add the poller']],
                ]],
            ]],
        ];
    }

    public function testEachLevelIsPrefixedTopToBottom(): void
    {
        $r = new ReferenceResolver();
        $doc = $this->document();

        self::assertSame('ITC1615 E3', $r->epic($doc, 0));
        self::assertSame('ITC1615 E3 US1', $r->userStory($doc, 0, 0));
        self::assertSame('ITC1615 E3 TS3', $r->technicalStory($doc, 0, 0));
        self::assertSame('ITC1615 E3 TS3 Subtask#1', $r->subtask($doc, 0, 0, 0));
    }

    public function testTitleIsReferenceThenDashThenTitle(): void
    {
        $r = new ReferenceResolver();

        self::assertSame('ITC1615 E3 TS3 - Radius polling', $r->title($r->technicalStory($this->document(), 0, 0), 'Radius polling'));
        self::assertSame('Bare title', $r->title('', 'Bare title'));
    }

    public function testMissingReferencesAreGeneratedFromPosition(): void
    {
        $doc = ['project' => ['name' => 'Demo', 'reference' => 'P'], 'epics' => [['title' => 'x', 'technicalStories' => [['title' => 'y']]]]];
        $r = new ReferenceResolver();

        self::assertSame('P E1', $r->epic($doc, 0));
        self::assertSame('P E1 TS1', $r->technicalStory($doc, 0, 0));
    }
}
