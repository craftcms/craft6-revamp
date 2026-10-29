<?php

namespace CraftCms\Prepper\Console\Checks;

use CraftCms\Prepper\Console\Project;

/**
 * Runs before anything changes. A check may report on the project, prompt, or cancel the upgrade.
 */
interface Check
{
    /**
     * Returns whether the upgrade may continue. Checks output their own errors or warnings before returning `false`.
     */
    public function passes(Project $project): bool;
}
