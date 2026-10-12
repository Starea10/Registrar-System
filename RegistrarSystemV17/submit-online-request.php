<?php
/**
 * submit-online-request.php
 *
 * Public, unauthenticated endpoint for the online request form
 * (request-online.php, submitted via assets/js/request-online.js).
 *
 * A request submitted here is unverified -- no staff member has looked at
 * it yet -- so it is inserted into the `online-request` staging table
 * (status = 'pending'), NOT into `requests`. Staff review pending rows on
 * online-request.php and either:
 *   - Approve    -> a new row is created in `requests` (source = 'online',
 *                   status = 'pending'), which then goes through the
 *                   normal requests.php pipeline (pending -> processing ->
 *                   for_signature -> for_release -> released) unmodified.
 *   - Disapprove -> the online-request row is flagged disapproved and
 *                   kept for the record; nothing is created in `requests`.
 *
 * Requires the `online-request` table added by
 * online-request_migration.sql.
 */

session_start();
require_once 'includes/config.php';
require_once 'includes/upload-helper.php';

header('Content-Type: application/json');

function respond(array $payload, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($payload);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['status' => 'error', 'message' => 'Invalid request method.'], 405);
}

// Reminder shown on the confirmation screen after a successful
// submission, for the document types that have one. Kept server-side
// next to DOCUMENT_LABELS so the wording lives in one place.
const DOCUMENT_REMINDERS = [
    'TOR' => 'Required documents must be submitted to the Campus Registrar before the TOR can be released.',
    'CAV' => 'Please submit the required documents to the Campus Registrar as soon as possible. '
        . 'Failure to submit the documents may result in the cancellation of the request.',
];

// ---------------------------------------------------------------------
// Text sanitization
// ---------------------------------------------------------------------
// This endpoint is public and unauthenticated, so every text value is
// treated as hostile until cleaned. Output is already escaped with
// htmlspecialchars() wherever these values are displayed (see
// online-request.php), but that's not a reason to store raw markup,
// control characters, or arbitrarily long strings -- this is defense
// in depth, and keeps garbage data out of the DB regardless of what a
// script hitting this endpoint directly (skipping the browser/JS
// entirely) sends.
//
// Max lengths below match the `online_requests` column sizes in
// request_system.sql, so nothing here can silently overflow a varchar.

/**
 * Strip HTML tags and control characters, collapse whitespace, trim,
 * and cap the length (in characters, not bytes -- utf8mb4 safe).
 * $allowNewlines keeps single line breaks for multi-line fields
 * (Notes) while still collapsing runs of spaces/tabs on each line.
 */
function sanitize_text(string $value, int $maxLength, bool $allowNewlines = false): string
{
    // No markup at all -- values are re-escaped on output anyway, but
    // there's no legitimate reason for a name/purpose/note to contain
    // HTML, and stripping it here removes any temptation to trust
    // these fields raw somewhere else later.
    $value = strip_tags($value);

    if ($allowNewlines) {
        // Drop control chars but keep \n (0x0A).
        $value = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
        $value = preg_replace('/[ \t]+/', ' ', $value);
        // Trim trailing spaces on each line and collapse 3+ blank
        // lines down to one, so Notes can't be used to pad the page.
        $value = preg_replace('/[ \t]+\n/', "\n", $value);
        $value = preg_replace('/\n{3,}/', "\n\n", $value);
    } else {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
    }

    $value = trim((string)$value);

    if (mb_strlen($value) > $maxLength) {
        $value = mb_substr($value, 0, $maxLength);
    }

    return $value;
}

/**
 * Same as sanitize_text(), but additionally strips anything that isn't
 * a digit or common phone punctuation -- contact_number is stored and
 * later used only as a phone number, so there's no reason to accept
 * letters or symbols in it.
 */
function sanitize_phone(string $value, int $maxLength): string
{
    $value = sanitize_text($value, $maxLength);
    return preg_replace('/[^0-9]/', '', $value);
}

// Document type => display label, matching the options in request-online.php.
const DOCUMENT_LABELS = [
    'TOR'      => 'Transcript of Record (TOR)',
    'Diploma'  => 'Diploma',
    'COG'      => 'Certificate of Grades (COG)',
    'COE'      => 'Certificate of Enrollment (COE)',
    'Form137'  => 'Form 137A',
    'CAV'      => 'Certification Authentication and Verification (CAV)',
    'Certification' => 'Certification',
];

