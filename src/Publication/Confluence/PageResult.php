<?php

declare(strict_types=1);

namespace App\Publication\Confluence;

final class PageResult
{
    /**
     * @param 'created'|'updated'|'unchanged'|'would-create'|'would-update' $status
     */
    public function __construct(
        public readonly string $status,
        public readonly string $id,
        public readonly string $url,
    ) {
    }
}
