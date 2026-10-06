<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Reads key/value pairs from a `.env` file.
 *
 * Supported syntax:
 *   KEY=value
 *   KEY="quoted value"
 *   KEY='quoted value'
 *   # comment lines are ignored
 *
 * Values already present in the real environment (e.g. hosting panel vars)
 * always take precedence over values in the `.env` file.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if ($key === '' || array_key_exists($key, self::$values)) {
                continue;
            }

            // Strip matching surrounding quotes.
            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];
                if (($first === '"' || $first === "'") && $last === $first) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$values[$key] = $value;
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return self::$values[$key] ?? $default;
    }

    public static function getString(string $key, string $default = ''): string
    {
        return self::get($key, $default) ?? $default;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value === null || $value === '' ? $default : (int) $value;
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
