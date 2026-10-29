<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class RemoveCraftExecutable implements Step
{
    public function label(Project $project): string
    {
        return 'Removing the Craft executable';
    }

    public function shouldRun(Project $project): bool
    {
        return file_exists($project->path('craft'));
    }

    public function run(Project $project, Logger $logger): void
    {
        unlink($project->path('craft'));

        $logger->success('Craft executable removed.');
        $logger->warning('The craft executable will be re-created the first time you publish assets with Laravel.');
    }
}
