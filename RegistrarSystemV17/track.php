<?php
/**
 * track.php - public document-request tracker (no login required)
 *
 * Visitors look up the status of their request using the
 * CVSU-REGXXXXXXXX reference returned after submission. Legacy ONL-
 * and older CVSU-NAIC-REG###### references remain
 * supported. Online requests use online_requests while staff review them;
 * admin-created requests use the requests row directly.
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
        'disapproved'   => 'Disapproved',
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

  function extract_request_purpose(?string $description): ?string
  {
    if (!$description || !preg_match('/^Purpose:\s*(.+)$/mi', $description, $matches)) {
      return null;
    }

    return trim($matches[1]);
  }

/**
 * Resolve a tracking reference and return its current status.
 *
 * @return array{status: string, message?: string, requests?: array}
 */
function track_lookup(mysqli $conn, string $ref_code): array
{
    $ref_code = trim($ref_code);

    $is_legacy_reference = preg_match('/^ONL-(\d+)$/i', $ref_code, $legacy_match);
    $is_current_reference = preg_match('/^CVSU-(?:NAIC-REG\d{6}|REG[A-Z0-9]{8})$/i', $ref_code);
    if (!$is_legacy_reference && !$is_current_reference) {
        return [
            'status'  => 'error',
        'message' => 'Please enter the tracking reference shown after you submitted your request.',
        ];
    }

    if ($is_legacy_reference) {
      $stmt = $conn->prepare(
        'SELECT o.title, o.purpose, o.disapproval_reason, o.status AS online_status, o.student_number, o.student_name,
          o.created_at, r.status AS request_status, r.claiming_date, r.released_at, r.description
         FROM online_requests o
         LEFT JOIN requests r ON r.id = o.approved_request_id
         WHERE o.id = ?'
      );
      $legacy_id = (int)$legacy_match[1];
      $stmt->bind_param('i', $legacy_id);
    } else {
      $stmt = $conn->prepare(
        'SELECT o.title, o.purpose, o.disapproval_reason, o.status AS online_status, o.student_number, o.student_name,
          o.created_at, r.status AS request_status, r.claiming_date, r.released_at, r.description
         FROM online_requests o
         LEFT JOIN requests r ON r.id = o.approved_request_id
         WHERE o.tracking_code = ?'
      );
      $stmt->bind_param('s', $ref_code);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $rows = [];
    if ($row) {
        // Online request still in staging (or approved): one row per code.
        $rows[] = $row;
    } elseif ($is_current_reference) {
        // Admin-created requests: several rows can share one tracking code.
        $stmt = $conn->prepare(
            'SELECT r.title, r.status AS request_status, r.student_number, r.student_name,
              r.created_at, r.claiming_date, r.released_at, r.description
             FROM requests r
             WHERE r.tracking_code = ?
             ORDER BY r.id ASC'
        );
        $stmt->bind_param('s', $ref_code);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
    }

    if (!$rows) {
        return [
            'status'  => 'error',
            'message' => 'No matching request found. Please check your reference code and try again.',
        ];
    }

    $requests = [];
    foreach ($rows as $row) {
        $status  = $row['request_status'] ?: $row['online_status'];
        $meta    = track_status_meta($status);
        $purpose = $row['purpose'] ?? extract_request_purpose($row['description'] ?? null);
        $requests[] = [
            'tracking_code'      => $ref_code,
            'document'           => $row['title'],
            'status'             => $status,
            'status_label'       => $meta['label'],
            'step'               => $meta['step'],
            'released'           => $meta['released'],
            'student_name'       => $row['student_name'],
            'student_number'     => $row['student_number'],
            'purpose'            => $purpose,
            'disapproval_reason' => $row['disapproval_reason'] ?? null,
            'date_requested'     => !empty($row['created_at']) ? date('F j, Y', strtotime($row['created_at'])) : null,
            'est_completion'     => !empty($row['claiming_date']) ? date('F j, Y', strtotime($row['claiming_date'])) : 'Not yet scheduled',
            'released_at'        => !empty($row['released_at']) ? date('F j, Y', strtotime($row['released_at'])) : null,
        ];
    }

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
  <link rel="stylesheet" href="assets/css/variables.css?v=20260921">
  <link rel="stylesheet" href="assets/css/style.css?v=20260921">
  <link rel="stylesheet" href="assets/css/request.css?v=20260921">
  <style>
    /* Mobile-friendly tracker */
    @media (max-width: 576px) {
      .track-search { flex-direction: column !important; }
      .track-search .btn-primary { width: 100%; }
      .page-title h1 { font-size: 1.8rem; }
      #statusResult .summary p { font-size: 0.9rem; }
    }
  </style>
</head>

<body style="background: var(--background);">
  <header style="position: relative; box-shadow: none;">
    <nav>
      <div class="container nav">
        <a href="index.php" class="logo">
          <img src="assets/images/logo.png" alt="Logo">
          <span>CvSU Naic Registrar</span>
        </a>
        <a href="index.php" class="back">← Back</a>
      </div>
    </nav>
  </header>

  <main class="container" style="max-width: 700px; margin: 40px auto 80px;">
    <div class="page-title">
      <h1>Document Request Tracker</h1>
          <p>Enter the tracking reference shown after you submitted your application.</p>
    </div>

    <!-- Search Card -->
    <div style="background: white; padding: 35px; border-radius: var(--radius); box-shadow: var(--shadow);">
      <form id="trackerForm" onsubmit="handleTrack(event)">
        <div class="input-group">
          <label style="font-weight: 600; font-size: 1rem;">Reference Control Code</label>
          <p style="margin: 4px 0 0; font-size: 0.85rem; color: var(--gray);">
            Example: <strong>CVSU-REGaB3xY7pQ</strong>
          </p>
          <div class="track-search" style="display: flex; gap: 12px; margin-top: 8px;">
            <input type="text" id="refCode" placeholder="e.g. CVSU-REGaB3xY7pQ" required style="flex: 1; padding: 14px; border: 1px solid var(--border); border-radius: 10px; font-size: 1rem;">
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
        released:      { bg: '#D1FAE5', color: '#065F46' },
        disapproved:   { bg: '#FEE2E2', color: '#991B1B' }
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
            <div style="display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 20px; padding: 14px 16px; background: var(--background); border-radius: 10px;">
              <div>
                <span style="display: block; font-size: 0.8rem; color: var(--gray); text-transform: uppercase; font-weight: 700;">Tracking Reference</span>
                <strong style="display: block; margin-top: 3px; color: var(--primary); font-size: 1.35rem; font-weight: 800;">${escapeHtml(r.tracking_code)}</strong>
              </div>
              <button type="button" class="btn-primary" data-tracking-code="${escapeHtml(r.tracking_code)}" onclick="copyTrackingCode(this)" style="background: var(--secondary); color: var(--text); padding: 10px 16px; white-space: nowrap;">Copy</button>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 20px;">
              <div>
                <span style="font-size: 0.85rem; color: var(--gray); text-transform: uppercase; font-weight: 700;">Document</span>
                <h3 style="color: var(--primary); margin: 0;">${escapeHtml(r.document)}</h3>
              </div>
              <span style="background: ${badge.bg}; color: ${badge.color}; padding: 8px 16px; border-radius: 999px; font-weight: 700; font-size: 0.9rem;">${escapeHtml(r.status_label)}</span>
            </div>
            <div class="summary" style="margin-bottom: 25px;">
              <p><strong>Student Name:</strong> ${escapeHtml(r.student_name)}</p>
              <p><strong>Student No.:</strong> ${escapeHtml(r.student_number || 'N/A')}</p>
              <p><strong>Purpose:</strong> ${escapeHtml(r.purpose || 'N/A')}</p>
              <p><strong>Date Requested:</strong> ${escapeHtml(r.date_requested || 'N/A')}</p>
              <p><strong>Est. Completion:</strong> ${escapeHtml(r.est_completion)}</p>
              ${r.released_at ? `<p><strong>Released On:</strong> ${escapeHtml(r.released_at)}</p>` : ''}
            </div>
            ${r.disapproval_reason ? `<div style="margin-top: -8px; margin-bottom: 25px; padding: 12px 14px; background: #FEE2E2; color: #991B1B; border-radius: 8px;"><strong>Reason for Disapproval:</strong> ${escapeHtml(r.disapproval_reason)}</div>` : ''}
            <div style="display: flex; justify-content: space-between; position: relative; margin-top: 30px; text-align: center;">
              ${stepsHtml}
            </div>
          </div>`;
    }

        async function copyTrackingCode(button) {
          const code = button.dataset.trackingCode;
          const originalLabel = button.textContent;

          try {
            await navigator.clipboard.writeText(code);
            button.textContent = 'Copied';
          } catch (err) {
            const fallbackInput = document.createElement('input');
            fallbackInput.value = code;
            document.body.appendChild(fallbackInput);
            fallbackInput.select();
            document.execCommand('copy');
            fallbackInput.remove();
            button.textContent = 'Copied';
          }

          setTimeout(() => {
            button.textContent = originalLabel;
          }, 1500);
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