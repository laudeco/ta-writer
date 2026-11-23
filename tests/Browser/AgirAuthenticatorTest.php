<?php

declare(strict_types=1);

namespace App\Tests\Browser;

use App\Browser\AgirAuthenticator;
use App\Browser\BrowserSessionInterface;
use App\Browser\NotAuthenticatedException;
use PHPUnit\Framework\TestCase;

final class AgirAuthenticatorTest extends TestCase
{
    /**
     * Fake browser: the probe answers with the scripted statuses, in order; the last one repeats.
     *
     * @param list<int>          $probeStatuses
     * @param array<int, string> $starts
     */
    private function session(array $probeStatuses, array &$starts): BrowserSessionInterface
    {
        return new class($probeStatuses, $starts) implements BrowserSessionInterface {
            /**
             * @param list<int>          $statuses
             * @param array<int, string> $starts
             */
            public function __construct(private array $statuses, private array &$starts)
            {
            }

            public function start(bool $headless): void
            {
                $this->starts[] = $headless ? 'headless' : 'headed';
            }

            public function open(string $url): void
            {
            }

            public function evaluate(string $expression): mixed
            {
                $status = count($this->statuses) > 1 ? array_shift($this->statuses) : $this->statuses[0];

                return ['status' => $status];
            }

            public function close(): void
            {
            }
        };
    }

    public function testAnExistingSessionIsUsedWithoutAnyPrompt(): void
    {
        $starts = [];
        $notes = [];
        (new AgirAuthenticator($this->session([200], $starts), 'https://espace.example', 10, 0))
            ->ensureLoggedIn(static function (string $m) use (&$notes): void {
                $notes[] = $m;
            });

        self::assertSame(['headless'], $starts);
        self::assertSame([], $notes);
    }

    public function testMissingLoginShowsTheBrowserThenResumesHeadless(): void
    {
        $starts = [];
        $notes = [];
        (new AgirAuthenticator($this->session([401, 401, 200], $starts), 'https://espace.example', 10, 0))
            ->ensureLoggedIn(static function (string $m) use (&$notes): void {
                $notes[] = $m;
            });

        self::assertSame(['headless', 'headed', 'headless'], $starts);
        self::assertCount(1, $notes);
        self::assertStringContainsString('6080', $notes[0]);
    }

    public function testNoLoginBeforeTheTimeoutStopsTheRun(): void
    {
        $starts = [];
        $this->expectException(NotAuthenticatedException::class);
        (new AgirAuthenticator($this->session([401], $starts), 'https://espace.example', 0, 0))
            ->ensureLoggedIn(static function (): void {
            });
    }
}
