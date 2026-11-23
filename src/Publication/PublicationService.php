<?php

declare(strict_types=1);

namespace App\Publication;

use App\Schema\SchemaValidator;
use App\Storage\TaRepositoryInterface;
use App\Validation\BusinessValidator;

/**
 * Every publication goes through here: the document is validated against the schema and the business rules first,
 * so an invalid analysis is never exported, whatever the format or the entry point (CLI, UI, API).
 */
final class PublicationService
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly SchemaValidator $schema,
        private readonly BusinessValidator $business,
        private readonly PublisherRegistry $registry,
    ) {
    }

    /**
     * @param array<string, string> $options
     */
    public function publish(string $documentId, string $format, array $options, bool $dryRun): PublishResult
    {
        $stored = $this->repository->get($documentId);
        $this->schema->validate($stored->data);
        $this->business->assertValid($stored->data);

        return $this->registry->get($format)->publish(
            new PublishRequest($documentId, $stored->data, $options, $dryRun),
        );
    }

    /**
     * @return list<OptionDefinition>
     */
    public function optionsFor(string $format): array
    {
        return $this->registry->get($format)->getOptionDefinitions();
    }
}
