<?php

declare(strict_types=1);

namespace App\Browser;

/**
 * A Chrome that PHP drives. The AGIR Confluence and Jira APIs are reachable only from the logged-in browser session:
 * requests are run as same-origin fetch() calls inside a tab, so no cookie ever leaves the browser.
 */
interface BrowserSessionInterface
{
    /**
     * Starts (or restarts) Chrome on the persistent profile. A headed Chrome is visible through noVNC, for the login.
     */
    public function start(bool $headless): void;

    public function open(string $url): void;

    /**
     * Evaluates an expression (a promise is awaited) in the current tab and returns its JSON-compatible value.
     */
    public function evaluate(string $expression): mixed;

    public function close(): void;
}
