<?php
declare(strict_types=1);

namespace DonorNexa\Core;

/**
 * CSRF protection. The Router checks the token on EVERY POST automatically.
 * Forms: echo Csrf::field();   AJAX: send header X-CSRF-TOKEN (see app.js).
 */
class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function verify(): bool
    {
        $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($sent) && hash_equals(self::token(), $sent);
    }
}
