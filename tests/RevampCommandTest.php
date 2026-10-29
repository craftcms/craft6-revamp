<?php

namespace CraftCms\Revamp\Tests;

use CraftCms\Revamp\RevampCommand;
use FilesystemIterator;
use Laravel\Prompts\ConfirmPrompt;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RevampCommandTest extends TestCase
{
    private string $projectPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectPath = sys_get_temp_dir().'/craft6-revamp-test-'.bin2hex(random_bytes(8));

        // Wide enough that Next steps aren’t wrapped, so assertions can match whole sentences
        putenv('COLUMNS=1000');

        mkdir("{$this->projectPath}/public", recursive: true);

        file_put_contents(
            "{$this->projectPath}/composer.json",
            json_encode([
                'require' => [
                    'craftcms/cms' => '^5.9',
                    'vlucas/phpdotenv' => '^5.0',
                ],
                'require-dev' => [
                    'yiisoft/yii2-shell' => '^2.0.3',
                ],
                'config' => [
                    'platform' => [
                        'php' => '8.2',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        file_put_contents(
            "{$this->projectPath}/composer.lock",
            json_encode([
                'packages' => [
                    [
                        'name' => 'craftcms/cms',
                        'version' => '5.9.0',
                    ],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        file_put_contents("{$this->projectPath}/public/index.php", '<?php echo "old";'.PHP_EOL);
        file_put_contents("{$this->projectPath}/bootstrap.php", '<?php // old bootstrap'.PHP_EOL);
        mkdir("{$this->projectPath}/config", recursive: true);
        file_put_contents("{$this->projectPath}/config/general.php", <<<'PHP'
<?php

return GeneralConfig::create()
    // PHP 8.4 syntax, which Craft 6 projects can use even when this tool runs on PHP 8.2
    ->maxUploadFileSize(new ArrayObject([1])->count())
    ->omitScriptNameInUrls()
    ->enableTwigSandbox()
    ->aliases([
        '@webroot' => dirname(__DIR__) . '/public',
    ])
;

PHP);
        mkdir("{$this->projectPath}/config/project", recursive: true);
        file_put_contents(
            "{$this->projectPath}/config/project/project.yaml",
            <<<'YAML'
fs:
  siteAssets:
    type: craft\fs\Local
    hasUrls: true
    url: '@assetBaseUrl/site'
    settings:
      path: '@assetBasePath/site'
YAML,
        );
        file_put_contents(
            "{$this->projectPath}/.env",
            implode(PHP_EOL, [
                'CRAFT_DB_DRIVER=mysql',
                'CRAFT_DB_SERVER=db',
                'CRAFT_DEV_MODE=true',
                'CRAFT_ENVIRONMENT=dev',
                'CRAFT_DB_TABLE=craft_',
                'PRIMARY_SITE_URL=https://example.test',
                '',
            ]),
        );
        file_put_contents(
            "{$this->projectPath}/.env.example.production",
            "CRAFT_ENVIRONMENT=production\nCRAFT_DB_SERVER=127.0.0.1\nCRAFT_SECURITY_KEY=\n",
        );
        file_put_contents("{$this->projectPath}/.gitignore", "/vendor\n/public/storage\n");
    }

    protected function tearDown(): void
    {
        Prompt::flushState();
        putenv('COLUMNS');
        $this->removeDirectory($this->projectPath);

        parent::tearDown();
    }

    public function test_it_prepares_a_craft_project_for_craft6(): void
    {
        $tester = new CommandTester(new RevampCommand);

        $exitCode = $tester->execute(
            ['path' => $this->projectPath],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Finished preparing your project for Craft 6!', $tester->getDisplay());
        self::assertFileExists("{$this->projectPath}/artisan");
        self::assertFileExists("{$this->projectPath}/bootstrap/app.php");
        self::assertFileExists("{$this->projectPath}/bootstrap/cache/.gitignore");
        self::assertFileExists("{$this->projectPath}/storage/framework/cache/.gitignore");
        self::assertFileDoesNotExist("{$this->projectPath}/bootstrap.php");
        self::assertFileExists("{$this->projectPath}/config/craft/general.php");
        self::assertFileExists("{$this->projectPath}/config/craft/project/project.yaml");
        self::assertFileExists("{$this->projectPath}/config/app.php");
        self::assertFileExists("{$this->projectPath}/config/database.php");
        self::assertFileExists(__DIR__.'/../resources/config/app.php');
        self::assertSame(
            file_get_contents(__DIR__.'/../resources/config/app.php'),
            file_get_contents("{$this->projectPath}/config/app.php"),
        );
        self::assertStringContainsString('Craft Filesystems have been removed.', $tester->getDisplay());
        self::assertStringContainsString("'siteAssets' => [", $tester->getDisplay());
        self::assertStringContainsString("'root' => '@assetBasePath/site',", $tester->getDisplay());
        self::assertStringContainsString('public/index.php has customizations that will be lost', $tester->getDisplay());
        self::assertStringContainsString('<?php echo "old";', $tester->getDisplay());
        self::assertStringContainsString('<?php // old bootstrap', $tester->getDisplay());
        self::assertStringNotContainsString('omitScriptNameInUrls', $tester->getDisplay());
        self::assertSame(<<<'PHP'
<?php

return GeneralConfig::create()
    // PHP 8.4 syntax, which Craft 6 projects can use even when this tool runs on PHP 8.2
    ->maxUploadFileSize(new ArrayObject([1])->count())
    ->aliases([
        '@webroot' => public_path(),
    ])
;

PHP, file_get_contents("{$this->projectPath}/config/craft/general.php"));
        self::assertSame(
            "/vendor\n/public/storage\n/public/vendor/craft\n/storage/*.key\n",
            file_get_contents("{$this->projectPath}/.gitignore"),
        );
        self::assertStringContainsString("'url' => '@assetBaseUrl/site',", $tester->getDisplay());

        $composerJson = json_decode(file_get_contents("{$this->projectPath}/composer.json"), true);

        self::assertSame('^6.0.0-alpha.1', $composerJson['require']['craftcms/cms']);
        self::assertSame('*', $composerJson['require']['craftcms/yii2-adapter']);
        self::assertArrayNotHasKey('vlucas/phpdotenv', $composerJson['require']);
        self::assertArrayNotHasKey('config', $composerJson);
        self::assertArrayNotHasKey('require-dev', $composerJson);

        $env = file_get_contents("{$this->projectPath}/.env");

        self::assertStringNotContainsString('CRAFT_DB_DRIVER=', $env);
        self::assertStringNotContainsString('CRAFT_DB_SERVER=', $env);
        self::assertStringContainsString('DB_CONNECTION=mysql', $env);
        self::assertStringContainsString('DB_HOST=db', $env);
        self::assertStringContainsString('APP_DEBUG=true', $env);
        self::assertStringContainsString('APP_KEY=', $env);
        self::assertStringContainsString('APP_ENV=local', $env);
        self::assertStringContainsString('APP_URL="https://example.test"', $env);
        self::assertStringContainsString('DB_TABLE_PREFIX="craft_"', $env);
        self::assertStringNotContainsString('CRAFT_DB_TABLE=', $env);

        $exampleEnv = file_get_contents("{$this->projectPath}/.env.example.production");
        self::assertStringContainsString('APP_ENV=production', $exampleEnv);
        self::assertStringContainsString('DB_HOST="127.0.0.1"', $exampleEnv);
        self::assertStringNotContainsString('CRAFT_DB_SERVER', $exampleEnv);
        self::assertStringNotContainsString('CRAFT_SECURITY_KEY', $exampleEnv);
        self::assertStringNotContainsString('oauth-private.key', $tester->getDisplay());
        self::assertStringNotContainsString('PASSPORT_PRIVATE_KEY', $tester->getDisplay());

        // A second run stops unless the user confirms, since steps that already happened are skipped
        $rerunTester = new CommandTester(new RevampCommand);
        $rerunExitCode = $rerunTester->execute(
            ['path' => $this->projectPath],
            ['interactive' => false],
        );

        self::assertSame(Command::FAILURE, $rerunExitCode);
        self::assertStringContainsString('bootstrap/ and config/craft/ already exist', preg_replace('/\s+/', ' ', $rerunTester->getDisplay()));

        Prompt::fallbackWhen(true);
        ConfirmPrompt::fallbackUsing(fn () => true);

        $rerunTester = new CommandTester(new RevampCommand);
        $rerunExitCode = $rerunTester->execute(['path' => $this->projectPath]);

        self::assertSame(Command::SUCCESS, $rerunExitCode);
        self::assertStringContainsString('Craft Filesystems have been removed.', $rerunTester->getDisplay());
        self::assertStringContainsString("'siteAssets' => [", $rerunTester->getDisplay());
    }

    public function test_it_suggests_next_steps_for_project_specific_config(): void
    {
        file_put_contents("{$this->projectPath}/public/index.php", <<<'PHP'
<?php
/**
 * Craft web bootstrap file
 */

// Load shared bootstrap
require dirname(__DIR__) . '/bootstrap.php';

// Load and run Craft
/** @var craft\web\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';
$app->run();

PHP);
        unlink("{$this->projectPath}/bootstrap.php");
        file_put_contents("{$this->projectPath}/config/general.php", <<<'PHP'
<?php

return [
    '*' => [
        'enableTwigSandbox' => true,
        // blowfishHashCost is set in .env
        'timezone' => 'Europe/Brussels',
        'aliases' => ['@libs' => CRAFT_VENDOR_PATH . '/libs'],
    ],
];

PHP);
        file_put_contents("{$this->projectPath}/config/general.web.php", "<?php return GeneralConfig::create()->pathParam(App::env(strtoupper('p')) ?: 'p')->testToEmailAddress('dev@example.com');\n");
        file_put_contents("{$this->projectPath}/config/db.php", "<?php return ['tablePrefix' => App::env('CRAFT_DB_TABLE_PREFIX'), 'server' => App::env('CRAFT_DB_SERVER')];\n");
        mkdir("{$this->projectPath}/.github/workflows", recursive: true);
        file_put_contents("{$this->projectPath}/.github/workflows/deploy.yml", "env:\n  CRAFT_DEV_MODE: false\n  CRAFT_SECURITY_KEY: \${{ secrets.KEY }}\n  CRAFT_DB_DRIVER: mysql\n");
        file_put_contents("{$this->projectPath}/docker-compose.dev.yml", "services:\n  web:\n    environment:\n      CRAFT_DB_PASSWORD: secret\n");
        file_put_contents("{$this->projectPath}/Dockerfile", "FROM php:8.5\n");
        file_put_contents("{$this->projectPath}/config/app.php", "<?php return ['id' => 'CraftCMS', 'modules' => ['site' => \\modules\\Module::class]];\n");
        file_put_contents("{$this->projectPath}/config/routes.php", "<?php\n// Site URL rules\nreturn ['blog/<slug>' => ['template' => 'blog/_entry']];\n");
        mkdir("{$this->projectPath}/config/htmlpurifier");
        file_put_contents("{$this->projectPath}/config/htmlpurifier/Default.json", '{}');
        file_put_contents("{$this->projectPath}/config/htmlpurifier/Simple.json", '{}');
        mkdir("{$this->projectPath}/config/project/categoryGroups");
        file_put_contents("{$this->projectPath}/config/project/categoryGroups/topics--1234.yaml", "handle: topics\nname: Topics\n");
        file_put_contents("{$this->projectPath}/config/project/project.yaml", <<<'YAML'
email:
  transportType: craft\mail\transportadapters\Smtp
  transportSettings:
    host: smtp.example.com
    port: '587'
globalSets:
  abcd:
    handle: siteSettings
YAML);

        $tester = new CommandTester(new RevampCommand);
        $exitCode = $tester->execute(['path' => $this->projectPath], ['interactive' => false]);
        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('has customizations', $display);
        self::assertStringContainsString('php craft entrify/categories topics', $display);
        self::assertStringContainsString('php craft entrify/global-set siteSettings', $display);
        self::assertStringContainsString("Replace the multi-environment config arrays (the '*' key) in config/craft/general.php", $display);
        self::assertStringContainsString('Update the paths in config/craft/general.php that were relative to the project root', $display);
        self::assertStringContainsString('Remove pathParam from config/craft/general.web.php', $display);
        self::assertStringContainsString('Merge config/craft/general.web.php into the main config file', $display);
        self::assertStringContainsString('config/craft/db.php', $display);
        self::assertStringContainsString('config/craft/app.php defines Yii modules', $display);
        self::assertStringContainsString('Translate the URL rules in config/craft/routes.php into routes/web.php', $display);
        self::assertStringContainsString('Register sanitizers for config/craft/htmlpurifier/Simple.json', $display);
        self::assertStringNotContainsString('htmlpurifier/Default.json', $display);
        self::assertStringContainsString('Set MAIL_MAILER=smtp in .env and configure the smtp mailer in config/mail.php', $display);
        self::assertStringContainsString('host: smtp.example.com, port: 587', $display);
        self::assertStringNotContainsString('enableTwigSandbox', file_get_contents("{$this->projectPath}/config/craft/general.php"));

        // Moved settings are flagged with where they go now, but left in place
        self::assertStringContainsString('Move timezone out of config/craft/general.php, since Craft 6 no longer reads it. Use the timezone key in config/app.php', $display);
        self::assertStringContainsString('Move testToEmailAddress out of config/craft/general.web.php, since Craft 6 no longer reads it. Use Mail::alwaysTo()', $display);
        self::assertStringNotContainsString('Move blowfishHashCost', $display);
        self::assertStringContainsString("'timezone' => 'Europe/Brussels'", file_get_contents("{$this->projectPath}/config/craft/general.php"));

        // Renamed environment variables are flagged in each file outside .env
        self::assertStringContainsString('.github/workflows/deploy.yml: CRAFT_DB_DRIVER → DB_CONNECTION, CRAFT_DEV_MODE → APP_DEBUG, CRAFT_SECURITY_KEY (remove it; Laravel uses APP_KEY)', $display);
        self::assertStringContainsString('config/craft/db.php: CRAFT_DB_SERVER → DB_HOST, CRAFT_DB_TABLE_PREFIX → DB_TABLE_PREFIX', $display);
        self::assertStringContainsString('docker-compose.dev.yml: CRAFT_DB_PASSWORD → DB_PASSWORD', $display);
        self::assertStringNotContainsString('Dockerfile:', $display);

        // Next steps keep the same order, whether a check, a step, or an advisor added them
        $nextSteps = substr($display, strpos($display, 'Next steps'));
        $positions = array_map(fn (string $step) => strpos($nextSteps, $step), [
            'Add loginPath',
            'Update the paths in config/craft/general.php',
            'Remove pathParam',
            'Replace the multi-environment config arrays',
            'restore the Craft 5 project and run php craft entrify',
            'Set MAIL_MAILER=smtp',
            'Run composer update',
            'Run artisan key:generate',
            'Run artisan craft:up',
        ]);
        self::assertNotContains(false, $positions);
        self::assertSame($positions, collect($positions)->sort()->values()->all());
    }

    public function test_it_wraps_next_steps_to_the_terminal_width(): void
    {
        putenv('COLUMNS=80');
        file_put_contents("{$this->projectPath}/.env", "CRAFT_OAUTH_PUBLIC_KEY=@root/keys/public.key\n", FILE_APPEND);

        $tester = new CommandTester(new RevampCommand);
        $tester->execute(['path' => $this->projectPath], ['interactive' => false]);

        $display = $tester->getDisplay();
        $table = substr($display, strpos($display, 'Next steps'));
        $plain = preg_replace('/\e\[[\d;]*m/', '', $table);

        foreach (explode("\n", $plain) as $line) {
            self::assertLessThanOrEqual(80, mb_strwidth($line), "Too wide: $line");
        }

        // The wrapped sentence reads the same once the table borders are removed
        $text = preg_replace('/\s+/', ' ', str_replace('│', ' ', $plain));
        self::assertStringContainsString('with PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY in each environment, set to the key contents, so existing OAuth tokens keep working', $text);

        // Formatting tags split across lines don’t leak into the output
        self::assertStringNotContainsString('options=bold', $table);
        self::assertStringNotContainsString('</>', $table);
    }

    public function test_it_stops_when_the_project_has_uncommitted_changes(): void
    {
        exec('git --version 2>&1', $output, $exitCode);
        if ($exitCode !== 0) {
            self::markTestSkipped('Git isn’t available.');
        }

        exec(sprintf('git -C %s init -q 2>&1', escapeshellarg($this->projectPath)));

        $tester = new CommandTester(new RevampCommand);
        $exitCode = $tester->execute(['path' => $this->projectPath], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('uncommitted changes', $tester->getDisplay());
        self::assertFileExists("{$this->projectPath}/config/general.php");
        self::assertFileDoesNotExist("{$this->projectPath}/artisan");
    }

    public function test_it_moves_oauth_keys_into_laravel_storage(): void
    {
        mkdir("{$this->projectPath}/craft-storage");
        file_put_contents("{$this->projectPath}/craft-storage/oauth-private.key", 'private');
        file_put_contents("{$this->projectPath}/craft-storage/oauth-public.key", 'public');
        file_put_contents("{$this->projectPath}/.env", "CRAFT_STORAGE_PATH=craft-storage\nCRAFT_OAUTH_PUBLIC_KEY=@root/keys/public.key\n", FILE_APPEND);

        $tester = new CommandTester(new RevampCommand);
        $exitCode = $tester->execute(['path' => $this->projectPath], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('private', file_get_contents("{$this->projectPath}/storage/oauth-private.key"));
        self::assertSame('public', file_get_contents("{$this->projectPath}/storage/oauth-public.key"));
        self::assertSame(0600, fileperms("{$this->projectPath}/storage/oauth-private.key") & 0777);
        self::assertFileDoesNotExist("{$this->projectPath}/craft-storage/oauth-private.key");

        $display = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Move oauth-private.key and oauth-public.key from CRAFT_STORAGE_PATH into storage/', $display);
        self::assertStringContainsString('with PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY in each environment', $display);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir()
                ? rmdir($file->getPathname())
                : unlink($file->getPathname());
        }

        rmdir($path);
    }
}
