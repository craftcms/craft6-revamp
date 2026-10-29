<?php

namespace CraftCms\Revamp\Advisors;

use CraftCms\Revamp\Project;

/**
 * Runs after all steps, and suggests Next steps that can’t be automated.
 */
interface Advisor
{
    public function advise(Project $project): void;
}
