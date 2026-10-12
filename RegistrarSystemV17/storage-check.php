<?php
/**
 * storage-check.php
 *
 * Admin-only diagnostic. Actually tries to create and delete a file in
 * each upload folder as the web server user, and reports what PHP itself
 * says went wrong. Run it after deploying to a new machine, before a
 * student hits an upload error.
 *
 * Note: it creates any missing upload folders as a side effect.
 */

session_start();
require_once 'includes/config.php';
require_once 'includes/upload-helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit();
}

function check_dir(string $label, string $dir): array
{
    $problem = ensure_writable_dir($dir);
    $writeError = null;

    if ($problem === null) {
        $probe = rtrim($dir, '/\\') . '/.write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            $err = error_get_last();
            $writeError = $err['message'] ?? 'unknown error';
        } else {
            @unlink($probe);
        }
    }

    return [
        'label' => $label,
        'dir'   => $dir,
        'ok'    => $problem === null && $writeError === null,
        'note'  => $problem ?? $writeError ?? 'Created and deleted a test file.',
    ];
}

$rows = [
    check_dir('Private documents', storage_dir_for('uploads/documents/')),
    check_dir('Archived documents', storage_dir_for('uploads/archived-online-documents/')),
    check_dir('Landing slideshow (public)', __DIR__ . '/assets/images/landing/'),
];

$tmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
$rows[] = [
    'label' => 'PHP upload temp folder',
    'dir'   => $tmpDir,
    'ok'    => is_writable($tmpDir),
    'note'  => is_writable($tmpDir) ? 'Writable.' : 'Not writable -- uploads fail before reaching the app.',
];

$docRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ''));
$storageReal = str_replace('\\', '/', (string)(realpath(storage_root()) ?: storage_root()));
$insideWebRoot = $docRoot !== '' && strpos($storageReal, $docRoot) === 0;

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Storage check - RMS</title>
    <style>
        body { font-family: sans-serif; max-width: 900px; margin: 30px auto; padding: 0 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; vertical-align: top; }
        code { background: #f2f2f2; padding: 1px 4px; }
        .ok { color: #1a7f37; font-weight: 600; }
        .bad { color: #b02a37; font-weight: 600; }
        .warn { background: #fff8e1; border: 1px solid #f0d78c; padding: 10px; margin-top: 16px; }
    </style>
</head>
<body>
    <h2>Storage check</h2>
    <p>PHP runs as <code><?php echo $h(php_process_user()); ?></code>.
       Storage root: <code><?php echo $h(storage_root()); ?></code></p>

    <table>
        <tr><th>Folder</th><th>Path</th><th>Result</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?php echo $h($r['label']); ?></td>
            <td><code><?php echo $h($r['dir']); ?></code></td>
            <td class="<?php echo $r['ok'] ? 'ok' : 'bad'; ?>">
                <?php echo $r['ok'] ? 'OK' : 'FAILED'; ?>
                <div style="font-weight: normal;"><?php echo $h($r['note']); ?></div>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($insideWebRoot): ?>
    <div class="warn">
        Storage is inside the web root. It is protected only by the generated
        <code>.htaccess</code>, which Apache ignores when <code>AllowOverride</code> is
        <code>None</code>. On a real server, set <code>STORAGE_PATH</code> in <code>.env</code>
        to a folder outside the web root.
    </div>
    <?php endif; ?>

    <?php if (ini_get('open_basedir')): ?>
    <div class="warn">
        <code>open_basedir</code> is set to <code><?php echo $h(ini_get('open_basedir')); ?></code>.
        Storage and temp folders must be inside it.
    </div>
    <?php endif; ?>

    <p style="margin-top:16px;">If a folder failed on Fedora/RHEL/CentOS with correct ownership, SELinux is the
       likely cause: <code>sudo chcon -R -t httpd_sys_rw_content_t &lt;folder&gt;</code></p>
</body>
</html>