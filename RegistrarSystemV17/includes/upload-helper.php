<?php
/**
 * includes/upload-helper.php
 *
 * Shared server-side helper for validating and storing an uploaded
 * document image (student ID, registration form, government ID, etc.)
 * submitted through the online request form.
 *
 * This is the ONLY place in the project that touches $_FILES / writes an
 * uploaded file to disk for request documents. submit-online-request.php
 * calls handle_document_upload() once per expected document field.
 *
 * Where files go
 * --------------
 * Files are written to the private storage root (see includes/storage.php),
 * NOT to a web-visible folder. The value returned in 'path' is the
 * "logical" path stored in the DB ('uploads/documents/<random>.jpg'); it is
 * never used as a URL. Staff view files through serve-document.php.
 */

require_once __DIR__ . '/storage.php';

// Logical path prefix stored in the DB. Physical folder: storage_dir_for().
const UPLOAD_ROOT_RELATIVE = 'uploads/documents/';

// 5 MB.
const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

// Only real content types we accept, and the extension each maps to.
// The storage extension is always derived from the ACTUAL file content,
// never from the client-supplied name or MIME header.
const ALLOWED_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
];

/**
 * Validate and store one uploaded document image.
 *
 * @param array  $file     A single element of $_FILES.
 * @param string $fieldKey Logical field name, used only in messages.
 *
 * @return array{ok: bool, path?: string|null, error?: string}
 *   - No file chosen for an optional field: ['ok' => true, 'path' => null]
 *   - Valid upload stored:                  ['ok' => true, 'path' => 'uploads/documents/xxxx.jpg']
 *   - Rejected / failed:                    ['ok' => false, 'error' => '...']
 */
function handle_document_upload(array $file, string $fieldKey): array
{
    // No file uploaded is not an error; the caller decides whether the
    // field was required for the chosen document type.
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null];
    }

    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => upload_error_message($fieldKey, $error)];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => "There was a problem receiving \"{$fieldKey}\". Please try uploading it again."];
    }

    // Size limit.
    $size = $file['size'] ?? filesize($tmpPath);
    if ($size > MAX_UPLOAD_BYTES) {
        $maxMb = number_format(MAX_UPLOAD_BYTES / (1024 * 1024), 0);
        return ['ok' => false, 'error' => "\"{$fieldKey}\" is too large. Please upload a file under {$maxMb}MB."];
    }
    if ($size <= 0) {
        return ['ok' => false, 'error' => "\"{$fieldKey}\" appears to be empty. Please choose a valid image file."];
    }

    // Reject double extensions ("id.php.jpg") on the original client name.
    $originalName = (string)($file['name'] ?? '');
    if (!has_single_safe_image_extension($originalName)) {
        return ['ok' => false, 'error' => "\"{$fieldKey}\" must be a single .jpg, .jpeg, or .png file."];
    }

    // Verify the ACTUAL file content, never $_FILES[...]['type'].
    $detectedType = detect_real_image_type($tmpPath);
    if ($detectedType === null || !isset(ALLOWED_IMAGE_TYPES[$detectedType])) {
        return ['ok' => false, 'error' => "\"{$fieldKey}\" doesn't look like a valid JPEG or PNG image."];
    }
    $ext = ALLOWED_IMAGE_TYPES[$detectedType];

    // Destination: private storage. ensure_writable_dir() creates it if
    // needed and reports WHY it can't be used (permissions, wrong user...).
    $uploadDirAbsolute = storage_dir_for(UPLOAD_ROOT_RELATIVE);
    $dirProblem = ensure_writable_dir($uploadDirAbsolute);
    if ($dirProblem !== null) {
        error_log("upload-helper: {$dirProblem}");
        return ['ok' => false, 'error' => 'Something went wrong while saving your upload. Please try again.'];
    }
    ensure_storage_protected();

    // Random filename -- never the original.
    $generatedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destinationAbsolute = $uploadDirAbsolute . $generatedName;

    if (file_exists($destinationAbsolute)) {
        $generatedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destinationAbsolute = $uploadDirAbsolute . $generatedName;
    }

    if (!move_uploaded_file($tmpPath, $destinationAbsolute)) {
        $phpError = error_get_last();
        error_log(
            "upload-helper: move_uploaded_file failed for field \"{$fieldKey}\" -> {$destinationAbsolute}"
            . ' | PHP user: ' . php_process_user()
            . ' | ' . ($phpError['message'] ?? 'no PHP error captured')
        );
        return ['ok' => false, 'error' => "Something went wrong while saving \"{$fieldKey}\". Please try again."];
    }

    // Logical path for the DB (not a URL).
    return ['ok' => true, 'path' => UPLOAD_ROOT_RELATIVE . $generatedName];
}

