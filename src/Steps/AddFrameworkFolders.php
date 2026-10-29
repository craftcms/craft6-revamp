<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class AddFrameworkFolders implements Step
{
    public function label(Project $project): string
    {
        return 'Creating framework storage folders';
    }

    public function shouldRun(Project $project): bool
    {
        return true;
    }

    public function run(Project $project, Logger $logger): void
    {
        $dirs = [
            'storage/framework' => false,
            'storage/framework/cache' => true,
            'storage/framework/sessions' => true,
            'storage/framework/views' => true,
        ];

        foreach ($dirs as $dir => $addGitIgnore) {
            if (! file_exists($project->path($dir))) {
                mkdir($project->path($dir), recursive: true);
            }

            if ($addGitIgnore && ! file_exists($project->path("$dir/.gitignore"))) {
                file_put_contents($project->path("$dir/.gitignore"), "*\n!.gitignore");
            }

            $logger->success("Created $dir");
        }
    }
}
