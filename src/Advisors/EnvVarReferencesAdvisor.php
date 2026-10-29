<?php

namespace CraftCms\Revamp\Advisors;

use CraftCms\Revamp\CraftConfigUpdater;
use CraftCms\Revamp\NextStepSection;
use CraftCms\Revamp\Project;
use CraftCms\Revamp\Steps\UpdateEnvVars;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Flags references to the renamed Craft environment variables outside .env files, which the tool only renames in .env files.
 */
class EnvVarReferencesAdvisor implements Advisor
{
    /**
     * Files and folders to search, relative to the project root. Globs are allowed.
     */
    private const PATHS = [
        'config',
        '.github',
        'docker-compose*.yml',
        'docker-compose*.yaml',
        'Dockerfile*',
        '.platform*',
        'fly.toml',
        'render.yaml',
    ];

    public function advise(Project $project): void
    {
        $references = [];

        foreach ($this->files($project) as $file) {
            $contents = file_get_contents($file);
            if (str_ends_with($file, '.php')) {
                $contents = CraftConfigUpdater::stripComments($contents);
            }

            if (! preg_match_all('/\bCRAFT_(?:DB_[A-Z_]+|DEV_MODE|SECURITY_KEY)\b/', $contents, $matches)) {
                continue;
            }

            foreach (array_unique($matches[0]) as $name) {
                $references[substr($file, strlen($project->path()) + 1)][] = $name;
            }
        }

        if ($references === []) {
            return;
        }

        ksort($references);
        $lines = [];

        foreach ($references as $file => $names) {
            sort($names);
            $lines[] = "$file: ".implode(', ', array_map($this->rename(...), $names));
        }

        $project->suggest(NextStepSection::EnvVars, "These files still reference Craft environment variables that were renamed in .env. Update them:\n\n".implode("\n", $lines));
    }

    private function rename(string $name): string
    {
        $new = UpdateEnvVars::MAP[$name] ?? null;

        return match (true) {
            is_string($new) => "<options=bold>$name</> → <options=bold>$new</>",
            $new === false => "<options=bold>$name</> (remove it; Laravel uses <options=bold>APP_KEY</>)",
            default => "<options=bold>$name</> (no Laravel equivalent; use config/database.php)",
        };
    }

    /**
     * @return string[]
     */
    private function files(Project $project): array
    {
        $files = [];

        foreach (self::PATHS as $pattern) {
            foreach (glob($project->path($pattern), GLOB_BRACE) ?: [] as $path) {
                if (is_file($path)) {
                    $files[] = $path;

                    continue;
                }

                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $fileInfo) {
                    $file = str_replace('\\', '/', $fileInfo->getPathname());

                    // Project config YAML, the license key, and other non-code files
                    if (str_starts_with($file, $project->path('config/')) && $fileInfo->getExtension() !== 'php') {
                        continue;
                    }

                    if ($fileInfo->isFile()) {
                        $files[] = $file;
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }
}
