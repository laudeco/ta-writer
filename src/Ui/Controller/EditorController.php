<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Schema\SchemaValidationException;
use App\Schema\SchemaValidator;
use App\Storage\Exception\StaleVersionException;
use App\Storage\TaRepositoryInterface;
use App\Ui\Form\FieldNode;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use App\Validation\BusinessValidationException;
use App\Validation\BusinessValidator;
use App\Validation\Violation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The editor. The form comes from the OpenAPI schema; the save goes through the same validation and versioning as the
 * API, with the version the form was opened on (a second tab cannot overwrite a newer version).
 */
final class EditorController extends AbstractController
{
    public function __construct(
        private readonly TaRepositoryInterface $repository,
        private readonly SchemaFormBuilder $formBuilder,
        private readonly FormDataNormalizer $normalizer,
        private readonly SchemaValidator $schema,
        private readonly BusinessValidator $business,
    ) {
    }

    #[Route('/', name: 'ui_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ui/editor/index.html.twig', ['items' => $this->repository->list()]);
    }

    #[Route('/documents/new', name: 'ui_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->renderForm(null, [], 0, []);
    }

    #[Route('/documents', name: 'ui_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $data = $this->normalizer->normalize($request->request->all('data'), $this->formBuilder->build());
        $errors = $this->errorsOf($data);
        if ($errors !== null) {
            return $this->renderForm(null, $data, 0, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $stored = $this->repository->save(null, $data, 'ui');
        $this->addFlash('success', sprintf('Analysis %s created.', $stored->id));

        return $this->redirectToRoute('ui_edit', ['id' => $stored->id]);
    }

    #[Route('/documents/{id}/edit', name: 'ui_edit', methods: ['GET'])]
    public function edit(string $id): Response
    {
        $stored = $this->repository->get($id);

        return $this->renderForm($id, $stored->data, $stored->version, [], Response::HTTP_OK, count($this->repository->versions($id)));
    }

    #[Route('/documents/{id}', name: 'ui_update', methods: ['POST'])]
    public function update(string $id, Request $request): Response
    {
        $expected = (int) $request->request->get('_version', 0);
        $data = $this->normalizer->normalize($request->request->all('data'), $this->formBuilder->build());
        $errors = $this->errorsOf($data);
        if ($errors !== null) {
            return $this->renderForm($id, $data, $expected, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $stored = $this->repository->save($id, $data, 'ui', $expected);
        } catch (StaleVersionException $e) {
            $this->addFlash('error', sprintf('Someone saved a newer version (%d) meanwhile. Your changes are shown below: copy them, reload, and apply them again.', $e->current));

            return $this->renderForm($id, $data, $e->expected, [], Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', sprintf('Saved as version %d.', $stored->version));

        return $this->redirectToRoute('ui_edit', ['id' => $id]);
    }

    #[Route('/documents/{id}/delete', name: 'ui_delete', methods: ['POST'])]
    public function delete(string $id): Response
    {
        $this->repository->delete($id);
        $this->addFlash('success', sprintf('Analysis %s deleted. Its history is kept on disk.', $id));

        return $this->redirectToRoute('ui_index');
    }

    /**
     * Schema and business errors as rows for the page, or null when the document is valid.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{path: string, message: string}>|null
     */
    private function errorsOf(array $data): ?array
    {
        $errors = [];
        try {
            $this->schema->validate($data);
        } catch (SchemaValidationException $e) {
            $errors = $e->getErrors();
        }
        foreach ($this->business->validate($data) as $violation) {
            if ($violation->severity === 'error') {
                $errors[] = ['path' => $violation->path, 'message' => $violation->message];
            }
        }

        return $errors === [] ? null : $errors;
    }

    /**
     * @param array<string, mixed>                  $document
     * @param list<array{path: string, message: string}> $errors
     */
    private function renderForm(?string $id, array $document, int $version, array $errors, int $status = Response::HTTP_OK, int $history = 0): Response
    {
        return $this->render('ui/editor/edit.html.twig', [
            'id' => $id,
            'version' => $version,
            'history' => $history,
            'document' => $document,
            'form' => $this->formBuilder->build(),
            'errors' => $errors,
        ], new Response(status: $status));
    }
}
