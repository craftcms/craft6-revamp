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
    public function __construct(
        private ?PluginCompatibility $compatibility = null,
    ) {}

    public function passes(Project $project): bool
    {
        $compatibility = $this->compatibility ?? new PluginCompatibility;
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

        // Plugins that Laravel configuration replaces, indexed by package name
        $obsolete = array_filter(array_map(
            fn (string $name) => PluginCompatibility::obsoleteReplacement($name),
            array_combine(array_keys($dependents), array_keys($dependents)),
        ));

        if ($obsolete !== []) {
            warning(sprintf(
                'Craft 6 configures filesystems, mail, and logging through Laravel, so %s %s probably no longer needed.',
                implode(', ', array_keys($obsolete)),
                count($obsolete) === 1 ? 'is' : 'are',
            ));

            foreach ($obsolete as $name => $replacement) {
                $project->suggest(NextStepSection::Packages, "<options=bold>$name</> is probably no longer needed in Craft 6. Replace it with $replacement, then remove it from composer.json");
            }
        }

        $incompatible = array_keys(array_filter(
            $results,
            fn (array $result, string $name) => $result['status'] === PluginCompatibility::STATUS_INCOMPATIBLE && ! isset($obsolete[$name]),
            ARRAY_FILTER_USE_BOTH,
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
