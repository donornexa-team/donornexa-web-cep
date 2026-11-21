<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Core\View;
use DonorNexa\Exceptions\ForbiddenException;
use DonorNexa\Exceptions\AuthenticationException;

/**
 * Parent of every controller (inheritance requirement).
 * Gives them render(), redirect(), json() and the login/role guards.
 */
abstract class BaseController
{
    /** Show templates/<view>.php inside the layout. */
    protected function render(string $view, array $data = [], int $status = 200): void
    {
        View::render($view, $data, $status);
    }

    /** Redirect to a page of this app: $this->redirect('/login'); */
    protected function redirect(string $path): void
    {
        header('Location: ' . url($path));
        exit;
    }

    /** Send JSON (for the api/ endpoints and AJAX). */
    protected function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /** Guard: only logged-in users. Guests are sent to the login page. */
    protected function requireLogin(): array
{
    $user = Session::user();
    if ($user === null) {
        // fetch() calls (DonorNexa.fetchJson sends "Accept: application/json")
        // get a real 401 instead of an HTML redirect they cannot follow.
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            throw new AuthenticationException();
        }
        Session::flash('error', 'Please log in to continue.');
        $this->redirect('/login');
    }
    return $user;
}

    /** Guard: only the given roles, e.g. $this->requireRole('admin'). Others get a 403 page. */
    protected function requireRole(string ...$roles): array
    {
        $user = $this->requireLogin();
        if (!in_array($user['role'], $roles, true)) {
            throw new ForbiddenException();
        }
        return $user;
    }
}
