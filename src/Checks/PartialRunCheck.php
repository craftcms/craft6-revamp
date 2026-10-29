<?php

namespace CraftCms\Revamp\Checks;

use CraftCms\Revamp\Project;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\warning;

/**
 * Asks before running again on a project this tool already changed.
 */
class PartialRunCheck implements Check
{
    public function passes(Project $project): bool
    {
        $existing = array_values(array_filter(
            ['bootstrap/', 'config/craft/'],
            fn (string $dir) => is_dir($project->path($dir)),
        ));

        if ($existing === []) {
            return true;
        }

        warning(sprintf(
            '%s already %s, so this tool may have run here before. Steps that already happened will be skipped, and files they created won’t be updated.',
            implode(' and ', $existing),
            count($existing) === 1 ? 'exists' : 'exist',
        ));

        if (! confirm(label: 'Continue anyway?', default: false, hint: 'Restore the project first for a clean run.')) {
            warning('Cancelled.');

            return false;
        }

        return true;
    }
}
