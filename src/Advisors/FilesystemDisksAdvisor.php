<?php

namespace CraftCms\Prepper\Console\Advisors;

use CraftCms\Prepper\Console\FilesystemMigrationSuggestions;
use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;

/**
 * Suggests Laravel disks to replace the Craft filesystems in project config.
 */
class FilesystemDisksAdvisor implements Advisor
{
    public function advise(Project $project): void
    {
        $suggestions = (new FilesystemMigrationSuggestions)->get(
            $project->projectConfigDir().'/project.yaml',
            $project->targetPublicPath(),
        );

        if ($suggestions !== []) {
            $project->suggest(NextStepSection::Filesystems, "Craft Filesystems have been removed. Add the following Laravel disks to the <options=bold>disks</> array in config/filesystems.php:\n\n".implode("\n\n", $suggestions));
        }
    }
}
