<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Publication\PublicationService;
use App\Publication\PublisherRegistry;
use App\Publication\PublishResult;
use App\Storage\TaRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publication page: choose the format, answer its questions, run a dry run, then confirm. Nothing is written in the
 * dry run; a confirmed run goes through the same service as the command.
 */
final class PublishUiController extends AbstractController
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly PublicationService $service,
        private readonly PublisherRegistry $registry,
    ) {
    }

    #[Route('/documents/{id}/publish', name: 'ui_publish', methods: ['GET'])]
    public function form(string $id, Request $request): Response
    {
        $this->repository->get($id);
        $format = (string) $request->query->get('format', 'markdown');

        return $this->page($id, $format, [], null);
    }

    #[Route('/documents/{id}/publish', name: 'ui_publish_run', methods: ['POST'])]
    public function run(string $id, Request $request): Response
    {
        $this->repository->get($id);
        $format = (string) $request->request->get('format', 'markdown');
        $answers = array_map('strval', $request->request->all('options'));
        $confirm = $request->request->getBoolean('confirm');

        try {
            $result = $this->service->publish($id, $format, $answers, !$confirm);
        } catch (\Throwable $e) {
            $result = new PublishResult('failed', [$e->getMessage()]);
        }

        return $this->page($id, $format, $answers, $result, $result->status === 'failed' ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * @param array<string, string> $answers
     */
    private function page(string $id, string $format, array $answers, ?PublishResult $result, int $status = Response::HTTP_OK): Response
    {
        $formats = [];
        foreach ($this->registry->all() as $name => $publisher) {
            $formats[$name] = $publisher->getLabel();
        }
        $options = $this->service->optionsFor($format);

        return $this->render('ui/publish/index.html.twig', [
            'id' => $id,
            'format' => $format,
            'formats' => $formats,
            'document_version' => $this->repository->get($id)->data['version'] ?? null,
            'options' => $options,
            'answers' => $answers,
            'result' => $result,
        ], new Response(status: $status));
    }
}
