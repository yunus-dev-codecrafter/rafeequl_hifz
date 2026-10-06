<?php

declare(strict_types=1);

use App\Helpers\Env;

/**
 * Base application configuration.
 *
 * All values come from the environment — nothing secret is stored here.
 * Consumed by later prompts (routing, sessions, features); safe to load
 * on every request because it performs no I/O beyond reading .env values.
 */

return [
    'name' => Env::getString('APP_NAME', 'Rafeequl Hifz'),
    'env' => Env::getString('APP_ENV', 'production'),
    'debug' => Env::getBool('APP_DEBUG', false),
    'url' => Env::getString('APP_URL', 'http://localhost'),
    'timezone' => Env::getString('APP_TIMEZONE', 'UTC'),
    'locale' => Env::getString('APP_LOCALE', 'en'),

    'session' => [
        'name' => Env::getString('SESSION_NAME', 'rafeequl_hifz_session'),
        'lifetime' => Env::getInt('SESSION_LIFETIME', 120),
    ],

    'log' => [
        'channel' => Env::getString('LOG_CHANNEL', 'storage'),
        'level' => Env::getString('LOG_LEVEL', 'info'),
    ],
];