// ---------------------------------------------------------------------
// CSRF check -- token was printed into a hidden field by
// request-online.php and must round-trip back here unchanged.
// ---------------------------------------------------------------------
$postToken    = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['online_form_token'] ?? '';

if (empty($postToken) || empty($sessionToken) || !hash_equals($sessionToken, $postToken)) {
    respond(['status' => 'error', 'message' => 'Your session has expired. Please refresh the page and try again.'], 400);
}

// Honeypot -- a field real visitors never see or fill in (hidden via CSS
// in request-online.php). A bot that fills in every input on the page
// will trip it; pretend success so it learns nothing, but write nothing.
if (!empty($_POST['website'])) {
    respond(['status' => 'success', 'message' => 'Request submitted.', 'reference' => null]);
}

// ---------------------------------------------------------------------
// Validate required fields
// ---------------------------------------------------------------------
// NOTE: PHP automatically converts dots and spaces in incoming POST
// field names to underscores (a leftover from the old register_globals
// days) -- a field posted as "Student Number" arrives here as
// $_POST['Student_Number'], NOT $_POST['Student Number']. The HTML
// still uses names with spaces (request-online.php / the summary
// display relies on that for readable labels), so every key below that
// has a space in the form must be read with an underscore here.
$required = ['Student_Number', 'Contact_Number', 'Email', 'First_Name', 'Last_Name', 'Program', 'Document', 'Purpose'];
$missing = [];
foreach ($required as $field) {
    if (trim((string)($_POST[$field] ?? '')) === '') {
        $missing[] = $field;
    }
}
if (!empty($missing)) {
    respond(['status' => 'error', 'message' => 'Please complete all required fields.'], 422);
}

// Column widths below match `online_requests` (varchar(50)/varchar(255)/
// varchar(128) etc.) so sanitize_text() can never hand the INSERT below
// a value too long for its column.
$student_number = sanitize_text($_POST['Student_Number'], 50);
$contact        = sanitize_phone($_POST['Contact_Number'], 50);
$email          = sanitize_text($_POST['Email'], 128);
$first_name     = sanitize_text($_POST['First_Name'], 100);
$last_name      = sanitize_text($_POST['Last_Name'], 100);
$middle_name    = sanitize_text($_POST['Middle_Name'] ?? '', 100);

$student_name = trim(preg_replace('/\s+/', ' ', "{$first_name} {$middle_name} {$last_name}"));
// student_name is a concatenation of the three fields above (already
// sanitized individually) and stored in a varchar(255) column.
if (mb_strlen($student_name) > 255) {
    $student_name = mb_substr($student_name, 0, 255);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);
}

if ($contact === '') {
    respond(['status' => 'error', 'message' => 'Please enter a valid contact number.'], 422);
}

// Re-check the required fields after sanitizing -- a value that was
// pure markup/control characters (e.g. "<script></script>") would have
// passed the earlier trim()-only check but sanitize to empty here.
foreach (['Student_Number' => $student_number, 'Contact_Number' => $contact, 'First_Name' => $first_name, 'Last_Name' => $last_name] as $label => $value) {
    if ($value === '') {
        respond(['status' => 'error', 'message' => 'Please complete all required fields.'], 422);
    }
}

if (!preg_match('/^[0-9]+$/', $student_number)) {
    respond(['status' => 'error', 'message' => 'Please enter a valid student number using numbers only.'], 422);
}

// Program: either a code from the `programs` table, or "others" + free text.
$program_code = $_POST['Program'];
if ($program_code === 'others') {
    $program = sanitize_text($_POST['Course'] ?? '', 255);
    if ($program === '') {
        respond(['status' => 'error', 'message' => 'Please specify your course.'], 422);
    }
} else {
    $stmt = $conn->prepare('SELECT program_name FROM programs WHERE program = ?');
    $stmt->bind_param('s', $program_code);
    $stmt->execute();
    $program_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$program_row) {
        respond(['status' => 'error', 'message' => 'Please select a valid course.'], 422);
    }
    $program = $program_row['program_name'];
}

