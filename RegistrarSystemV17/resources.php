<?php
/**
 * Administrative resource management page.
 *
 * This screen centralizes non-transactional reference data that is reused
 * across the registrar workflow but is not tied to a single request record:
 *
 * - Landing page hero slides for the public home screen
 * - Staff records used in reviewer / processed-by selectors
 * - Academic programs presented in request forms and reports
 *
 * The page intentionally keeps these datasets editable from a single secure
 * admin location. It assumes the database has already been migrated with the
 * tables and constraints introduced by resources_migration.sql.
 */

session_start();
require_once 'includes/config.php';
/**
 * Shared image validation helpers are reused here so the landing page upload
 * rules stay consistent with the rest of the application without duplicating
 * the document-upload logic that lives in includes/upload-helper.php.
 */
require_once 'includes/upload-helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}
if ($_SESSION['role'] !== 'admin') {
    header('Location: requests.php');
    exit();
}

function log_audit(mysqli $conn, int $userId, string $action, string $details): void
{
    $stmt = $conn->prepare('INSERT INTO audit_trail (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $details);
    $stmt->execute();
    $stmt->close();
}

/**
 * Landing page slideshow assets are stored in a dedicated upload directory so
 * they remain isolated from document attachments created for individual
 * requests. The validation flow mirrors the general upload helper: verify the
 * file was received correctly, enforce size and extension rules, confirm the
 * actual file type, and save the result under a random filename.
 */
const LANDING_UPLOAD_ROOT_RELATIVE = 'assets/images/landing/';
const LANDING_MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20MB

function handle_landing_image_upload(array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Please choose an image to upload.'];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'There was a problem uploading the file. Please try again.'];
    }

    $tmpPath = $file['tmp_name'] ?? '';
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        return ['ok' => false, 'error' => 'There was a problem receiving the upload. Please try again.'];
    }

    $size = $file['size'] ?? filesize($tmpPath);
    if ($size > LANDING_MAX_UPLOAD_BYTES) {
        return ['ok' => false, 'error' => 'Image is too large. Please upload a file under 20MB.'];
    }
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'The selected file appears to be empty.'];
    }

    $originalName = (string)($file['name'] ?? '');
    if (!has_single_safe_image_extension($originalName)) {
        return ['ok' => false, 'error' => 'Please upload a single .jpg, .jpeg, or .png file.'];
    }

    $detectedType = detect_real_image_type($tmpPath);
    if ($detectedType === null || !isset(ALLOWED_IMAGE_TYPES[$detectedType])) {
        return ['ok' => false, 'error' => "That file doesn't look like a valid JPEG or PNG image."];
    }
    $ext = ALLOWED_IMAGE_TYPES[$detectedType];

    // This page sits at the project root alongside index.php, so the upload
    // directory can be resolved directly without traversing extra folder
    // levels like the helper under includes/.
    $uploadDirAbsolute = rtrim(__DIR__, '/') . '/' . LANDING_UPLOAD_ROOT_RELATIVE;
    $dirProblem = ensure_writable_dir($uploadDirAbsolute);
    if ($dirProblem !== null) {
        error_log("resources.php: {$dirProblem}");
        return ['ok' => false, 'error' => 'Something went wrong while saving the image. Please try again.'];
    }
    ensure_upload_dir_htaccess($uploadDirAbsolute);

    $generatedName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destinationAbsolute = $uploadDirAbsolute . $generatedName;
    if (file_exists($destinationAbsolute)) {
        $generatedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destinationAbsolute = $uploadDirAbsolute . $generatedName;
    }

    if (!move_uploaded_file($tmpPath, $destinationAbsolute)) {
        $phpError = error_get_last();
        error_log("resources.php: move_uploaded_file failed -> {$destinationAbsolute} | PHP user: " . php_process_user() . ' | ' . ($phpError['message'] ?? 'no PHP error captured'));
        return ['ok' => false, 'error' => 'Something went wrong while saving the image. Please try again.'];
    }

    return ['ok' => true, 'path' => LANDING_UPLOAD_ROOT_RELATIVE . $generatedName, 'error' => null];
}

