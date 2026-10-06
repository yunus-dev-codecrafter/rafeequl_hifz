<?php

declare(strict_types=1);

namespace App;

/**
 * HTTP response (core infrastructure).
 *
 * All API responses use the project envelope:
 *   { "ok": bool, "data": ..., "errors": [ { "field"?: ..., "message": ... } ] }
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<int, array{name: string, value: string, expires: int}> */
    private array $cookies = [];

    private function __construct(
        private readonly string $body,
        private readonly int $status = 200,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode(
            $data,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($body === false) {
            throw new \RuntimeException('JSON encoding failed: ' . json_last_error_msg());
        }

        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $response;
    }

    public static function ok(mixed $data = null, int $status = 200): self
    {
        return self::json(['ok' => true, 'data' => $data, 'errors' => []], $status);
    }

    /** Raw HTML response (the SPA shell served by routes/web.php). */
    public static function html(string $body, int $status = 200): self
    {
        $response = new self($body, $status);
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';
        return $response;
    }

    /**
     * Raw CSV file download (Prompt 23) — the body *is* the file, not an
     * envelope. Used for data exports; clients consume it as text.
     */
    public static function csv(string $body): self
    {
        $response = new self($body, 200);
        $response->headers['Content-Type'] = 'text/csv; charset=utf-8';
        return $response;
    }

    /** @param array<int, array<string, mixed>|string> $errors */
    public static function failure(array $errors, int $status = 400): self
    {
        $normalized = [];
        foreach ($errors as $error) {
            $normalized[] = is_array($error) ? $error : ['message' => (string) $error];
        }
        if ($normalized === []) {
            $normalized = [['message' => 'Request failed']];
        }

        return self::json(['ok' => false, 'data' => null, 'errors' => $normalized], $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /**
     * Baseline hardening headers, applied to every response (success or error).
     *
     * CSP: the frontend is fully CSP-clean — no inline scripts/styles, no eval,
     * no third-party origins (audit: docs/security/security-audit.md, F-02).
     */
    public function withSecurityHeaders(): self
    {
        $self = $this
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Permitted-Cross-Domain-Policies', 'none')
            ->withHeader('Permissions-Policy', 'accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()')
            ->withHeader(
                'Content-Security-Policy',
                "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; "
                . "script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
                . "manifest-src 'self'; worker-src 'self'"
            );

        // HSTS only over HTTPS; browsers ignore it on plain HTTP, so the
        // X-Forwarded-Proto check cannot downgrade or break local dev.
        if (self::isHttps()) {
            $self = $self->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        return $self;
    }

    /**
     * Attaches a session cookie: HttpOnly, SameSite=Lax, Path=/,
     * Secure automatically when the request is HTTPS (conventions §12).
     */
    public function withCookie(string $name, string $value, int $maxAgeSeconds): self
    {
        $clone = clone $this;
        $clone->cookies[] = [
            'name' => $name,
            'value' => $value,
            'expires' => time() + $maxAgeSeconds,
        ];
        return $clone;
    }

    /** Expired cookie — forces browser removal (logout / invalid session). */
    public function withoutCookie(string $name): self
    {
        $clone = clone $this;
        $clone->cookies[] = [
            'name' => $name,
            'value' => '',
            'expires' => time() - 3600,
        ];
        return $clone;
    }

    /** @return array<int, array{name: string, value: string, expires: int}> */
    public function cookies(): array
    {
        return $this->cookies;
    }

    private static function isHttps(): bool
    {
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        // TLS terminated in front of PHP without the usual markers (some
        // shared hosts, InfinityFree among them): the connection itself
        // arrived on 443.
        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public function send(): void
    {
        http_response_code($this->status);

        $body = $this->body;
        if (self::shouldCompress($body)) {
            $compressed = gzencode($body, 6);
            if ($compressed !== false && strlen($compressed) < strlen($body)) {
                $body = $compressed;
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
            }
        }

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        foreach ($this->cookies as $cookie) {
            setcookie($cookie['name'], $cookie['value'], [
                'expires' => $cookie['expires'],
                'path' => '/',
                'secure' => self::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        echo $body;
    }

    /**
     * gzip only when it actually helps (Prompt 26): the client must offer it,
     * no server-level compression may already be on, and the caller re-checks
     * that the result is smaller before swapping the body.
     */
    private static function shouldCompress(string $body): bool
    {
        if ($body === '' || !function_exists('gzencode')) {
            return false;
        }
        if (ini_get('zlib.output_compression')) {
            return false;
        }
        if (stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') === false) {
            return false;
        }
        // Legacy PowerShell 5.1 does not always decode Content-Encoding: gzip
        // correctly (probe: docs/deployment/performance.md §4); sending it
        // uncompressed keeps smoke/automation clients working byte-exact.
        if (stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'powershell/') !== false
            || stripos($_SERVER['HTTP_USER_AGENT'] ?? '', 'pwsh/') !== false) {
            return false;
        }
        return true;
    }
}
