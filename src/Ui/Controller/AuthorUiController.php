<?php

declare(strict_types=1);

namespace App\Ui\Controller;

use App\Author\Author;
use App\Author\AuthorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The list of authors: add, change and remove. A document keeps a copy of the author it chose, so removing an author
 * changes no analysis.
 */
final class AuthorUiController extends AbstractController
{
    public function __construct(private readonly AuthorRepository $authors)
    {
    }

    #[Route('/authors', name: 'ui_authors', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ui/authors/index.html.twig', ['authors' => $this->authors->all()]);
    }

    #[Route('/authors', name: 'ui_author_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        return $this->write(fn () => $this->authors->add($this->fromRequest($request)), 'Author added.');
    }

    #[Route('/authors/save', name: 'ui_author_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $original = (string) $request->request->get('original', '');

        return $this->write(fn () => $this->authors->update($original, $this->fromRequest($request)), 'Author saved.');
    }

    #[Route('/authors/delete', name: 'ui_author_delete', methods: ['POST'])]
    public function delete(Request $request): Response
    {
        $email = (string) $request->request->get('email', '');
        if (!$this->authors->remove($email)) {
            $this->addFlash('error', sprintf('No author with the email %s.', $email));

            return $this->redirectToRoute('ui_authors');
        }
        $this->addFlash('success', 'Author removed. The analyses that chose it keep their copy.');

        return $this->redirectToRoute('ui_authors');
    }

    private function fromRequest(Request $request): Author
    {
        return new Author(
            trim((string) $request->request->get('name', '')),
            trim((string) $request->request->get('email', '')),
            trim((string) $request->request->get('username', '')),
        );
    }

    private function write(callable $action, string $done): Response
    {
        try {
            $action();
            $this->addFlash('success', $done);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('ui_authors');
    }
}
