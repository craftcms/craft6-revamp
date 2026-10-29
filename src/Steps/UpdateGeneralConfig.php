<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\CraftConfigUpdater;
use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;
use ParseError;

/**
 * Rewrites paths that pointed into the project root, and removes settings that Craft 6 dropped.
 */
class UpdateGeneralConfig implements Step
{
    private const FILES = ['general.php', 'general.web.php', 'general.console.php'];

    public function label(Project $project): string
    {
        return 'Updating config/craft/general.php';
    }

    public function shouldRun(Project $project): bool
    {
        return $this->files($project) !== [];
    }

    public function run(Project $project, Logger $logger): void
    {
        $generalConfigPath = $project->path('config/craft/general.php');
        if (file_exists($generalConfigPath) && ! preg_match('/\bloginPath\b/', CraftConfigUpdater::stripComments(file_get_contents($generalConfigPath)))) {
            $project->suggest(NextStepSection::GeneralConfig, 'Add <options=bold>loginPath</> to config/craft/general.php if you have a front-end login form');
        }

        $updater = new CraftConfigUpdater($project->originalPublicPath, $project->targetPublicPath());
        $unresolvedPathFiles = [];
        $remainingSettings = [];

        foreach ($this->files($project) as $file) {
            $filePath = $project->path("config/craft/$file");
            $original = file_get_contents($filePath);
            [$contents, $pathCount] = $updater->rewritePaths($original);
            [$contents, $removedSettings] = $updater->removeSettings($contents);

            // Never write a file we broke. The tool may run on an older PHP than the project (e.g. on the host
            // with DDEV), so only check files that parse on this PHP to begin with.
            if ($contents !== $original && $this->isValidPhp($original) && ! $this->isValidPhp($contents)) {
                $logger->warning("config/craft/$file couldn’t be updated safely, so it was left as-is.");
                $contents = $original;
                $pathCount = 0;
                $removedSettings = [];
            }

            if ($contents !== $original) {
                file_put_contents($filePath, $contents);
            }

            if ($pathCount > 0) {
                $logger->success(sprintf('Updated %d %s in config/craft/%s that pointed into the project root', $pathCount, $pathCount === 1 ? 'path' : 'paths', $file));
            }

            if ($removedSettings !== []) {
                $logger->success(sprintf('Removed %s from config/craft/%s', implode(', ', $removedSettings), $file));
            }

            if ($updater->hasUnresolvedPaths($contents)) {
                $unresolvedPathFiles[] = "config/craft/$file";
            }

            $remaining = $updater->remainingSettings($contents);
            if ($remaining !== []) {
                $remainingSettings["config/craft/$file"] = $remaining;
            }
        }

        if ($unresolvedPathFiles !== [] || $remainingSettings !== []) {
            $logger->warning('Some settings need to be updated by hand. See the next steps.');
        }

        if ($unresolvedPathFiles !== []) {
            $project->suggest(NextStepSection::GeneralConfig, sprintf(
                'Update the paths in %s that were relative to the project root or the old web root, using <options=bold>base_path()</> or <options=bold>public_path()</>, since the config now lives in config/craft/',
                implode(', ', $unresolvedPathFiles),
            ));
        }

        foreach ($remainingSettings as $file => $settings) {
            $project->suggest(NextStepSection::GeneralConfig, sprintf(
                'Remove <options=bold>%s</> from %s. %s no longer supported in Craft 6',
                implode('</>, <options=bold>', $settings),
                $file,
                count($settings) === 1 ? 'It is' : 'They are',
            ));
        }
    }

    /**
     * @return string[]
     */
    private function files(Project $project): array
    {
        return array_values(array_filter(
            self::FILES,
            fn (string $file) => file_exists($project->path("config/craft/$file")),
        ));
    }

    private function isValidPhp(string $contents): bool
    {
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (ParseError) {
            return false;
        }

        return true;
    }
}
