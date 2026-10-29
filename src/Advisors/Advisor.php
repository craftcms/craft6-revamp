<?php

namespace CraftCms\Prepper\Console\Advisors;

use CraftCms\Prepper\Console\Project;

/**
 * Runs after all steps, and suggests Next steps that can’t be automated.
 */
interface Advisor
{
    public function advise(Project $project): void;
}
