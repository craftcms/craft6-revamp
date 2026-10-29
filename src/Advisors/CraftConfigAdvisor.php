<?php

namespace CraftCms\Revamp\Advisors;

use CraftCms\Revamp\CraftConfigUpdater;
use CraftCms\Revamp\NextStepSection;
use CraftCms\Revamp\Project;

/**
 * Suggests changes to Craft config files that Craft 6 no longer reads, or only reads through the Yii2 adapter.
 */
class CraftConfigAdvisor implements Advisor
{
    public function advise(Project $project): void
    {
        $configPath = $project->path('config/craft');

        if (! is_dir($configPath)) {
            return;
        }

        $read = fn (string $file) => CraftConfigUpdater::stripComments(file_get_contents("$configPath/$file"));
        $files = array_map('basename', glob("$configPath/*.php") ?: []);

        $multiEnvFiles = array_values(array_filter(
            $files,
            fn (string $file) => preg_match('/([\'"])\*\1\s*=>/', $read($file)),
        ));

        if ($multiEnvFiles !== []) {
            $project->suggest(NextStepSection::CraftConfig, sprintf(
                'Replace the multi-environment config arrays (the <options=bold>\'*\'</> key) in %s with <options=bold>env()</> values or <options=bold>app()->environment()</> checks. Craft 6 reads config files as-is',
                implode(', ', array_map(fn (string $file) => "config/craft/$file", $multiEnvFiles)),
            ));
        }

        $appTypeFiles = array_values(array_filter(
            $files,
            fn (string $file) => preg_match('/^\w[\w-]*\.(web|console)\.php$/', $file),
        ));

        if ($appTypeFiles !== []) {
            $project->suggest(NextStepSection::CraftConfig, sprintf(
                'Merge %s into the main config %s. Craft 6 only reads app-type config files through craftcms/yii2-adapter',
                implode(', ', array_map(fn (string $file) => "config/craft/$file", $appTypeFiles)),
                count($appTypeFiles) === 1 ? 'file' : 'files',
            ));
        }

        if (in_array('db.php', $files)) {
            $project->suggest(NextStepSection::CraftConfig, 'Move your database settings from config/craft/db.php into config/database.php or the <options=bold>DB_*</> environment variables, and delete it. Craft 6 no longer reads it');
        }

        if (in_array('app.php', $files)) {
            $keys = array_values(array_filter(
                ['components', 'modules', 'bootstrap'],
                fn (string $key) => preg_match("/(['\"])$key\\1\\s*=>/", $read('app.php')),
            ));

            if ($keys !== []) {
                $project->suggest(NextStepSection::CraftConfig, sprintf(
                    'config/craft/app.php defines Yii <options=bold>%s</>, which only craftcms/yii2-adapter reads. Move them to Laravel service providers when you can',
                    implode('</>, <options=bold>', $keys),
                ));
            }
        }

        if (in_array('routes.php', $files) && ! preg_match('/^\s*<\?php\s*return\s*(\[\s*\]|array\(\s*\))\s*;\s*$/', $read('routes.php'))) {
            $project->suggest(NextStepSection::CraftConfig, 'Translate the URL rules in config/craft/routes.php into routes/web.php. Craft 6 only reads them through craftcms/yii2-adapter');
        }

        $purifierConfigs = array_values(array_filter(
            array_map('basename', glob("$configPath/htmlpurifier/*.json") ?: []),
            fn (string $file) => $file !== 'Default.json',
        ));

        if ($purifierConfigs !== []) {
            $project->suggest(NextStepSection::CraftConfig, sprintf(
                'Craft 6 replaces HTMLPurifier with Symfony HtmlSanitizer. Register sanitizers for %s with the <options=bold>HtmlSanitizers</> facade (see vendor/craftcms/cms/docs/html-sanitizers.md)',
                implode(', ', array_map(fn (string $file) => "config/craft/htmlpurifier/$file", $purifierConfigs)),
            ));
        }
    }
}
