<?php

namespace CraftCms\Revamp;

use CraftCms\Revamp\Support\Yaml;
use Dotenv\Dotenv;

/**
 * The project being upgraded, and the state that checks, steps, and advisors share.
 */
class Project
{
    /** The web root folder, relative to the project root. Updated once it’s renamed. */
    public string $publicPath;

    /** The web root folder before the upgrade. */
    public string $originalPublicPath;

    /** Whether the web root will be renamed to public/. */
    public bool $renamePublicPath = false;

    /** @var array<string, string[]> Next steps, indexed by section name */
    private array $nextSteps = [];

    public function __construct(public readonly string $basePath) {}

    public function path(string $relativePath = ''): string
    {
        return $relativePath === '' ? $this->basePath : "$this->basePath/$relativePath";
    }

    /**
     * The web root folder name once the upgrade is done.
     */
    public function targetPublicPath(): string
    {
        return $this->renamePublicPath ? 'public' : $this->publicPath;
    }

    public function isDdev(): bool
    {
        return file_exists($this->path('.ddev/config.yaml'));
    }

    /**
     * Returns an environment variable’s value from the project’s .env files, or `null` if it isn’t set.
     */
    public function env(string $name): ?string
    {
        foreach (['.env', '.ddev/.env.web', '.ddev/.env'] as $envPath) {
            if (file_exists($this->path($envPath))) {
                $value = Dotenv::parse(file_get_contents($this->path($envPath)))[$name] ?? null;

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The project config directory, before or after it moves into config/craft/.
     */
    public function projectConfigDir(): string
    {
        return file_exists($this->path('config/project/project.yaml'))
            ? $this->path('config/project')
            : $this->path('config/craft/project');
    }

    /**
     * @return array<mixed>
     */
    public function projectConfig(): array
    {
        return Yaml::decodeFromFile($this->projectConfigDir().'/project.yaml') ?? [];
    }

    /**
     * Adds a step to the Next steps that are listed once the upgrade is done.
     */
    public function suggest(NextStepSection $section, string $step): void
    {
        $this->nextSteps[$section->name][] = $step;
    }

    /**
     * Returns the Next steps in section order, then in the order they were added.
     *
     * @return string[]
     */
    public function nextSteps(): array
    {
        $steps = [];

        foreach (NextStepSection::cases() as $section) {
            array_push($steps, ...$this->nextSteps[$section->name] ?? []);
        }

        return $steps;
    }
}
