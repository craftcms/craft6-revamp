<?php

namespace CraftCms\Prepper\Console;

use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class FilesystemMigrationSuggestions
{
    private string $publicPath = 'public';

    /** @var string[] Craft aliases found in the current filesystem’s settings that have no Laravel equivalent */
    private array $unresolvedAliases = [];

    /**
     * @param  string  $publicPath  The web root folder name, relative to the project root, once the upgrade is done
     * @return string[]
     */
    public function get(string $projectConfigPath, string $publicPath = 'public'): array
    {
        $this->publicPath = $publicPath;

        if (! file_exists($projectConfigPath)) {
            return [];
        }

        try {
            $projectConfig = Yaml::parseFile($projectConfigPath);
        } catch (ParseException) {
            return [];
        }

        if (! is_array($projectConfig) || ! is_array($projectConfig['fs'] ?? null)) {
            return [];
        }

        $suggestions = [];

        foreach ($projectConfig['fs'] as $handle => $filesystem) {
            if (! is_string($handle) || ! is_array($filesystem)) {
                continue;
            }

            $type = $filesystem['type'] ?? null;
            $settings = is_array($filesystem['settings'] ?? null) ? $filesystem['settings'] : [];
            $credentials = [];
            $this->unresolvedAliases = [];
            $config = match ($type) {
                'craft\\fs\\Local' => $this->localDiskConfig($filesystem, $settings),
                'craft\\awss3\\Fs' => $this->s3DiskConfig($handle, $filesystem, $settings, $credentials),
                'vaersaagod\\dospaces\\Fs', 'mwikala\\linodes3\\Fs' => $this->s3CompatibleDiskConfig($handle, $filesystem, $settings, $credentials),
                'jrrdnx\\cloudflarer2\\Fs' => $this->cloudflareR2DiskConfig($handle, $filesystem, $settings, $credentials),
                'craft\\googlecloud\\Fs' => $this->googleCloudDiskConfig($handle, $filesystem, $settings, $credentials),
                'craft\\azureblob\\Fs' => $this->azureBlobDiskConfig($handle, $filesystem, $settings, $credentials),
                default => null,
            };

            if ($config === null) {
                $suggestions[] = sprintf(
                    "Manually migrate the <options=bold>%s</> filesystem%s to a Laravel disk named <options=bold>%s</>.",
                    $handle,
                    is_string($type) ? " ($type)" : '',
                    $handle,
                );

                continue;
            }

            $suggestion = $this->renderDiskConfig($handle, $config);
            if ($credentials !== []) {
                $suggestion .= sprintf(
                    "\nSet %s in .env using the previous credential %s.",
                    implode(' and ', array_map(fn (string $name) => "<options=bold>$name</>", $credentials)),
                    count($credentials) === 1 ? 'value' : 'values',
                );
            }

            if ($this->unresolvedAliases !== []) {
                $aliases = array_values(array_unique($this->unresolvedAliases));
                $suggestion .= sprintf(
                    "\nReplace %s with a real %s, because Laravel config doesn’t resolve Craft aliases.",
                    implode(' and ', array_map(fn (string $alias) => "<options=bold>$alias</>", $aliases)),
                    count($aliases) === 1 ? 'path or URL' : 'paths or URLs',
                );
            }

            $suggestions[] = $suggestion;
        }

        return $suggestions;
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @return array<string, string>|null
     */
    private function localDiskConfig(array $filesystem, array $settings): ?array
    {
        if (! is_string($settings['path'] ?? null)) {
            return null;
        }

        return array_filter([
            'driver' => var_export('local', true),
            'root' => $this->configExpression($settings['path']),
            'url' => $this->filesystemUrlExpression($filesystem, $settings),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @param  string[]  $credentials
     * @return array<string, string>
     */
    private function s3DiskConfig(string $handle, array $filesystem, array $settings, array &$credentials): array
    {
        return array_filter([
            'driver' => var_export('s3', true),
            'key' => $this->credentialExpression($handle, 'KEY_ID', $settings['keyId'] ?? null, $credentials),
            'secret' => $this->credentialExpression($handle, 'SECRET', $settings['secret'] ?? null, $credentials),
            'region' => $this->configExpression($settings['region'] ?? null),
            'bucket' => $this->configExpression($settings['bucket'] ?? null),
            'root' => $this->configExpression($settings['subfolder'] ?? null),
            'url' => $this->filesystemUrlExpression($filesystem, $settings),
            'visibility' => var_export(($settings['makeUploadsPublic'] ?? true) ? 'public' : 'private', true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @param  string[]  $credentials
     * @return array<string, string>
     */
    private function s3CompatibleDiskConfig(string $handle, array $filesystem, array $settings, array &$credentials): array
    {
        return array_filter([
            'driver' => var_export('s3', true),
            'key' => $this->credentialExpression($handle, 'KEY_ID', $settings['keyId'] ?? null, $credentials),
            'secret' => $this->credentialExpression($handle, 'SECRET', $settings['secret'] ?? null, $credentials),
            'region' => $this->configExpression($settings['region'] ?? null),
            'bucket' => $this->configExpression($settings['bucket'] ?? null),
            'endpoint' => $this->configExpression($settings['endpoint'] ?? null),
            'root' => $this->configExpression($settings['subfolder'] ?? null),
            'url' => $this->filesystemUrlExpression($filesystem, $settings),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @param  string[]  $credentials
     * @return array<string, string>
     */
    private function cloudflareR2DiskConfig(string $handle, array $filesystem, array $settings, array &$credentials): array
    {
        $accountId = $this->configExpression($settings['accountId'] ?? null);
        $bucket = ($settings['bucketSelectionMode'] ?? null) === 'manual'
            ? ($settings['manualBucket'] ?? null)
            : ($settings['bucket'] ?? null);

        return array_filter([
            'driver' => var_export('r2', true),
            'key' => $this->credentialExpression($handle, 'KEY_ID', $settings['keyId'] ?? null, $credentials),
            'secret' => $this->credentialExpression($handle, 'SECRET', $settings['secret'] ?? null, $credentials),
            'bucket' => $this->configExpression($bucket),
            'endpoint' => $accountId === null ? null : "sprintf('https://%s.r2.cloudflarestorage.com', $accountId)",
            'region' => $this->configExpression($settings['region'] ?? 'auto'),
            'url' => $this->filesystemUrlExpression($filesystem, $settings),
            'prefix' => $this->configExpression($settings['subfolder'] ?? null),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @param  string[]  $credentials
     * @return array<string, string>
     */
    private function googleCloudDiskConfig(string $handle, array $filesystem, array $settings, array &$credentials): array
    {
        $keyFile = $this->credentialExpression($handle, 'KEY_FILE', $settings['keyFileContents'] ?? null, $credentials);

        return array_filter([
            '// Install with: composer require spatie/laravel-google-cloud-storage' => '',
            'driver' => var_export('gcs', true),
            'project_id' => $this->configExpression($settings['projectId'] ?? null),
            'key_file' => $keyFile === null ? null : "json_decode((string) $keyFile, true)",
            'bucket' => $this->configExpression($settings['bucket'] ?? null),
            'path_prefix' => $this->configExpression($settings['subfolder'] ?? null),
            'storage_api_uri' => $this->filesystemUrlExpression($filesystem, $settings),
            'visibility' => $this->configExpression($settings['visibility'] ?? null),
        ], fn (?string $value, string $key) => $value !== null && ($value !== '' || str_starts_with($key, '//')), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     * @param  string[]  $credentials
     * @return array<string, string>
     */
    private function azureBlobDiskConfig(string $handle, array $filesystem, array $settings, array &$credentials): array
    {
        return array_filter([
            '// Install with: composer require azure-oss/storage-blob-laravel' => '',
            'driver' => var_export('azure-storage-blob', true),
            'connection_string' => $this->credentialExpression($handle, 'CONNECTION_STRING', $settings['connectionString'] ?? null, $credentials),
            'container' => $this->configExpression($settings['container'] ?? null),
            'prefix' => $this->configExpression($settings['subfolder'] ?? null),
            'url' => $this->filesystemUrlExpression($filesystem, $settings),
        ], fn (?string $value, string $key) => $value !== null && ($value !== '' || str_starts_with($key, '//')), ARRAY_FILTER_USE_BOTH);
    }

    /** @param array<string, string> $config */
    private function renderDiskConfig(string $handle, array $config): string
    {
        $lines = [sprintf('%s => [', var_export($handle, true))];

        foreach ($config as $key => $expression) {
            $lines[] = str_starts_with($key, '//')
                ? "    $key"
                : sprintf("    %s => %s,", var_export($key, true), $expression);
        }

        $lines[] = '],';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Turns a Craft setting value into a PHP expression for Laravel config, resolving `$ENV_VAR`
     * references and the Craft aliases that have Laravel equivalents.
     */
    private function configExpression(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        // Craft resolves `${VAR}` anywhere, and `$VAR` as a whole path segment
        $parts = preg_split('/(\$\{\w+\}|(?<=^|\/)\$\w+(?=$|\/))/', $value, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $expressions = [];

        foreach ($parts as $i => $part) {
            if (preg_match('/^\$\{?(\w+)\}?$/', $part, $match)) {
                $expressions[] = sprintf('env(%s)', var_export($match[1], true));

                continue;
            }

            if ($i === 0 && preg_match('/^(@\w+)(\/.*)?$/s', $part, $match)) {
                $expression = $this->aliasExpression($match[1], $match[2] ?? '');

                if ($expression !== null) {
                    $expressions[] = $expression;

                    continue;
                }

                $this->unresolvedAliases[] = $match[1];
            }

            $expressions[] = var_export($part, true);
        }

        return implode(' . ', $expressions);
    }

    private function aliasExpression(string $alias, string $suffix): ?string
    {
        $path = ltrim($suffix, '/');

        return match ($alias) {
            '@webroot' => $this->publicPath === 'public'
                ? $this->call('public_path', $path)
                // public_path() only reflects a custom web root once the app has booted, after config is loaded
                : $this->call('base_path', $this->publicPath.($path === '' ? '' : "/$path")),
            '@root' => $this->call('base_path', $path),
            '@storage' => $this->call('storage_path', $path),
            '@web' => "env('APP_URL')".($path === '' ? '' : ' . '.var_export("/$path", true)),
            default => null,
        };
    }

    private function call(string $function, string $path): string
    {
        return sprintf('%s(%s)', $function, $path === '' ? '' : var_export($path, true));
    }

    /** @param string[] $credentials */
    private function credentialExpression(string $handle, string $suffix, mixed $value, array &$credentials): ?string
    {
        $expression = $this->configExpression($value);
        if ($expression === null || str_starts_with($expression, 'env(')) {
            return $expression;
        }

        $name = Str::of($handle)->snake()->upper()."_$suffix";
        $credentials[] = $name;

        return sprintf("env(%s)", var_export($name, true));
    }

    /**
     * @param  array<string, mixed>  $filesystem
     * @param  array<string, mixed>  $settings
     */
    private function filesystemUrlExpression(array $filesystem, array $settings): ?string
    {
        if (($settings['hasUrls'] ?? $filesystem['hasUrls'] ?? false) !== true) {
            return null;
        }

        return $this->configExpression($settings['url'] ?? $filesystem['url'] ?? null);
    }
}
