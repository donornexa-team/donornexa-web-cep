<?php
declare(strict_types=1);

namespace DonorNexa\Controllers;

use DonorNexa\Core\Session;
use DonorNexa\Exceptions\NotFoundException;
use DonorNexa\Repositories\DonorRepository;
use DonorNexa\Repositories\UserRepository;
use DonorNexa\Services\EligibilityService;
use DonorNexa\Services\FileUploader;
use DonorNexa\Services\ProfileValidator;
use Throwable;

/**
 * The donor's own profile page: view and edit the profile, switch availability
 * on or off, upload a photo and see "eligible again on".
 * Every action starts with requireRole('donor'), and every query is limited to
 * the logged-in donor's own id, so a donor can never touch another donor's data.
 */
class DonorController extends BaseController
{
    use ReadsPostData;

    private const EDIT_FIELDS = ['name', 'phone', 'weight_kg', 'city_id', 'last_donation_date'];

    public function profile(array $params = []): void
    {
        $user    = $this->requireRole('donor');
        $profile = $this->loadProfile((int) $user['id']);

        $this->renderProfile($profile, $this->valuesFrom($profile), []);
    }

    public function update(array $params = []): void
    {
        $user    = $this->requireRole('donor');
        $userId  = (int) $user['id'];
        $profile = $this->loadProfile($userId);

        $input  = $this->postFields(self::EDIT_FIELDS);
        $cityIds = array_map('intval', array_column((new UserRepository())->cities(), 'id'));

        $errors = (new ProfileValidator())->validate(
            $input,
            $cityIds,
            (string) $profile['dob'],
            $profile['last_donation_date'] ?? null
        );

        if ($errors !== []) {
            $this->renderProfile($profile, $input, $errors, 422);
            return;
        }

        (new DonorRepository())->updateProfile($userId, [
            'name'               => $input['name'],
            'weight_kg'          => round((float) $input['weight_kg'], 2),
            'city_id'            => (int) $input['city_id'],
            'phone'              => $input['phone'],
            'last_donation_date' => ($input['last_donation_date'] === '') ? null : $input['last_donation_date'],
        ]);

        // The navbar shows the name from the session, so keep it in step with the database.
        $_SESSION['user']['name'] = $input['name'];

        Session::flash('success', 'Your profile has been updated.');
        $this->redirect('/donor/profile');
    }

    /** Switch availability on or off (the form sends is_available = 1 or 0). */
    public function availability(array $params = []): void
    {
        $user  = $this->requireRole('donor');
        $value = $this->postString('is_available');

        if ($value !== '0' && $value !== '1') {
            Session::flash('error', 'Invalid request.');
            $this->redirect('/donor/profile');
        }

        (new DonorRepository())->setAvailability((int) $user['id'], $value === '1');

        Session::flash('success', ($value === '1')
            ? 'You are available again. Hospitals can match you with requests.'
            : 'You are now unavailable. You will not be matched with new requests.');
        $this->redirect('/donor/profile');
    }

    public function photo(array $params = []): void
    {
        $user     = $this->requireRole('donor');
        $userId   = (int) $user['id'];
        $uploader = new FileUploader();

        $file  = $_FILES['photo'] ?? null;
        $error = $uploader->imageError(is_array($file) ? $file : null);
        if ($error !== null) {
            Session::flash('error', $error);
            $this->redirect('/donor/profile');
        }

        $storedName = $uploader->saveImage($file);

        try {
            $oldName = (new DonorRepository())->replacePhoto($userId, $storedName);
        } catch (Throwable $e) {
            $uploader->deleteImage($storedName);   // do not leave an orphan file behind
            throw $e;
        }

        if ($oldName !== null) {
            $uploader->deleteImage($oldName);
        }

        Session::flash('success', 'Your photo has been updated.');
        $this->redirect('/donor/profile');
    }

    // ---------------------------------------------------------------- helpers

    private function loadProfile(int $userId): array
    {
        $profile = (new DonorRepository())->findProfile($userId);
        if ($profile === null) {
            throw new NotFoundException('Donor profile not found.');
        }

        return $profile;
    }

    /** The form's starting values, taken from the database. */
    private function valuesFrom(array $profile): array
    {
        return [
            'name'               => (string) $profile['name'],
            'phone'              => (string) $profile['phone'],
            'weight_kg'          => (string) $profile['weight_kg'],
            'city_id'            => (string) $profile['city_id'],
            'last_donation_date' => (string) ($profile['last_donation_date'] ?? ''),
        ];
    }

    private function renderProfile(array $profile, array $old, array $errors, int $status = 200): void
    {
        $donors   = new DonorRepository();
        $cooldown = $donors->cooldownDays();

        $this->render('donor/profile', [
            'title'        => 'My Profile',
            'profile'      => $profile,
            'eligibility'  => (new EligibilityService())->status($profile, $cooldown),
            'cooldownDays' => $cooldown,
            'cities'       => (new UserRepository())->cities(),
            'old'          => $old,
            'errors'       => $errors,
        ], $status);
    }
}
