<?php

declare(strict_types=1);

namespace App\Tests\Author;

use App\Author\Author;
use App\Author\AuthorRepository;
use App\Storage\AtomicFileWriter;
use PHPUnit\Framework\TestCase;

final class AuthorRepositoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/ta-authors-'.bin2hex(random_bytes(4)).'/authors.json';
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

    public function testAMissingFileIsAnEmptyList(): void
    {
        self::assertSame([], $this->repository()->all());
        self::assertNull($this->repository()->first());
    }

    public function testTheFirstAuthorAddedIsTheDefault(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test', 'first'));
        $repo->add(new Author('Second', 'second@example.test'));

        self::assertSame('first@example.test', $repo->first()?->email);
        self::assertCount(2, $repo->all());
    }

    public function testAnAuthorIsFoundByEmailWhateverTheCase(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test'));

        self::assertSame('First', $repo->find('FIRST@example.test')?->name);
        self::assertNull($repo->find('nobody@example.test'));
    }

    public function testTheEmailIsUnique(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test'));

        $this->expectException(\InvalidArgumentException::class);
        $repo->add(new Author('Other', 'First@example.test'));
    }

    public function testANameAndAValidEmailAreRequired(): void
    {
        $repo = $this->repository();

        try {
            $repo->add(new Author('  ', 'first@example.test'));
            self::fail('an empty name must be refused');
        } catch (\InvalidArgumentException) {
        }
        try {
            $repo->add(new Author('First', 'not-an-email'));
            self::fail('an invalid email must be refused');
        } catch (\InvalidArgumentException) {
        }
        self::assertSame([], $repo->all());
    }

    public function testAnAuthorCanBeUpdatedAndItsEmailChangedIfFree(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test'));
        $repo->add(new Author('Second', 'second@example.test'));

        $repo->update('first@example.test', new Author('First renamed', 'first.new@example.test', 'fn'));

        self::assertSame('First renamed', $repo->find('first.new@example.test')?->name);
        self::assertNull($repo->find('first@example.test'));
        $this->expectException(\InvalidArgumentException::class);
        $repo->update('first.new@example.test', new Author('Clash', 'second@example.test'));
    }

    public function testAnAuthorCanBeRemoved(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test'));

        self::assertTrue($repo->remove('first@example.test'));
        self::assertFalse($repo->remove('first@example.test'));
        self::assertSame([], $repo->all());
    }

    public function testAnEmptyOrBrokenFileIsAnEmptyList(): void
    {
        mkdir(\dirname($this->path), 0775, true);
        file_put_contents($this->path, 'not json');

        self::assertSame([], $this->repository()->all());
    }

    public function testTheUsernameIsOptionalAndOmittedWhenEmpty(): void
    {
        $repo = $this->repository();
        $repo->add(new Author('First', 'first@example.test'));
        $repo->add(new Author('Second', 'second@example.test', 'second'));

        $rows = json_decode((string) file_get_contents($this->path), true);
        self::assertArrayNotHasKey('username', $rows[0]);
        self::assertSame('second', $rows[1]['username']);
        self::assertSame('', $repo->find('first@example.test')?->username);
    }
}