// Document type: a known key, or "Others" + free text.
$document_key = $_POST['Document'];
if ($document_key === 'Others') {
    $doc_label = sanitize_text($_POST['others_type'] ?? '', 150);
    if ($doc_label === '') {
        respond(['status' => 'error', 'message' => 'Please specify the document you need.'], 422);
    }
} elseif (isset(DOCUMENT_LABELS[$document_key])) {
    $doc_label = DOCUMENT_LABELS[$document_key];
} else {
    respond(['status' => 'error', 'message' => 'Please select a valid document type.'], 422);
}

// ---------------------------------------------------------------------
// TOR request type -- only meaningful when the document is TOR. Drives
// which uploads are required below (first-time vs previous requester).
// ---------------------------------------------------------------------
$tor_request_type = null;
$tor_previous_confirmed = 0;
$tor_board_exam = !empty($_POST['tor_board_exam']);
$tor_transferee = !empty($_POST['tor_transferee']);

if ($document_key === 'TOR') {
    $tor_request_type = $_POST['tor_request_type'] ?? '';
    if (!in_array($tor_request_type, ['first_time', 'previous'], true)) {
        respond(['status' => 'error', 'message' => 'Please indicate whether this is your first time requesting a TOR.'], 422);
    }
    if ($tor_request_type === 'previous') {
        if (empty($_POST['tor_previous_confirmed'])) {
            respond(['status' => 'error', 'message' => 'Please confirm that you have requested a TOR before.'], 422);
        }
        $tor_previous_confirmed = 1;
    }
}

// Purpose: a known option, or "Others" + free text.
$purpose_key = $_POST['Purpose'];
if ($purpose_key === 'Others') {
    $purpose = sanitize_text($_POST['others_purpose'] ?? '', 255);
    if ($purpose === '') {
        respond(['status' => 'error', 'message' => 'Please specify the purpose of your request.'], 422);
    }
} else {
    $purpose = $purpose_key;
}

// notes is a `text` column (no hard DB limit), but an unauthenticated
// endpoint still shouldn't be able to store an unbounded blob -- cap it
// generously instead. Newlines are kept since this is a free-text box.
$notes = sanitize_text($_POST['Notes'] ?? '', 2000, true);

// ---------------------------------------------------------------------
// Document image uploads. Which fields are REQUIRED is decided here,
// server-side -- never trust the client's `required` attributes, which
// are trivially bypassed. Mirrors DOCUMENT_LABELS' style.
// ---------------------------------------------------------------------
const DOCUMENT_REQUIRED_UPLOADS = [
    'COE' => ['student_id_image', 'registration_form_image'],
    'COG' => ['student_id_image'],
    'CAV' => ['government_id_image', 'original_document_image', 'photocopy_document_image', 'photocopy_document_image_2'],
];

// TOR's required set depends on the first-time/previous choice and the
// two situational checkboxes, so it's built here instead of living in
// the constant above.
$required_uploads = DOCUMENT_REQUIRED_UPLOADS[$document_key] ?? [];
if ($document_key === 'TOR') {
    if ($tor_request_type === 'first_time') {
        $required_uploads = ['student_id_image', 'clearance_form_image', 'proof_of_graduation_image'];
        if ($tor_board_exam) {
            $required_uploads[] = 'id_picture_image';
        }
        if ($tor_transferee) {
            $required_uploads[] = 'honorable_dismissal_image';
        }
    } else {
        $required_uploads = ['student_id_image'];
    }
}

// Every upload column we might store, required or not -- anything in
// $_FILES outside this list is ignored outright.
const ALL_UPLOAD_FIELDS = [
    'student_id_image',
    'registration_form_image',
    'clearance_form_image',
    'proof_of_graduation_image',
    'id_picture_image',
    'honorable_dismissal_image',
    'government_id_image',
    'original_document_image',
    'photocopy_document_image',
    'photocopy_document_image_2',
];

// field name => stored relative path (or null). Kept so the catch block
// below can delete files if the DB insert fails after they've moved.
$uploaded_paths = array_fill_keys(ALL_UPLOAD_FIELDS, null);

foreach (ALL_UPLOAD_FIELDS as $field) {
    $file = $_FILES[$field] ?? null;

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if (in_array($field, $required_uploads, true)) {
            // Delete anything already moved to disk before bailing out.
            foreach ($uploaded_paths as $path) {
                delete_uploaded_document($path);
            }
            respond(['status' => 'error', 'message' => "Please upload the required document: {$field}."], 422);
        }
        continue; // optional field left empty -- fine
    }

    $result = handle_document_upload($file, $field);
    if (!$result['ok']) {
        foreach ($uploaded_paths as $path) {
            delete_uploaded_document($path);
        }
        respond(['status' => 'error', 'message' => $result['error']], 422);
    }
    $uploaded_paths[$field] = $result['path'];
}

