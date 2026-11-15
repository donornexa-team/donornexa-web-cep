<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Repositories\InventoryRepository;
use DonorNexa\Services\InventoryValidator;

/**
 * The blood stock of the logged-in hospital: view it and update the numbers.
 * Only the hospital's OWN row set is ever read or written (the hospital id comes
 * from the session, never from the form).
 */
class InventoryController extends BaseController
{
    /** GET /hospital/inventory */
    public function index(array $params = []): void
    {
        $user = $this->requireRole('hospital');

        $this->renderPage((new InventoryRepository())->forHospital((int) $user['id']), [], []);
    }

    /** POST /hospital/inventory */
    public function update(array $params = []): void
    {
        $user       = $this->requireRole('hospital');
        $repository = new InventoryRepository();

        $result = (new InventoryValidator())->validate($_POST['units'] ?? []);

        if ($result['errors'] !== []) {
            $old = [];
            if (is_array($_POST['units'] ?? null)) {
                foreach ($_POST['units'] as $group => $text) {
                    $old[(string) $group] = is_string($text) ? $text : '';
                }
            }
            $this->renderPage($repository->forHospital((int) $user['id']), $old, $result['errors'], 422);
            return;
        }

        $ip      = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $changes = $repository->saveAll((int) $user['id'], $result['values'], (int) $user['id'], $ip);

        if ($changes === []) {
            Session::flash('info', 'Nothing changed: the numbers are the same as before.');
        } else {
            Session::flash('success', 'Inventory saved (' . count($changes) . ' blood group(s) changed).');
        }

        $this->redirect('/hospital/inventory');
    }

    private function renderPage(array $stock, array $old, array $errors, int $status = 200): void
    {
        $total = 0;
        foreach ($stock as $row) {
            $total += $row['units'];
        }

        $this->render('hospital/inventory', [
            'title'  => 'Blood Inventory',
            'stock'  => $stock,
            'total'  => $total,
            'old'    => $old,
            'errors' => $errors,
        ], $status);
    }
}
