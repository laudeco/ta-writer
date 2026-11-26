<?php

declare(strict_types=1);

namespace App\Tests\Author;

use App\Author\Author;
use App\Author\AuthorRepository;
use App\Render\ContentConverter;
use App\Schema\SchemaProvider;
use App\Storage\AtomicFileWriter;
use App\Ui\Form\FormDataNormalizer;
use App\Ui\Form\SchemaFormBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The author of a document is chosen in the list: the form sends an email, the copy comes from the list.
 */
final class AuthorFieldTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ta-author-field-'.bin2hex(random_bytes(4)).'/authors.json';
        $repo = $this->repository();
        $repo->add(new Author('Listed Person', 'listed@example.test', 'listed'));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_dir(\dirname($this->path))) {
            rmdir(\dirname($this->path));
        }
    }

    private function repository(): AuthorRepository
    {
        return new AuthorRepository($this->path, new AtomicFileWriter());
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(array $author): array
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(__DIR__.'/../../config/schema/document.openapi.yaml')))->build();
        $submitted = ['format' => 'markdown', 'author' => $author, 'project' => ['name' => 'P', 'reference' => 'P1']];

        return (new FormDataNormalizer(new ContentConverter(), $this->repository()))->normalize($submitted, $root, 'markdown');
    }

    public function testTheAuthorIsAnAuthorWidgetInTheForm(): void
    {
        $root = (new SchemaFormBuilder(new SchemaProvider(__DIR__.'/../../config/schema/document.openapi.yaml')))->build();
        $author = null;
        foreach ($root->children as $child) {
            if ($child->name === 'author') {
                $author = $child;
            }
        }

        self::assertNotNull($author);
        self::assertSame('author', $author->widget);
    }

    public function testAnAuthorChosenInTheListIsCopiedFromTheList(): void
    {
        // the name and username typed in the hidden fields are not trusted: the list wins
        $out = $this->normalize(['email' => 'listed@example.test', 'name' => 'Someone else', 'username' => 'x']);

        self::assertSame(['name' => 'Listed Person', 'username' => 'listed', 'email' => 'listed@example.test'], $out['author']);
    }

    public function testAnEmailNoLongerInTheListKeepsTheCopyTheFormCarries(): void
    {
        $out = $this->normalize(['email' => 'gone@example.test', 'name' => 'Old Name', 'username' => '']);

        self::assertSame(['name' => 'Old Name', 'email' => 'gone@example.test'], $out['author']);
    }

    public function testNoEmailLeavesTheAuthorOut(): void
    {
        $out = $this->normalize(['email' => '', 'name' => 'Old Name']);

        self::assertArrayNotHasKey('author', $out);
    }
}
