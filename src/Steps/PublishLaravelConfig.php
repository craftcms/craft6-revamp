<?php

namespace CraftCms\Revamp\Steps;

use FilesystemIterator;
use CraftCms\Revamp\Project;
use Laravel\Prompts\Support\Logger;

class PublishLaravelConfig implements Step
{
    public function label(Project $project): string
    {
        return 'Publishing Laravel configuration';
    }

    public function shouldRun(Project $project): bool
    {
        return true;
    }

    public function run(Project $project, Logger $logger): void
    {
        $sourcePath = __DIR__.'/../../resources/config';

        if (! is_dir($sourcePath)) {
            $logger->error('Laravel configuration files could not be found.');

            return;
        }

        $targetPath = $project->path('config');
        if (! is_dir($targetPath)) {
            mkdir($targetPath, recursive: true);
        }

        $published = 0;

        foreach (new FilesystemIterator($sourcePath) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $targetFile = "$targetPath/{$file->getFilename()}";

            if (file_exists($targetFile)) {
                continue;
            }

            copy($file->getPathname(), $targetFile);
            $published++;
        }

        $logger->success("Published $published Laravel configuration files.");
    }
}
