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
        public readonly string $epicType = 'Epic',
        public readonly string $storyType = 'Technical Story',
        public readonly string $subtaskType = 'Sub-task',
        /** the "Epic Name" custom field, for the projects that require it on an epic (null = not sent) */
        public readonly ?string $epicNameField = null,
    ) {
    }
}