function delete_landing_image_file(?string $relativePath): void
{
    if ($relativePath === null || $relativePath === '') {
        return;
    }
    $normalized = str_replace('\\', '/', $relativePath);
    // Only remove files that belong to the landing-image upload namespace.
    // Existing theme assets such as assets/images/hero*.jpg are intentionally
    // left untouched because they are part of the base design and are not
    // stored in the admin-managed upload directory.
    if (strpos($normalized, LANDING_UPLOAD_ROOT_RELATIVE) !== 0 || strpos($normalized, '..') !== false) {
        return;
    }
    $absolute = rtrim(__DIR__, '/') . '/' . $normalized;
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

/**
 * Request handlers for all admin actions on the resource tables.
 *
 * Each branch validates the request, updates the database, logs the change in
 * the audit trail, and redirects back to the same resource tab so the UI stays
 * in sync with the persisted state.
 */

// --- Landing page image management ---------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_landing_image'])) {
    $result = isset($_FILES['image'])
        ? handle_landing_image_upload($_FILES['image'])
        : ['ok' => false, 'error' => 'Please choose an image to upload.'];

    if (!$result['ok']) {
        $_SESSION['error'] = $result['error'];
    } else {
        $maxOrderRow = $conn->query('SELECT COALESCE(MAX(display_order), 0) AS max_order FROM landing_images')->fetch_assoc();
        $nextOrder = (int)$maxOrderRow['max_order'] + 1;

        $stmt = $conn->prepare('INSERT INTO landing_images (image_path, display_order) VALUES (?, ?)');
        $stmt->bind_param('si', $result['path'], $nextOrder);
        if ($stmt->execute()) {
            log_audit($conn, (int)$_SESSION['user_id'], 'add_landing_image', 'Added a new landing page slide');
            $_SESSION['success'] = 'Slide added.';
        } else {
            delete_landing_image_file($result['path']);
            $_SESSION['error'] = 'Failed to save the new slide.';
        }
        $stmt->close();
    }
    header('Location: resources.php?tab=images');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['replace_landing_image'])) {
    $image_id = (int)($_POST['image_id'] ?? 0);

    $stmt = $conn->prepare('SELECT image_path FROM landing_images WHERE id = ?');
    $stmt->bind_param('i', $image_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        $_SESSION['error'] = 'Slide not found.';
    } else {
        $result = isset($_FILES['image'])
            ? handle_landing_image_upload($_FILES['image'])
            : ['ok' => false, 'error' => 'Please choose a replacement image.'];

        if (!$result['ok']) {
            $_SESSION['error'] = $result['error'];
        } else {
            $stmt = $conn->prepare('UPDATE landing_images SET image_path = ? WHERE id = ?');
            $stmt->bind_param('si', $result['path'], $image_id);
            if ($stmt->execute()) {
                delete_landing_image_file($existing['image_path']);
                log_audit($conn, (int)$_SESSION['user_id'], 'replace_landing_image', "Replaced landing page slide #{$image_id}");
                $_SESSION['success'] = 'Slide replaced.';
            } else {
                delete_landing_image_file($result['path']);
                $_SESSION['error'] = 'Failed to replace the slide.';
            }
            $stmt->close();
        }
    }
    header('Location: resources.php?tab=images');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_landing_image'])) {
    $image_id = (int)($_POST['image_id'] ?? 0);

    $countRow = $conn->query('SELECT COUNT(*) AS c FROM landing_images')->fetch_assoc();
    if ((int)$countRow['c'] <= 1) {
        $_SESSION['error'] = 'At least one landing page image is required -- add a replacement before removing the last one.';
    } else {
        $stmt = $conn->prepare('SELECT image_path FROM landing_images WHERE id = ?');
        $stmt->bind_param('i', $image_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            $_SESSION['error'] = 'Slide not found.';
        } else {
            $stmt = $conn->prepare('DELETE FROM landing_images WHERE id = ?');
            $stmt->bind_param('i', $image_id);
            if ($stmt->execute()) {
                delete_landing_image_file($existing['image_path']);
                log_audit($conn, (int)$_SESSION['user_id'], 'remove_landing_image', "Removed landing page slide #{$image_id}");
                $_SESSION['success'] = 'Slide removed.';
            } else {
                $_SESSION['error'] = 'Failed to remove the slide.';
            }
            $stmt->close();
        }
    }
    header('Location: resources.php?tab=images');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['move_landing_image'])) {
    $image_id = (int)($_POST['image_id'] ?? 0);
    $direction = ($_POST['direction'] ?? '') === 'up' ? 'up' : 'down';

    $stmt = $conn->prepare('SELECT id, display_order FROM landing_images WHERE id = ?');
    $stmt->bind_param('i', $image_id);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($current) {
        if ($direction === 'up') {
            $stmt = $conn->prepare('SELECT id, display_order FROM landing_images WHERE display_order < ? ORDER BY display_order DESC LIMIT 1');
        } else {
            $stmt = $conn->prepare('SELECT id, display_order FROM landing_images WHERE display_order > ? ORDER BY display_order ASC LIMIT 1');
        }
        $stmt->bind_param('i', $current['display_order']);
        $stmt->execute();
        $neighbor = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($neighbor) {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('UPDATE landing_images SET display_order = ? WHERE id = ?');
                $stmt->bind_param('ii', $neighbor['display_order'], $current['id']);
                $stmt->execute();
                $stmt->bind_param('ii', $current['display_order'], $neighbor['id']);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error'] = 'Failed to reorder slides.';
            }
        }
    }
    header('Location: resources.php?tab=images');
    exit();
}

