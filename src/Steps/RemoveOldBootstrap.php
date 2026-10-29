<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class RemoveOldBootstrap implements Step
{
    public function label(Project $project): string
    {
        return 'Removing old bootstrap.php';
    }

    public function shouldRun(Project $project): bool
    {
        return file_exists($project->path('bootstrap.php'));
    }

    public function run(Project $project, Logger $logger): void
    {
        unlink($project->path('bootstrap.php'));

        $logger->success('bootstrap.php file removed.');
    }
}
