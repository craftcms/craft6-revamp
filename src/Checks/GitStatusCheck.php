<?php

namespace CraftCms\Revamp\Checks;

use CraftCms\Revamp\Project;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Asks before continuing with uncommitted changes, so what the tool changes can be reviewed and undone.
 */
class GitStatusCheck implements Check
{
    public function passes(Project $project): bool
    {
        $output = [];
        @exec(sprintf('git -C %s status --porcelain -- . 2>&1', escapeshellarg($project->path())), $output, $exitCode);

        // Not a Git repository, or Git isn’t installed
        if ($exitCode !== 0) {
            return true;
        }

        $changes = array_values(array_filter($output));

        if ($changes === []) {
            return true;
        }

        warning(sprintf('The project has %d uncommitted %s. Commit or stash them first, so you can review and undo what this tool changes.', count($changes), count($changes) === 1 ? 'change' : 'changes'));
        note(implode("\n", array_slice($changes, 0, 10)).(count($changes) > 10 ? "\n…" : ''));

        if (! confirm(label: 'Continue with uncommitted changes?', default: false)) {
            warning('Cancelled.');

            return false;
        }

        return true;
    }
}