// --- Staff directory records ---------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_staff'])) {
    $staff_name = trim((string)($_POST['staff_name'] ?? ''));
    $staff_name = preg_replace('/[\x00-\x1F\x7F]/u', ' ', strip_tags($staff_name));
    $staff_name = trim(preg_replace('/\s+/', ' ', $staff_name));
    // Staff names are kept within the database's varchar(32) limit so the
    // reviewer dropdown remains compact and consistent with the schema.
    $staff_name = mb_substr($staff_name, 0, 32);

    if ($staff_name === '') {
        $_SESSION['error'] = 'Please enter a staff name.';
    } else {
        $stmt = $conn->prepare('INSERT INTO staffs (staff_name, is_deleted) VALUES (?, 0)');
        $stmt->bind_param('s', $staff_name);
        if ($stmt->execute()) {
            log_audit($conn, (int)$_SESSION['user_id'], 'add_staff', "Added staff member: {$staff_name}");
            $_SESSION['success'] = "Staff member \"{$staff_name}\" added.";
        } else {
            $_SESSION['error'] = 'Failed to add staff member.';
        }
        $stmt->close();
    }
    header('Location: resources.php?tab=staff');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_staff'])) {
    $staff_id = (int)($_POST['staff_id'] ?? 0);

    $stmt = $conn->prepare('SELECT staff_name FROM staffs WHERE id = ?');
    $stmt->bind_param('i', $staff_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        $_SESSION['error'] = 'Staff member not found.';
    } else {
        $stmt = $conn->prepare('UPDATE staffs SET is_deleted = 1 WHERE id = ?');
        $stmt->bind_param('i', $staff_id);
        if ($stmt->execute()) {
            log_audit($conn, (int)$_SESSION['user_id'], 'remove_staff', "Removed staff member: {$staff['staff_name']}");
            $_SESSION['success'] = "Staff member \"{$staff['staff_name']}\" removed.";
        } else {
            $_SESSION['error'] = 'Failed to remove staff member.';
        }
        $stmt->close();
    }
    header('Location: resources.php?tab=staff');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_staff'])) {
    $staff_id = (int)($_POST['staff_id'] ?? 0);

    $stmt = $conn->prepare('SELECT staff_name FROM staffs WHERE id = ?');
    $stmt->bind_param('i', $staff_id);
    $stmt->execute();
    $staff = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$staff) {
        $_SESSION['error'] = 'Staff member not found.';
    } else {
        $stmt = $conn->prepare('UPDATE staffs SET is_deleted = 0 WHERE id = ?');
        $stmt->bind_param('i', $staff_id);
        if ($stmt->execute()) {
            log_audit($conn, (int)$_SESSION['user_id'], 'restore_staff', "Restored staff member: {$staff['staff_name']}");
            $_SESSION['success'] = "Staff member \"{$staff['staff_name']}\" restored.";
        } else {
            $_SESSION['error'] = 'Failed to restore staff member.';
        }
        $stmt->close();
    }
    header('Location: resources.php?tab=staff');
    exit();
}

