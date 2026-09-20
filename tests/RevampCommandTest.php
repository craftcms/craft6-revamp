<?php

namespace CraftCms\Prepper\Console\Tests;

use CraftCms\Prepper\Console\RevampCommand;
use FilesystemIterator;
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

        mkdir("{$this->projectPath}/public", recursive: true);

        file_put_contents(
            "{$this->projectPath}/composer.json",
            json_encode([
                'require' => [
                    'craftcms/cms' => '^5.9',
                    'vlucas/phpdotenv' => '^5.0',
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
        file_put_contents("{$this->projectPath}/config/general.php", '<?php return [];'.PHP_EOL);
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
                '',
            ]),
        );
    }

    protected function tearDown(): void
    {
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
        self::assertStringContainsString("'url' => '@assetBaseUrl/site',", $tester->getDisplay());

        $composerJson = json_decode(file_get_contents("{$this->projectPath}/composer.json"), true);

        self::assertSame('^6.0.0-alpha.1', $composerJson['require']['craftcms/cms']);
        self::assertSame('*', $composerJson['require']['craftcms/yii2-adapter']);
        self::assertArrayNotHasKey('vlucas/phpdotenv', $composerJson['require']);
        self::assertArrayNotHasKey('config', $composerJson);

        $env = file_get_contents("{$this->projectPath}/.env");

        self::assertStringNotContainsString('CRAFT_DB_DRIVER=', $env);
        self::assertStringNotContainsString('CRAFT_DB_SERVER=', $env);
        self::assertStringContainsString('DB_CONNECTION=mysql', $env);
        self::assertStringContainsString('DB_HOST=db', $env);
        self::assertStringContainsString('APP_DEBUG=true', $env);
        self::assertStringContainsString('APP_KEY=', $env);

        $rerunTester = new CommandTester(new RevampCommand);
        $rerunExitCode = $rerunTester->execute(
            ['path' => $this->projectPath],
            ['interactive' => false],
        );

        self::assertSame(Command::SUCCESS, $rerunExitCode);
        self::assertStringContainsString('Craft Filesystems have been removed.', $rerunTester->getDisplay());
        self::assertStringContainsString("'siteAssets' => [", $rerunTester->getDisplay());
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
