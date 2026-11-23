<?php

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Preview\SectionScope;
use PHPUnit\Framework\TestCase;

final class SectionScopeTest extends TestCase
{
    /** @return array<string, mixed> */
    private function raw(): array
    {
        return [
            'format' => 'markdown',
            'project' => ['name' => 'Demo', 'reference' => 'DEMO'],
            'author' => ['name' => 'A', 'email' => 'a@example.com'],
            'dependencies' => [['from' => 'TS1', 'to' => 'TS2']],
            'epics' => [
                ['title' => 'Epic one', 'reference' => 'E1', 'userStories' => [['title' => 'US1']], 'technicalStories' => [
                    ['title' => 'TS one', 'reference' => 'TS1', 'subtasks' => [['title' => 'S0'], ['title' => 'S1']]],
                    ['title' => 'TS two', 'reference' => 'TS2', 'subtasks' => [['title' => 'S0'], ['title' => 'S1'], ['title' => 'S2']]],
                ]],
                ['title' => 'Epic two', 'reference' => 'E2', 'technicalStories' => [['title' => 'TS three', 'reference' => 'TS3']]],
            ],
        ];
    }

    public function testTheDocumentIsKeptAsItIs(): void
    {
        $raw = $this->raw();

        $this->assertSame($raw, SectionScope::for($raw, 'document'));
    }

    public function testTheHeaderHasNoEpic(): void
    {
        $scoped = SectionScope::for($this->raw(), 'header');

        $this->assertIsArray($scoped);
        $this->assertSame([], $scoped['epics']);
        $this->assertSame('DEMO', $scoped['project']['reference']);
    }

    public function testAnEpicKeepsOnlyItself(): void
    {
        $scoped = SectionScope::for($this->raw(), 'epics.1');

        $this->assertIsArray($scoped);
        $this->assertCount(1, $scoped['epics']);
        $this->assertSame('E2', $scoped['epics'][0]['reference']);
        $this->assertSame([], $scoped['dependencies']);
    }

    public function testATechnicalStoryKeepsItsEpicAndOnlyItself(): void
    {
        $scoped = SectionScope::for($this->raw(), 'epics.0.technicalStories.1');

        $this->assertIsArray($scoped);
        $epic = $scoped['epics'][0];
        $this->assertSame('E1', $epic['reference']);
        $this->assertSame([], $epic['userStories']);
        $this->assertCount(1, $epic['technicalStories']);
        $this->assertSame('TS2', $epic['technicalStories'][0]['reference']);
        $this->assertSame([], $epic['technicalStories'][0]['subtasks']);
    }

    public function testASubtaskKeepsItsChainAndOnlyItself(): void
    {
        $scoped = SectionScope::for($this->raw(), 'epics.0.technicalStories.1.subtasks.2');

        $this->assertIsArray($scoped);
        $subtasks = $scoped['epics'][0]['technicalStories'][0]['subtasks'];
        $this->assertCount(1, $subtasks);
        $this->assertSame('S2', $subtasks[0]['title']);
    }

    public function testAMissingSectionIsNull(): void
    {
        $this->assertNull(SectionScope::for($this->raw(), 'epics.5'));
        $this->assertNull(SectionScope::for($this->raw(), 'epics.0.technicalStories.9'));
        $this->assertNull(SectionScope::for($this->raw(), 'epics'));
    }
}
