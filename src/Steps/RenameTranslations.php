<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class RenameTranslations implements Step
{
    public function label(Project $project): string
    {
        return 'Renaming translations directory';
    }

    public function shouldRun(Project $project): bool
    {
        return is_dir($project->path('translations'));
    }

    public function run(Project $project, Logger $logger): void
    {
        rename($project->path('translations'), $project->path('lang'));

        $logger->success('Translations directory renamed from /translations to /lang.');
    }
}
