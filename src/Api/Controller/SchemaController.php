<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Schema\SchemaProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SchemaController extends AbstractController
{
    public function __construct(
        private readonly SchemaProvider $provider,
        private readonly string $schemaPath,
    ) {
    }

    #[Route('/api/schema', name: 'api_schema_json', methods: ['GET'])]
    public function jsonSchema(): JsonResponse
    {
        return $this->json($this->provider->getDocumentJsonSchema());
    }

    #[Route('/api/openapi', name: 'api_schema_openapi', methods: ['GET'])]
    public function openApi(): Response
    {
        return new Response((string) file_get_contents($this->schemaPath), 200, ['Content-Type' => 'application/yaml']);
    }
}
