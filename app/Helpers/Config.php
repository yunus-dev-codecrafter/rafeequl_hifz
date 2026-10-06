<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * Small configuration loader with per-file caching.
 * Config::get('app', 'session.name') reads config/app.php → ['session']['name'].
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** @return array<string, mixed> */
    public static function load(string $file): array
    {
        if (!isset(self::$cache[$file])) {
            $path = CONFIG_PATH . '/' . $file . '.php';
            if (!is_file($path)) {
                throw new \RuntimeException('Config file not found: ' . $file);
            }
            /** @var array<string, mixed> $data */
            $data = require $path;
            self::$cache[$file] = $data;
        }

        return self::$cache[$file];
    }

    public static function get(string $file, string $path, mixed $default = null): mixed
    {
        $value = self::load($file);

        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** Drops cached files (tests / tooling). */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
