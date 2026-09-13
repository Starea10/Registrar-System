<?php
/**
 * track.php - public document-request tracker (no login required)
 *
 * Visitors look up the status of their request using a reference code
 * made of their last name + the date the request was submitted, e.g.
 * JOHNSON-07202026 (case-insensitive). MMDDYYYY = month, day, year.
 *
 * IMPORTANT CAVEAT: this "code" is just a last name + a date, not a
 * secret token. Anyone who knows (or guesses) a person's last name and
 * the day they applied can see their request status, document type,
 * student number, and claiming date. That's fine for a small school
 * registrar, but if this ever needs to be tamper/guess-proof, the more
 * robust fix is a dedicated random tracking_code column generated when
 * a request is created and printed on the student's receipt, then
 * looked up by that code alone instead of by name + date. Happy to add
 * that if you want it -- it just needs one new column on `requests`.
 *
 * This assumes `includes/config.php` sets up a mysqli connection in
 * $conn, the same way requests.php does.
 */

require_once 'includes/config.php';

/**
 * Human-readable label + which of the 3 public tracker stages
 * (Submitted / Processing / Ready for Pickup) a raw status maps to.
 */
function track_status_meta(string $status): array
{
    $labels = [
        'pending'       => 'Pending',
        'processing'    => 'Processing',
        'for_signature' => 'For Signature',
        'for_release'   => 'For Release',
        'released'      => 'Released',
    ];

    $step = 1;
    if (in_array($status, ['processing', 'for_signature'], true)) {
        $step = 2;
    } elseif (in_array($status, ['for_release', 'released'], true)) {
        $step = 3;
    }

    return [
        'label'    => $labels[$status] ?? ucfirst($status),
        'step'     => $step,
        'released' => $status === 'released',
    ];
}

/**
 * Whether $last_name appears inside $full_name, ignoring case, spacing
 * and punctuation (so "Dela Cruz, Juan", "Juan Dela Cruz", and
 * "juan  dela-cruz" all match a last name of "Dela Cruz"). This is a
 * substring check, not a strict field match, since student_name is
 * stored as a single free-text field with no dedicated last-name
 * column -- see the note at the top of this file.
 */
function name_contains_last_name(string $full_name, string $last_name): bool
{
    $normalize = fn(string $s) => strtolower(preg_replace('/[^a-z]/i', '', $s));
    $needle = $normalize($last_name);
    if ($needle === '') {
        return false;
    }
    return str_contains($normalize($full_name), $needle);
}

/**
 * Parse a LASTNAME-MMDDYYYY code and return the matching request rows
 * (any status, released or not) formatted for display.
 *
 * @return array{status: string, message?: string, requests?: array}
 */
