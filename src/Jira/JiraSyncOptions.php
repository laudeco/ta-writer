<?php

declare(strict_types=1);

namespace App\Jira;

final class JiraSyncOptions
{
    public function __construct(
        public readonly string $epicProject,
        public readonly string $storyProject,
        public readonly ?string $fixVersion = null,
        public readonly string $epicLinkField = 'customfield_10000',
    ) {
    }
}
