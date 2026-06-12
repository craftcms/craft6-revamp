<?php

namespace CraftCms\Prepper\Console;

use Closure;
use Composer\Semver\Semver;
use CraftCms\Prepper\Console\Support\Env;
use CraftCms\Prepper\Console\Support\Json;
use Dotenv\Dotenv;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Support\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\table;
use function Laravel\Prompts\task;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

class RevampCommand extends Command
{
    const MIN_CRAFT_VERSION = '5.9.0';

    const PHP_VERSION = '8.5';

    private string $publicPath;

    private bool $renamePublicPath = false;

    protected function configure(): void
    {
        $this
            ->setName('revamp')
            ->setDescription('Prepares a Craft 5 project for Craft 6')
            ->addArgument('path', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Prompt::setOutput($output);

        title('Craft 6 Revamp');

        $path = str_replace('\\', '/', $input->getArgument('path') ?? getcwd());
        $composerJsonPath = "$path/composer.json";

        if (! file_exists($composerJsonPath)) {
            error("No composer.json file found at $path.");

            return self::FAILURE;
        }

        $composerLockPath = "$path/composer.lock";
        if (! file_exists($composerLockPath)) {
            error("No composer.lock file found at $path. Run `composer install` first.");

            return self::FAILURE;
        }

        $craftVersion = $this->findCraftVersion($composerLockPath);

        if (! $craftVersion) {
            error("No Craft project found at $path.");

            return self::FAILURE;
        }

        if (
            ! preg_match('/^[\d\.]+$/', $craftVersion) ||
            ! Semver::satisfies($craftVersion, sprintf('^%s', self::MIN_CRAFT_VERSION))
        ) {
            error(sprintf('The project must be running Craft CMS %s or later.', self::MIN_CRAFT_VERSION));

            return self::FAILURE;
        }

        if ($this->findPublicPath($path) === self::FAILURE) {
            return self::FAILURE;
        }

        if ($this->publicPath !== 'public' && ! file_exists("$path/public")) {
            $this->renamePublicPath = confirm(
                label: "Would you like to rename {$this->publicPath} to public?",
            );
        }

        $warning = "This script will replace {$this->publicPath}/index.php";
        if (file_exists("$path/bootstrap.php")) {
            $warning .= ' and remove bootstrap.php';
        }

        warning($warning);

        if (! confirm(label: 'Proceed?', hint: 'Any customizations will be lost.')) {
            warning('Cancelled.');

            return self::FAILURE;
        }

        $this->runSteps([
            'Updating composer.json' => fn (Logger $logger) => $this->updateComposer($logger, $composerJsonPath),
            'Updating DDEV configuration' => fn (Logger $logger) => $this->updateDdevConfig($logger, $path),
            'Updating environment variables' => fn (Logger $logger) => $this->updateEnvVars($logger, $path),
            'Creating the artisan executable' => fn (Logger $logger) => $this->addArtisan($logger, $path),
            'Creating Laravel bootstrap files' => fn (Logger $logger) => $this->addBootstrap($logger, $path),
            'Creating framework storage folders' => fn (Logger $logger) => $this->addFrameworkFolders($logger, $path),
            'Moving Craft config directory' => fn (Logger $logger) => $this->moveConfigDirectory($logger, $path),
            'Renaming translations directory' => fn (Logger $logger) => $this->renameTranslations($logger, $path),
            'Moving templates directory' => fn (Logger $logger) => $this->moveTemplates($logger, $path),
            'Renaming the public folder' => fn (Logger $logger) => $this->renamePublic($logger, $path),
            "Updating {$this->publicPath}/index.php" => fn (Logger $logger) => $this->updateIndex($logger, $path),
            'Removing the Craft executable' => fn (Logger $logger) => $this->removeCraft($logger, $path),
            'Removing old bootstrap.php' => fn (Logger $logger) => $this->removeOldBootstrap($logger, $path),
        ]);

        outro('Finished preparing your project for Craft 6!');

        $steps = [];

        $generalConfigPath = "$path/config/craft/general.php";
        if (file_exists($generalConfigPath)) {
            $generalConfig = file_get_contents($generalConfigPath);

            if ($this->renamePublicPath) {
                $aliases = collect(['web', 'webroot'])
                    ->filter(fn (string $alias) => preg_match("/\b@?$alias\b/", $generalConfig))
                    ->all();

                if (! empty($aliases)) {
                    $steps[] = sprintf(
                        'Update the %s %s in config/craft/general.php',
                        implode(' and ', array_map(fn (string $alias) => "@$alias", $aliases)),
                        count($aliases) === 1 ? 'alias' : 'aliases',
                    );
                }
            }

            $deprecatedSettings = collect(['omitScriptNameInUrls', 'pathParam'])
                ->filter(fn (string $setting) => preg_match("/\b$setting\b/", $generalConfig))
                ->all();

            if (! empty($deprecatedSettings)) {
                $steps[] = sprintf(
                    'Remove `%s` from config/craft/general.php',
                    implode(' and ', array_map(fn (string $setting) => $setting, $deprecatedSettings)),
                );
            }
        }

        if ($this->isDdev($path)) {
            $steps[] = 'Run ddev restart to pick up the new project type and configuration';
        }

        $ddevPrefix = $this->isDdev($path) ? 'ddev ' : '';
        $steps[] = "Run <options=bold>{$ddevPrefix}composer update</>";
        $steps[] = "Run <options=bold>{$ddevPrefix}artisan craft:setup:publish</>";
        $steps[] = "Run <options=bold>{$ddevPrefix}artisan key:generate</>";

        info('Next steps');
        table(
            ['#', 'Step'],
            collect($steps)
                ->map(fn (string $step, int $index) => [$index + 1, $step])
                ->all(),
        );

        return self::SUCCESS;
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

    /**
     * @param  array<string, Closure(Logger $logger): void>  $steps
     */
    private function runSteps(array $steps): void
    {
        foreach ($steps as $label => $step) {
            task(Str::finish($label, '...'), fn (Logger $logger) => $step($logger), keepSummary: true);
        }
    }

    private function findPublicPath(string $path): int
    {
        // first check the usual suspects
        $testPaths = [
            'public',
            'public_html',
            'web',
            'html',
        ];

        foreach ($testPaths as $testPath) {
            if (file_exists("$path/$testPath/index.php")) {
                $this->publicPath = $testPath;

                return self::SUCCESS;
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
                $this->publicPath = substr($dirPath, strlen($path) + 1);

                return self::SUCCESS;
            }
        }

        error('No public folder could be found.');

        return self::FAILURE;
    }

    private function updateComposer(Logger $logger, string $composerJsonPath): void
    {
        $config = Json::decodeFromFile($composerJsonPath);

        $config['require']['craftcms/cms'] = '^6.0.0-alpha.1';
        $config['require']['craftcms/yii2-adapter'] = '*';

        if (isset($config['require-dev']['craftcms/generator'])) {
            $config['require-dev']['craftcms/generator'] = '3.x-dev';
        } elseif (isset($config['require']['craftcms/generator'])) {
            $config['require']['craftcms/generator'] = '3.x-dev';
        }
        $logger->success('Updated dependencies');

        unset($config['require']['vlucas/phpdotenv']);
        unset($config['config']['platform']['php']);

        // Otherwise it ends up as `platform: []` which is invalid.
        if (empty($config['config']['platform'])) {
            unset($config['config']['platform']);
        }
        $logger->success('Removed platform.php config');

        if (! isset($config['scripts']['post-autoload-dump'])) {
            $config['scripts']['post-autoload-dump'] = [];
        }

        $scripts = [
            'Illuminate\Foundation\ComposerScripts::postAutoloadDump',
            '@php artisan package:discover --ansi',
            '@php artisan craft:setup:publish --ansi',
        ];

        foreach ($scripts as $script) {
            if (! in_array($script, $config['scripts']['post-autoload-dump'])) {
                $config['scripts']['post-autoload-dump'][] = $script;
            }
        }
        $logger->success('Added post-autoload-dump scripts');

        Json::encodeToFile($composerJsonPath, $config);
        $logger->success('composer.json updated');
    }

    private function updateDdevConfig(Logger $logger, string $path): void
    {
        if (! $this->isDdev($path)) {
            $logger->warning('No .ddev/config.yaml file detected.');

            return;
        }

        $ddevConfigPath = "$path/.ddev/config.yaml";
        $ddevConfig = file_get_contents($ddevConfigPath);

        $this->updateDdevProjectType($logger, $ddevConfig);
        $this->updateDdevPhpVersion($logger, $ddevConfig);
        $this->updateDdevDocroot($logger, $ddevConfig);

        file_put_contents($ddevConfigPath, $ddevConfig);
        $logger->success('.ddev/config.yaml updated');
    }

    private function updateDdevProjectType(Logger $logger, string &$ddevConfig): void
    {
        if (! preg_match('/^type:\s+[\'"]?([\w\d]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No project type detected in .ddev/config.yaml.');

            return;
        }

        if ($match[1][0] === 'laravel') {
            $logger->success('Project type already set to Laravel.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            'type: laravel'.
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));
        $logger->success('Project type updated.');
    }

    private function updateDdevPhpVersion(Logger $logger, string &$ddevConfig): void
    {
        if (! preg_match('/^php_version:\s+[\'"]?([\d\.]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No php_version detected in .ddev/config.yaml.');

            return;
        }

        if ($match[1][0] === self::PHP_VERSION) {
            $logger->success('PHP version already set to '.self::PHP_VERSION.'.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            sprintf('php_version: "%s"', self::PHP_VERSION).
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));

        $logger->success('PHP version updated to '.self::PHP_VERSION.'.');
    }

    private function updateDdevDocroot(Logger $logger, string &$ddevConfig): void
    {
        if (! $this->renamePublicPath) {
            return;
        }

        if (! preg_match('/^docroot:\s+[\'"]?([\w+\/]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $logger->warning('No docroot detected in .ddev/config.yaml.');

            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]).
            'docroot: public'.
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));

        $logger->success('Docroot updated in .ddev/config.yaml.');
    }

