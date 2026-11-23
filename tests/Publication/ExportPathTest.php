<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\ExportPath;
use PHPUnit\Framework\TestCase;

final class ExportPathTest extends TestCase
{
    public function testNameIsProjectReferenceAndVersion(): void
    {
        $document = ['version' => '1.0.2', 'project' => ['reference' => 'TA-WRITER']];

        $this->assertSame(
            'data/exports/20261123/TA-WRITER_v1.0.2.pdf',
            ExportPath::for('data/exports', '20261123_ta-writer', $document, 'pdf'),
        );
    }

    public function testReferenceKeepsItsCaseAndReplacesOtherCharactersWithDashes(): void
    {
        $document = ['version' => '2.0.0', 'project' => ['reference' => 'ITC 1615/OBE']];

        $this->assertSame(
            'exports/20261123/ITC-1615-OBE_v2.0.0.md',
            ExportPath::for('exports/', '20261123_slug', $document, 'md'),
        );
    }

    public function testFallsBackToTheDocumentSlugWithoutReference(): void
    {
        $document = ['version' => '1.0.0', 'project' => ['name' => 'No reference']];

        $this->assertSame('x/20261123/ta-writer_v1.0.0.json', ExportPath::for('x', '20261123_ta-writer', $document, 'json'));
    }

    public function testVersionIsLeftOutWhenMissing(): void
    {
        $document = ['project' => ['reference' => 'TA-WRITER']];

        $this->assertSame('x/20261123/TA-WRITER.pdf', ExportPath::for('x', '20261123_ta-writer', $document, 'pdf'));
    }

    public function testExplicitOutputWins(): void
    {
        $document = ['version' => '1.0.0', 'project' => ['reference' => 'TA-WRITER']];

        $this->assertSame('/tmp/custom.pdf', ExportPath::for('x', '20261123_ta-writer', $document, 'pdf', '/tmp/custom.pdf'));
    }
}
