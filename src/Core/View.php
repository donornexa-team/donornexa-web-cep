<?php
declare(strict_types=1);

namespace DonorNexa\Core;

/**
 * Renders a template inside templates/layout.php.
 * View::render('donor/dashboard', ['title' => 'Dashboard', 'requests' => $rows]);
 * Inside the template every key of the array is a variable ($requests).
 */
class View
{
    public static function render(string $view, array $data = [], int $status = 200): void
    {
        http_response_code($status);

        (static function () use ($view, $data): void {
            extract($data, EXTR_SKIP);
            ob_start();
            require TEMPLATE_PATH . '/' . $view . '.php';
            $content = ob_get_clean();
            require TEMPLATE_PATH . '/layout.php';
        })();
    }

    /** Friendly error page for 401, 403, 404, 500 and others. */
    public static function error(int $code, string $message): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $titles = [
            401 => 'Login required',
            403 => 'Access denied',
            404 => 'Page not found',
            500 => 'Something went wrong',
        ];
        $title = $titles[$code] ?? 'Error';

        self::render('errors/error', [
            'title'   => $title,
            'code'    => $code,
            'message' => $message,
        ], $code);
    }
}
