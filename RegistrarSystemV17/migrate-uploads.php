<?php
/**
 * migrate-uploads.php
 *
 * One-time command-line script: moves existing files from the old
 * web-visible folders (uploads/documents, uploads/archived-online-documents)
 * into the private storage root.
 *
 *   php migrate-uploads.php --dry-run    (show what would move)
 *   php migrate-uploads.php              (do it)
 *
 * On Linux, run it as the web server user so file ownership stays correct:
 *   sudo -u www-data php migrate-uploads.php
 *
 * Nothing in the database changes -- logical paths stay the same -- and
 * files not yet migrated keep working, because serve-document.php also
 * looks in the legacy folder.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line: php migrate-uploads.php\n");
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/upload-helper.php';

$dryRun = in_array('--dry-run', $argv ?? [], true);
$moved = $skipped = $failed = 0;

echo 'Storage root: ' . storage_root() . "\n";
echo $dryRun ? "DRY RUN -- nothing will be changed\n\n" : "\n";

foreach (PRIVATE_UPLOAD_PREFIXES as $prefix) {
    $legacyDir = project_root_path() . '/' . $prefix;
    if (!is_dir($legacyDir)) {
        continue;
    }

    $targetDir = storage_dir_for($prefix);
    if (!$dryRun) {
        $problem = ensure_writable_dir($targetDir);
        if ($problem !== null) {
            fwrite(STDERR, "Cannot use {$targetDir}: {$problem}\n");
            exit(1);
        }
        ensure_storage_protected();
    }

    foreach (scandir($legacyDir) as $name) {
        $from = $legacyDir . $name;
        if ($name === '.' || $name === '..' || $name === '.htaccess' || !is_file($from)) {
            continue;
        }

        $to = $targetDir . $name;
        if (file_exists($to)) {
            echo "skip (already in storage): {$prefix}{$name}\n";
            $skipped++;
            continue;
        }

        if ($dryRun) {
            echo "would move: {$prefix}{$name}\n";
            $moved++;
            continue;
        }

        // rename() is instant on one filesystem; copy+delete covers the
        // case where STORAGE_PATH is on another drive/volume.
        if (@rename($from, $to) || (@copy($from, $to) && @unlink($from))) {
            echo "moved: {$prefix}{$name}\n";
            $moved++;
        } else {
            $err = error_get_last();
            echo "FAILED: {$prefix}{$name} (" . ($err['message'] ?? 'unknown') . ")\n";
            $failed++;
        }
    }
}

echo "\nDone. moved={$moved} skipped={$skipped} failed={$failed}\n";
if (!$dryRun && $failed === 0) {
    echo "The old uploads/ folders can now be deleted once they are empty.\n";
}
exit($failed > 0 ? 1 : 0);