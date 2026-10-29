<?php

namespace CraftCms\Revamp\Checks;

use Composer\Semver\Semver;
use CraftCms\Revamp\Project;
use CraftCms\Revamp\Support\Json;

use function Laravel\Prompts\error;

class CraftVersionCheck implements Check
{
    const MIN_CRAFT_VERSION = '5.9.0';

    public function passes(Project $project): bool
    {
        if (! file_exists($project->path('composer.json'))) {
            error("No composer.json file found at {$project->path()}.");

            return false;
        }

        if (! file_exists($project->path('composer.lock'))) {
            error("No composer.lock file found at {$project->path()}. Run <options=bold>composer install</> first.");

            return false;
        }

        $craftVersion = $this->findCraftVersion($project->path('composer.lock'));

        if (! $craftVersion) {
            error("No Craft project found at {$project->path()}.");

            return false;
        }

        if (
            ! preg_match('/^[\d\.]+$/', $craftVersion) ||
            ! Semver::satisfies($craftVersion, sprintf('^%s', self::MIN_CRAFT_VERSION))
        ) {
            error(sprintf('The project must be running Craft CMS %s or later.', self::MIN_CRAFT_VERSION));

            return false;
        }

        return true;
    }

    private function findCraftVersion(string $composerLockPath): ?string
    {
        $lockData = Json::decodeFromFile($composerLockPath);
        foreach ($lockData['packages'] as $package) {
            if ($package['name'] === 'craftcms/cms') {
                return $package['version'];
            }
        }

        return null;
    }
}
