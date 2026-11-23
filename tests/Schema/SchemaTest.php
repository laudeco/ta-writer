<?php

declare(strict_types=1);

namespace App\Tests\Schema;

use App\Schema\SchemaProvider;
use App\Schema\SchemaValidationException;
use App\Schema\SchemaValidator;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private const SCHEMA = __DIR__.'/../../config/schema/document.openapi.yaml';

    /** @return array<string, mixed> */
    private function minimal(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../fixtures/minimal-document.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testResolvedSchemaHasNoReferenceLeft(): void
    {
        $json = json_encode((new SchemaProvider(self::SCHEMA))->getDocumentJsonSchema(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('$ref', $json);
        self::assertStringNotContainsString('x-widget', $json);
    }

    public function testMinimalDocumentIsValid(): void
    {
        (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($this->minimal());
        $this->addToAssertionCount(1);
    }

    public function testExtendedFieldsAreAccepted(): void
    {
        $doc = $this->minimal();
        $doc['epics'][0]['technicalStories'][0]['subtasks'][0]['featureToggle'] = 'demo_toggle';
        $doc['epics'][0]['technicalStories'][0]['subtasks'][0]['sqlImpact'] = ['nbColumns' => 3, 'nbRows' => 2];
        (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
        $this->addToAssertionCount(1);
    }

    public function testUnknownFormatAndMissingProjectAreReportedWithPaths(): void
    {
        $doc = $this->minimal();
        $doc['format'] = 'pdf';
        unset($doc['project']);
        // both problems are reported together, not only the first one

        try {
            (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
            self::fail('The document should be refused.');
        } catch (SchemaValidationException $e) {
            $paths = array_column($e->getErrors(), 'path');
            self::assertNotEmpty($e->getErrors());
            self::assertContains('/format', $paths);
        }
    }

    public function testSqlImpactRequiresBothCounters(): void
    {
        $doc = $this->minimal();
        $doc['epics'][0]['technicalStories'][0]['subtasks'][0]['sqlImpact'] = ['nbColumns' => 3];

        $this->expectException(SchemaValidationException::class);
        (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
    }

    public function testProvidedTechnicalAnalysisIsValid(): void
    {
        $doc = json_decode((string) file_get_contents(__DIR__.'/../../docs/ta-writer.document.json'), true, 512, JSON_THROW_ON_ERROR);
        (new SchemaValidator(new SchemaProvider(self::SCHEMA)))->validate($doc);
        $this->addToAssertionCount(1);
    }
}
