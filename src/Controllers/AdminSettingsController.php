<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\SettingsRepository;
use DonorNexa\Services\SettingsValidator;

/**
 * Admin: system settings (cool-down days, how many donors to notify, ...) and cities.
 * Every action starts with requireRole('admin'); changes are POST forms (CSRF-checked
 * by the Router) and each change is written to audit_logs in the same transaction.
 */
class AdminSettingsController extends BaseController
{
    use ReadsPostData;

    public function show(array $params = []): void
    {
        $this->requireRole('admin');
        $this->renderPage([], [], 200);
    }

    public function saveSettings(array $params = []): void
    {
        $admin     = $this->requireRole('admin');
        $validator = new SettingsValidator();

        $input = [];
        foreach (array_keys($validator->rules()) as $key) {
            $input[$key] = $this->postString($key);
        }

        $errors = $validator->validateSettings($input);
        if ($errors !== []) {
            $this->renderPage($input, $errors, 422);
            return;
        }

        $changes = (new SettingsRepository())->saveSettings($input, (int) $admin['id'], $this->ip());

        Session::flash('success', $changes === []
            ? 'No setting was changed.'
            : count($changes) . ' setting(s) saved. The new values apply immediately.');
        $this->redirect('/admin/settings');
    }

    public function addCity(array $params = []): void
    {
        $admin = $this->requireRole('admin');
        [$name, $province] = [$this->postString('city_name'), $this->postString('province')];

        $this->saveCity($name, $province, function () use ($name, $province, $admin): void {
            (new SettingsRepository())->addCity($name, $province, (int) $admin['id'], $this->ip());
        }, 'City added.');
    }

    public function updateCity(array $params = []): void
    {
        $admin = $this->requireRole('admin');
        $id    = (int) ($params['id'] ?? 0);
        [$name, $province] = [$this->postString('city_name'), $this->postString('province')];

        $this->saveCity($name, $province, function () use ($id, $name, $province, $admin): void {
            (new SettingsRepository())->updateCity($id, $name, $province, (int) $admin['id'], $this->ip());
        }, 'City saved.');
    }

    // ---------------------------------------------------------------- helpers

    /** Validate the city fields, run $save, and go back to the page with a message. */
    private function saveCity(string $name, string $province, callable $save, string $successMessage): void
    {
        $validator = new SettingsValidator();
        $error     = $validator->cityNameError($name) ?? $validator->provinceError($province);

        if ($error === null) {
            try {
                $save();
            } catch (ValidationException $e) {
                $error = (string) (array_values($e->getErrors())[0] ?? $e->getMessage());
            }
        }

        if ($error !== null) {
            Session::flash('error', $error);
        } else {
            Session::flash('success', $successMessage);
        }
        $this->redirect('/admin/settings');
    }

    private function renderPage(array $old, array $errors, int $status): void
    {
        $repo      = new SettingsRepository();
        $validator = new SettingsValidator();

        $settings = [];
        foreach ($repo->allSettings() as $row) {
            if (isset($validator->rules()[$row['setting_key']])) {
                $settings[] = $row;
            }
        }

        $this->render('admin/settings', [
            'title'     => 'Settings and cities',
            'settings'  => $settings,
            'rules'     => $validator->rules(),
            'old'       => $old,
            'errors'    => $errors,
            'cities'    => $repo->cities(),
            'provinces' => SettingsValidator::PROVINCES,
        ], $status);
    }

    private function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
