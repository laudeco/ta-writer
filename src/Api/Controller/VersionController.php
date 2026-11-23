<?php

declare(strict_types=1);

namespace App\Api\Controller;

use App\Storage\History\DocumentDiffer;
use App\Storage\TaRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class VersionController extends AbstractController
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly DocumentDiffer $differ,
    ) {
    }

    #[Route('/api/documents/{id}/versions', name: 'api_versions_list', methods: ['GET'])]
    public function list(string $id): JsonResponse
    {
        $versions = array_map(static fn ($meta): array => $meta->toArray(), $this->repository->versions($id));

        return $this->json(['items' => $versions]);
    }

    #[Route('/api/documents/{id}/versions/{number}', name: 'api_versions_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(string $id, int $number): JsonResponse
    {
        return $this->json($this->repository->getVersionData($id, $number));
    }

    #[Route('/api/documents/{id}/versions/{from}/diff/{to}', name: 'api_versions_diff', requirements: ['from' => '\d+', 'to' => '\d+'], methods: ['GET'])]
    public function diff(string $id, int $from, int $to): JsonResponse
    {
        $before = $this->repository->getVersionData($id, $from);
        $after = $this->repository->getVersionData($id, $to);

        return $this->json([
            'from' => $from,
            'to' => $to,
            'changes' => $this->differ->diff($before, $after),
        ]);
    }

    #[Route('/api/documents/{id}/versions/{number}/restore', name: 'api_versions_restore', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function restore(string $id, int $number): JsonResponse
    {
        $stored = $this->repository->restore($id, $number, 'restore');

        return $this->json($stored->data, 200, ['ETag' => sprintf('"%d"', $stored->version)]);
    }
}
