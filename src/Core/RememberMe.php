<?php
declare(strict_types=1);

namespace DonorNexa\Core;

use DonorNexa\Repositories\UserRepository;
use Throwable;

/**
 * "Keep me logged in" cookie, without any extra database table.
 *
 * The cookie holds  userId.expiresAt.signature  where
 *     signature = HMAC-SHA256(userId | expiresAt | password_hash, secret key)
 *
 *  - nobody can forge it without the secret key (config/remember.key, created on first use);
 *  - it contains the password hash in the signature, so changing the password
 *    makes every old cookie invalid;
 *  - a blocked user is never restored;
 *  - flags: HttpOnly (JavaScript cannot read it), SameSite=Lax, Secure on HTTPS;
 *  - logout deletes the cookie.
 * Limit: a stolen cookie works until it expires (30 days) or the password changes.
 */
final class RememberMe
{
    public const COOKIE = 'donornexa_remember';
    public const DAYS   = 30;

    /** Build the cookie value. Pure function, tested in tests/remember_test.php. */
    public static function makeToken(int $userId, string $passwordHash, int $expiresAt, string $secret): string
    {
        $signature = hash_hmac('sha256', $userId . '|' . $expiresAt . '|' . $passwordHash, $secret);

        return $userId . '.' . $expiresAt . '.' . $signature;
    }

    /**
     * Check a cookie value.
     *
     * @param callable $lookup fn(int $userId): ?array  (needs id, name, role, status, password_hash)
     * @return array|null the user row when the cookie is valid, otherwise null
     */
    public static function verifyToken(string $token, callable $lookup, string $secret, ?int $now = null): ?array
    {
        $now   = $now ?? time();
        $parts = explode('.', $token, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || $parts[2] === '') {
            return null;
        }

        $userId    = (int) $parts[0];
        $expiresAt = (int) $parts[1];
        if ($expiresAt <= $now || $expiresAt > $now + (self::DAYS + 1) * 86400) {
            return null;                       // expired, or a date too far ahead to be ours
        }

        $user = $lookup($userId);
        if ($user === null || $user['status'] !== 'active') {
            return null;
        }

        $expected = self::makeToken($userId, (string) $user['password_hash'], $expiresAt, $secret);

        return hash_equals($expected, $token) ? $user : null;
    }

    /** Send the cookie after a successful login (only when "keep me logged in" was ticked). */
    public static function issue(int $userId, string $passwordHash): void
    {
        try {
            $expires = time() + self::DAYS * 86400;
            self::setCookie(self::makeToken($userId, $passwordHash, $expires, self::secret()), $expires);
        } catch (Throwable $e) {
            Logger::exception($e);             // the user is still logged in, just not remembered
        }
    }

    public static function forget(): void
    {
        self::setCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
    }

    /** Called on every request: with no session but a valid cookie, log the user in again. */
    public static function restoreSession(): void
    {
        if (Session::user() !== null || empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) {
            return;
        }

        try {
            $user = self::verifyToken(
                $_COOKIE[self::COOKIE],
                static function (int $id): ?array {
                    return (new UserRepository())->findById($id);
                },
                self::secret()
            );
        } catch (Throwable $e) {
            return;                            // database or key problem: stay logged out
        }

        if ($user === null) {
            self::forget();                    // bad or old cookie: remove it
            return;
        }

        Session::login((int) $user['id'], (string) $user['name'], (string) $user['role']);
    }

    // ---------------------------------------------------------------- helpers

    private static function setCookie(string $value, int $expires): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** The secret key: config('remember_secret') if set, otherwise a random key saved in config/remember.key. */
    private static function secret(): string
    {
        $configured = config('remember_secret');
        if (is_string($configured) && strlen($configured) >= 32) {
            return $configured;
        }

        $file = ROOT_PATH . '/config/remember.key';
        $key  = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            if (file_put_contents($file, $key, LOCK_EX) === false) {
                throw new \RuntimeException('Cannot write config/remember.key');
            }
        }

        return $key;
    }
}