    private function isDdev(string $path): bool
    {
        return file_exists("$path/.ddev/config.yaml");
    }

    private function updateEnvVars(Logger $logger, string $path): void
    {
        $envPaths = collect([
            '.ddev/.env',
            '.ddev/.env.web',
            '.env',
        ]);

        $map = [
            'CRAFT_DB_DRIVER' => 'DB_CONNECTION',
            'CRAFT_DB_SERVER' => 'DB_HOST',
            'CRAFT_DB_PORT' => 'DB_PORT',
            'CRAFT_DB_USER' => 'DB_USERNAME',
            'CRAFT_DB_PASSWORD' => 'DB_PASSWORD',
            'CRAFT_DB_DATABASE' => 'DB_DATABASE',
            'CRAFT_DB_SCHEMA' => 'DB_SCHEMA',
            'CRAFT_DB_TABLE_PREFIX' => 'DB_TABLE_PREFIX',
            'CRAFT_DEV_MODE' => 'APP_DEBUG',
            'CRAFT_SECURITY_KEY' => false,
        ];

        foreach ($envPaths as $envPath) {
            $fullPath = "$path/$envPath";

            if (! file_exists($fullPath)) {
                continue;
            }

            $vars = Dotenv::parse(file_get_contents($fullPath));
            $remove = [];
            $add = [];

            foreach ($map as $old => $new) {
                if (isset($vars[$old])) {
                    $remove[] = $old;
                    if ($new !== false) {
                        $add[$new] = $vars[$old];
                    }
                }
            }

            if ($envPath === '.env') {
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

    private function addArtisan(Logger $logger, string $path): void
    {
        $artisanPath = "$path/artisan";

        if (file_exists($artisanPath)) {
            $logger->success('Artisan executable already exists.');

            return;
        }

        $contents = <<<PHP
#!/usr/bin/env php
<?php

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

define('LARAVEL_START', microtime(true));

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the command...
/** @var Application \$app */
\$app = require_once __DIR__.'/bootstrap/app.php';

\$status = \$app->handleCommand(new ArgvInput);

exit(\$status);

PHP;

        file_put_contents($artisanPath, $contents);
        @chmod($artisanPath, 0755);

        $logger->success('Added Artisan executable.');
    }

    private function addBootstrap(Logger $logger, string $path): void
    {
        $dirs = [
            'bootstrap',
            'bootstrap/cache',
        ];

        foreach ($dirs as $dir) {
            $dirPath = "$path/$dir";
            if (! file_exists($dirPath)) {
                mkdir($dirPath, recursive: true);
            }
        }

        if (! file_exists("$path/bootstrap/cache/.gitignore")) {
            file_put_contents("$path/bootstrap/cache/.gitignore", "*\n!.gitignore");
        }

        $appPath = "$path/bootstrap/app.php";

        if (file_exists($appPath)) {
            $logger->success('bootstrap/app.php already exists.');

            return;
        }

        $contents = <<<PHP
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        health: '/up',
    )
    ->withMiddleware(function (Middleware \$middleware): void {
        //
    })
    ->withExceptions(function (Exceptions \$exceptions): void {
        //
    })

PHP;
        if ($this->publicPath !== 'public' && ! $this->renamePublicPath) {
            $contents .= <<<PHP
    ->usePublicPath(base_path('$this->publicPath'))

PHP;
        }

        $contents .= <<<'PHP'
    ->create();

PHP;

        file_put_contents($appPath, $contents);

        $logger->success('Laravel Bootstrap created.');
    }

    private function addFrameworkFolders(Logger $logger, string $path): void
    {
        $dirs = [
            'storage/framework' => false,
            'storage/framework/cache' => true,
            'storage/framework/sessions' => true,
            'storage/framework/views' => true,
        ];

        foreach ($dirs as $dir => $addGitIgnore) {
            $dirPath = "$path/$dir";

            if (! file_exists($dirPath)) {
                mkdir($dirPath, recursive: true);
            }

            if ($addGitIgnore && ! file_exists("$path/$dir/.gitignore")) {
                file_put_contents("$path/$dir/.gitignore", "*\n!.gitignore");
            }

            $logger->success("Created $dir");
        }
    }

    private function moveConfigDirectory(Logger $logger, string $path): void
    {
        $configPath = "$path/config";

        if (! is_dir($configPath)) {
            $logger->warning('No config directory found at /config.');

            return;
        }

        $targetPath = "$configPath/craft";

        if (is_dir($targetPath)) {
            $logger->success('Config directory at /config/craft exists.');

            return;
        }

        rename($configPath, "$path/craft-config");
        mkdir($targetPath, recursive: true);
        rename("$path/craft-config", $targetPath);

        $logger->success('Config created directory at /config/craft.');
    }

    private function renameTranslations(Logger $logger, string $path): void
    {
        $translationsPath = "$path/translations";

        if (! is_dir($translationsPath)) {
            $logger->warning('No translations directory found at /translations.');

            return;
        }

        rename($translationsPath, "$path/lang");

        $logger->success('Translations directory renamed from /translations to /lang.');
    }

    private function moveTemplates(Logger $logger, string $path): void
    {
        $templatesPath = "$path/templates";

        if (! is_dir($templatesPath)) {
            $logger->warning('No templates directory found at /templates.');

            return;
        }

        if (is_dir("$path/resources/views")) {
            $overwrite = confirm("The /resources/views directory already exists. Do you want to move the templates there?");

            if (! $overwrite) {
                $logger->warning('Not renaming templates directory as /resources/views already exists.');

                return;
            }

            rename("$path/resources/views", "$path/resources/views-old");
        }

        if (! is_dir("$path/resources")) {
            mkdir("$path/resources", recursive: true);
        }

        rename($templatesPath, "$path/resources/views");

        $logger->success('Templates directory moved from /templates to /resources/views.');
    }

    private function renamePublic(Logger $logger, string $path): void
    {
        if (! $this->renamePublicPath) {
            $logger->success('Not renaming public path.');

            return;
        }

        rename("$path/$this->publicPath", "$path/public");

        $logger->success("Public path renamed from /$this->publicPath to /public.");

        $this->publicPath = 'public';
    }

    private function updateIndex(Logger $logger, string $path): void
    {
        $contents = <<<PHP
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists(\$maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require \$maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application \$app */
\$app = require_once __DIR__.'/../bootstrap/app.php';

\$app->handleRequest(Request::capture());

PHP;

        file_put_contents("$path/$this->publicPath/index.php", $contents);

        $logger->success("/$this->publicPath/index.php updated");
    }

    private function removeCraft(Logger $logger, string $path): void
    {
        $craftPath = "$path/craft";

        if (! file_exists($craftPath)) {
            $logger->success('No craft executable exists.');

            return;
        }

        unlink($craftPath);

        $logger->success('Craft executable removed.');
        $logger->warning('The craft executable will be re-created the first time you publish assets with Laravel.');
    }

    private function removeOldBootstrap(Logger $logger, string $path): void
    {
        $bootstrapPath = "$path/bootstrap.php";

        if (! file_exists($bootstrapPath)) {
            $logger->success('No bootstrap.php file exists.');

            return;
        }

        unlink($bootstrapPath);

        $logger->success('bootstrap.php file removed.');
    }
}
