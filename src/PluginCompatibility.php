<?php

namespace CraftCms\Revamp;

use Closure;
use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;
use CraftCms\Revamp\Support\Json;
use UnexpectedValueException;

/**
 * Checks Packagist for Craft 6-compatible versions of the packages that require craftcms/cms.
 */
class PluginCompatibility
{
    const STATUS_COMPATIBLE = 'compatible';

    const STATUS_INCOMPATIBLE = 'incompatible';

    const STATUS_NOT_FOUND = 'not-found';

    const STATUS_UNKNOWN = 'unknown';

    /**
     * Patterns matching plugins that Laravel configuration replaces in Craft 6, and what replaces them.
     */
    private const OBSOLETE_PLUGINS = [
        '/(?<![a-z0-9])(aws-s3|s3|google-cloud|gcs|azure-?blob|azure-?storage|dospaces|linode-?s3|cloudflare-?r2|r2)(?![a-z0-9])/' => 'a Laravel disk in config/filesystems.php',
        '/(?<![a-z0-9])(sendgrid|mailgun|postmark|amazon-?ses|ses|mandrill)(?![a-z0-9])/' => 'a Laravel mailer in config/mail.php',
        '/(?<![a-z0-9])(log|logs|logging|logger|logtail|papertrail)(?![a-z0-9])/' => 'a Laravel logging channel in config/logging.php',
    ];

    /** @var Closure(string $url): (string|false|null) */
    private Closure $fetch;

    /**
     * @param  (Closure(string $url): (string|false|null))|null  $fetch  Returns the response body, `null` for a 404, or `false` if Packagist couldn’t be reached
     */
    public function __construct(?Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::fetchFromPackagist(...);
    }

    /**
     * @return array<string, string> The installed versions of packages that require craftcms/cms, indexed by package name
     */
    public function dependents(string $composerLockPath): array
    {
        $lockData = Json::decodeFromFile($composerLockPath);
        $dependents = [];

        foreach ([...$lockData['packages'] ?? [], ...$lockData['packages-dev'] ?? []] as $package) {
            if (isset($package['require']['craftcms/cms'])) {
                $dependents[$package['name']] = $package['version'];
            }
        }

        ksort($dependents);

        return $dependents;
    }

    /**
     * Returns what replaces a plugin that’s probably no longer needed in Craft 6, or `null`.
     */
    public static function obsoleteReplacement(string $package): ?string
    {
        foreach (self::OBSOLETE_PLUGINS as $pattern => $replacement) {
            if (preg_match($pattern, strtolower($package))) {
                return $replacement;
            }
        }

        return null;
    }

    /**
     * @param  string[]  $packages
     * @return array<string, array{status: string, version: string|null}>
     */
    public function check(array $packages): array
    {
        $results = [];
        $offline = false;

        foreach ($packages as $package) {
            if ($offline) {
                $results[$package] = ['status' => self::STATUS_UNKNOWN, 'version' => null];

                continue;
            }

            $status = self::STATUS_NOT_FOUND;
            $version = null;

            // Tagged releases come first, then dev branches (such as craftcms/generator’s 3.x-dev)
            foreach (['', '~dev'] as $suffix) {
                $response = ($this->fetch)("https://repo.packagist.org/p2/$package$suffix.json");

                if ($response === false) {
                    $offline = true;
                    $status = self::STATUS_UNKNOWN;

                    break;
                }

                if ($response === null) {
                    break;
                }

                $status = self::STATUS_INCOMPATIBLE;
                $version = $this->findCompatibleVersion($package, $response);

                if ($version !== null) {
                    $status = self::STATUS_COMPATIBLE;

                    break;
                }
            }

            $results[$package] = ['status' => $status, 'version' => $version];
        }

        return $results;
    }

    private function findCompatibleVersion(string $package, string $response): ?string
    {
        $versions = Json::decode($response)['packages'][$package] ?? null;

        if (! is_array($versions)) {
            return null;
        }

        $parser = new VersionParser;
        $craft6 = $parser->parseConstraints('>=6.0.0-dev <7.0.0-dev');
        $data = [];

        foreach ($versions as $version) {
            // Packagist’s metadata is minified: each version only lists the keys that changed since the previous one.
            foreach ($version as $key => $value) {
                if ($value === '__unset') {
                    unset($data[$key]);
                } else {
                    $data[$key] = $value;
                }
            }

            $constraint = $data['require']['craftcms/cms'] ?? null;

            if (! is_string($constraint)) {
                continue;
            }

            try {
                if (Intervals::haveIntersections($parser->parseConstraints($constraint), $craft6)) {
                    return $data['version'];
                }
            } catch (UnexpectedValueException) {
                continue;
            }
        }

        return null;
    }

    private static function fetchFromPackagist(string $url): string|false|null
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'header' => "User-Agent: craftcms/craft6-revamp\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        // $http_response_header is deprecated as of PHP 8.5
        $headers = function_exists('http_get_last_response_headers')
            ? http_get_last_response_headers()
            : ($http_response_header ?? null);
        $status = $headers[0] ?? null;

        if ($response === false || $status === null) {
            return false;
        }

        if (preg_match('/^HTTP\/\S+\s+404\b/', $status)) {
            return null;
        }

        if (! preg_match('/^HTTP\/\S+\s+200\b/', $status)) {
            return false;
        }

        return $response;
    }
}
