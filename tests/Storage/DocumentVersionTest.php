<?php

declare(strict_types=1);

namespace App\Tests\Storage;

use App\Storage\DocumentVersion;
use PHPUnit\Framework\TestCase;

final class DocumentVersionTest extends TestCase
{
    public function testCreationStartsAt100AndTakesTheStructureVersionFromTheServer(): void
    {
        $data = DocumentVersion::prepare(null, ['version' => '9.9.9', 'structureVersion' => '0.0.1', 'x' => 1], 'api', '1.0.0');

        self::assertSame('1.0.0', $data['version'], 'the version sent by a client is ignored');
        self::assertSame('1.0.0', $data['structureVersion']);
    }

    public function testAChangeByAPersonIncrementsThePatchNumber(): void
    {
        $current = ['version' => '1.0.4', 'structureVersion' => '1.0.0', 'what' => 'a'];

        $data = DocumentVersion::prepare($current, ['version' => '1.0.4', 'what' => 'b'], 'ui', '1.0.0');

        self::assertSame('1.0.5', $data['version']);
    }

    public function testSameContentKeepsTheVersion(): void
    {
        $current = ['version' => '1.0.4', 'what' => 'a'];

        self::assertSame('1.0.4', DocumentVersion::prepare($current, ['what' => 'a'], 'api', '1.0.0')['version']);
    }

    public function testAnAdapterWriteBackKeepsTheVersion(): void
    {
        $current = ['version' => '1.0.4', 'what' => 'a'];

        self::assertSame('1.0.4', DocumentVersion::prepare($current, ['what' => 'a', 'ticket' => 'CFPOBT-1'], 'agir', '1.0.0')['version']);
        self::assertSame('1.0.4', DocumentVersion::prepare($current, ['what' => 'a', 'pageLink' => 'x'], 'confluence', '1.0.0')['version']);
    }

    public function testStructureVersionIsAlwaysTheServerOne(): void
    {
        $data = DocumentVersion::prepare(['version' => '1.0.0', 'structureVersion' => '1.0.0'], ['structureVersion' => '7.0.0'], 'ui', '1.0.0');

        self::assertSame('1.0.0', $data['structureVersion']);
    }

    public function testBumpPatch(): void
    {
        self::assertSame('1.2.10', DocumentVersion::bumpPatch('1.2.9'));
        self::assertSame('1.0.0', DocumentVersion::bumpPatch('not-a-version'));
    }
}
