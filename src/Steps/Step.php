<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use Laravel\Prompts\Support\Logger;

/**
 * Makes one change to the project.
 */
interface Step
{
    /**
     * The label shown while the step runs, e.g. “Updating composer.json”.
     */
    public function label(Project $project): string;

    /**
     * Whether the step has anything to do. Called right before run(), so it sees the changes earlier steps made.
     */
    public function shouldRun(Project $project): bool;

    public function run(Project $project, Logger $logger): void;
}
