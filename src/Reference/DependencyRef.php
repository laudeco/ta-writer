<?php

declare(strict_types=1);

namespace App\Reference;

/**
 * How a dependency names a sub-task. Sub-task references (Subtask#1) repeat in every technical story, so a sub-task is
 * named by its technical story and its own reference: `TS1/Subtask#1`. Epics, user stories and technical stories keep
 * their plain reference.
 */
final class DependencyRef
{
    public static function subtask(string $technicalStoryReference, string $subtaskReference): string
    {
        return $technicalStoryReference.'/'.$subtaskReference;
    }
}
