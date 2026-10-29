<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use Laravel\Prompts\Support\Logger;

use function Laravel\Prompts\confirm;

class MoveTemplates implements Step
{
    public function label(Project $project): string
    {
        return 'Moving templates directory';
    }

    public function shouldRun(Project $project): bool
    {
        return is_dir($project->path('templates')) && (
            ! is_dir($project->path('resources/views')) ||
            // Asked here rather than in run(), so the prompt isn’t drawn inside the step’s spinner
            confirm('The /resources/views directory already exists. Do you want to move the templates there?')
        );
    }

    public function run(Project $project, Logger $logger): void
    {
        if (is_dir($project->path('resources/views'))) {
            rename($project->path('resources/views'), $project->path('resources/views-old'));
        }

        if (! is_dir($project->path('resources'))) {
            mkdir($project->path('resources'), recursive: true);
        }

        rename($project->path('templates'), $project->path('resources/views'));

        $logger->success('Templates directory moved from /templates to /resources/views.');
    }
}
