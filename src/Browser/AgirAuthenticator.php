<?php

declare(strict_types=1);

namespace App\Browser;

/**
 * Checks the AGIR session. When it is missing, the browser is shown (noVNC) and the user logs in; the publication then
 * resumes on the same profile, headless.
 */
final class AgirAuthenticator
{
    private const PROBE = "(async()=>{const r=await fetch('/rest/api/user/current',{credentials:'same-origin',headers:{'Accept':'application/json'}});return {status:r.status};})()";

    public function __construct(
        private readonly BrowserSessionInterface $session,
        private readonly string $origin,
        private readonly int $timeoutSeconds = 600,
        private readonly int $pollSeconds = 3,
    ) {
    }

    public function isLoggedIn(): bool
    {
        $this->session->open(rtrim($this->origin, '/').'/');
        $probe = $this->session->evaluate(self::PROBE);

        return is_array($probe) && ($probe['status'] ?? 0) === 200;
    }

    /**
     * @param callable(string): void $notify receives the instruction to show the user
     *
     * @throws NotAuthenticatedException when the login does not happen before the timeout
     */
    public function ensureLoggedIn(callable $notify): void
    {
        $this->session->start(true);
        if ($this->isLoggedIn()) {
            return;
        }

        $this->session->start(false);
        $notify('Log in to AGIR in the browser shown at http://127.0.0.1:6080/vnc.html, then wait: the publication resumes by itself.');
        $this->session->open(rtrim($this->origin, '/').'/');

        $deadline = time() + $this->timeoutSeconds;
        do {
            sleep($this->pollSeconds);
            // a fresh probe on every pass: the session can appear while we wait
            /** @phpstan-ignore if.alwaysFalse */
            if ($this->isLoggedIn()) {
                $this->session->start(true);

                return;
            }
        } while (time() < $deadline);

        throw new NotAuthenticatedException(sprintf('No AGIR login after %d seconds.', $this->timeoutSeconds));
    }
}
