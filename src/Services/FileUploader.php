<?php
declare(strict_types=1);

namespace DonorNexa\Services;

use DonorNexa\Exceptions\AppException;
use finfo;

/**
 * Safe handling of an uploaded PDF (the hospital licence).
 *
 * The client's file name and the client's claimed type are never trusted:
 *   1. the upload must have finished without an error and be within the size limit,
 *   2. the extension must be .pdf,
 *   3. the REAL content type is read from the file itself with finfo,
 *   4. the file is stored under a new random name, so nobody can guess it
 *      and a name like "evil.php" can never reach the disk.
 *
 * Licences are private documents: public/uploads/.htaccess blocks direct
 * downloads of .pdf files, so only PHP code (the admin pages) can read them.
 */
class FileUploader
{
    private const MAX_BYTES = 5 * 1024 * 1024;   // 5 MB

    /** Message to show the user, or null when the upload is acceptable. */
    public function pdfError(?array $file): ?string
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return 'Upload your hospital licence as a PDF file.';
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            return 'The file is too large (5 MB at most).';
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return 'The upload failed. Please try again.';
        }
        if ($file['size'] > self::MAX_BYTES) {
            return 'The file is too large (5 MB at most).';
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return 'The upload failed. Please try again.';
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mimeType  = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if ($extension !== 'pdf' || $mimeType !== 'application/pdf') {
            return 'The licence must be a PDF file.';
        }

        return null;
    }

    /** Move a file that passed pdfError() into public/uploads/licences. Returns the new file name. */
    public function savePdf(array $file): string
    {
        $folder = ROOT_PATH . '/public/uploads/licences';
        if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
            throw new AppException('Could not save the uploaded file.', 500);
        }

        $name = bin2hex(random_bytes(16)) . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $name)) {
            throw new AppException('Could not save the uploaded file.', 500);
        }

        return $name;
    }

    /** Remove a stored licence (used when the database insert fails after the upload). */
    public function deletePdf(string $name): void
    {
        $path = ROOT_PATH . '/public/uploads/licences/' . basename($name);
        if (is_file($path)) {
            unlink($path);
        }
    }
}
