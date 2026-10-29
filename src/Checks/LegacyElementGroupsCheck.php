<?php

namespace CraftCms\Prepper\Console\Checks;

use CraftCms\Prepper\Console\NextStepSection;
use CraftCms\Prepper\Console\Project;
use CraftCms\Prepper\Console\Support\Yaml;

use function Laravel\Prompts\note;
use function Laravel\Prompts\warning;

/**
 * Reports category groups, tag groups, and global sets, which should be entrified on Craft 5 first. Never cancels.
 */
class LegacyElementGroupsCheck implements Check
{
    /**
     * Project config keys, with their label and entrify command.
     */
    private const TYPES = [
        'categoryGroups' => ['category groups', 'categories'],
        'tagGroups' => ['tag groups', 'tags'],
        'globalSets' => ['global sets', 'global-set'],
    ];

    public function passes(Project $project): bool
    {
        $labels = [];
        $commands = [];

        foreach (self::TYPES as $key => [$label, $command]) {
            $handles = $this->handles($project, $key);

            if ($handles !== []) {
                $labels[] = $label;
                array_push($commands, ...array_map(fn (string $handle) => "php craft entrify/$command $handle", $handles));
            }
        }

        if ($labels === []) {
            return true;
        }

        warning(sprintf(
            'The project has %s. Craft 6 only supports them as legacy elements through craftcms/yii2-adapter. To convert them to entries, cancel and run these commands on Craft 5 first:',
            implode(', ', $labels),
        ));
        note(implode("\n", $commands));

        $project->suggest(NextStepSection::LegacyElements, sprintf(
            'Craft 6 only supports %s as legacy elements through craftcms/yii2-adapter. To convert them to entries, restore the Craft 5 project and run %s, then run this tool again',
            implode(', ', $labels),
            implode(', ', $commands),
        ));

        return true;
    }

    /**
     * @return string[]
     */
    private function handles(Project $project, string $key): array
    {
        $projectConfig = $project->projectConfig();
        $configs = is_array($projectConfig[$key] ?? null) ? array_values($projectConfig[$key]) : [];

        foreach (glob($project->projectConfigDir()."/$key/*.yaml") ?: [] as $file) {
            $configs[] = Yaml::decodeFromFile($file);
        }

        return collect($configs)
            ->map(fn (mixed $config) => is_array($config) ? ($config['handle'] ?? null) : null)
            ->filter(fn (mixed $handle) => is_string($handle))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
