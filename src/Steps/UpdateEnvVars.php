<?php

namespace CraftCms\Revamp\Steps;

use CraftCms\Revamp\Project;
use CraftCms\Revamp\Support\Env;
use Dotenv\Dotenv;
use Laravel\Prompts\Support\Logger;

class UpdateEnvVars implements Step
{
    private const ENV_PATHS = [
        '.ddev/.env',
        '.ddev/.env.web',
        '.env',
        '.env.example',
        '.env.example.dev',
        '.env.example.staging',
        '.env.example.production',
    ];

    /**
     * Craft variables, and the Laravel variables that replace them (`false` to remove them).
     */
    private const MAP = [
        'CRAFT_DB_DRIVER' => 'DB_CONNECTION',
        'CRAFT_DB_SERVER' => 'DB_HOST',
        'CRAFT_DB_PORT' => 'DB_PORT',
        'CRAFT_DB_USER' => 'DB_USERNAME',
        'CRAFT_DB_PASSWORD' => 'DB_PASSWORD',
        'CRAFT_DB_DATABASE' => 'DB_DATABASE',
        'CRAFT_DB_SCHEMA' => 'DB_SCHEMA',
        'CRAFT_DB_TABLE_PREFIX' => 'DB_TABLE_PREFIX',
        'CRAFT_DB_TABLE' => 'DB_TABLE_PREFIX',
        'CRAFT_DEV_MODE' => 'APP_DEBUG',
        'CRAFT_SECURITY_KEY' => false,
    ];

    public function label(Project $project): string
    {
        return 'Updating environment variables';
    }

    public function shouldRun(Project $project): bool
    {
        foreach (self::ENV_PATHS as $envPath) {
            if (file_exists($project->path($envPath))) {
                return true;
            }
        }

        return false;
    }

    public function run(Project $project, Logger $logger): void
    {
        foreach (self::ENV_PATHS as $envPath) {
            $fullPath = $project->path($envPath);

            if (! file_exists($fullPath)) {
                continue;
            }

            $vars = Dotenv::parse(file_get_contents($fullPath));
            $remove = [];
            $add = [];

            foreach (self::MAP as $old => $new) {
                if (isset($vars[$old])) {
                    $remove[] = $old;
                    if ($new !== false && ! isset($add[$new])) {
                        $add[$new] = $vars[$old];
                    }
                }
            }

            // Without APP_ENV, Laravel assumes production. CRAFT_ENVIRONMENT and PRIMARY_SITE_URL are kept,
            // since templates and project config may still reference them.
            $environment = $vars['CRAFT_ENVIRONMENT'] ?? $vars['ENVIRONMENT'] ?? null;
            if (! isset($vars['APP_ENV']) && $environment !== null && $environment !== '') {
                $add['APP_ENV'] = $environment === 'dev' ? 'local' : $environment;
            }

            if (! isset($vars['APP_URL']) && ($vars['PRIMARY_SITE_URL'] ?? '') !== '') {
                $add['APP_URL'] = $vars['PRIMARY_SITE_URL'];
            }

            if ($envPath === '.env' || str_starts_with($envPath, '.env.example')) {
                $add['CACHE_STORE'] = 'file';
                $add['SESSION_DRIVER'] = 'file';
                $add['APP_KEY'] = '';
            }

            if (! empty($remove) || ! empty($add)) {
                foreach ($remove as $name) {
                    Env::removeVariable($name, $fullPath);
                }
                Env::writeVariables($add, $fullPath, true);
            }
        }

        $logger->success('Updated .env variables');
    }
}
