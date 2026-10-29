<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use Laravel\Prompts\Support\Logger;

class MoveConfigDirectory implements Step
{
    public function label(Project $project): string
    {
        return 'Moving Craft config directory';
    }

    public function shouldRun(Project $project): bool
    {
        return is_dir($project->path('config')) && ! is_dir($project->path('config/craft'));
    }

    public function run(Project $project, Logger $logger): void
    {
        rename($project->path('config'), $project->path('craft-config'));
        mkdir($project->path('config/craft'), recursive: true);
        rename($project->path('craft-config'), $project->path('config/craft'));

        $logger->success('Config created directory at /config/craft.');
    }
}
