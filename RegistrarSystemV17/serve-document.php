<?php
/**
 * serve-document.php
 *
 * Streams one private uploaded document image (student ID, registration
 * form, ...) to a logged-in admin/staff user.
 *
 *   serve-document.php?f=uploads/documents/<name>.jpg
 *
 * Uploads no longer have a public URL; document-images.php builds its
 * <img> and "open in new tab" links to this script instead.
 *
 * Checks, in order: logged in as admin/staff -> path is a valid logical
 * upload path (no traversal, allowed folder, bare filename) -> file exists
 * in storage (or the legacy folder) -> real content type is JPEG/PNG.
 */

// Let this script set its own cache headers; session_start() would
// otherwise send "no-store" and stop the browser caching thumbnails.
session_cache_limiter('');
session_start();

require_once 'includes/config.php';
require_once 'includes/upload-helper.php';

$role = $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || !in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

// Release the session lock immediately. A details modal can request ~10
// thumbnails at once; without this PHP would serve them one at a time.
session_write_close();

$absolute = resolve_stored_file((string)($_GET['f'] ?? ''));
if ($absolute === null) {
    http_response_code(404);
    exit('Not found');
}

// Re-verify the content type on the way out, not just on upload.
$mime = detect_real_image_type($absolute);
if ($mime === null || !isset(ALLOWED_IMAGE_TYPES[$mime])) {
    http_response_code(415);
    exit('Unsupported file');
}

$size = filesize($absolute);
if ($size === false) {
    http_response_code(500);
    exit('Unable to read file');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . basename($absolute) . '"');
header('X-Content-Type-Options: nosniff');
// "private": only the staff member's own browser may cache it.
header('Cache-Control: private, max-age=3600');

readfile($absolute);
exit();