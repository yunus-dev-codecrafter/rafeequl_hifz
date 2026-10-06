<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Display theme modes as the API exposes them (auto | light | dark).
 * The storage column user_settings.theme uses the day|night|auto
 * vocabulary from migration 0003 — mapping lives here, nowhere else.
 */
enum ThemeMode: string
{
    case Auto = 'auto';
    case Light = 'light';
    case Dark = 'dark';

    public function toDb(): string
    {
        return match ($this) {
            self::Light => 'day',
            self::Dark => 'night',
            self::Auto => 'auto',
        };
    }

    public static function fromDb(string $value): self
    {
        return match ($value) {
            'day' => self::Light,
            'night' => self::Dark,
            default => self::Auto,
        };
    }
}
