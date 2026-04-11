<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Allow admin user OR cron token
$adminUser    = current_user();
$isAdmin      = $adminUser && $adminUser['role'] === 'admin';
$cronToken    = getenv('CRON_TOKEN');
$providedToken= $_GET['token'] ?? $_POST['token'] ?? '';
$isValidToken = $cronToken && $cronToken !== '' && hash_equals($cronToken, $providedToken);

if (!$isAdmin && !$isValidToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $actorId = $adminUser ? (int) $adminUser['id'] : 0;

    // Gather current metrics
    $metrics = [];

    $row = db()->query("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status='available') AS available, COUNT(*) FILTER (WHERE status='allocated') AS allocated, COUNT(*) FILTER (WHERE status='maintenance') AS maintenance FROM equipment")->fetch();
    $metrics['equipment_total']       = (int) ($row['total']       ?? 0);
    $metrics['equipment_available']   = (int) ($row['available']   ?? 0);
    $metrics['equipment_allocated']   = (int) ($row['allocated']   ?? 0);
    $metrics['equipment_maintenance'] = (int) ($row['maintenance'] ?? 0);

    $row = db()->query("SELECT COUNT(*) FILTER (WHERE status='pending') AS pending, COUNT(*) FILTER (WHERE status='allocated') AS approved FROM equipment_requests")->fetch();
    $metrics['requests_pending']      = (int) ($row['pending']  ?? 0);
    $metrics['requests_approved']     = (int) ($row['approved'] ?? 0);

    $row = db()->query("SELECT COUNT(*) FILTER (WHERE status='scheduled') AS scheduled, COUNT(*) FILTER (WHERE status='completed') AS completed FROM maintenance_logs")->fetch();
    $metrics['maintenance_scheduled'] = (int) ($row['scheduled'] ?? 0);
    $metrics['maintenance_completed'] = (int) ($row['completed'] ?? 0);

    $row = db()->query("SELECT COUNT(*) AS overdue FROM allocations WHERE expected_return_date < CURRENT_DATE AND expected_return_date IS NOT NULL AND status = 'active'")->fetch();
    $metrics['allocations_overdue']   = (int) ($row['overdue'] ?? 0);

    $metrics['snapshot_date']         = date('Y-m-d');
    $metrics['snapshot_time']         = date('Y-m-d H:i:s');

    // Write to audit_logs as a snapshot record (record_id=0 = system-wide)
    if ($actorId > 0) {
        log_audit('snapshot', 'equipment', 0, $actorId, null, $metrics);
    }

    $message = "Snapshot captured for " . date('Y-m-d') . ": {$metrics['equipment_total']} equipment, {$metrics['requests_pending']} pending requests, {$metrics['allocations_overdue']} overdue.";

    if (isset($_GET['json']) || isset($_POST['json'])) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => $message, 'metrics' => $metrics]);
    } else {
        redirect_to('/api/reports.php', ['ok' => $message]);
    }
} catch (Throwable $e) {
    error_log('Snapshot daily failed: ' . $e->getMessage());
    if (isset($_GET['json']) || isset($_POST['json'])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    } else {
        redirect_to('/api/reports.php', ['error' => 'Failed to capture metrics: ' . $e->getMessage()]);
    }
}
