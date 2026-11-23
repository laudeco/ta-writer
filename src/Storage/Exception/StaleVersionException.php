<?php

declare(strict_types=1);

namespace App\Storage\Exception;

/**
 * A write was based on an older version than the current one: nothing is overwritten.
 */
final class StaleVersionException extends \RuntimeException
{
    public function __construct(public readonly int $expected, public readonly int $current)
    {
        parent::__construct(sprintf('Version %d is stale: the current version is %d.', $expected, $current));
    }
}
