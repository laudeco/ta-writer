<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Api\JsonPatchApplier;
use App\Schema\SchemaValidationException;
use App\Schema\SchemaValidator;
use App\Storage\StoredDocument;
use App\Storage\TaRepositoryInterface;
use App\Validation\BusinessValidator;
use App\Validation\Violation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;

final class DocumentController extends AbstractController
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly SchemaValidator $schema,
        private readonly BusinessValidator $business,
        private readonly JsonPatchApplier $patcher,
    ) {
    }

    #[Route('/api/documents', name: 'api_documents_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return $this->json(['items' => $this->repository->list()]);
    }

    #[Route('/api/documents', name: 'api_documents_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();
        $this->checkDocument($data);

        $stored = $this->repository->save(null, $data, 'api');

        return $this->documentResponse($stored, 201, '/api/documents/'.$stored->id);
    }

    #[Route('/api/documents/{id}', name: 'api_documents_show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        return $this->documentResponse($this->repository->get($id));
    }

    #[Route('/api/documents/{id}', name: 'api_documents_replace', methods: ['PUT'])]
    public function replace(string $id, Request $request): JsonResponse
    {
        $expected = $this->expectedVersion($request);
        $data = $request->toArray();
        $this->checkDocument($data);

        return $this->documentResponse($this->repository->save($id, $data, 'api', $expected));
    }

    /**
     * Body: RFC 6902 operations (`Content-Type: application/json-patch+json`). Needs If-Match.
     */
    #[Route('/api/documents/{id}', name: 'api_documents_patch', methods: ['PATCH'])]
    public function patch(string $id, Request $request): JsonResponse
    {
        $expected = $this->expectedVersion($request);
        $operations = $request->toArray();
        $current = $this->repository->get($id);
        $patched = $this->patcher->apply($current->data, $operations);
        $this->checkDocument($patched);

        return $this->documentResponse($this->repository->save($id, $patched, 'api', $expected));
    }

    #[Route('/api/documents/{id}', name: 'api_documents_delete', methods: ['DELETE'])]
    public function delete(string $id): Response
    {
        $this->repository->delete($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Reports schema errors and business violations without saving anything.
     */
    #[Route('/api/documents/{id}/validate', name: 'api_documents_validate', methods: ['POST'])]
    public function validate(string $id): JsonResponse
    {
        $data = $this->repository->get($id)->data;
        $errors = [];
        try {
            $this->schema->validate($data);
        } catch (SchemaValidationException $e) {
            $errors = $e->getErrors();
        }
        $violations = array_map(static fn (Violation $v): array => $v->toArray(), $this->business->validate($data));

        return $this->json([
            'valid' => $errors === [] && !array_filter($violations, static fn (array $v): bool => $v['severity'] === 'error'),
            'schemaErrors' => $errors,
            'violations' => $violations,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function checkDocument(array $data): void
    {
        $this->schema->validate($data);
        $this->business->assertValid($data);
    }

    private function expectedVersion(Request $request): int
    {
        $header = $request->headers->get('If-Match');
        if ($header === null || $header === '') {
            throw new HttpException(428, 'The If-Match header with the current version is required.');
        }

        return (int) trim($header, " \t\"W/");
    }

    private function documentResponse(StoredDocument $stored, int $status = 200, ?string $location = null): JsonResponse
    {
        $response = $this->json($stored->data, $status, ['ETag' => sprintf('"%d"', $stored->version)]);
        if ($location !== null) {
            $response->headers->set('Location', $location);
        }

        return $response;
    }
}
