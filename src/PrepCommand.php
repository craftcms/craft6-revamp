<?php

namespace CraftCms\Prepper\Console;

use Composer\Semver\Semver;
use CraftCms\Prepper\Console\Support\Env;
use CraftCms\Prepper\Console\Support\Json;
use Dotenv\Dotenv;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\confirm;

class PrepCommand extends Command
{
    const MIN_CRAFT_VERSION = '5.8.0';
    const PHP_VERSION = '8.4';

    private string $publicPath;
    private bool $renamePublicPath = false;

    protected function configure()
    {
        $this
            ->setName('prep')
            ->setDescription('Prepares a Craft 5 project for Craft 6')
            ->addArgument('path', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = str_replace('\\', '/', $input->getArgument('path') ?? getcwd());
        $composerJsonPath = "$path/composer.json";
        if (! file_exists($composerJsonPath)) {
            throw new RuntimeException("No composer.json file found at $path.");
        }

        $composerLockPath = "$path/composer.lock";
        if (! file_exists($composerLockPath)) {
            throw new RuntimeException("No composer.lock file found at $path. Run `composer install` first.");
        }

        $craftVersion = $this->findCraftVersion($composerLockPath);

        if (! $craftVersion) {
            throw new RuntimeException("No Craft project found at $path.");
        }

        if (
            ! preg_match('/^[\d\.]+$/', $craftVersion) ||
            ! Semver::satisfies($craftVersion, sprintf('^%s', self::MIN_CRAFT_VERSION))
        ) {
            throw new RuntimeException(sprintf('The project must be running Craft CMS %s or later.', self::MIN_CRAFT_VERSION));
        }

        $output->write('<fg=gray>➜</> Finding the public folder … ');
        $this->findPublicPath($path, $output);
        $output->writeln("<fg=green>done (<options=bold>$this->publicPath</>)</>");

        if ($this->publicPath !== 'public' && ! file_exists("$path/public")) {
            $this->renamePublicPath = confirm(
                label: sprintf('Would you like to rename <options=bold>%s</> to <options=bold>public</>?', $this->publicPath),
            );
        }

        $this->updateComposer($composerJsonPath, $output);
        $this->updateDdevConfig($path, $output);
        $this->updateEnvVars($path, $output);
        $this->addArtisan($path, $output);
        $this->addBootstrap($path, $output);
        $this->addFrameworkFolders($path, $output);

        $this->renamePublic($path, $output);
        $this->updateIndex($path, $output);
        $this->removeCraft($path, $output);
        $this->removeOldBootstrap($path, $output);

        $output->writeln(PHP_EOL."  <bg=blue;fg=white> INFO </> Finished preparing your project for Craft 6! Now run the following commands to complete the update:".PHP_EOL);

        if ($this->isDdev($path)) {
            $output->writeln("<fg=gray>➜</> <options=bold>ddev restart</>");
        }

        $ddevPrefix = $this->isDdev($path) ? 'ddev ' : '';
        $output->writeln("<fg=gray>➜</> <options=bold>{$ddevPrefix}composer update</>");
        $output->writeln("<fg=gray>➜</> <options=bold>{$ddevPrefix}php artisan vendor:publish --tag=craftcms</>");

        $output->writeln(PHP_EOL."  <bg=blue;fg=white> INFO </> You will also need to remove the following config settings from <options=bold>config/general.php</>:".PHP_EOL);
        $output->writeln('<fg=gray>➜</> <options=bold>omitScriptNameInUrls</>');
        $output->writeln('<fg=gray>➜</> <options=bold>pathParam</>');

        return 0;
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

    private function findPublicPath(string $path, OutputInterface $output): void
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
                return;
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
                return;
            }
        }

