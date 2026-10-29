<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use CraftCms\Revamp\Support\Json;
use Laravel\Prompts\Support\Logger;

class UpdateComposer implements Step
{
    public function label(Project $project): string
    {
        return 'Updating composer.json';
    }

    public function shouldRun(Project $project): bool
    {
        return true;
    }

    public function run(Project $project, Logger $logger): void
    {
        $composerJsonPath = $project->path('composer.json');
        $config = Json::decodeFromFile($composerJsonPath);

        $config['require']['craftcms/cms'] = '^6.0.0-alpha.1';
        $config['require']['craftcms/yii2-adapter'] = '*';

        if (isset($config['require-dev']['craftcms/generator'])) {
            $config['require-dev']['craftcms/generator'] = '3.x-dev';
        } elseif (isset($config['require']['craftcms/generator'])) {
            $config['require']['craftcms/generator'] = '3.x-dev';
        }
        $logger->success('Updated dependencies');

        unset($config['require']['vlucas/phpdotenv']);

        // Craft 6 has no `craft shell` command; Laravel Tinker (`artisan tinker`) replaces it
        if (isset($config['require']['yiisoft/yii2-shell']) || isset($config['require-dev']['yiisoft/yii2-shell'])) {
            unset($config['require']['yiisoft/yii2-shell'], $config['require-dev']['yiisoft/yii2-shell']);

            if (empty($config['require-dev'])) {
                unset($config['require-dev']);
            }

            $logger->success('Removed yiisoft/yii2-shell');
        }
        unset($config['config']['platform']['php']);

        // Otherwise it ends up as `platform: []` which is invalid.
        if (empty($config['config']['platform'])) {
            unset($config['config']['platform']);
        }
        if (empty($config['config'])) {
            unset($config['config']);
        }
        $logger->success('Removed platform.php config');

        if (! isset($config['scripts']['post-autoload-dump'])) {
            $config['scripts']['post-autoload-dump'] = [];
        }

        $scripts = [
            'Illuminate\Foundation\ComposerScripts::postAutoloadDump',
            '@php artisan package:discover --ansi',
            '@php artisan craft:setup:publish --ansi',
        ];

        foreach ($scripts as $script) {
            if (! in_array($script, $config['scripts']['post-autoload-dump'])) {
                $config['scripts']['post-autoload-dump'][] = $script;
            }
        }
        $logger->success('Added post-autoload-dump scripts');

        Json::encodeToFile($composerJsonPath, $config);
        $logger->success('composer.json updated');
    }
}
