<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

// This endpoint can be called by:
// 1. Admin user from the notification logs page
// 2. External cron service
// 3. Manual trigger

// Allow either admin user OR simple token verification for cron
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
    $results = NotificationService::getInstance()->sendOverdueAlerts();
    
    $message = 'Checked overdue allocations. Sent ' . count($results) . ' notifications.';
    
    if (isset($_GET['json']) || isset($_POST['json'])) {
        // Return JSON for cron jobs
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'notifications_sent' => count($results),
            'details' => $results,
        ]);
    } else {
        // Redirect for admin UI
        redirect_to('/api/notification_logs.php', ['ok' => $message]);
    }
} catch (Throwable $e) {
    error_log('Overdue check failed: ' . $e->getMessage());
    
    if (isset($_GET['json']) || isset($_POST['json'])) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
        ]);
    } else {
        redirect_to('/api/notification_logs.php', ['error' => 'Failed to check overdue items: ' . $e->getMessage()]);
    }
}