        throw new RuntimeException('No public folder could be found.');
    }

    private function updateComposer(string $composerJsonPath, OutputInterface $output): void
    {
        $config = Json::decodeFromFile($composerJsonPath);
        $config['require']['craftcms/cms'] = '6.x-dev as 5.8.0';
        unset($config['config']['platform']['php']);

        // Otherwise it ends up as `platform: []` which is invalid.
        if (empty($config['config']['platform'])) {
            unset($config['config']['platform']);
        }

        if (! isset($config['scripts']['post-autoload-dump'])) {
            $config['scripts']['post-autoload-dump'] = [];
        }

        $scripts = [
            'Illuminate\Foundation\ComposerScripts::postAutoloadDump',
            '@php artisan package:discover --ansi',
        ];

        foreach ($scripts as $script) {
            if (!in_array($script, $config['scripts']['post-autoload-dump'])) {
                $config['scripts']['post-autoload-dump'][] = $script;
            }
        }

        $output->write('<fg=gray>➜</> Updating <options=bold>composer.json</> … ');
        Json::encodeToFile($composerJsonPath, $config);
        $output->writeln('<fg=green>done</>');
    }

    private function updateDdevConfig(string $path, OutputInterface $output): void
    {
        if (! $this->isDdev($path)) {
            $output->writeln('<fg=gray>➜</> <fg=yellow>No .ddev/config.yaml file detected.</>');
            return;
        }

        $ddevConfigPath = "$path/.ddev/config.yaml";
        $ddevConfig = file_get_contents($ddevConfigPath);

        $this->updateDdevPhpVersion($ddevConfig, $output);
        $this->updateDdevDocroot($ddevConfig, $output);

        $output->write('<fg=gray>➜</> Updating <options=bold>.ddev/config.yaml</> … ');
        file_put_contents($ddevConfigPath, $ddevConfig);
        $output->writeln('<fg=green>done</>');
    }

    private function updateDdevPhpVersion(string &$ddevConfig, OutputInterface $output): void
    {
        if (! preg_match('/^php_version:\s+[\'"]?([\d\.]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $output->writeln('<fg=gray>➜</> <fg=red>No php_version detected in .ddev/config.yaml.</>');
            return;
        }

        if ($match[1][0] === self::PHP_VERSION) {
            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]) .
            sprintf('php_version: "%s"', self::PHP_VERSION) .
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));
    }

    private function updateDdevDocroot(string &$ddevConfig, OutputInterface $output): void
    {
        if (! $this->renamePublicPath) {
            return;
        }
        if (! preg_match('/^docroot:\s+[\'"]?([\w+\/]+)[\'"]?\s*$/m', $ddevConfig, $match, PREG_OFFSET_CAPTURE)) {
            $output->writeln('<fg=gray>➜</> <fg=red>No docroot detected in .ddev/config.yaml.</>');
            return;
        }

        // use str_replace() instead of symfony/yaml so we don't lose the comments
        $ddevConfig = substr($ddevConfig, 0, $match[0][1]) .
            'docroot: public' .
            substr($ddevConfig, $match[0][1] + strlen($match[0][0]));
    }

    private function isDdev(string $path): bool
    {
        return file_exists("$path/.ddev/config.yaml");
    }

    private function updateEnvVars(string $path, OutputInterface $output): void
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
                    $add[$new] = $vars[$old];
                }
            }

            if (!empty($remove)) {
                $output->write("<fg=gray>➜</> Updating variables in <options=bold>$envPath</> … ");
                foreach ($remove as $name) {
                    Env::removeVariable($name, $fullPath);
                }
                Env::writeVariables($add, $fullPath);
                $output->writeln('<fg=green>done</>');
            }
        }
    }

    private function addArtisan(string $path, OutputInterface $output): void
    {
        $artisanPath = "$path/artisan";

        if (file_exists($artisanPath)) {
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

        $output->write("<fg=gray>➜</> Creating the <options=bold>artisan</> executable … ");
        file_put_contents($artisanPath, $contents);
        @chmod($artisanPath, 0755);
        $output->writeln('<fg=green>done</>');
    }

    private function addBootstrap(string $path, OutputInterface $output): void
    {
        $dirs = [
            'bootstrap',
            'bootstrap/cache',
        ];

        foreach ($dirs as $dir) {
            $dirPath = "$path/$dir";
            if (! file_exists($dirPath)) {
                $output->write("<fg=gray>➜</> Creating <options=bold>$dir</> … ");
                mkdir($dirPath);
                $output->writeln('<fg=green>done</>');
            }
        }

        $appPath = "$path/bootstrap/app.php";

        if (file_exists($appPath)) {
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

        $contents .= <<<PHP
    ->create();

PHP;

        $output->write("<fg=gray>➜</> Creating <options=bold>bootstrap/app.php</> … ");
        file_put_contents($appPath, $contents);
        $output->writeln('<fg=green>done</>');
    }

    private function addFrameworkFolders(string $path, OutputInterface $output): void
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
                $output->write("<fg=gray>➜</> Creating <options=bold>$dir</> … ");
                mkdir($dirPath);
                $output->writeln('<fg=green>done</>');
            }

            if ($addGitIgnore && !file_exists("$path/$dir/.gitignore")) {
                file_put_contents("$path/$dir/.gitignore", "*\n!.gitignore");
            }
        }
    }

    private function renamePublic(string $path, OutputInterface $output): void
    {
        if (! $this->renamePublicPath) {
            return;
        }

        $output->write("<fg=gray>➜</> Renaming <options=bold>$this->publicPath</> to <options=bold>public</> … ");
        rename("$path/$this->publicPath", "$path/public");
        $output->writeln('<fg=green>done</>');
        $this->publicPath = 'public';
    }

    private function updateIndex(string $path, OutputInterface $output): void
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

        $output->write("<fg=gray>➜</> Updating <options=bold>$this->publicPath/index.php</> … ");
        file_put_contents("$path/$this->publicPath/index.php", $contents);
        $output->writeln('<fg=green>done</>');
    }

    private function removeCraft(string $path, OutputInterface $output): void
    {
        $craftPath = "$path/craft";

        if (! file_exists($craftPath)) {
            return;
        }

        $output->write("<fg=gray>➜</> Removing the <options=bold>craft</> executable … ");
        unlink($craftPath);
        $output->writeln('<fg=green>done</>');
    }

    private function removeOldBootstrap(string $path, OutputInterface $output): void
    {
        $bootstrapPath = "$path/bootstrap.php";

        if (! file_exists($bootstrapPath)) {
            return;
        }

        $output->write('<fg=gray>➜</> Removing <options=bold>bootstrap.php</> … ');
        unlink($bootstrapPath);
        $output->writeln('<fg=green>done</>');
    }
}
