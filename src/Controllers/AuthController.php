<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Repositories\UserRepository;
use DonorNexa\Core\RememberMe;

/**
 * Login and logout.
 *
 * Security notes:
 *  - the password is checked with password_verify() against the stored hash;
 *  - the same message is shown for "unknown email" and "wrong password", so an
 *    attacker cannot find out which emails are registered;
 *  - Session::login() creates a new session id (prevents session fixation);
 *  - logout is a POST form, so the Router's CSRF check protects it as well;
 *  - brute-force limit: 5 wrong passwords for one email (or 20 from one IP)
 *    within 15 minutes locks login for that window. Attempts are stored in
 *    audit_logs, so the limit survives clearing cookies.
 */
class AuthController extends BaseController
{
    use ReadsPostData;

    private const BAD_LOGIN = 'Incorrect email or password.';

    private const MAX_FAILS_PER_EMAIL = 5;
    private const MAX_FAILS_PER_IP    = 20;
    private const LOCK_MINUTES        = 15;

    public function showLogin(array $params = []): void
    {
        if (Session::user() !== null) {
            $this->redirect('/');
        }

        $this->render('login', ['title' => 'Login', 'old' => [], 'errors' => []]);
    }

    public function login(array $params = []): void
    {
        $email    = strtolower($this->postString('email'));
        $password = $this->postPassword('password');

        $users = new UserRepository();
        $ip    = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

        // Locked out? Refuse before looking at the password at all.
        if ($users->failedLoginsForEmail($email, $ip, self::LOCK_MINUTES) >= self::MAX_FAILS_PER_EMAIL
            || $users->failedLoginsForIp($ip, self::LOCK_MINUTES) >= self::MAX_FAILS_PER_IP) {
            $this->showLoginError('Too many failed login attempts. Please try again in ' . self::LOCK_MINUTES . ' minutes.', $email, 429);
            return;
        }

        $user = ($email !== '') ? $users->findByEmail($email) : null;

        if ($user === null) {
            // Do the same slow hashing work as a real check, so the response
            // time does not reveal whether the email exists.
            password_hash($password, PASSWORD_DEFAULT);
            $users->recordLoginAttempt($email, $ip, false);
            $this->showLoginError(self::BAD_LOGIN, $email, 401);
            return;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $users->recordLoginAttempt($email, $ip, false, (int) $user['id']);
            $this->showLoginError(self::BAD_LOGIN, $email, 401);
            return;
        }

        // Only a correct password gets to learn that the account is blocked.
        if ($user['status'] !== 'active') {
            $this->showLoginError('This account has been blocked. Please contact the administrator.', $email, 403);
            return;
        }

        $users->recordLoginAttempt($email, $ip, true, (int) $user['id']);
        Session::login((int) $user['id'], (string) $user['name'], (string) $user['role']);
        Session::flash('success', 'Welcome back, ' . $user['name'] . '.');

       
        if ($this->postString('remember') === '1') {
        RememberMe::issue((int) $user['id'], (string) $user['password_hash']);
}

        if ($user['role'] === 'hospital' && $user['verification_status'] !== 'verified') {
            Session::flash('info', 'Your hospital is not verified yet. You can raise blood requests after an administrator approves your licence.');
        }

        // Each role lands on its own dashboard.
        $this->redirect(['donor' => '/donor/dashboard', 'hospital' => '/hospital/dashboard', 'admin' => '/admin/dashboard'][$user['role']] ?? '/');
    }

    public function logout(array $params = []): void
    {
        RememberMe::forget();   
        Session::logout();
        Session::flash('success', 'You have been logged out.');
        $this->redirect('/');
    }

    private function showLoginError(string $message, string $email, int $status): void
    {
        $this->render('login', [
            'title'  => 'Login',
            'old'    => ['email' => $email],
            'errors' => ['form' => $message],
        ], $status);
    }
}
