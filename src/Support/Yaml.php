<?php

namespace CraftCms\Prepper\Console\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml as SymfonyYaml;

class Yaml
{
    /**
     * Decodes a YAML file into an array, or returns `null` if it doesn’t exist or isn’t a valid YAML array.
     *
     * @return array<mixed>|null
     */
    public static function decodeFromFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $data = SymfonyYaml::parseFile($path);
        } catch (ParseException) {
            return null;
        }

        return is_array($data) ? $data : null;
    }
}
