<?php

declare(strict_types=1);

namespace App\Browser;

use HeadlessChromium\Browser;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

/**
 * Chrome started and driven from PHP (chrome-php/chrome, DevTools protocol), with a profile kept on disk.
 */
final class ChromeSession implements BrowserSessionInterface
{
    private ?Browser $browser = null;
    private ?Page $page = null;

    public function __construct(
        private readonly string $profileDir,
        private readonly string $chromeBinary,
    ) {
    }

    public function start(bool $headless): void
    {
        $this->close();
        if (!is_dir($this->profileDir) && !mkdir($this->profileDir, 0700, true) && !is_dir($this->profileDir)) {
            throw new \RuntimeException(sprintf('Cannot create the browser profile %s', $this->profileDir));
        }

        $factory = new BrowserFactory($this->chromeBinary);
        $this->browser = $factory->createBrowser([
            'headless' => $headless,
            'noSandbox' => true,
            'userDataDir' => $this->profileDir,
            'windowSize' => [1440, 900],
            'startupTimeout' => 60,
            'customFlags' => ['--disable-gpu', '--disable-dev-shm-usage'],
        ]);
        $this->page = null;
    }

    public function open(string $url): void
    {
        if ($this->browser === null) {
            throw new \LogicException('The browser is not started.');
        }
        $this->page = $this->browser->createPage();
        $this->page->navigate($url)->waitForNavigation(Page::DOM_CONTENT_LOADED, 60000);
    }

    public function evaluate(string $expression): mixed
    {
        if ($this->page === null) {
            throw new \LogicException('No page is open: call open() first.');
        }

        return $this->page->evaluate($expression)->getReturnValue(120000);
    }

    public function close(): void
    {
        $this->browser?->close();
        $this->browser = null;
        $this->page = null;
    }
}
