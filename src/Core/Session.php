<?php
declare(strict_types=1);

namespace DonorNexa\Core;

/**
 * Session handling: secure cookie flags, login state and flash messages.
 */
class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('donornexa_sid');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Call right after a successful password check. */
    public static function login(int $id, string $name, string $role): void
    {
        session_regenerate_id(true);          // prevents session fixation
        unset($_SESSION['csrf']);             // new token for the new session
        $_SESSION['user'] = ['id' => $id, 'name' => $name, 'role' => $role];
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** The logged-in user as ['id', 'name', 'role'], or null for guests. */
    public static function user(): ?array
    {
        return $_SESSION['user'] ?? null;
    }

    /** Queue a message for the next page: type is success, error, info or warning. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /** Return and clear the queued messages. */
    public static function pullFlash(): array
    {
        $messages = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $messages;
    }
}
