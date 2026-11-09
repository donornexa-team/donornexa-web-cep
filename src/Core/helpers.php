<?php
/**
 * Small global helper functions used in controllers and templates.
 */
declare(strict_types=1);

/** Read a value from config/app.php. */
function config(string $key, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = require ROOT_PATH . '/config/app.php';
    }
    return $settings[$key] ?? $default;
}

/** Escape a value for safe output in HTML (use on EVERY printed value). */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build a link to a page: url('/login'). */
function url(string $path = '/'): string
{
    return BASE_PATH . '/' . ltrim($path, '/');
}

/** Build a link to a file in public/assets: asset('css/style.css'). */
function asset(string $path): string
{
    return BASE_PATH . '/assets/' . ltrim($path, '/');
}

/**
 * The requested path without the base folder, e.g. "/login".
 * The site can be opened as /DonorNexa/public/login or, through the root
 * .htaccess, as /DonorNexa/ so both prefixes are removed.
 */
function current_path(): string
{
    $path  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $bases = [BASE_PATH];
    if (substr(BASE_PATH, -7) === '/public') {
        $bases[] = substr(BASE_PATH, 0, -7);   // project folder without /public
    }

    foreach ($bases as $base) {
        if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
            $path = substr($path, strlen($base));
            break;
        }
    }
    return '/' . trim($path, '/');
}
