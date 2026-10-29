<?php

namespace CraftCms\Revamp\Tests;

use CraftCms\Revamp\PluginCompatibility;
use PHPUnit\Framework\TestCase;

class PluginCompatibilityTest extends TestCase
{
    public function test_it_finds_packages_that_require_craft(): void
    {
        $lockPath = tempnam(sys_get_temp_dir(), 'craft-lock-');
        file_put_contents($lockPath, json_encode([
            'packages' => [
                ['name' => 'craftcms/cms', 'version' => '5.9.0', 'require' => ['php' => '^8.2']],
                ['name' => 'vendor/plugin', 'version' => '2.0.0', 'require' => ['craftcms/cms' => '^5.0']],
                ['name' => 'other/library', 'version' => '1.0.0', 'require' => ['php' => '^8.0']],
            ],
            'packages-dev' => [
                ['name' => 'craftcms/generator', 'version' => '2.2.0', 'require' => ['craftcms/cms' => '^5.0.0-beta.1']],
            ],
        ]));

        try {
            self::assertSame(
                ['craftcms/generator' => '2.2.0', 'vendor/plugin' => '2.0.0'],
                (new PluginCompatibility)->dependents($lockPath),
            );
        } finally {
            unlink($lockPath);
        }
    }

    public function test_it_checks_packagist_for_craft6_versions(): void
    {
        $responses = [
            // Minified metadata: 3.0.0 inherits the require list from 4.0.0-beta.1, then 2.0.0 overrides it
            'https://repo.packagist.org/p2/vendor/compatible.json' => $this->metadata('vendor/compatible', [
                ['version' => '4.0.0-beta.1', 'require' => ['craftcms/cms' => '^6.0.0-alpha.1']],
                ['version' => '3.0.0'],
                ['version' => '2.0.0', 'require' => ['craftcms/cms' => '^5.0']],
            ]),
            'https://repo.packagist.org/p2/vendor/dev-only.json' => $this->metadata('vendor/dev-only', [
                ['version' => '2.0.0', 'require' => ['craftcms/cms' => '^5.0']],
            ]),
            'https://repo.packagist.org/p2/vendor/dev-only~dev.json' => $this->metadata('vendor/dev-only', [
                ['version' => '2.x-dev', 'require' => ['craftcms/cms' => '^5.0']],
                ['version' => '3.x-dev', 'require' => ['craftcms/cms' => '^6.0']],
            ]),
            'https://repo.packagist.org/p2/vendor/incompatible.json' => $this->metadata('vendor/incompatible', [
                ['version' => '2.0.0', 'require' => ['craftcms/cms' => '^4.0|^5.0']],
            ]),
            'https://repo.packagist.org/p2/vendor/incompatible~dev.json' => $this->metadata('vendor/incompatible', [
                ['version' => 'dev-main', 'require' => ['craftcms/cms' => '^5.0']],
            ]),
        ];
        $requested = [];

        $results = (new PluginCompatibility(function (string $url) use ($responses, &$requested) {
            $requested[] = $url;

            return $responses[$url] ?? null;
        }))->check(['vendor/compatible', 'vendor/dev-only', 'vendor/incompatible', 'vendor/private']);

        self::assertSame([
            'vendor/compatible' => ['status' => PluginCompatibility::STATUS_COMPATIBLE, 'version' => '4.0.0-beta.1'],
            'vendor/dev-only' => ['status' => PluginCompatibility::STATUS_COMPATIBLE, 'version' => '3.x-dev'],
            'vendor/incompatible' => ['status' => PluginCompatibility::STATUS_INCOMPATIBLE, 'version' => null],
            'vendor/private' => ['status' => PluginCompatibility::STATUS_NOT_FOUND, 'version' => null],
        ], $results);
        self::assertNotContains('https://repo.packagist.org/p2/vendor/compatible~dev.json', $requested);
    }

    public function test_it_stops_checking_when_packagist_cant_be_reached(): void
    {
        $requests = 0;

        $results = (new PluginCompatibility(function () use (&$requests) {
            $requests++;

            return false;
        }))->check(['vendor/a', 'vendor/b']);

        self::assertSame(1, $requests);
        self::assertSame(PluginCompatibility::STATUS_UNKNOWN, $results['vendor/a']['status']);
        self::assertSame(PluginCompatibility::STATUS_UNKNOWN, $results['vendor/b']['status']);
    }

    /**
     * @param  array<array<string, mixed>>  $versions
     */
    private function metadata(string $name, array $versions): string
    {
        return json_encode(['minified' => 'composer/2.0', 'packages' => [$name => $versions]]);
    }
}
