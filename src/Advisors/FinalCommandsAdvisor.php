<?php

namespace CraftCms\Revamp\Advisors;

use CraftCms\Revamp\NextStepSection;
use CraftCms\Revamp\Project;

/**
 * Suggests the commands that finish the upgrade. Runs last.
 */
class FinalCommandsAdvisor implements Advisor
{
    public function advise(Project $project): void
    {
        $prefix = $project->isDdev() ? 'ddev ' : '';

        $project->suggest(NextStepSection::Commands, "Run <options=bold>{$prefix}composer update</>");
        $project->suggest(NextStepSection::Commands, "Run <options=bold>{$prefix}artisan craft:setup:publish</>");
        $project->suggest(NextStepSection::Commands, "Run <options=bold>{$prefix}artisan key:generate</>");
        $project->suggest(NextStepSection::Commands, "Run <options=bold>{$prefix}artisan craft:up</> to run Craft 6’s migrations");
    }
}
