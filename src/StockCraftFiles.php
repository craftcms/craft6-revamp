<?php

namespace CraftCms\Revamp;

/**
 * Finds customizations in a project’s Craft 5 `index.php` and `bootstrap.php` files.
 */
class StockCraftFiles
{
    /**
     * Every line from the stock `web/index.php` and `bootstrap.php` files that craftcms/craft has shipped
     * since Craft 3.6 (when `bootstrap.php` was introduced), plus the self-contained `index.php` before it.
     */
    private const STOCK_LINES = [
        '<?php',
        '/**',
        '*/',
        '* Shared bootstrap file',
        '* Craft web bootstrap file',
        '// Set the error reporting level',
        'error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);',
        '// Define path constants',
        "define('CRAFT_BASE_PATH', __DIR__);",
        "define('CRAFT_BASE_PATH', dirname(__DIR__));",
        "define('CRAFT_VENDOR_PATH', CRAFT_BASE_PATH . '/vendor');",
        "// Load Composer's autoloader",
        "require_once CRAFT_VENDOR_PATH . '/autoload.php';",
        '// Load dotenv?',
        'if (class_exists(Dotenv\Dotenv::class)) {',
        "if (class_exists('Dotenv\Dotenv')) {",
        "if (class_exists('Dotenv\Dotenv') && file_exists(CRAFT_BASE_PATH . '/.env')) {",
        '// Use `createMutable` to allow .env file overrides for existing environment variables.',
        '// By default, this will allow .env file values to override environment variables',
        '// with matching names. Use `createUnsafeImmutable` to disable this.',
        'Dotenv\Dotenv::createImmutable(CRAFT_BASE_PATH)->safeLoad();',
        'Dotenv\Dotenv::createUnsafeMutable(CRAFT_BASE_PATH)->safeLoad();',
        'Dotenv\Dotenv::createUnsafeImmutable(CRAFT_BASE_PATH)->safeLoad();',
        'Dotenv\Dotenv::create(CRAFT_BASE_PATH)->load();',
        '}',
        '// Define additional PHP constants',
        '// (see https://craftcms.com/docs/3.x/config/#php-constants)',
        "define('CRAFT_ENVIRONMENT', getenv('ENVIRONMENT') ?: 'production');",
        '// ...',
        '// Load shared bootstrap',
        "require dirname(__DIR__) . '/bootstrap.php';",
        '// Load and run Craft',
        '/** @var craft\web\Application $app */',
        "\$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/web.php';",
        '$app->run();',
    ];

    /**
     * Returns the lines of a file that aren’t part of any stock Craft `index.php` or `bootstrap.php` file.
     *
     * @return string[]
     */
    public static function customLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $lines = preg_split('/\R/', file_get_contents($path));

        return array_values(array_filter(
            array_map('rtrim', $lines),
            fn (string $line) => trim($line) !== '' && ! in_array(trim($line), self::STOCK_LINES, true),
        ));
    }
}
