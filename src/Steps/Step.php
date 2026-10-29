<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
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