// ---------------------------------------------------------------------
// Build the record, following the same title/description convention the
// staff-facing form in requests.php uses, so both look consistent in the
// dashboard and its "View Details" modal.
// ---------------------------------------------------------------------
$title = $doc_label;

$description = "Student Number: {$student_number}\n"
    . "Student Name: {$student_name}\n"
    . "Program: {$program}\n"
    . "Contact Information: {$contact}\n"
    . "Email: {$email}\n"
    . "Purpose: {$purpose}\n"
    . "Requested Document: {$doc_label}";

if ($notes !== '') {
    $description .= "\nAdditional Notes: {$notes}";
}

try {
    // Lands in the staging table, NOT `requests` -- see docblock. Staff
    // approval on online-request.php is what eventually creates the
    // `requests` row (source='online', status='pending').
    $stmt = $conn->prepare(
        "INSERT INTO online_requests
            (title, description, student_number, student_name, program,
             document_type, purpose, contact_number, email_address, notes,
             status, tor_request_type, tor_previous_confirmed,
             student_id_image, registration_form_image, clearance_form_image,
             proof_of_graduation_image, id_picture_image, honorable_dismissal_image,
             government_id_image, original_document_image, photocopy_document_image,
             photocopy_document_image_2)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        'ssssssssss' . 'si' . 'ssssssssss',
        $title,
        $description,
        $student_number,
        $student_name,
        $program,
        $document_key,
        $purpose,
        $contact,
        $email,
        $notes,
        $tor_request_type,
        $tor_previous_confirmed,
        $uploaded_paths['student_id_image'],
        $uploaded_paths['registration_form_image'],
        $uploaded_paths['clearance_form_image'],
        $uploaded_paths['proof_of_graduation_image'],
        $uploaded_paths['id_picture_image'],
        $uploaded_paths['honorable_dismissal_image'],
        $uploaded_paths['government_id_image'],
        $uploaded_paths['original_document_image'],
        $uploaded_paths['photocopy_document_image'],
        $uploaded_paths['photocopy_document_image_2']
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to save request: ' . $conn->error);
    }
    $online_request_id = $stmt->insert_id;
    $stmt->close();

    $tracking_characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    do {
        $tracking_suffix = '';
        for ($index = 0; $index < 8; $index++) {
            $tracking_suffix .= $tracking_characters[random_int(0, strlen($tracking_characters) - 1)];
        }
        $tracking_code = 'CVSU-REG' . $tracking_suffix;
        $check_stmt = $conn->prepare(
            'SELECT id FROM online_requests WHERE tracking_code = ?
             UNION ALL
             SELECT id FROM requests WHERE tracking_code = ?
             LIMIT 1'
        );
        $check_stmt->bind_param('ss', $tracking_code, $tracking_code);
        $check_stmt->execute();
        $tracking_exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
    } while ($tracking_exists);

    $update_stmt = $conn->prepare('UPDATE online_requests SET tracking_code = ? WHERE id = ?');
    $update_stmt->bind_param('si', $tracking_code, $online_request_id);
    if (!$update_stmt->execute()) {
        throw new Exception('Failed to create tracking reference: ' . $conn->error);
    }
    $update_stmt->close();

    // Token is single-use -- only invalidate it once the request has
    // actually been saved, so a failed attempt can still be retried.
    unset($_SESSION['online_form_token']);

    respond([
        'status'    => 'success',
        'message'   => 'Your request has been submitted successfully. Staff will review it shortly.',
        'reference' => $tracking_code,
        // Printed on the success card by request-online.js; null for
        // document types that have no reminder.
        'reminder'  => DOCUMENT_REMINDERS[$document_key] ?? null,
    ]);
} catch (\Throwable $e) {
    // The DB write failed AFTER files were moved to disk -- delete them
    // so failed submissions don't leave orphaned uploads behind (there's
    // no cleanup job elsewhere in this codebase to rely on).
    foreach ($uploaded_paths as $path) {
        delete_uploaded_document($path);
    }
    error_log('Online request submission error: ' . $e->getMessage());
    respond(['status' => 'error', 'message' => 'Something went wrong while submitting your request. Please try again.'], 500);
}