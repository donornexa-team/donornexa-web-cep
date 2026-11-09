<?php
declare(strict_types=1);

namespace DonorNexa\Core;

use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\NotFoundException;

/**
 * Minimal router. Register routes in config/routes_*.php:
 *   $router->get('/login', [AuthController::class, 'showLogin']);
 *   $router->post('/login', [AuthController::class, 'login']);
 *   $router->get('/requests/{id}', [RequestController::class, 'show']);
 * The controller method receives the {placeholders} as an array: ['id' => '5'].
 */
class Router
{
    private array $routes = [];

    public function get(string $path, array $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    public function post(string $path, array $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path   = current_path();

        // Every POST must carry a valid CSRF token.
        if ($method === 'POST' && !Csrf::verify()) {
            throw new ForbiddenException('Your security token is missing or has expired. Go back, refresh the page and try again.');
        }

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($routeMethod !== $method) {
                continue;
            }
            $pattern = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $routePath) . '$#';
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                [$class, $action] = $handler;
                (new $class())->$action($params);
                return;
            }
        }

        throw new NotFoundException();
    }
}
