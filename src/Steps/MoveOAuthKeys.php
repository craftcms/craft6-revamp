<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

/**
 * Moves the OAuth signing keys from a custom Craft storage path into storage/, where Laravel Passport looks for them.
 */
class MoveOAuthKeys implements Step
{
    public function label(Project $project): string
    {
        return 'Moving OAuth signing keys';
    }

    public function shouldRun(Project $project): bool
    {
        return $this->craftStoragePath($project) !== null;
    }

    public function run(Project $project, Logger $logger): void
    {
        $craftStoragePath = $this->craftStoragePath($project);
        $moved = false;

        foreach (['oauth-private.key', 'oauth-public.key'] as $file) {
            $source = "$craftStoragePath/$file";
            $target = $project->path("storage/$file");

            if (! file_exists($source)) {
                continue;
            }

            if (file_exists($target)) {
                $logger->warning("storage/$file already exists, so $source wasn’t moved.");

                continue;
            }

            if (! is_dir($project->path('storage'))) {
                mkdir($project->path('storage'), recursive: true);
            }

            rename($source, $target);
            chmod($target, 0600);
            $moved = true;
            $logger->success("Moved $file to storage/");
        }

        if ($moved) {
            $project->suggest(NextStepSection::OAuth, 'Move <options=bold>oauth-private.key</> and <options=bold>oauth-public.key</> from <options=bold>CRAFT_STORAGE_PATH</> into storage/ in your other environments, so existing OAuth tokens keep working');
        }
    }

    /**
     * Returns the custom Craft storage path, or `null` if Craft used storage/.
     */
    private function craftStoragePath(Project $project): ?string
    {
        $craftStoragePath = $project->env('CRAFT_STORAGE_PATH');

        if ($craftStoragePath === null) {
            return null;
        }

        if (! str_starts_with($craftStoragePath, '/') && ! preg_match('#^[a-zA-Z]:[/\\\\]#', $craftStoragePath)) {
            $craftStoragePath = $project->path($craftStoragePath);
        }

        $craftStoragePath = rtrim(str_replace('\\', '/', $craftStoragePath), '/');

        if (realpath($craftStoragePath) !== false && realpath($craftStoragePath) === realpath($project->path('storage'))) {
            return null;
        }

        return $craftStoragePath;
    }
}
