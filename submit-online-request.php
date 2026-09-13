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

$student_number = trim($_POST['Student_Number']);
$contact        = trim($_POST['Contact_Number']);
$email          = trim($_POST['Email']);
$first_name     = trim($_POST['First_Name']);
$last_name      = trim($_POST['Last_Name']);
$middle_name    = trim($_POST['Middle_Name'] ?? '');

$student_name = trim(preg_replace('/\s+/', ' ', "{$first_name} {$middle_name} {$last_name}"));

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(['status' => 'error', 'message' => 'Please enter a valid email address.'], 422);
}

// Program: either a code from the `programs` table, or "others" + free text.
$program_code = $_POST['Program'];
if ($program_code === 'others') {
    $program = trim($_POST['Course'] ?? '');
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
    $doc_label = trim($_POST['others_type'] ?? '');
    if ($doc_label === '') {
        respond(['status' => 'error', 'message' => 'Please specify the document you need.'], 422);
    }
} elseif (isset(DOCUMENT_LABELS[$document_key])) {
    $doc_label = DOCUMENT_LABELS[$document_key];
} else {
    respond(['status' => 'error', 'message' => 'Please select a valid document type.'], 422);
}

// Purpose: a known option, or "Others" + free text.
$purpose_key = $_POST['Purpose'];
if ($purpose_key === 'Others') {
    $purpose = trim($_POST['others_purpose'] ?? '');
    if ($purpose === '') {
        respond(['status' => 'error', 'message' => 'Please specify the purpose of your request.'], 422);
    }
} else {
    $purpose = $purpose_key;
}

$notes = trim($_POST['Notes'] ?? '');

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
             document_type, purpose, contact_number, email_address, notes, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->bind_param(
        'ssssssssss',
        $title,
        $description,
        $student_number,
        $student_name,
        $program,
        $document_key,
        $purpose,
        $contact,
        $email,
        $notes
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to save request: ' . $conn->error);
    }
    $online_request_id = $stmt->insert_id;
    $stmt->close();

    // Token is single-use -- only invalidate it once the request has
    // actually been saved, so a failed attempt can still be retried.
    unset($_SESSION['online_form_token']);

    respond([
        'status'    => 'success',
        'message'   => 'Your request has been submitted successfully. Staff will review it shortly.',
        // "ONL-" (not "REQ-") is deliberate: this is a submission
        // reference into online-request, not a `requests` ID -- those
        // are only assigned once staff approve the submission.
        'reference' => 'ONL-' . str_pad((string)$online_request_id, 6, '0', STR_PAD_LEFT),
    ]);
} catch (\Throwable $e) {
    error_log('Online request submission error: ' . $e->getMessage());
    respond(['status' => 'error', 'message' => 'Something went wrong while submitting your request. Please try again.'], 500);
}