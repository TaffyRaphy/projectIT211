<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

// This endpoint runs daily to populate report snapshots
// Can be called by:
// 1. Admin user manually
// 2. External cron service
// 3. Scheduled task

// Allow either admin user OR token verification for cron
$adminUser = current_user();
$isAdmin = $adminUser && $adminUser['role'] === 'admin';

// Simple token-based access for cron jobs
$croneToken = getenv('CRON_TOKEN');
$providedToken = $_GET['token'] ?? $_POST['token'] ?? '';
$isValidToken = $croneToken && $croneToken !== '' && hash_equals($croneToken, $providedToken);

if (!$isAdmin && !$isValidToken) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $message = 'Historical snapshots are not enabled in this schema. No data was written.';
    
    if (isset($_GET['json']) || isset($_POST['json'])) {
        // Return JSON for cron jobs
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'metrics_captured' => 0,
            'snapshot_date' => date('Y-m-d'),
        ]);
    } else {
        // Redirect for admin UI
        redirect_to('/api/reports.php', ['ok' => $message]);
    }
} catch (Throwable $e) {
    error_log('Snapshot daily failed: ' . $e->getMessage());
    
    if (isset($_GET['json']) || isset($_POST['json'])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    } else {
        redirect_to('/api/reports.php', ['error' => 'Failed to capture metrics: ' . $e->getMessage()]);
    }
}
