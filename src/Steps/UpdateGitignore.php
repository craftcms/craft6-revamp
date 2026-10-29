<?php

namespace CraftCms\Prepper\Console\Steps;

use CraftCms\Prepper\Console\Project;
use Laravel\Prompts\Support\Logger;

class UpdateGitignore implements Step
{
    public function label(Project $project): string
    {
        return 'Updating .gitignore';
    }

    public function shouldRun(Project $project): bool
    {
        return $this->missingEntries($project) !== [];
    }

    public function run(Project $project, Logger $logger): void
    {
        $gitignorePath = $project->path('.gitignore');
        $contents = file_exists($gitignorePath) ? file_get_contents($gitignorePath) : '';
        $missing = $this->missingEntries($project);

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        file_put_contents($gitignorePath, $contents.implode("\n", $missing)."\n");

        $logger->success(sprintf('Added %s to .gitignore', implode(', ', $missing)));
    }

    /**
     * @return string[]
     */
    private function missingEntries(Project $project): array
    {
        $gitignorePath = $project->path('.gitignore');
        $existing = file_exists($gitignorePath)
            ? array_map('trim', preg_split('/\R/', file_get_contents($gitignorePath)))
            : [];

        // Control panel assets published by craft:setup:publish, the public storage symlink, and the Passport keys
        return array_values(array_diff(['/public/vendor/craft', '/public/storage', '/storage/*.key'], $existing));
    }
}
