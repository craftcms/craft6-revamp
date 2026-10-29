<?php

namespace CraftCms\Revamp\Checks;

use CraftCms\Revamp\Project;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;

/**
 * Finds the web root, and asks whether to rename it to public/.
 */
class PublicPathCheck implements Check
{
    public function passes(Project $project): bool
    {
        $publicPath = $this->findPublicPath($project->path());

        if ($publicPath === null) {
            error('No public folder could be found.');

            return false;
        }

        $project->publicPath = $publicPath;
        $project->originalPublicPath = $publicPath;

        if ($publicPath !== 'public' && ! file_exists($project->path('public'))) {
            $project->renamePublicPath = confirm(
                label: "Would you like to rename $publicPath to public?",
            );
        }

        return true;
    }

    private function findPublicPath(string $path): ?string
    {
        // first check the usual suspects
        foreach (['public', 'public_html', 'web', 'html'] as $testPath) {
            if (file_exists("$path/$testPath/index.php")) {
                return $testPath;
            }
        }

        // go with the first non-vendor folder that contains an index.php file
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $fileInfo) {
            if (! $fileInfo->isDir()) {
                continue;
            }

            $dirPath = str_replace('\\', '/', $fileInfo->getPath());
            if (
                str_ends_with($dirPath, '/.') ||
                str_ends_with($dirPath, '/..') ||
                str_contains($dirPath, '/vendor/')
            ) {
                continue;
            }

            if (file_exists("$dirPath/index.php")) {
                return substr($dirPath, strlen($path) + 1);
            }
        }

        return null;
    }
}
