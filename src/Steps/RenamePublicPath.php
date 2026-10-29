<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class RenamePublicPath implements Step
{
    public function label(Project $project): string
    {
        return 'Renaming the public folder';
    }

    public function shouldRun(Project $project): bool
    {
        return $project->renamePublicPath;
    }

    public function run(Project $project, Logger $logger): void
    {
        rename($project->path($project->publicPath), $project->path('public'));

        $logger->success("Public path renamed from /{$project->publicPath} to /public.");

        $project->publicPath = 'public';
    }
}
