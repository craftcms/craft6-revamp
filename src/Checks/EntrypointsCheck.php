<?php

namespace CraftCms\Revamp\Checks;

use CraftCms\Revamp\Project;
use CraftCms\Revamp\StockCraftFiles;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Shows the customizations in index.php and bootstrap.php that will be lost, and asks to proceed.
 */
class EntrypointsCheck implements Check
{
    public function passes(Project $project): bool
    {
        $warning = "This script will replace {$project->publicPath}/index.php";
        if (file_exists($project->path('bootstrap.php'))) {
            $warning .= ' and remove bootstrap.php';
        }

        warning($warning);

        $customized = false;
        foreach (["{$project->publicPath}/index.php", 'bootstrap.php'] as $file) {
            $customLines = StockCraftFiles::customLines($project->path($file));

            if ($customLines !== []) {
                $customized = true;
                warning("$file has customizations that will be lost:");
                note(implode("\n", $customLines));
            }
        }

        if (! confirm(label: 'Proceed?', hint: $customized ? 'The customizations above will be lost.' : 'No customizations were found.')) {
            warning('Cancelled.');

            return false;
        }

        return true;
    }
}