/**
 * Delete a previously-stored upload, given the logical path returned by
 * handle_document_upload(). Removes the file from the private storage
 * and from the legacy web-visible folder if a copy is still there.
 *
 * Safe to call with null/empty input (no-op).
 */
function delete_uploaded_document(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }

    // Only ever delete files under our own upload prefix.
    $clean = normalize_logical_path($relativePath);
    if ($clean === null || strpos($clean, UPLOAD_ROOT_RELATIVE) !== 0) {
        error_log("upload-helper: refused to delete suspicious path \"{$relativePath}\"");
        return;
    }

    foreach (stored_file_candidates($clean) as $absolute) {
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

// ===========================================================================
// Internal helpers
// ===========================================================================

/**
 * True only if $filename has exactly one extension and it's
 * .jpg / .jpeg / .png (case-insensitive).
 */
function has_single_safe_image_extension(string $filename): bool
{
    $filename = trim($filename);
    if ($filename === '') {
        return false;
    }

    $filename = basename(str_replace('\\', '/', $filename));
    $parts = explode('.', $filename);

    // "id.jpg" ok; "id.php.jpg", "id", ".jpg" rejected.
    if (count($parts) !== 2 || $parts[0] === '') {
        return false;
    }

    return in_array(strtolower($parts[1]), ['jpg', 'jpeg', 'png'], true);
}

/**
 * Real content type of a file on disk, from its bytes. fileinfo first,
 * getimagesize() as a fallback.
 *
 * @return string|null e.g. 'image/jpeg', or null if undetermined.
 */
function detect_real_image_type(string $absolutePath): ?string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $mime = finfo_file($finfo, $absolutePath);
            finfo_close($finfo);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }
    }

    $info = @getimagesize($absolutePath);
    if ($info !== false && isset($info['mime'])) {
        return $info['mime'];
    }

    return null;
}

/**
 * Write (or confirm) an .htaccess in a WEB-VISIBLE upload directory that
 * disables script execution there. Still used for the public landing
 * slideshow images (resources.php). Private documents don't need it --
 * they live in storage and are protected by ensure_storage_protected().
 */
function ensure_upload_dir_htaccess(string $uploadDirAbsolute): void
{
    $htaccessPath = rtrim($uploadDirAbsolute, '/') . '/.htaccess';

    if (file_exists($htaccessPath)) {
        return;
    }

    $contents = <<<HTACCESS
# Auto-generated by includes/upload-helper.php.
# This folder only ever holds uploaded image files -- never allow any
# script to execute here, even if something slips past the content-type
# check in upload-helper.php.

# Apache 2.4+
<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>

<FilesMatch "\.(php|phtml|php\d?|phar|cgi|pl|py|sh)$">
    Require all denied
</FilesMatch>

# Apache 2.2 fallback
<IfModule !mod_authz_core.c>
    <FilesMatch "\.(php|phtml|php\d?|phar|cgi|pl|py|sh)$">
        Order allow,deny
        Deny from all
    </FilesMatch>
</IfModule>

Options -ExecCGI
RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .phar
HTACCESS;

    if (@file_put_contents($htaccessPath, $contents) === false) {
        error_log("upload-helper: failed to write .htaccess backstop in {$uploadDirAbsolute}");
    }
}

/**
 * Human-readable message for a PHP upload error code.
 */
function upload_error_message(string $fieldKey, int $errorCode): string
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "\"{$fieldKey}\" is too large. Please upload a smaller file.";
        case UPLOAD_ERR_PARTIAL:
            return "\"{$fieldKey}\" was only partially uploaded. Please try again.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
        case UPLOAD_ERR_EXTENSION:
            error_log("upload-helper: server-side upload error {$errorCode} for field \"{$fieldKey}\"");
            return 'Something went wrong while receiving your upload. Please try again.';
        default:
            return "There was a problem with \"{$fieldKey}\". Please try uploading it again.";
    }
}