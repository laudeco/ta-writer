<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Storage\History\DocumentDiffer;
use App\Storage\TaRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VersionUiController extends AbstractController
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly DocumentDiffer $differ,
    ) {
    }

    #[Route('/documents/{id}/versions', name: 'ui_versions', methods: ['GET'])]
    public function list(string $id): Response
    {
        $metas = $this->repository->versions($id);
        $rows = [];
        foreach ($metas as $i => $meta) {
            $rows[] = ['meta' => $meta, 'previous' => $i > 0 ? $metas[$i - 1]->number : null];
        }
        $current = $metas === [] ? 0 : end($metas)->number;

        return $this->render('ui/versions/list.html.twig', ['id' => $id, 'rows' => $rows, 'current' => $current]);
    }

    #[Route('/documents/{id}/versions/{number}', name: 'ui_version_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(string $id, int $number): Response
    {
        $data = $this->repository->getVersionData($id, $number);
        $current = $this->currentNumber($id);

        return $this->render('ui/versions/show.html.twig', [
            'id' => $id,
            'number' => $number,
            'current' => $current,
            'json' => (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    #[Route('/documents/{id}/versions/{from}/diff/{to}', name: 'ui_version_diff', requirements: ['from' => '\d+', 'to' => '\d+'], methods: ['GET'])]
    public function diff(string $id, int $from, int $to): Response
    {
        $changes = $this->differ->diff(
            $this->repository->getVersionData($id, $from),
            $this->repository->getVersionData($id, $to),
        );

        return $this->render('ui/versions/diff.html.twig', ['id' => $id, 'from' => $from, 'to' => $to, 'changes' => $changes]);
    }

    /**
     * Restores as a new version: nothing is deleted, the restore itself is in the history.
     */
    #[Route('/documents/{id}/versions/{number}/restore', name: 'ui_version_restore', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function restore(string $id, int $number): Response
    {
        $stored = $this->repository->restore($id, $number, 'restore');
        $this->addFlash('success', sprintf('Version %d restored as version %d.', $number, $stored->version));

        return $this->redirectToRoute('ui_edit', ['id' => $id]);
    }

    private function currentNumber(string $id): int
    {
        $metas = $this->repository->versions($id);

        return $metas === [] ? 0 : end($metas)->number;
    }
}
