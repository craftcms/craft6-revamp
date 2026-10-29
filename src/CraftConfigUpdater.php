<?php

namespace CraftCms\Revamp;

/**
 * Updates Craft config files after they move into config/craft/.
 */
class CraftConfigUpdater
{
    /**
     * General config settings that no longer do anything in Craft 6, and log deprecations when set.
     */
    const REMOVED_SETTINGS = ['omitScriptNameInUrls', 'pathParam', 'enableTwigSandbox'];

    /**
     * Matches the expressions that used to point at the project root, with an optional `'/path'` suffix.
     */
    private const BASE_PATH_PATTERN = <<<'REGEX'
/(?:dirname\(\s*__DIR__\s*\)|\bCRAFT_BASE_PATH\b)(?:\s*\.\s*(['"])(\/[^'"$\\]*)\1)?|__DIR__\s*\.\s*(['"])\/\.\.(\/[^'"$\\]*)?\3/
REGEX;

    /**
     * Path expressions this class can’t rewrite safely.
     */
    private const UNRESOLVED_PATH_PATTERN = '/dirname\(\s*(?:dirname\(\s*)?__DIR__\s*[,)]|\bCRAFT_(?:BASE|VENDOR)_PATH\b|__DIR__\s*\.\s*[\'"]\/\.\./';

    /**
     * @param  string  $oldPublicPath  The web root folder name before the upgrade (e.g. `web`)
     * @param  string  $publicPath  The web root folder name after the upgrade (e.g. `public`)
     */
    public function __construct(
        private string $oldPublicPath,
        private string $publicPath,
    ) {}

    /**
     * Rewrites paths that were relative to the project root, which now resolve inside config/.
     *
     * @return array{0: string, 1: int} The updated contents and the number of rewritten paths
     */
    public function rewritePaths(string $contents): array
    {
        // Paths above the project root: `dirname(__DIR__, 2)` and `dirname(dirname(__DIR__))` become `dirname(base_path())`
        $contents = preg_replace_callback(
            '/dirname\(\s*__DIR__\s*,\s*(\d+)\s*\)|dirname\(\s*dirname\(\s*__DIR__\s*\)\s*\)/',
            function (array $match) {
                $levels = ($match[1] ?? '') !== '' ? (int) $match[1] - 1 : 1;

                return match (true) {
                    $levels < 1 => 'base_path()',
                    $levels === 1 => 'dirname(base_path())',
                    default => "dirname(base_path(), $levels)",
                };
            },
            $contents,
            -1,
            $count,
        );

        $contents = preg_replace_callback(self::BASE_PATH_PATTERN, function (array $match) {
            // Unmatched groups come back as empty strings, so `??` can't pick between the alternatives
            return $this->pathExpression(($match[2] ?? '') !== '' ? $match[2] : ($match[4] ?? ''));
        }, $contents, -1, $baseCount);

        return [$contents, $count + $baseCount];
    }

    /**
     * Removes settings that were removed in Craft 6, in both the fluent `->setting()` and the `'setting' => value` forms.
     *
     * @return array{0: string, 1: string[]} The updated contents and the removed settings
     */
    public function removeSettings(string $contents): array
    {
        $removed = [];
        $args = '\((?:[^()]|\([^()]*\))*\)';
        $comment = '(?:[ \t]*\/\/[^\n]*\n)?';

        foreach (self::REMOVED_SETTINGS as $setting) {
            $patterns = [
                // `->setting(...)` on its own line, along with the comment line right above it
                "/^$comment\h*->$setting\h*$args\h*(?:\/\/[^\n]*)?\n/m" => '',
                // `->setting(...);` ending the chain on its own line
                "/^$comment(\h*)->$setting\h*$args\h*;/m" => '$1;',
                // `->setting(...)` chained inline
                "/\h*->$setting\h*$args/" => '',
                // `'setting' => value,` on its own line, along with the comment line right above it
                "/^$comment\h*(['\"])$setting\\1\h*=>\h*(?:[^,()\[\]\n]|\([^()\n]*\))*,?\h*(?:\/\/[^\n]*)?\n/m" => '',
            ];

            foreach ($patterns as $pattern => $replacement) {
                $contents = preg_replace($pattern, $replacement, $contents, -1, $count);

                if ($count > 0 && ! in_array($setting, $removed)) {
                    $removed[] = $setting;
                }
            }
        }

        return [$contents, $removed];
    }

    /**
     * Returns the removed settings that are still referenced, because they used syntax that couldn’t be removed safely.
     *
     * @return string[]
     */
    public function remainingSettings(string $contents): array
    {
        $code = self::stripComments($contents);

        return array_values(array_filter(
            self::REMOVED_SETTINGS,
            fn (string $setting) => preg_match("/\b$setting\b/", $code),
        ));
    }

    /**
     * Returns whether the contents still contain paths that need to be updated by hand.
     */
    public function hasUnresolvedPaths(string $contents): bool
    {
        $code = self::stripComments($contents);

        if (preg_match(self::UNRESOLVED_PATH_PATTERN, $code)) {
            return true;
        }

        if ($this->oldPublicPath === $this->publicPath) {
            return false;
        }

        // Look for string literals that still reference the old web root, like `'/web/uploads'`
        // (but not aliases like `'@web/uploads'`).
        $folder = preg_quote($this->oldPublicPath, '/');
        foreach (token_get_all($code) as $token) {
            if (
                is_array($token) &&
                $token[0] === T_CONSTANT_ENCAPSED_STRING &&
                ! str_starts_with(substr($token[1], 1), '@') &&
                preg_match("/(?:^['\"]|\/)$folder(?:\/|['\"]$)/", $token[1])
            ) {
                return true;
            }
        }

        return false;
    }

    public static function stripComments(string $contents): string
    {
        $code = '';

        foreach (token_get_all($contents) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }

    /**
     * @param  string  $path  The path relative to the project root, starting with a slash (or empty)
     */
    private function pathExpression(string $path): string
    {
        if (preg_match('/^\/'.preg_quote($this->oldPublicPath, '/').'(\/.*)?$/', $path, $match)) {
            $path = $match[1] ?? '';

            // public_path() only reflects a custom web root after the app has booted, which is too late for config files
            if ($this->publicPath !== 'public') {
                return $this->call('base_path', $this->publicPath.$path);
            }

            return $this->call('public_path', $path);
        }

        return $this->call('base_path', $path);
    }

    private function call(string $function, string $path): string
    {
        $path = preg_replace('/^\//', '', $path);

        return sprintf('%s(%s)', $function, $path === '' ? '' : var_export($path, true));
    }
}
