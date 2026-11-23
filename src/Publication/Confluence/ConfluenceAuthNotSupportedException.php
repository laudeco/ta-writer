<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

/**
 * The instance rejected the Personal Access Token. The Confluence API adapter is then unusable: there is no fallback.
 */
final class ConfluenceAuthNotSupportedException extends \RuntimeException
{
    public static function rejected(int $status): self
    {
        return new self(sprintf(
            'Confluence answered HTTP %d to the Personal Access Token. Either the token is wrong or this instance does not '
            .'accept PAT: the Confluence API adapter cannot be used here, use the AGIR adapter.',
            $status,
        ));
    }
}
