<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\ValidationException;
use DonorNexa\Repositories\UserRepository;
use DonorNexa\Services\AccountValidator;
use DonorNexa\Services\FileUploader;
use Throwable;

/**
 * Donor and hospital registration.
 *
 * Flow of every store method:
 *   read the fields -> validate (format) -> check duplicates -> save -> redirect to login.
 * When something is wrong the form is shown again with the messages and the
 * old values (never the passwords), with HTTP status 422.
 */
class RegisterController extends BaseController
{
    use ReadsPostData;

    private const DONOR_FIELDS    = ['name', 'email', 'blood_group', 'dob', 'weight_kg', 'cnic', 'city_id', 'phone'];
    private const HOSPITAL_FIELDS = ['hospital_name', 'email', 'licence_no', 'city_id', 'address', 'phone'];

    // ------------------------------------------------------------------ donor

    public function showDonor(array $params = []): void
    {
        $this->guestOnly();
        $this->renderDonorForm([], []);
    }

    public function storeDonor(array $params = []): void
    {
        $this->guestOnly();

        $users     = new UserRepository();
        $validator = new AccountValidator();
        $cityIds   = $this->cityIds($users);

        $input = $this->postFields(self::DONOR_FIELDS);
        $input['email']            = strtolower($input['email']);
        $input['password']         = $this->postPassword('password');
        $input['password_confirm'] = $this->postPassword('password_confirm');

        $errors = $validator->validateDonor($input, $cityIds);

        if (!isset($errors['email']) && $users->emailExists($input['email'])) {
            $errors['email'] = 'This email is already registered.';
        }
        if (!isset($errors['cnic']) && $users->cnicExists($input['cnic'])) {
            $errors['cnic'] = 'This CNIC is already registered.';
        }

        if ($errors === []) {
            try {
                $users->createDonor([
                    'name'          => $input['name'],
                    'email'         => $input['email'],
                    'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
                    'blood_group'   => $input['blood_group'],
                    'dob'           => $input['dob'],
                    'weight_kg'     => round((float) $input['weight_kg'], 2),
                    'cnic'          => $input['cnic'],
                    'city_id'       => (int) $input['city_id'],
                    'phone'         => $input['phone'],
                ]);
            } catch (ValidationException $e) {
                $errors = $e->getErrors();
            }
        }

        if ($errors !== []) {
            $this->renderDonorForm($input, $errors);
            return;
        }

        Session::flash('success', 'Your donor account has been created. Please log in.');
        $this->redirect('/login');
    }

    // --------------------------------------------------------------- hospital

    public function showHospital(array $params = []): void
    {
        $this->guestOnly();
        $this->renderHospitalForm([], []);
    }

    public function storeHospital(array $params = []): void
    {
        $this->guestOnly();

        $users     = new UserRepository();
        $validator = new AccountValidator();
        $uploader  = new FileUploader();
        $cityIds   = $this->cityIds($users);

        $input = $this->postFields(self::HOSPITAL_FIELDS);
        $input['email']            = strtolower($input['email']);
        $input['password']         = $this->postPassword('password');
        $input['password_confirm'] = $this->postPassword('password_confirm');

        $file   = $_FILES['licence_file'] ?? null;
        $errors = $validator->validateHospital($input, $cityIds);

        $fileError = $uploader->pdfError(is_array($file) ? $file : null);
        if ($fileError !== null) {
            $errors['licence_file'] = $fileError;
        }

        if (!isset($errors['email']) && $users->emailExists($input['email'])) {
            $errors['email'] = 'This email is already registered.';
        }
        if (!isset($errors['licence_no']) && $users->licenceExists($input['licence_no'])) {
            $errors['licence_no'] = 'This licence number is already registered.';
        }

        if ($errors === []) {
            $storedName = $uploader->savePdf($file);

            try {
                $users->createHospital([
                    'hospital_name' => $input['hospital_name'],
                    'email'         => $input['email'],
                    'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
                    'licence_no'    => $input['licence_no'],
                    'licence_file'  => $storedName,
                    'city_id'       => (int) $input['city_id'],
                    'address'       => $input['address'],
                    'phone'         => $input['phone'],
                ]);
            } catch (ValidationException $e) {
                $uploader->deletePdf($storedName);   // do not leave an orphan file behind
                $errors = $e->getErrors();
            } catch (Throwable $e) {
                $uploader->deletePdf($storedName);
                throw $e;
            }
        }

        if ($errors !== []) {
            $this->renderHospitalForm($input, $errors);
            return;
        }

        Session::flash('success', 'Your hospital has been registered. You can log in now; an administrator must verify your licence before you can raise blood requests.');
        $this->redirect('/login');
    }

    // ---------------------------------------------------------------- helpers

    /** Registration is for visitors; a logged-in user is sent home. */
    private function guestOnly(): void
    {
        if (Session::user() !== null) {
            $this->redirect('/');
        }
    }

    /** @return int[] ids of all cities, to check the chosen city against */
    private function cityIds(UserRepository $users): array
    {
        return array_map('intval', array_column($users->cities(), 'id'));
    }

    private function renderDonorForm(array $old, array $errors): void
    {
        unset($old['password'], $old['password_confirm']);   // never send passwords back to the browser
        $this->render('register_donor', [
            'title'       => 'Become a Donor',
            'old'         => $old,
            'errors'      => $errors,
            'cities'      => (new UserRepository())->cities(),
            'bloodGroups' => AccountValidator::BLOOD_GROUPS,
        ], $errors === [] ? 200 : 422);
    }

    private function renderHospitalForm(array $old, array $errors): void
    {
        unset($old['password'], $old['password_confirm']);
        $this->render('register_hospital', [
            'title'  => 'Register Hospital',
            'old'    => $old,
            'errors' => $errors,
            'cities' => (new UserRepository())->cities(),
        ], $errors === [] ? 200 : 422);
    }
}
