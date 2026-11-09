<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

/**
 * Placeholder landing page so the skeleton runs end to end.
 * The public slice replaces templates/home.php with the real landing page.
 */
class HomeController extends BaseController
{
    public function index(array $params = []): void
    {
        $this->render('home', ['title' => 'Home']);
    }
}