function track_lookup(mysqli $conn, string $ref_code): array
{
    $ref_code = trim($ref_code);

    if (!preg_match('/^(.+)-(\d{2})(\d{2})(\d{4})$/', $ref_code, $m)) {
        return [
            'status'  => 'error',
            'message' => 'Please enter your reference code in the format LASTNAME-MMDDYYYY (e.g. JOHNSON-07202026).',
        ];
    }

    [, $last_name, $mm, $dd, $yyyy] = $m;
    $last_name = trim($last_name);

    if ($last_name === '' || !checkdate((int)$mm, (int)$dd, (int)$yyyy)) {
        return [
            'status'  => 'error',
            'message' => 'That reference code doesn\'t look right. Please double check it and try again.',
        ];
    }

    $target_date = sprintf('%04d-%02d-%02d', (int)$yyyy, (int)$mm, (int)$dd);

    $stmt = $conn->prepare(
        'SELECT title, status, student_number, student_name, claiming_date, created_at, released_at
         FROM requests
         WHERE DATE(created_at) = ?'
    );
    $stmt->bind_param('s', $target_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $matches = [];
    while ($row = $result->fetch_assoc()) {
        if (name_contains_last_name($row['student_name'], $last_name)) {
            $matches[] = $row;
        }
    }
    $stmt->close();

    if (empty($matches)) {
        return [
            'status'  => 'error',
            'message' => 'No matching request found. Please check your reference code and try again.',
        ];
    }

    $requests = array_map(function (array $row): array {
        $meta = track_status_meta($row['status']);
        return [
            'document'       => $row['title'],
            'status'         => $row['status'],
            'status_label'   => $meta['label'],
            'step'           => $meta['step'],
            'released'       => $meta['released'],
            'student_name'   => $row['student_name'],
            'student_number' => $row['student_number'],
            'date_requested' => !empty($row['created_at']) ? date('F j, Y', strtotime($row['created_at'])) : null,
            'est_completion' => !empty($row['claiming_date']) ? date('F j, Y', strtotime($row['claiming_date'])) : 'Not yet scheduled',
            'released_at'    => !empty($row['released_at']) ? date('F j, Y', strtotime($row['released_at'])) : null,
        ];
    }, $matches);

    return ['status' => 'success', 'requests' => $requests];
}

// ---------------------------------------------------------------------
// AJAX endpoint used by the form below
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ref_code'])) {
    header('Content-Type: application/json');
    echo json_encode(track_lookup($conn, (string)$_POST['ref_code']));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Request | CvSU Naic Registrar</title>
  <link rel="icon" href="assets/images/logo.png" type="image/x-icon">
  
  <!-- Stylesheets -->
  <link rel="stylesheet" href="assets/css/variables.css">
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="assets/css/request.css">
</head>

<body style="background: var(--background);">
  <header style="position: relative; box-shadow: none;">
    <nav>
      <div class="container nav">
        <a href="index.php" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>
        <a href="index.php" class="back">← Back Home</a>
      </div>
    </nav>
  </header>

  <main class="container" style="max-width: 700px; margin: 40px auto 80px;">
    <div class="page-title">
      <h1>Document Request Tracker</h1>
      <p>Enter your Reference Control Code to check the current status of your application.</p>
    </div>

    <!-- Search Card -->
    <div style="background: white; padding: 35px; border-radius: var(--radius); box-shadow: var(--shadow);">
      <form id="trackerForm" onsubmit="handleTrack(event)">
        <div class="input-group">
          <label style="font-weight: 600; font-size: 1rem;">Reference Control Code</label>
          <p style="margin: 4px 0 0; font-size: 0.85rem; color: var(--gray);">
            Your last name + the date you submitted your request, e.g. <strong>JOHNSON-07202026</strong>
          </p>
          <div style="display: flex; gap: 12px; margin-top: 8px;">
            <input type="text" id="refCode" placeholder="e.g. JOHNSON-07202026" required style="flex: 1; padding: 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem;">
            <button type="submit" class="btn-primary" style="background: var(--secondary); color: var(--text); padding: 14px 28px; white-space: nowrap;">Track Status</button>
          </div>
        </div>
      </form>

      <!-- Error message -->
      <div id="trackError" style="display: none; margin-top: 20px; padding: 14px 18px; background: #FEE2E2; color: #991B1B; border-radius: 10px; font-size: 0.95rem;"></div>

      <!-- Status Display Card(s) -- filled in by JS from track.php's JSON response -->
      <div id="statusResult" style="display: none;"></div>
    </div>
  </main>

  <script>
    async function handleTrack(e) {
        e.preventDefault();

        const codeInput = document.getElementById('refCode');
        const code = codeInput.value.trim();
        const resultBox = document.getElementById('statusResult');
        const errorBox = document.getElementById('trackError');
        const submitBtn = e.target.querySelector('button[type="submit"]');

        if (!code) return;

        errorBox.style.display = 'none';
        resultBox.style.display = 'none';
        resultBox.innerHTML = '';
        submitBtn.disabled = true;
        const originalLabel = submitBtn.textContent;
        submitBtn.textContent = 'Checking...';

        try {
            const response = await fetch('track.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ref_code=' + encodeURIComponent(code)
            });

            if (!response.ok) {
                throw new Error(`Server returned ${response.status}`);
            }

            const data = await response.json();

            if (data.status !== 'success') {
                errorBox.textContent = data.message || 'No matching request found.';
                errorBox.style.display = 'block';
                return;
            }

            resultBox.innerHTML = data.requests.map(buildResultCard).join('');
            resultBox.style.display = 'block';
        } catch (err) {
            console.error('Tracker error:', err);
            errorBox.textContent = 'Something went wrong while checking your request. Please try again.';
            errorBox.style.display = 'block';
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = originalLabel;
        }
    }

    const STATUS_BADGE_COLORS = {
        pending:       { bg: '#FEF3C7', color: '#92400E' },
        processing:    { bg: '#CFFAFE', color: '#155E75' },
        for_signature: { bg: '#DBEAFE', color: '#1E40AF' },
        for_release:   { bg: '#E5E7EB', color: '#374151' },
        released:      { bg: '#D1FAE5', color: '#065F46' }
    };

    function buildResultCard(r) {
        const badge = STATUS_BADGE_COLORS[r.status] || STATUS_BADGE_COLORS.pending;
        const stepLabels = ['Submitted', 'Processing', 'Ready for Pickup'];

        const stepsHtml = stepLabels.map((label, i) => {
            const n = i + 1;
            const done = n < r.step || (n === r.step && r.released);
            const active = n === r.step && !r.released;

            const circleBg = done ? 'var(--primary)' : (active ? 'var(--secondary)' : 'var(--border)');
            const circleColor = done ? 'white' : (active ? 'var(--text)' : 'var(--gray)');
            const content = done ? '✓' : String(n);
            const textColor = done ? 'var(--primary)' : (active ? 'var(--text)' : 'var(--gray)');
            const textWeight = active ? 700 : 500;

            return `
              <div style="flex: 1;">
                <div style="width: 32px; height: 32px; background: ${circleBg}; color: ${circleColor}; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 8px; font-weight: bold; font-size: 0.85rem;">${content}</div>
                <p style="font-size: 0.8rem; font-weight: ${textWeight}; color: ${textColor};">${escapeHtml(label)}</p>
              </div>`;
        }).join('');

        return `
          <div style="border-top: 2px dashed var(--border); padding-top: 25px; margin-top: 25px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
              <div>
                <span style="font-size: 0.85rem; color: var(--gray); text-transform: uppercase; font-weight: 700;">Document</span>
                <h3 style="color: var(--primary); margin: 0;">${escapeHtml(r.document)}</h3>
              </div>
              <span style="background: ${badge.bg}; color: ${badge.color}; padding: 8px 16px; border-radius: 999px; font-weight: 700; font-size: 0.9rem;">${escapeHtml(r.status_label)}</span>
            </div>
            <div class="summary" style="margin-bottom: 25px;">
              <p><strong>Student Name:</strong> ${escapeHtml(r.student_name)}</p>
              <p><strong>Student No.:</strong> ${escapeHtml(r.student_number || 'N/A')}</p>
              <p><strong>Date Requested:</strong> ${escapeHtml(r.date_requested || 'N/A')}</p>
              <p><strong>Est. Completion:</strong> ${escapeHtml(r.est_completion)}</p>
              ${r.released_at ? `<p><strong>Released On:</strong> ${escapeHtml(r.released_at)}</p>` : ''}
            </div>
            <div style="display: flex; justify-content: space-between; position: relative; margin-top: 30px; text-align: center;">
              ${stepsHtml}
            </div>
          </div>`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    }
  </script>
</body>
</html>