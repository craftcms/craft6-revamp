<?php

namespace CraftCms\Prepper\Console\Support;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * @since 6.0.0
 */
final class Env extends \Illuminate\Support\Env
{
    /**
     * Remove a single key from the environment file.
     *
     * @throws RuntimeException
     */
    public static function removeVariable(string $key, string $pathToFile): void
    {
        $filesystem = new Filesystem;

        if ($filesystem->missing($pathToFile)) {
            throw new RuntimeException("The file [{$pathToFile}] does not exist.");
        }

        $envContent = $filesystem->get($pathToFile);

        $lines = explode(PHP_EOL, $envContent);
        $lines = array_filter($lines, fn ($line) => ! str_starts_with($line, $key.'='));

        $filesystem->put($pathToFile, implode(PHP_EOL, $lines));
    }
}
