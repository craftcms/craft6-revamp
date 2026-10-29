<?php

namespace CraftCms\Revamp\Tests;

use CraftCms\Revamp\CraftConfigUpdater;
use PHPUnit\Framework\TestCase;

class CraftConfigUpdaterTest extends TestCase
{
    public function test_it_updates_the_stock_craft5_general_config(): void
    {
        $updater = new CraftConfigUpdater('web', 'public');

        // config/general.php from craftcms/craft 5.x
        $contents = <<<'PHP'
<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    // Set the default week start day for date pickers (0 = Sunday, 1 = Monday, etc.)
    ->defaultWeekStartDay(1)
    // Prevent generated URLs from including "index.php"
    ->omitScriptNameInUrls()
    // Preload Single entries as Twig variables
    ->preloadSingles()
    // Enable the Twig sandbox for system messages, etc.
    ->enableTwigSandbox()
    // Set the @webroot alias so the clear-caches command knows where to find CP resources
    ->aliases([
        '@webroot' => dirname(__DIR__) . '/web',
    ])
;
PHP;

        [$contents, $pathCount] = $updater->rewritePaths($contents);
        [$contents, $removed] = $updater->removeSettings($contents);

        self::assertSame(<<<'PHP'
<?php

use craft\config\GeneralConfig;

return GeneralConfig::create()
    // Set the default week start day for date pickers (0 = Sunday, 1 = Monday, etc.)
    ->defaultWeekStartDay(1)
    // Preload Single entries as Twig variables
    ->preloadSingles()
    // Set the @webroot alias so the clear-caches command knows where to find CP resources
    ->aliases([
        '@webroot' => public_path(),
    ])
;
PHP, $contents);
        self::assertSame(1, $pathCount);
        self::assertSame(['omitScriptNameInUrls', 'enableTwigSandbox'], $removed);
        self::assertSame([], $updater->remainingSettings($contents));
        self::assertFalse($updater->hasUnresolvedPaths($contents));
    }

    public function test_it_updates_array_configs_and_paths_outside_the_web_root(): void
    {
        $updater = new CraftConfigUpdater('web', 'public');

        $contents = <<<'PHP'
<?php

return [
    '*' => [
        // Removed in Craft 6
        'enableTwigSandbox' => true,
        'pathParam' => null, // no path param
        "omitScriptNameInUrls" => App::env('OMIT') ?? true,
        'devMode' => false,
        'aliases' => [
            '@uploads' => dirname(__DIR__) . '/web/uploads',
            '@webrootish' => dirname(__DIR__) . '/webroot',
            '@backups' => CRAFT_BASE_PATH . "/storage/backups",
            '@legacy' => __DIR__ . '/../templates',
            '@shared' => dirname(__DIR__, 2) . '/shared',
        ],
    ],
];
PHP;

        [$contents, $pathCount] = $updater->rewritePaths($contents);
        [$contents, $removed] = $updater->removeSettings($contents);

        self::assertSame(<<<'PHP'
<?php

return [
    '*' => [
        'devMode' => false,
        'aliases' => [
            '@uploads' => public_path('uploads'),
            '@webrootish' => base_path('webroot'),
            '@backups' => base_path('storage/backups'),
            '@legacy' => base_path('templates'),
            '@shared' => dirname(base_path()) . '/shared',
        ],
    ],
];
PHP, $contents);
        self::assertSame(5, $pathCount);
        self::assertSame(['omitScriptNameInUrls', 'pathParam', 'enableTwigSandbox'], $removed);
        self::assertFalse($updater->hasUnresolvedPaths($contents));
    }

    public function test_it_keeps_a_web_root_that_isnt_renamed(): void
    {
        [$contents] = (new CraftConfigUpdater('web', 'web'))->rewritePaths("<?php return ['@webroot' => dirname(__DIR__) . '/web/'];");

        // public_path() only reflects a custom web root once the app has booted, after config files load
        self::assertSame("<?php return ['@webroot' => base_path('web/')];", $contents);
    }

    public function test_it_reports_what_it_cant_update_safely(): void
    {
        $updater = new CraftConfigUpdater('web', 'public');

        $contents = <<<'PHP'
<?php

return GeneralConfig::create()
    ->devMode(true)->pathParam(null)
    ->enableTwigSandbox(fn () => foo(bar(baz(1))))
    ->aliases(['@uploads' => '/var/www/web/uploads', '@web' => '@web/web-assets'])
    ->omitScriptNameInUrls();
PHP;

        [$contents] = $updater->rewritePaths($contents);
        [$contents, $removed] = $updater->removeSettings($contents);

        self::assertSame(<<<'PHP'
<?php

return GeneralConfig::create()
    ->devMode(true)
    ->enableTwigSandbox(fn () => foo(bar(baz(1))))
    ->aliases(['@uploads' => '/var/www/web/uploads', '@web' => '@web/web-assets'])
    ;
PHP, $contents);
        self::assertSame(['omitScriptNameInUrls', 'pathParam'], $removed);
        self::assertSame(['enableTwigSandbox'], $updater->remainingSettings($contents));
        self::assertTrue($updater->hasUnresolvedPaths($contents));
        self::assertFalse((new CraftConfigUpdater('web', 'web'))->hasUnresolvedPaths($contents));
    }

    public function test_it_finds_settings_that_moved_into_laravel_config(): void
    {
        $contents = <<<'PHP'
<?php

// blowfishHashCost used to be set here
return GeneralConfig::create()
    ->timezone('Europe/Brussels')
    ->testToEmailAddress(App::env('TEST_EMAIL'))
    ->aliases(['@phpSessionName' => '/tmp'])
    ->merge([
        'defaultCookieDomain' => '.example.com',
        "elevatedSessionDuration" => 600,
    ]);
PHP;

        // Comments, and the names inside other values, aren't settings
        self::assertSame(
            ['timezone', 'defaultCookieDomain', 'elevatedSessionDuration', 'testToEmailAddress'],
            (new CraftConfigUpdater('web', 'public'))->movedSettings($contents),
        );
    }
}
