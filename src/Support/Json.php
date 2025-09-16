<?php

namespace CraftCms\Prepper\Console\Support;

use InvalidArgumentException;
use Throwable;

/**
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 */
class Json
{
    /**
     * Encodes the given value into a JSON string.
     *
     * @param  mixed  $value  the data to be encoded.
     * @param  int  $options  The encoding options. `JSON_UNESCAPED_UNICODE` is used by default.
     */
    public static function encode($value, $options = JSON_UNESCAPED_UNICODE): string
    {
        return json_encode($value, $options);
    }

    /**
     * Decodes the given JSON string into a PHP data structure.
     *
     * @param  string  $json  the JSON string to be decoded
     * @param  bool  $asArray  whether to return objects in terms of associative arrays.
     * @return mixed the PHP data
     *
     * @throws InvalidArgumentException if there is any decoding error
     */
    public static function decode(mixed $json, bool $asArray = true): mixed
    {
        if ($json === null || $json === '') {
            return null;
        }

        return json_decode((string) $json, $asArray);
    }

    /**
     * Decodes JSON from a given file path.
     *
     * @param  string  $file  the file path
     * @param  bool  $asArray  whether to return objects in terms of associative arrays
     * @return mixed The JSON-decoded file contents
     *
     * @throws InvalidArgumentException if the file doesn’t exist or there was a problem JSON-decoding it
     */
    public static function decodeFromFile(string $file, bool $asArray = true): mixed
    {
        if (! file_exists($file)) {
            throw new InvalidArgumentException("`$file` doesn’t exist.");
        }

        if (is_dir($file)) {
            throw new InvalidArgumentException("`$file` is a directory.");
        }

        try {
            return static::decode(file_get_contents($file), $asArray);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException("`$file` doesn’t contain valid JSON.");
        }
    }

    /**
     * Writes out a JSON file for the given value, maintaining its current
     * indentation sequence if the file already exists.
     *
     * @param  string  $path  The file path
     * @param  mixed  $value  the data to be encoded.
     * @param  int  $options  The encoding options. `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`
     *                        is used by default.
     * @param  string  $defaultIndent  The default indentation sequence to use if the file doesn’t exist
     */
    public static function encodeToFile(
        string $path,
        mixed $value,
        int $options = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        string $defaultIndent = '  ',
    ): void {
        $json = static::encode($value, $options);

        if ($options & JSON_PRETTY_PRINT) {
            if (file_exists($path)) {
                $indent = static::detectIndent(file_get_contents($path));
            } else {
                $indent = $defaultIndent;
            }

            $json = static::reindent($json, $indent);
        }

        file_put_contents($path, $json . "\n");
    }

    /**
     * Detects and returns the indentation sequence used by the given JSON string.
     */
    public static function detectIndent(string $json): string
    {
        if (! preg_match('/^\s*\{\s*[\r\n]+([ \t]+)"/', $json, $match)) {
            return '  ';
        }

        return $match[1];
    }

    /**
     * Re-indents JSON with the given indentation string.
     */
    public static function reindent(string $json, string $indent = '  '): string
    {
        if ($indent !== '    ') {
            return preg_replace_callback('/^ {4,}/m', fn (array $match) => strtr($match[0], ['    ' => $indent]), $json);
        }

        return $json;
    }
}
