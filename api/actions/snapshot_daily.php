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
    $pdo = db();
    $today = date('Y-m-d');
    
    // Define metrics to collect
    $metrics = [];
    
    // 1. Total equipment count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM equipment");
    $metrics[] = ['metric_key' => 'total_equipment', 'value' => $stmt->fetch()['count']];
    
    // 2. Available equipment count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM equipment WHERE status = 'available'");
    $metrics[] = ['metric_key' => 'available_equipment', 'value' => $stmt->fetch()['count']];
    
    // 3. Allocated equipment count
    $stmt = $pdo->query("SELECT COUNT(DISTINCT equipment_id) as count FROM allocations WHERE checkout_date <= CURRENT_DATE AND (expected_return_date IS NULL OR expected_return_date >= CURRENT_DATE)");
    $metrics[] = ['metric_key' => 'allocated_equipment', 'value' => $stmt->fetch()['count']];
    
    // 4. Under maintenance count
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM equipment WHERE status = 'maintenance'");
    $metrics[] = ['metric_key' => 'maintenance_equipment', 'value' => $stmt->fetch()['count']];
    
    // 5. Total allocations (all time)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM allocations");
    $metrics[] = ['metric_key' => 'total_allocations', 'value' => $stmt->fetch()['count']];
    
    // 6. Pending requests
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM equipment_requests WHERE status = 'pending'");
    $metrics[] = ['metric_key' => 'pending_requests', 'value' => $stmt->fetch()['count']];
    
    // 7. Approved requests (today)
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM equipment_requests WHERE status = 'allocated' AND DATE(reviewed_at) = CURRENT_DATE");
    $metrics[] = ['metric_key' => 'approved_requests_today', 'value' => $stmt->fetch()['count']];
    
    // 8. Total maintenance logs completed
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM maintenance_logs WHERE status = 'completed'");
    $metrics[] = ['metric_key' => 'completed_maintenance', 'value' => $stmt->fetch()['count']];
    
    // 9. Total maintenance cost (sum)
    $stmt = $pdo->query("SELECT COALESCE(SUM(cost), 0) as total FROM maintenance_logs WHERE status = 'completed'");
    $metrics[] = ['metric_key' => 'total_maintenance_cost', 'value' => $stmt->fetch()['total']];
    
    // 10. Equipment by category
    $stmt = $pdo->query("SELECT category, COUNT(*) as count FROM equipment GROUP BY category");
    $categories = $stmt->fetchAll();
    foreach ($categories as $cat) {
        $metrics[] = [
            'metric_key' => 'equipment_by_category_' . strtolower(str_replace(' ', '_', $cat['category'])),
            'category' => $cat['category'],
            'value' => $cat['count'],
        ];
    }
    
    // Insert all metrics for today
    $insertStmt = $pdo->prepare(
        'INSERT INTO report_snapshots (snapshot_date, category, metric_key, metric_value)
         VALUES (:date, :category, :key, :value)
         ON CONFLICT (snapshot_date, category, metric_key) DO UPDATE SET metric_value = :value'
    );
    
    $inserted = 0;
    foreach ($metrics as $metric) {
        $insertStmt->execute([
            ':date' => $today,
            ':category' => $metric['category'] ?? null,
            ':key' => $metric['metric_key'],
            ':value' => $metric['value'],
        ]);
        $inserted++;
    }
    
    $message = "Captured $inserted metrics for $today";
    
    if (isset($_GET['json']) || isset($_POST['json'])) {
        // Return JSON for cron jobs
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $message,
            'metrics_captured' => $inserted,
            'snapshot_date' => $today,
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
