<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\HttpException;

/**
 * HTTP request abstraction (core infrastructure).
 *
 * Wraps superglobals once at the front controller so controllers,
 * validators, and middleware never touch $_GET/$_POST/$_SERVER directly.
 */
final class Request
{
    /** @var array<string, string> normalized (canonical-cased) header names => value */
    private array $headers = [];

    /** @var array<string, string> route parameters extracted by the router */
    private array $params = [];

    /** @var array<string, mixed> middleware/controller attributes (e.g. authenticated user) */
    private array $attributes = [];

    /** @var array<string, string>|null lazily parsed cookies */
    private ?array $cookieCache = null;

    private function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly ?string $ip = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = '/';
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!str_starts_with($key, 'HTTP_')) {
                continue;
            }
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = (string) $value;
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        $request = new self(
            $method,
            $path,
            $_GET,
            self::parseBody($method, $headers),
            isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        );
        $request->headers = $headers;

        return $request;
    }

    /** @return array<string, mixed> */
    private static function parseBody(string $method, array $headers): array
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        $contentType = $headers['Content-Type'] ?? '';

        if (stripos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if ($raw === false || $raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new HttpException(400, 'Malformed JSON body');
            }
            return is_array($decoded) ? $decoded : [];
        }

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false
            || stripos($contentType, 'multipart/form-data') !== false
        ) {
            return $_POST;
        }

        return [];
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /** @return array<string, mixed> */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }
        return $this->query[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }
        return $this->body[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $canonical = str_replace(' ', '-', ucwords(strtolower(str_replace('-', ' ', $name))));
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $canonical) === 0) {
                return $value;
            }
        }
        return $default;
    }

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('Content-Type', '') ?? ''), 'application/json');
    }

    public function param(string $name, ?string $default = null): ?string
    {
        return $this->params[$name] ?? $default;
    }

    /** @return array<string, string> */
    public function params(): array
    {
        return $this->params;
    }

    public function ip(): ?string
    {
        return $this->ip;
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        if ($this->cookieCache !== null) {
            return $this->cookieCache;
        }

        $parsed = [];
        $header = $this->header('Cookie');
        if ($header !== null && $header !== '') {
            foreach (explode(';', $header) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                $parts = explode('=', $pair, 2);
                if (count($parts) === 2 && $parts[0] !== '') {
                    $parsed[$parts[0]] = rawurldecode($parts[1]);
                }
            }
        }

        return $this->cookieCache = $parsed;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies()[$name] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Returns a copy carrying the given attribute (immutable request). */
    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;
        return $clone;
    }

    /** Returns a copy carrying the given route parameters. */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }
}
