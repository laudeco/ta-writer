<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

/**
 * A page with the wanted title exists, but not under the expected parent: stop and report, never rename or move.
 */
final class ConfluenceTitleCollisionException extends \RuntimeException
{
    public static function under(string $title, string $expectedParent, string $pageId): self
    {
        return new self(sprintf('Title collision: "%s" already exists (page %s) and is not under page %s.', $title, $pageId, $expectedParent));
    }
}
