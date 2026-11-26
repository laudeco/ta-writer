<?php

declare(strict_types=1);

namespace App\Tests\Author;

use App\Author\AuthorRepository;
use App\Author\Command\AuthorAddCommand;
use App\Author\Command\AuthorRemoveCommand;
use App\Storage\AtomicFileWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AuthorCommandsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ta-author-cmd-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function repository(): AuthorRepository
    {
        return new AuthorRepository($this->dir.'/authors.json', new AtomicFileWriter());
    }

    public function testIfEmptyAddsOnlyWhileTheListIsEmpty(): void
    {
        $repo = $this->repository();
        $tester = new CommandTester(new AuthorAddCommand($repo));

        $tester->execute(['name' => 'First', 'email' => 'first@example.test', '--if-empty' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $repo->all());

        // a second run of the install script does not add a second author
        $tester->execute(['name' => 'Other', 'email' => 'other@example.test', '--if-empty' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertCount(1, $repo->all());
        self::assertStringContainsString('nothing added', $tester->getDisplay());
    }

    public function testAnInvalidAuthorFailsWithoutWriting(): void
    {
        $repo = $this->repository();
        $tester = new CommandTester(new AuthorAddCommand($repo));

        $tester->execute(['name' => 'Bad', 'email' => 'not-an-email']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame([], $repo->all());
    }

    public function testRemovingAnUnknownAuthorFails(): void
    {
        $tester = new CommandTester(new AuthorRemoveCommand($this->repository()));
        $tester->execute(['email' => 'nobody@example.test']);

        self::assertSame(1, $tester->getStatusCode());
    }
}
