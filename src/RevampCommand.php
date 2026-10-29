<?php

namespace CraftCms\Prepper\Console;

use Closure;
use Composer\Semver\Semver;
use CraftCms\Prepper\Console\Support\Env;
use CraftCms\Prepper\Console\Support\Json;
use Dotenv\Dotenv;
use FilesystemIterator;
use Illuminate\Support\Str;
use Laravel\Prompts\Prompt;
use Laravel\Prompts\Support\Logger;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
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

    private bool $movedOAuthKeys = false;

    private string $originalPublicPath;

    /** @var string[] Config files with paths that couldn’t be rewritten */
    private array $unresolvedPathFiles = [];

    /** @var array<string, string[]> Removed settings that couldn’t be removed safely, indexed by config file */
    private array $remainingRemovedSettings = [];

    /** @var string[] */
    private array $incompatiblePackages = [];

    /** @var array<string, string[]> Legacy element group handles, indexed by their label */
    private array $legacyElementGroups = [];

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
            error("No composer.lock file found at $path. Run <options=bold>composer install</> first.");

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

        if (! $this->confirmGitStatus($path) || ! $this->confirmPartialRun($path)) {
            warning('Cancelled.');

            return self::FAILURE;
        }

        $projectConfigPath = file_exists("$path/config/project/project.yaml")
            ? "$path/config/project/project.yaml"
            : "$path/config/craft/project/project.yaml";

        $this->reportPluginCompatibility($composerLockPath);
        $this->legacyElementGroups = $this->findLegacyElementGroups(dirname($projectConfigPath));
        $this->reportLegacyElementGroups();

        if ($this->findPublicPath($path) === self::FAILURE) {
            return self::FAILURE;
        }

        $this->originalPublicPath = $this->publicPath;

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

        $customized = false;
        foreach (["{$this->publicPath}/index.php", 'bootstrap.php'] as $file) {
            $customLines = StockCraftFiles::customLines("$path/$file");

            if ($customLines !== []) {
                $customized = true;
                warning("$file has customizations that will be lost:");
                note(implode("\n", $customLines));
            }
        }

        if (! confirm(label: 'Proceed?', hint: $customized ? 'The customizations above will be lost.' : 'No customizations were found.')) {
            warning('Cancelled.');

            return self::FAILURE;
        }

        $filesystemSuggestions = (new FilesystemMigrationSuggestions)->get(
            $projectConfigPath,
            $this->renamePublicPath ? 'public' : $this->publicPath,
        );
        $emailTransport = $this->findEmailTransport($projectConfigPath);

        $this->runSteps([
            'Updating composer.json' => fn (Logger $logger) => $this->updateComposer($logger, $composerJsonPath),
            'Updating DDEV configuration' => fn (Logger $logger) => $this->updateDdevConfig($logger, $path),
            'Moving OAuth signing keys' => fn (Logger $logger) => $this->moveOAuthKeys($logger, $path),
            'Updating environment variables' => fn (Logger $logger) => $this->updateEnvVars($logger, $path),
            'Creating the artisan executable' => fn (Logger $logger) => $this->addArtisan($logger, $path),
            'Creating Laravel bootstrap files' => fn (Logger $logger) => $this->addBootstrap($logger, $path),
            'Creating framework storage folders' => fn (Logger $logger) => $this->addFrameworkFolders($logger, $path),
            'Moving Craft config directory' => fn (Logger $logger) => $this->moveConfigDirectory($logger, $path),
            'Publishing Laravel configuration' => fn (Logger $logger) => $this->publishLaravelConfig($logger, $path),
            'Updating config/craft/general.php' => fn (Logger $logger) => $this->updateGeneralConfig($logger, $path),
            'Renaming translations directory' => fn (Logger $logger) => $this->renameTranslations($logger, $path),
            'Moving templates directory' => fn (Logger $logger) => $this->moveTemplates($logger, $path),
            'Renaming the public folder' => fn (Logger $logger) => $this->renamePublic($logger, $path),
            "Updating {$this->publicPath}/index.php" => fn (Logger $logger) => $this->updateIndex($logger, $path),
            'Removing the Craft executable' => fn (Logger $logger) => $this->removeCraft($logger, $path),
            'Removing old bootstrap.php' => fn (Logger $logger) => $this->removeOldBootstrap($logger, $path),
            'Updating .gitignore' => fn (Logger $logger) => $this->updateGitignore($logger, $path),
        ]);

        outro('Finished preparing your project for Craft 6!');

        $steps = [];

        $generalConfigPath = "$path/config/craft/general.php";
        if (file_exists($generalConfigPath)) {
            $generalConfig = CraftConfigUpdater::stripComments(file_get_contents($generalConfigPath));

            if (! preg_match('/\bloginPath\b/', $generalConfig)) {
                $steps[] = 'Add <options=bold>loginPath</> to config/craft/general.php if you have a front-end login form';
            }
        }

        if ($this->unresolvedPathFiles !== []) {
            $steps[] = sprintf(
                'Update the paths in %s that were relative to the project root or the old web root, using <options=bold>base_path()</> or <options=bold>public_path()</>, since the config now lives in config/craft/',
                implode(', ', $this->unresolvedPathFiles),
            );
        }

        foreach ($this->remainingRemovedSettings as $file => $settings) {
            $steps[] = sprintf(
                'Remove <options=bold>%s</> from %s. %s no longer supported in Craft 6',
                implode('</>, <options=bold>', $settings),
                $file,
                count($settings) === 1 ? 'It is' : 'They are',
            );
        }

        array_push($steps, ...$this->craftConfigSteps($path));

        if (! empty($this->legacyElementGroups)) {
            $steps[] = sprintf(
                'Craft 6 only supports %s as legacy elements through craftcms/yii2-adapter. To convert them to entries, restore the Craft 5 project and run %s, then run this tool again',
                implode(', ', array_keys($this->legacyElementGroups)),
                implode(', ', $this->entrifyCommands()),
            );
        }

        if ($emailTransport !== null) {
            $steps[] = $emailTransport;
        }

        if ($this->movedOAuthKeys) {
            $steps[] = 'Move <options=bold>oauth-private.key</> and <options=bold>oauth-public.key</> from <options=bold>CRAFT_STORAGE_PATH</> into storage/ in your other environments, so existing OAuth tokens keep working';
        }

        if ($this->hasOAuthKeySettings($path)) {
            $steps[] = 'Replace the <options=bold>privateKey</> and <options=bold>publicKey</> OAuth settings (<options=bold>CRAFT_OAUTH_PRIVATE_KEY</> and <options=bold>CRAFT_OAUTH_PUBLIC_KEY</>) with <options=bold>PASSPORT_PRIVATE_KEY</> and <options=bold>PASSPORT_PUBLIC_KEY</> in each environment, set to the key contents, so existing OAuth tokens keep working';
        }

        if ($this->isDdev($path)) {
            $steps[] = 'Run <options=bold>ddev restart</> to pick up the new project type and configuration';
        }

        $ddevPrefix = $this->isDdev($path) ? 'ddev ' : '';

        if (! empty($filesystemSuggestions)) {
            $steps[] = "Craft Filesystems have been removed. Add the following Laravel disks to the <options=bold>disks</> array in config/filesystems.php:\n\n".implode("\n\n", $filesystemSuggestions);
        }

        if (! empty($this->incompatiblePackages)) {
            $steps[] = sprintf(
                'Remove or replace %s, which %s no Craft 6 version on Packagist yet, so <options=bold>composer update</> can resolve',
                implode(', ', array_map(fn (string $name) => "<options=bold>$name</>", $this->incompatiblePackages)),
                count($this->incompatiblePackages) === 1 ? 'has' : 'have',
            );
        }

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

    private function confirmGitStatus(string $path): bool
    {
        $output = [];
        @exec(sprintf('git -C %s status --porcelain -- . 2>&1', escapeshellarg($path)), $output, $exitCode);

        // Not a Git repository, or Git isn’t installed
        if ($exitCode !== 0) {
            return true;
        }

        $changes = array_values(array_filter($output));

        if ($changes === []) {
            return true;
        }

        warning(sprintf('The project has %d uncommitted %s. Commit or stash them first, so you can review and undo what this tool changes.', count($changes), count($changes) === 1 ? 'change' : 'changes'));
        note(implode("\n", array_slice($changes, 0, 10)).(count($changes) > 10 ? "\n…" : ''));

        return confirm(label: 'Continue with uncommitted changes?', default: false);
    }

    private function confirmPartialRun(string $path): bool
    {
        $existing = array_values(array_filter(
            ['bootstrap/', 'config/craft/'],
            fn (string $dir) => is_dir("$path/$dir"),
        ));

        if ($existing === []) {
            return true;
        }

        warning(sprintf(
            '%s already %s, so this tool may have run here before. Steps that already happened will be skipped, and files they created won’t be updated.',
            implode(' and ', $existing),
            count($existing) === 1 ? 'exists' : 'exist',
        ));

        return confirm(label: 'Continue anyway?', default: false, hint: 'Restore the project first for a clean run.');
    }

    private function reportPluginCompatibility(string $composerLockPath): void
    {
        $compatibility = new PluginCompatibility;
        $dependents = $compatibility->dependents($composerLockPath);

        if ($dependents === []) {
            return;
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

        $this->incompatiblePackages = array_keys(array_filter(
            $results,
            fn (array $result) => $result['status'] === PluginCompatibility::STATUS_INCOMPATIBLE,
        ));

        if ($this->incompatiblePackages !== []) {
            warning('composer update won’t resolve until the packages without a Craft 6 version are removed or replaced.');
        }
    }

    /**
     * @return array<string, string[]>
     */
    private function findLegacyElementGroups(string $projectConfigDir): array
    {
        $types = [
            'categoryGroups' => 'category groups',
            'tagGroups' => 'tag groups',
            'globalSets' => 'global sets',
        ];

        $projectConfig = $this->parseYaml("$projectConfigDir/project.yaml") ?? [];
        $groups = [];

        foreach ($types as $key => $label) {
            $configs = is_array($projectConfig[$key] ?? null) ? array_values($projectConfig[$key]) : [];

            foreach (glob("$projectConfigDir/$key/*.yaml") ?: [] as $file) {
                $configs[] = $this->parseYaml($file);
            }

            $handles = collect($configs)
                ->map(fn (mixed $config) => is_array($config) ? ($config['handle'] ?? null) : null)
                ->filter(fn (mixed $handle) => is_string($handle))
                ->unique()
                ->sort()
                ->values()
                ->all();

            if ($handles !== []) {
                $groups[$label] = $handles;
            }
        }

        return $groups;
    }

    private function reportLegacyElementGroups(): void
    {
        if ($this->legacyElementGroups === []) {
            return;
        }

        warning(sprintf(
            'The project has %s. Craft 6 only supports them as legacy elements through craftcms/yii2-adapter. To convert them to entries, cancel and run these commands on Craft 5 first:',
            implode(', ', array_keys($this->legacyElementGroups)),
        ));
        note(implode("\n", $this->entrifyCommands()));
    }

    /**
     * @return string[]
     */
    private function entrifyCommands(): array
    {
        $commands = [
            'category groups' => 'categories',
            'tag groups' => 'tags',
            'global sets' => 'global-set',
        ];
        $lines = [];

        foreach ($this->legacyElementGroups as $label => $handles) {
            foreach ($handles as $handle) {
                $lines[] = "php craft entrify/{$commands[$label]} $handle";
            }
        }

        return $lines;
    }

    private function findEmailTransport(string $projectConfigPath): ?string
    {
        $email = $this->parseYaml($projectConfigPath)['email'] ?? null;
        $type = is_array($email) ? ($email['transportType'] ?? null) : null;

        if (! is_string($type) || $type === '') {
            return null;
        }

        // Laravel mailers, indexed by a pattern matching the Craft transport adapter classes
        $mailers = [
            '/gmail|smtp/i' => 'smtp',
            '/sendmail/i' => 'sendmail',
            '/mailgun/i' => 'mailgun',
            '/postmark/i' => 'postmark',
            '/resend/i' => 'resend',
            '/amazonses|\\\\ses\\b/i' => 'ses',
        ];
        $mailer = collect($mailers)->first(fn (string $mailer, string $pattern) => preg_match($pattern, $type));

        $settings = is_array($email['transportSettings'] ?? null) ? $email['transportSettings'] : [];
        $details = collect(['host', 'port', 'username', 'encryptionMethod', 'domain', 'endpoint'])
            ->filter(fn (string $key) => is_scalar($settings[$key] ?? null) && (string) $settings[$key] !== '')
            ->map(fn (string $key) => "$key: {$settings[$key]}")
            ->implode(', ');
        $details = $details === '' ? '' : " ($details)";

        if ($mailer === null) {
            return "Craft no longer reads the email transport from project config. Configure a Laravel mailer in config/mail.php to replace <options=bold>$type</>$details, and set <options=bold>MAIL_MAILER</> in .env";
        }

        if ($mailer === 'sendmail') {
            return 'Craft no longer reads the email transport from project config. Set <options=bold>MAIL_MAILER=sendmail</> in .env to keep sending email with Sendmail';
        }

        return sprintf(
            'Craft no longer reads the email transport from project config. Set <options=bold>MAIL_MAILER=%s</> in .env and configure the <options=bold>%s</> mailer in config/mail.php to replace <options=bold>%s</>%s%s',
            $mailer,
            $mailer,
            $type,
            $details,
            in_array($mailer, ['mailgun', 'postmark', 'resend', 'ses']) ? ", following https://laravel.com/docs/mail#driver-prerequisites" : '',
        );
    }

    /**
     * @return array<mixed>|null
     */
    private function parseYaml(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Suggests next steps for Craft config files that Craft 6 no longer reads, or only reads through the Yii2 adapter.
     *
     * @return string[]
     */
    private function craftConfigSteps(string $path): array
    {
        $configPath = "$path/config/craft";

        if (! is_dir($configPath)) {
            return [];
        }

        $steps = [];
        $read = fn (string $file) => CraftConfigUpdater::stripComments(file_get_contents("$configPath/$file"));
        $files = array_map('basename', glob("$configPath/*.php") ?: []);

        $multiEnvFiles = array_values(array_filter(
            $files,
            fn (string $file) => preg_match('/([\'"])\*\1\s*=>/', $read($file)),
        ));

        if ($multiEnvFiles !== []) {
            $steps[] = sprintf(
                'Replace the multi-environment config arrays (the <options=bold>\'*\'</> key) in %s with <options=bold>env()</> values or <options=bold>app()->environment()</> checks. Craft 6 reads config files as-is',
                implode(', ', array_map(fn (string $file) => "config/craft/$file", $multiEnvFiles)),
            );
        }

        $appTypeFiles = array_values(array_filter(
            $files,
            fn (string $file) => preg_match('/^\w[\w-]*\.(web|console)\.php$/', $file),
        ));

        if ($appTypeFiles !== []) {
            $steps[] = sprintf(
                'Merge %s into the main config %s. Craft 6 only reads app-type config files through craftcms/yii2-adapter',
                implode(', ', array_map(fn (string $file) => "config/craft/$file", $appTypeFiles)),
                count($appTypeFiles) === 1 ? 'file' : 'files',
            );
        }

        if (in_array('db.php', $files)) {
            $steps[] = 'Move your database settings from config/craft/db.php into config/database.php or the <options=bold>DB_*</> environment variables, and delete it. Craft 6 no longer reads it';
        }

        if (in_array('app.php', $files)) {
            $keys = array_values(array_filter(
                ['components', 'modules', 'bootstrap'],
                fn (string $key) => preg_match("/(['\"])$key\\1\\s*=>/", $read('app.php')),
            ));

            if ($keys !== []) {
                $steps[] = sprintf(
                    'config/craft/app.php defines Yii <options=bold>%s</>, which only craftcms/yii2-adapter reads. Move them to Laravel service providers when you can',
                    implode('</>, <options=bold>', $keys),
                );
            }
        }

        if (in_array('routes.php', $files) && ! preg_match('/^\s*<\?php\s*return\s*(\[\s*\]|array\(\s*\))\s*;\s*$/', $read('routes.php'))) {
            $steps[] = 'Translate the URL rules in config/craft/routes.php into routes/web.php. Craft 6 only reads them through craftcms/yii2-adapter';
        }

        $purifierConfigs = array_values(array_filter(
            array_map('basename', glob("$configPath/htmlpurifier/*.json") ?: []),
            fn (string $file) => $file !== 'Default.json',
        ));

        if ($purifierConfigs !== []) {
            $steps[] = sprintf(
                'Craft 6 replaces HTMLPurifier with Symfony HtmlSanitizer. Register sanitizers for %s with the <options=bold>HtmlSanitizers</> facade (see vendor/craftcms/cms/docs/html-sanitizers.md)',
                implode(', ', array_map(fn (string $file) => "config/craft/htmlpurifier/$file", $purifierConfigs)),
            );
        }

        return $steps;
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

        // Craft 6 has no `craft shell` command; Laravel Tinker (`artisan tinker`) replaces it
        if (isset($config['require']['yiisoft/yii2-shell']) || isset($config['require-dev']['yiisoft/yii2-shell'])) {
            unset($config['require']['yiisoft/yii2-shell'], $config['require-dev']['yiisoft/yii2-shell']);

            if (empty($config['require-dev'])) {
                unset($config['require-dev']);
            }

            $logger->success('Removed yiisoft/yii2-shell');
        }
        unset($config['config']['platform']['php']);

        // Otherwise it ends up as `platform: []` which is invalid.
        if (empty($config['config']['platform'])) {
            unset($config['config']['platform']);
        }
        if (empty($config['config'])) {
            unset($config['config']);
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

    /**
     * Moves the OAuth signing keys from a custom Craft storage path into storage/, where Laravel Passport looks for them.
     */
    private function moveOAuthKeys(Logger $logger, string $path): void
    {
        $craftStoragePath = $this->envValue($path, 'CRAFT_STORAGE_PATH');

        if ($craftStoragePath === null) {
            $logger->success('OAuth signing keys are already in storage/.');

            return;
        }

        if (! str_starts_with($craftStoragePath, '/') && ! preg_match('#^[a-zA-Z]:[/\\\\]#', $craftStoragePath)) {
            $craftStoragePath = "$path/$craftStoragePath";
        }

        $craftStoragePath = rtrim(str_replace('\\', '/', $craftStoragePath), '/');

        if (realpath($craftStoragePath) !== false && realpath($craftStoragePath) === realpath("$path/storage")) {
            $logger->success('OAuth signing keys are already in storage/.');

            return;
        }

        foreach (['oauth-private.key', 'oauth-public.key'] as $file) {
            $source = "$craftStoragePath/$file";
            $target = "$path/storage/$file";

            if (! file_exists($source)) {
                continue;
            }

            if (file_exists($target)) {
                $logger->warning("storage/$file already exists, so $source wasn’t moved.");

                continue;
            }

            if (! is_dir("$path/storage")) {
                mkdir("$path/storage", recursive: true);
            }

            rename($source, $target);
            chmod($target, 0600);
            $this->movedOAuthKeys = true;
            $logger->success("Moved $file to storage/");
        }
    }

    private function hasOAuthKeySettings(string $path): bool
    {
        foreach (['CRAFT_OAUTH_PRIVATE_KEY', 'CRAFT_OAUTH_PUBLIC_KEY'] as $name) {
            if ($this->envValue($path, $name) !== null) {
                return true;
            }
        }

        $configPath = "$path/config/craft/oauth.php";

        return file_exists($configPath) && preg_match('/\b(privateKey|publicKey)\b/', file_get_contents($configPath));
    }

    private function envValue(string $path, string $name): ?string
    {
        foreach (['.env', '.ddev/.env.web', '.ddev/.env'] as $envPath) {
            if (file_exists("$path/$envPath")) {
                $value = Dotenv::parse(file_get_contents("$path/$envPath"))[$name] ?? null;

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function updateEnvVars(Logger $logger, string $path): void
    {
        $envPaths = collect([
            '.ddev/.env',
            '.ddev/.env.web',
            '.env',
            '.env.example',
            '.env.example.dev',
            '.env.example.staging',
            '.env.example.production',
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
            'CRAFT_DB_TABLE' => 'DB_TABLE_PREFIX',
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
    ->booted(function (Application \$app) {
        \$app->usePublicPath(base_path('$this->publicPath'));
    })

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

    private function publishLaravelConfig(Logger $logger, string $path): void
    {
        $sourcePath = __DIR__.'/../resources/config';

        if (! is_dir($sourcePath)) {
            $logger->error('Laravel configuration files could not be found.');

            return;
        }

        $targetPath = "$path/config";
        if (! is_dir($targetPath)) {
            mkdir($targetPath, recursive: true);
        }

        $published = 0;

        foreach (new FilesystemIterator($sourcePath) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $targetFile = "$targetPath/{$file->getFilename()}";

            if (file_exists($targetFile)) {
                continue;
            }

            copy($file->getPathname(), $targetFile);
            $published++;
        }

        $logger->success("Published $published Laravel configuration files.");
    }

    private function updateGeneralConfig(Logger $logger, string $path): void
    {
        $files = array_values(array_filter(
            ['general.php', 'general.web.php', 'general.console.php'],
            fn (string $file) => file_exists("$path/config/craft/$file"),
        ));

        if ($files === []) {
            $logger->warning('No config/craft/general.php file found.');

            return;
        }

        $updater = new CraftConfigUpdater(
            $this->originalPublicPath,
            $this->renamePublicPath ? 'public' : $this->publicPath,
        );

        foreach ($files as $file) {
            $filePath = "$path/config/craft/$file";
            $original = file_get_contents($filePath);
            [$contents, $pathCount] = $updater->rewritePaths($original);
            [$contents, $removedSettings] = $updater->removeSettings($contents);

            // Never write a file we broke. The tool may run on an older PHP than the project (e.g. on the host
            // with DDEV), so only check files that parse on this PHP to begin with.
            if ($contents !== $original && $this->isValidPhp($original) && ! $this->isValidPhp($contents)) {
                $logger->warning("config/craft/$file couldn’t be updated safely, so it was left as-is.");
                $contents = $original;
                $pathCount = 0;
                $removedSettings = [];
            }

            if ($contents !== $original) {
                file_put_contents($filePath, $contents);
            }

            if ($pathCount > 0) {
                $logger->success(sprintf('Updated %d %s in config/craft/%s that pointed into the project root', $pathCount, $pathCount === 1 ? 'path' : 'paths', $file));
            }

            if ($removedSettings !== []) {
                $logger->success(sprintf('Removed %s from config/craft/%s', implode(', ', $removedSettings), $file));
            }

            if ($updater->hasUnresolvedPaths($contents)) {
                $this->unresolvedPathFiles[] = "config/craft/$file";
            }

            $remaining = $updater->remainingSettings($contents);
            if ($remaining !== []) {
                $this->remainingRemovedSettings["config/craft/$file"] = $remaining;
            }
        }

        if ($this->unresolvedPathFiles !== [] || $this->remainingRemovedSettings !== []) {
            $logger->warning('Some settings need to be updated by hand. See the next steps.');
        }
    }

    private function isValidPhp(string $contents): bool
    {
        try {
            token_get_all($contents, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }

        return true;
    }

    private function updateGitignore(Logger $logger, string $path): void
    {
        $gitignorePath = "$path/.gitignore";
        $contents = file_exists($gitignorePath) ? file_get_contents($gitignorePath) : '';
        $existing = array_map('trim', preg_split('/\R/', $contents));

        // Control panel assets published by craft:setup:publish, the public storage symlink, and the Passport keys
        $missing = array_values(array_diff(['/public/vendor/craft', '/public/storage', '/storage/*.key'], $existing));

        if ($missing === []) {
            $logger->success('.gitignore is already up to date.');

            return;
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        file_put_contents($gitignorePath, $contents.implode("\n", $missing)."\n");

        $logger->success(sprintf('Added %s to .gitignore', implode(', ', $missing)));
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
