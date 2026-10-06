<?php

declare(strict_types=1);

namespace App;

use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use App\Middleware\MiddlewareInterface;

/**
 * Lightweight router (core infrastructure).
 *
 * Route file format (routes/*.php returns a list):
 *   [METHOD, '/path/with/{param}', [Controller::class, 'method'], [Middleware::class, ...?]]
 *
 * Dispatch: global middleware → route middleware → controller → Response.
 */
final class Router
{
    /** @var array<int, array{method: string, path: string, regex: string, names: array<int, string>, handler: callable|array, middleware: array}> */
    private array $routes = [];

    /** @var array<int, class-string|MiddlewareInterface> */
    private array $globalMiddleware = [];

    /** @param array<int, class-string|MiddlewareInterface> $middleware */
    public function setGlobalMiddleware(array $middleware): void
    {
        $this->globalMiddleware = $middleware;
    }

    /** @param callable|array{0: class-string, 1: string} $handler */
    public function add(string $method, string $path, callable|array $handler, array $middleware = []): void
    {
        $path = $this->normalizePath($path);
        [$regex, $names] = $this->compile($path);

        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'regex' => $regex,
            'names' => $names,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function loadFile(string $file): void
    {
        if (!is_file($file)) {
            throw new \RuntimeException('Routes file not found: ' . $file);
        }

        /** @var mixed $routes */
        $routes = require $file;
        if (!is_array($routes)) {
            throw new \RuntimeException('Routes file must return an array: ' . $file);
        }

        foreach ($routes as $index => $row) {
            if (!is_array($row) || count($row) < 3) {
                throw new \RuntimeException(sprintf('Invalid route definition at index %s in %s', $index, $file));
            }
            $this->add((string) $row[0], (string) $row[1], $row[2], $row[3] ?? []);
        }
    }

    public function dispatch(Request $request): Response
    {
        $path = $this->normalizePath($request->path());
        $matchedPath = false;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }
            $matchedPath = true;

            if ($route['method'] !== $request->method()) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = [];
            foreach ($route['names'] as $name) {
                $params[$name] = rawurldecode((string) ($matches[$name] ?? ''));
            }
            $request = $request->withParams($params);

            $destination = $this->makeDestination($route['handler'], $params);
            $middleware = array_merge($this->globalMiddleware, $route['middleware']);

            return $this->pipeline($middleware, $request, $destination);
        }

        if ($matchedPath) {
            $allowed = array_values(array_unique($allowed));
            throw new HttpException(405, 'Method Not Allowed', [
                ['message' => 'Method not allowed for this path. Allowed: ' . implode(', ', $allowed)],
            ]);
        }

        throw new NotFoundException('Not Found');
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    private function resolveMiddleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        $instance = is_string($middleware) ? new $middleware() : $middleware;
        if (!$instance instanceof MiddlewareInterface) {
            throw new \RuntimeException('Middleware must implement MiddlewareInterface');
        }
        return $instance;
    }

    /** @param array<int, class-string|MiddlewareInterface> $middleware */
    private function pipeline(array $middleware, Request $request, callable $destination): Response
    {
        $next = static function (Request $current) use ($destination): Response {
            $response = $destination($current);
            if (!$response instanceof Response) {
                throw new \RuntimeException('Route handler must return App\Response');
            }
            return $response;
        };

        foreach (array_reverse($middleware) as $item) {
            $instance = $this->resolveMiddleware($item);
            $downstream = $next;
            $next = static function (Request $current) use ($instance, $downstream): Response {
                $response = $instance->handle($current, $downstream);
                if (!$response instanceof Response) {
                    throw new \RuntimeException('Middleware must return App\Response');
                }
                return $response;
            };
        }

        return $next($request);
    }

    /** @param array<string, string> $params */
    private function makeDestination(callable|array $handler, array $params): \Closure
    {
        if (is_array($handler)) {
            [$class, $method] = $handler;
            if (!class_exists($class)) {
                throw new \RuntimeException('Controller class not found: ' . $class);
            }
            if (!method_exists($class, $method)) {
                throw new \RuntimeException(sprintf('Method %s::%s not found', $class, $method));
            }

            return static function (Request $request) use ($class, $method, $params): Response {
                $controller = new $class($request);
                $response = $controller->{$method}($params);
                if (!$response instanceof Response) {
                    throw new \RuntimeException(sprintf('%s::%s must return App\Response', $class, $method));
                }
                return $response;
            };
        }

        return static function (Request $request) use ($handler, $params): Response {
            $response = $handler($request, $params);
            if (!$response instanceof Response) {
                throw new \RuntimeException('Route closure must return App\Response');
            }
            return $response;
        };
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $trimmed = rtrim($path, '/');
        return $trimmed === '' ? '/' : $trimmed;
    }

    /** @return array{0: string, 1: array<int, string>} regex + parameter names */
    private function compile(string $path): array
    {
        $parts = preg_split('/(\{[A-Za-z_][A-Za-z0-9_]*\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new \RuntimeException('Failed to compile route: ' . $path);
        }

        $regex = '';
        $names = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_]*)\}$/', $part, $match) === 1) {
                $names[] = $match[1];
                $regex .= '(?P<' . $match[1] . '>[^/]+)';
                continue;
            }
            $regex .= preg_quote($part, '#');
        }

        return ['#^' . $regex . '$#u', $names];
    }
}
