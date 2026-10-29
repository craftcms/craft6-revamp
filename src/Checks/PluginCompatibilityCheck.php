<?php

namespace CraftCms\Revamp\Checks;

use CraftCms\Revamp\PluginCompatibility;
use CraftCms\Revamp\NextStepSection;
use CraftCms\Revamp\Project;

use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

/**
 * Reports which packages that require craftcms/cms have a Craft 6 version on Packagist. Never cancels.
 */
class PluginCompatibilityCheck implements Check
{
    public function passes(Project $project): bool
    {
        $compatibility = new PluginCompatibility;
        $dependents = $compatibility->dependents($project->path('composer.lock'));

        if ($dependents === []) {
            return true;
        }

        info('Checking Packagist for Craft 6 versions of packages that require craftcms/cms…');
        $results = $compatibility->check(array_keys($dependents));

        table(
            ['Package', 'Installed', 'Craft 6'],
            collect($results)
                ->map(fn (array $result, string $name) => [
                    $name,
                    $dependents[$name],
                    match ($result['status']) {
                        PluginCompatibility::STATUS_COMPATIBLE => "✓ {$result['version']}",
                        PluginCompatibility::STATUS_INCOMPATIBLE => '✗ No Craft 6 version yet',
                        PluginCompatibility::STATUS_NOT_FOUND => '? Not on Packagist',
                        default => '? Packagist couldn’t be reached',
                    },
                ])
                ->values()
                ->all(),
        );

        $incompatible = array_keys(array_filter(
            $results,
            fn (array $result) => $result['status'] === PluginCompatibility::STATUS_INCOMPATIBLE,
        ));

        if ($incompatible !== []) {
            warning('composer update won’t resolve until the packages without a Craft 6 version are removed or replaced.');

            $project->suggest(NextStepSection::Packages, sprintf(
                'Remove or replace %s, which %s no Craft 6 version on Packagist yet, so <options=bold>composer update</> can resolve',
                implode(', ', array_map(fn (string $name) => "<options=bold>$name</>", $incompatible)),
                count($incompatible) === 1 ? 'has' : 'have',
            ));
        }

        return true;
    }
}
