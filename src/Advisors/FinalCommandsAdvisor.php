<?php

namespace CraftCms\Prepper\Console\Advisors;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;

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
    }
}
