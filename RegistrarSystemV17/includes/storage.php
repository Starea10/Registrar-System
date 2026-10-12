<?php
/**
 * includes/storage.php
 *
 * Single source of truth for where PRIVATE uploads (student IDs, forms,
 * archived copies) live on disk and how to find them again.
 *
 * Logical paths vs. physical files
 * --------------------------------
 * The database keeps storing the same "logical" path it always has, e.g.
 *   uploads/documents/ab12....jpg
 *   uploads/archived-online-documents/request_5_....png
 * so no schema change or data migration is needed. This file maps a
 * logical path to a real file, checking two places in order:
 *
 *   1. <storage root>/documents/ab12....jpg          (new, private)
 *   2. <project root>/uploads/documents/ab12....jpg  (legacy, web-visible)
 *
 * Files uploaded before the move therefore keep working until
 * migrate-uploads.php relocates them.
 *
 * Storage root
 * ------------
 * Set STORAGE_PATH in .env to keep uploads completely outside the web
 * root (recommended on a real server), e.g.
 *     STORAGE_PATH=/var/www/rms-storage        (Linux)
 *     STORAGE_PATH=C:/xampp/rms-storage        (Windows)
 * A relative value is resolved against the project root.
 *
 * If STORAGE_PATH is empty the default is <project root>/storage. That is
 * still inside the web root, so it is protected only by a generated
 * .htaccess deny rule (see ensure_storage_protected()) -- fine on XAMPP,
 * but on hosts where Apache ignores .htaccess it is NOT private. Files
 * are only ever delivered through serve-document.php either way.
 */

const UPLOAD_LOGICAL_PREFIX = 'uploads/';

// The only logical folders private uploads may live in.
const PRIVATE_UPLOAD_PREFIXES = [
    'uploads/documents/',
    'uploads/archived-online-documents/',
];

function project_root_path(): string
{
    return rtrim(str_replace('\\', '/', dirname(__DIR__)), '/');
}

/**
 * Absolute storage root, forward slashes, no trailing slash.
 */
function storage_root(): string
{
    static $root = null;
    if ($root !== null) {
        return $root;
    }

    $configured = trim((string)getenv('STORAGE_PATH'));
    if ($configured === '') {
        $root = project_root_path() . '/storage';
        return $root;
    }

    $configured = str_replace('\\', '/', $configured);
    $isAbsolute = preg_match('#^(/|[A-Za-z]:/)#', $configured) === 1;
    $root = rtrim($isAbsolute ? $configured : project_root_path() . '/' . $configured, '/');
    return $root;
}

/**
 * Physical folder (with trailing slash) for a logical prefix such as
 * 'uploads/documents/'.
 */
function storage_dir_for(string $logicalPrefix): string
{
    return storage_root() . '/' . substr($logicalPrefix, strlen(UPLOAD_LOGICAL_PREFIX));
}

/**
 * Validate a logical path that came from the database or a URL.
 * Returns the cleaned path, or null unless it is exactly
 * <allowed prefix><bare filename>. Paths round-trip through the DB and
 * the query string, so they are treated as untrusted.
 */
function normalize_logical_path(?string $path): ?string
{
    if ($path === null) {
        return null;
    }

    $path = str_replace('\\', '/', trim($path));
    if ($path === '' || strpos($path, "\0") !== false || strpos($path, '..') !== false || $path[0] === '/') {
        return null;
    }

    foreach (PRIVATE_UPLOAD_PREFIXES as $prefix) {
        if (strpos($path, $prefix) === 0) {
            $rest = substr($path, strlen($prefix));
            if ($rest !== '' && strpos($rest, '/') === false) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Every place a logical path might physically exist, newest location first.
 *
 * @return string[]
 */
function stored_file_candidates(?string $logicalPath): array
{
    $clean = normalize_logical_path($logicalPath);
    if ($clean === null) {
        return [];
    }

    return [
        storage_root() . '/' . substr($clean, strlen(UPLOAD_LOGICAL_PREFIX)),
        project_root_path() . '/' . $clean,
    ];
}

/**
 * First existing physical file for a logical path, or null.
 */
function resolve_stored_file(?string $logicalPath): ?string
{
    foreach (stored_file_candidates($logicalPath) as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

/**
 * Name of the OS user PHP is running as (for error messages).
 */
function php_process_user(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $info = posix_getpwuid(posix_geteuid());
        if (is_array($info) && isset($info['name'])) {
            return $info['name'];
        }
    }
    return get_current_user();
}

if (!function_exists('ensure_writable_dir')) {
    /**
     * Make sure $dir exists and PHP can write to it.
     * Returns null on success, or a human-readable reason on failure
     * (meant for error_log(), not for end users).
     */
    function ensure_writable_dir(string $dir): ?string
    {
        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $err = error_get_last();
            return "cannot create {$dir} (" . ($err['message'] ?? 'unknown reason') . ')';
        }

        if (!is_writable($dir)) {
            @chmod($dir, 0775); // only succeeds if PHP owns the folder
            clearstatcache(true, $dir);
        }

        if (!is_writable($dir)) {
            return "{$dir} is not writable by PHP user '" . php_process_user() . "'";
        }

        return null;
    }
}

/**
 * Drop a deny-all .htaccess in the storage root. Harmless when the root
 * is outside the web root; the only protection when it is inside.
 */
function ensure_storage_protected(): void
{
    $root = storage_root();
    $htaccess = $root . '/.htaccess';

    if (!is_dir($root) || is_file($htaccess)) {
        return;
    }

    $contents = <<<HTACCESS
# Auto-generated by includes/storage.php.
# Private uploads. Never serve these directly -- they are delivered only
# through serve-document.php after a login check.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS;

    if (@file_put_contents($htaccess, $contents) === false) {
        error_log("storage: failed to write .htaccess in {$root}");
    }
}