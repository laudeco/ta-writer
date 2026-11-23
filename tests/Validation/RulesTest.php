<?php

declare(strict_types=1);

namespace App\Tests\Validation;

use App\Validation\BusinessValidationException;
use App\Validation\BusinessValidator;
use App\Validation\Rule\AcceptanceCriteriaCoverageRule;
use App\Validation\Rule\DependencyCycleRule;
use App\Validation\Rule\UniqueReferenceRule;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $ts
     * @param list<array<string, mixed>> $us
     *
     * @return array<string, mixed>
     */
    private function epic(array $ts = [], array $us = []): array
    {
        return ['title' => 'E', 'reference' => 'E1', 'userStories' => $us, 'technicalStories' => $ts];
    }

    public function testDuplicatedTechnicalStoryReferenceIsAnError(): void
    {
        $doc = ['epics' => [$this->epic([['reference' => 'TS1'], ['reference' => 'TS1']])]];
        $violations = (new UniqueReferenceRule())->check($doc);

        self::assertCount(1, $violations);
        self::assertSame('/epics/0/technicalStories/1/reference', $violations[0]->path);
    }

    public function testTechnicalStoryNumbersRestartInEachEpic(): void
    {
        $first = $this->epic([['reference' => 'TS1']]);
        $second = ['title' => 'E2', 'reference' => 'E2', 'userStories' => [], 'technicalStories' => [['reference' => 'TS1']]];

        self::assertSame([], (new UniqueReferenceRule())->check(['epics' => [$first, $second]]));
    }

    public function testDuplicatedEpicReferenceIsAnError(): void
    {
        $doc = ['epics' => [$this->epic(), $this->epic()]];
        $violations = (new UniqueReferenceRule())->check($doc);

        self::assertCount(1, $violations);
        self::assertSame('/epics/1/reference', $violations[0]->path);
    }

    public function testDependencyOnUnknownReferenceIsAnError(): void
    {
        $doc = ['epics' => [$this->epic([['reference' => 'TS1']])], 'dependencies' => [['from' => 'TS1', 'to' => 'TS9']]];
        $violations = (new DependencyCycleRule())->check($doc);

        self::assertCount(1, $violations);
        self::assertSame('/dependencies/0/to', $violations[0]->path);
    }

    public function testCycleIsReported(): void
    {
        $doc = [
            'epics' => [$this->epic([['reference' => 'TS1'], ['reference' => 'TS2'], ['reference' => 'TS3']])],
            'dependencies' => [['from' => 'TS1', 'to' => 'TS2'], ['from' => 'TS2', 'to' => 'TS3'], ['from' => 'TS3', 'to' => 'TS1']],
        ];
        $messages = array_map(static fn ($v) => $v->message, (new DependencyCycleRule())->check($doc));

        self::assertNotEmpty(array_filter($messages, static fn (string $m): bool => str_contains($m, 'Dependency cycle')));
    }

    public function testValidDagHasNoViolation(): void
    {
        $doc = [
            'epics' => [$this->epic([['reference' => 'TS1'], ['reference' => 'TS2']])],
            'dependencies' => [['from' => 'TS1', 'to' => 'TS2']],
        ];

        self::assertSame([], (new DependencyCycleRule())->check($doc));
    }

    public function testUncoveredCriterionIsAWarningAndDoesNotBlock(): void
    {
        $doc = ['epics' => [$this->epic(
            [['reference' => 'TS1', 'acceptanceCriteriaCovered' => ['AC1']]],
            [['reference' => 'US1', 'acceptanceCriteria' => ['AC1', 'AC2']]],
        )]];
        $violations = (new AcceptanceCriteriaCoverageRule())->check($doc);

        self::assertCount(1, $violations);
        self::assertSame('warning', $violations[0]->severity);
        (new BusinessValidator([new AcceptanceCriteriaCoverageRule()]))->assertValid($doc);
        $this->addToAssertionCount(1);
    }

    public function testErrorBlocksPublication(): void
    {
        $validator = new BusinessValidator([new UniqueReferenceRule(), new DependencyCycleRule()]);
        $doc = ['epics' => [$this->epic([['reference' => 'TS1'], ['reference' => 'TS1']])]];

        $this->expectException(BusinessValidationException::class);
        $validator->assertValid($doc);
    }
}