// --- Academic program catalog -------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_program'])) {
    $program_code = strtoupper(trim((string)($_POST['program_code'] ?? '')));
    // The program code is the canonical identifier used elsewhere in the app
    // (for example, 'BSCS') and is stored as a short schema-defined value.
    $program_code = mb_substr(preg_replace('/[^A-Z0-9]/', '', $program_code), 0, 16);

    $program_name = trim((string)($_POST['program_name'] ?? ''));
    $program_name = trim(preg_replace('/\s+/', ' ', strip_tags($program_name)));
    // Keep the full program name within the varchar(255) field limit defined
    // by the schema to avoid truncation or validation issues.
    $program_name = mb_substr($program_name, 0, 255);

    if ($program_code === '' || $program_name === '') {
        $_SESSION['error'] = 'Please provide both a program code and full name.';
    } else {
        $stmt = $conn->prepare('SELECT program FROM programs WHERE program = ?');
        $stmt->bind_param('s', $program_code);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $_SESSION['error'] = "Program code \"{$program_code}\" already exists.";
        } else {
            $stmt = $conn->prepare('INSERT INTO programs (program, program_name) VALUES (?, ?)');
            $stmt->bind_param('ss', $program_code, $program_name);
            if ($stmt->execute()) {
                log_audit($conn, (int)$_SESSION['user_id'], 'add_program', "Added program: {$program_code} - {$program_name}");
                $_SESSION['success'] = "Program \"{$program_code}\" added.";
            } else {
                $_SESSION['error'] = 'Failed to add program.';
            }
            $stmt->close();
        }
    }
    header('Location: resources.php?tab=programs');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_program'])) {
    // The program code functions as the stable primary key and is therefore
    // treated as immutable. Only the descriptive display name is editable from
    // this admin action.
    $program_code = trim((string)($_POST['program_code'] ?? ''));
    $program_name = trim((string)($_POST['program_name'] ?? ''));
    $program_name = trim(preg_replace('/\s+/', ' ', strip_tags($program_name)));
    $program_name = mb_substr($program_name, 0, 255);

    if ($program_code === '' || $program_name === '') {
        $_SESSION['error'] = 'Please provide a program name.';
    } else {
        $stmt = $conn->prepare('UPDATE programs SET program_name = ? WHERE program = ?');
        $stmt->bind_param('ss', $program_name, $program_code);
        if ($stmt->execute()) {
            log_audit($conn, (int)$_SESSION['user_id'], 'edit_program', "Edited program {$program_code}: now \"{$program_name}\"");
            $_SESSION['success'] = "Program \"{$program_code}\" updated.";
        } else {
            $_SESSION['error'] = 'Failed to update program.';
        }
        $stmt->close();
    }
    header('Location: resources.php?tab=programs');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_program'])) {
    $program_code = trim((string)($_POST['program_code'] ?? ''));

    $stmt = $conn->prepare('DELETE FROM programs WHERE program = ?');
    $stmt->bind_param('s', $program_code);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    if ($deleted) {
        log_audit($conn, (int)$_SESSION['user_id'], 'delete_program', "Deleted program: {$program_code}");
        $_SESSION['success'] = "Program \"{$program_code}\" deleted.";
    } else {
        $_SESSION['error'] = 'Failed to delete program (it may already be gone).';
    }
    header('Location: resources.php?tab=programs');
    exit();
}

