<?php
/**
 * Request workflow service helpers.
 *
 * This file centralizes reusable request-related constants and helper
 * functions that were previously duplicated across the request pages.
 *
 * Constants:
 * - DOCUMENT_LABELS: maps document keys to display labels.
 * - ALLOWED_STATUSES: whitelist of valid request lifecycle statuses.
 *
 * Functions:
 * - requestsRedirectUrl(): rebuilds the current page URL with query params.
 * - stmtExecuteWithParams(): safely executes prepared statements with
 *   dynamic parameter binding.
 * - logAudit(): inserts an audit trail record for request actions.
 * - getHeaderUrl(): generates sortable table header URLs.
 */

declare(strict_types=1);

const DOCUMENT_LABELS = [
    'tor' => 'Transcript of Record (TOR)',
    'diploma' => 'Diploma',
    'cog' => 'Certificate of Grades (COG)',
    'coe' => 'Certificate of Enrollment (COE)',
    'form_137a' => 'Form 137A',
    'cav' => 'Certification Authentication and Verification (CAV)',
];

const ALLOWED_STATUSES = ['pending', 'processing', 'for_signature', 'for_release', 'released'];

function requestsRedirectUrl(string $extra = ''): string
{
    $params = [];
    if (isset($_GET['page'])) {
        $params[] = 'page=' . urlencode((string) $_GET['page']);
    }
    if (isset($_GET['view'])) {
        $params[] = 'view=' . urlencode((string) $_GET['view']);
    }
    if ($extra !== '') {
        $params[] = $extra;
    }

    return $_SERVER['PHP_SELF'] . ($params ? '?' . implode('&', $params) : '');
}

function stmtExecuteWithParams(mysqli_stmt $stmt, string $types, array $params): bool
{
    if ($types !== '') {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        array_unshift($refs, $types);
        call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    return $stmt->execute();
}

function logAudit(mysqli $conn, int $userId, string $action, string $details): void
{
    $stmt = $conn->prepare('INSERT INTO audit_trail (user_id, action, details) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $userId, $action, $details);
    $stmt->execute();
    $stmt->close();
}

function getHeaderUrl(string $columnName, string $currentSortColumn, string $nextOrder): string
{
    $query = $_GET;
    $query['sort'] = $columnName;
    $query['order'] = ($currentSortColumn === $columnName) ? $nextOrder : 'asc';

    return $_SERVER['PHP_SELF'] . '?' . http_build_query($query);
}