/**
 * Fetch the current resource data for the page view.
 *
 * The admin interface renders separate tabs for landing images, staff, and
 * programs, so each dataset is loaded in the shape needed by the template.
 */

$landing_images = [];
$res = $conn->query('SELECT id, image_path, alt_text, display_order FROM landing_images ORDER BY display_order ASC, id ASC');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $landing_images[] = $row;
    }
}

$active_staff = [];
$res = $conn->query('SELECT id, staff_name FROM staffs WHERE is_deleted = 0 ORDER BY staff_name ASC');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $active_staff[] = $row;
    }
}

$removed_staff = [];
$res = $conn->query('SELECT id, staff_name FROM staffs WHERE is_deleted = 1 ORDER BY staff_name ASC');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $removed_staff[] = $row;
    }
}

$programs = [];
$res = $conn->query('SELECT program, program_name FROM programs ORDER BY program_name ASC');
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $programs[] = $row;
    }
}

$active_tab = in_array($_GET['tab'] ?? '', ['images', 'staff', 'programs'], true) ? $_GET['tab'] : 'images';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resources - RMS</title>
    <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
    <link rel="stylesheet" href="assets/css/styles2.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .sidebar { min-height: 100vh; background: #343a40; color: white; }
        .sidebar a { color: white; text-decoration: none; padding: 10px 20px; display: block; }
        .sidebar a:hover { background: #495057; }
        :root {
            --green-light: #d6e2d6ff;
            --green-main: #4caf50;
            --green-dark: #388e3c;
            --text-color: #2e2e2e;
            --border-color: #c8e6c9;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--green-light);
            color: var(--text-color);
            padding: 20px;
        }
        h2 { font-weight: 600; color: var(--green-dark); }

        /* ---- Landing images grid ---- */
        .slide-card {
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,.08);
            display: flex;
            flex-direction: column;
        }
        .slide-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            background: #eee;
        }
        .slide-card .slide-body {
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .slide-card .slide-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .add-slide-card {
            background: #fff;
            border: 2px dashed var(--border-color);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 220px;
            color: var(--green-dark);
            cursor: pointer;
            transition: .2s;
        }
        .add-slide-card:hover { background: var(--green-light); }

        @media (max-width: 767px) {
            .sidebar { min-height: auto; }
        }
    </style>
    <link rel="stylesheet" href="assets/css/responsive.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <button class="mobile-menu-toggle d-md-none" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>

            <div class="sidebar-overlay" onclick="closeSidebar()"></div>

            <div class="col-md-2 sidebar p-0" id="sidebar">
                <div class="sidebar-header">
                    <div class="d-flex align-items-center">
                        <img src="assets/images/logo.png" alt="CvSU Logo" class="sidebar-logo">
                        <h4>Request<br>System</h4>
                    </div>
                </div>

                <nav class="sidebar-nav">
                    <a href="dashboard.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span>Dashboard</span>
                    </a>
                    <a href="requests.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'requests.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Requests</span>
                    </a>
                    <a href="online-request.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'online-request.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Online Requests</span>
                    </a>
                    <a href="archives.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'archives.php' ? 'active' : ''; ?>">
                        <i class="fas fa-list"></i>
                        <span>Archives</span>
                    </a>
                    <a href="users.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'users.php' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span>Users</span>
                    </a>
                    <a href="resources.php" class="active">
                        <i class="fas fa-images"></i>
                        <span>Resources</span>
                    </a>
                    <a href="audit.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'audit.php' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        <span>Audit Trail</span>
                    </a>
                    <a href="includes/logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </nav>

                <div class="sidebar-footer">
                    <small>© 2025 CvSU RMS</small>
                </div>
            </div>

            <div class="col-md-10 p-4">
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
                <?php endif; ?>

                <h2 class="mb-4">Resources</h2>

                <ul class="nav nav-tabs mb-4" id="resourceTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'images' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-images" type="button" role="tab">
                            <i class="fas fa-image me-1"></i> Landing Images
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'staff' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-staff" type="button" role="tab">
                            <i class="fas fa-user-tie me-1"></i> Staff
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $active_tab === 'programs' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#tab-programs" type="button" role="tab">
                            <i class="fas fa-graduation-cap me-1"></i> Programs
                        </button>
                    </li>
                </ul>

                <div class="tab-content">

                    <!-- =========================== LANDING IMAGES =========================== -->
                    <div class="tab-pane fade <?php echo $active_tab === 'images' ? 'show active' : ''; ?>" id="tab-images" role="tabpanel">
                        <p class="text-muted mb-3">
                            These images are shown as the rotating slideshow on the public landing page.
                            Use the arrows to reorder them, or replace/remove individual slides.
                            At least one image must remain.
                        </p>
                        <div class="row g-3">
                            <?php foreach ($landing_images as $i => $img): ?>
                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="slide-card">
                                    <img src="<?php echo htmlspecialchars($img['image_path']); ?>" alt="<?php echo htmlspecialchars($img['alt_text']); ?>">
                                    <div class="slide-body">
                                        <strong>Slide <?php echo $i + 1; ?></strong>
                                        <div class="slide-actions">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="image_id" value="<?php echo (int)$img['id']; ?>">
                                                <input type="hidden" name="direction" value="up">
                                                <button type="submit" name="move_landing_image" class="btn btn-sm btn-outline-secondary" <?php echo $i === 0 ? 'disabled' : ''; ?> title="Move left">
                                                    <i class="fas fa-arrow-left"></i>
                                                </button>
                                            </form>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="image_id" value="<?php echo (int)$img['id']; ?>">
                                                <input type="hidden" name="direction" value="down">
                                                <button type="submit" name="move_landing_image" class="btn btn-sm btn-outline-secondary" <?php echo $i === count($landing_images) - 1 ? 'disabled' : ''; ?> title="Move right">
                                                    <i class="fas fa-arrow-right"></i>
                                                </button>
                                            </form>
                                            <button type="button" class="btn btn-sm btn-outline-primary" title="Replace"
                                                onclick="openReplaceSlideModal(<?php echo (int)$img['id']; ?>)">
                                                <i class="fas fa-upload"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this slide from the landing page?');">
                                                <input type="hidden" name="image_id" value="<?php echo (int)$img['id']; ?>">
                                                <button type="submit" name="remove_landing_image" class="btn btn-sm btn-outline-danger" title="Remove" <?php echo count($landing_images) <= 1 ? 'disabled' : ''; ?>>
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <div class="col-6 col-md-4 col-lg-3">
                                <div class="add-slide-card" data-bs-toggle="modal" data-bs-target="#addSlideModal">
                                    <div class="text-center">
                                        <i class="fas fa-plus fa-2x mb-2"></i>
                                        <div>Add Slide</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- =========================== STAFF =========================== -->
                    <div class="tab-pane fade <?php echo $active_tab === 'staff' ? 'show active' : ''; ?>" id="tab-staff" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Active Staff</h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStaffModal">
                                <i class="fas fa-user-plus me-1"></i> Add Staff
                            </button>
                        </div>
                        <div class="table-responsive mb-4">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($active_staff)): ?>
                                    <tr><td colspan="2" class="text-center text-muted">No active staff members.</td></tr>
                                    <?php else: foreach ($active_staff as $staff): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($staff['staff_name']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Remove &quot;<?php echo htmlspecialchars($staff['staff_name'], ENT_QUOTES); ?>&quot; from the staff list?');">
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$staff['id']; ?>">
                                                <button type="submit" name="remove_staff" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-user-minus"></i> Remove
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <h5 class="mb-3">Removed Staff</h5>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr><th>Name</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($removed_staff)): ?>
                                    <tr><td colspan="2" class="text-center text-muted">No removed staff members.</td></tr>
                                    <?php else: foreach ($removed_staff as $staff): ?>
                                    <tr>
                                        <td class="text-muted"><?php echo htmlspecialchars($staff['staff_name']); ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="staff_id" value="<?php echo (int)$staff['id']; ?>">
                                                <button type="submit" name="restore_staff" class="btn btn-sm btn-outline-success">
                                                    <i class="fas fa-rotate-left"></i> Restore
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- =========================== PROGRAMS =========================== -->
                    <div class="tab-pane fade <?php echo $active_tab === 'programs' ? 'show active' : ''; ?>" id="tab-programs" role="tabpanel">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0">Programs</h5>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                                <i class="fas fa-plus me-1"></i> Add Program
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-striped align-middle">
                                <thead>
                                    <tr><th>Code</th><th>Full Name</th><th class="text-end">Actions</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($programs)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">No programs yet.</td></tr>
                                    <?php else: foreach ($programs as $program): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($program['program']); ?></span></td>
                                        <td><?php echo htmlspecialchars($program['program_name']); ?></td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-primary"
                                                onclick="openEditProgramModal('<?php echo htmlspecialchars($program['program'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete program &quot;<?php echo htmlspecialchars($program['program'], ENT_QUOTES); ?>&quot;? This cannot be undone.');">
                                                <input type="hidden" name="program_code" value="<?php echo htmlspecialchars($program['program']); ?>">
                                                <button type="submit" name="delete_program" class="btn btn-sm btn-outline-danger">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Add Slide Modal -->
    <div class="modal fade" id="addSlideModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Slide</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Image (JPEG or PNG, up to 20MB)</label>
                        <input type="file" name="image" class="form-control" accept="image/jpeg,image/png" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_landing_image" class="btn btn-primary">Add Slide</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Replace Slide Modal -->
    <div class="modal fade" id="replaceSlideModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Replace Slide</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="image_id" id="replaceSlideImageId">
                        <label class="form-label">New image (JPEG or PNG, up to 20MB)</label>
                        <input type="file" name="image" class="form-control" accept="image/jpeg,image/png" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="replace_landing_image" class="btn btn-primary">Replace</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Staff Modal -->
    <div class="modal fade" id="addStaffModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Staff</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label">Staff Name</label>
                        <input type="text" name="staff_name" class="form-control" maxlength="32" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_staff" class="btn btn-primary">Add Staff</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Program</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Code (e.g. BSCS)</label>
                            <input type="text" name="program_code" class="form-control" maxlength="16" required style="text-transform: uppercase;">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="program_name" class="form-control" maxlength="255" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_program" class="btn btn-primary">Add Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Program Modal -->
    <div class="modal fade" id="editProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title">Edit Program</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="program_code" id="editProgramCode">
                        <div class="mb-3">
                            <label class="form-label">Code</label>
                            <input type="text" id="editProgramCodeDisplay" class="form-control" disabled>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" name="program_name" id="editProgramName" class="form-control" maxlength="255" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="edit_program" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/sidebar.js" defer></script>
    <script>
        function openReplaceSlideModal(imageId) {
            document.getElementById('replaceSlideImageId').value = imageId;
            new bootstrap.Modal(document.getElementById('replaceSlideModal')).show();
        }

        function openEditProgramModal(code, name) {
            document.getElementById('editProgramCode').value = code;
            document.getElementById('editProgramCodeDisplay').value = code;
            document.getElementById('editProgramName').value = name;
            new bootstrap.Modal(document.getElementById('editProgramModal')).show();
        }
    </script>
</body>
</html>