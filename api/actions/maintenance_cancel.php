<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser = require_login();

// Read ID
$maintenanceId = 0;
if (isset($_GET['id']))     $maintenanceId = (int) $_GET['id'];
if ($maintenanceId <= 0 && isset($_POST['id']))    $maintenanceId = (int) $_POST['id'];
if ($maintenanceId <= 0 && isset($_REQUEST['id'])) $maintenanceId = (int) $_REQUEST['id'];

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

$pdo = db();

// 1. Cancel the log — any status that is 'scheduled' (regardless of date)
try {
    $stmt = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'cancelled'
         WHERE id = :mid AND status = 'scheduled'
         RETURNING equipment_id"
    );
    $stmt->execute([':mid' => $maintenanceId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('maintenance_cancel UPDATE error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Cancel failed: ' . $e->getMessage()]);
}

if (!$row) {
    redirect_to('/api/maintenance.php', ['error' => 'Task not found or not in scheduled status']);
}

$equipmentId = (int) $row['equipment_id'];

// 2. Check remaining scheduled logs
$remaining = 0;
try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM maintenance_logs WHERE equipment_id = :eid AND status = 'scheduled'"
    );
    $countStmt->execute([':eid' => $equipmentId]);
    $remaining = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('maintenance_cancel COUNT error: ' . $e->getMessage());
}

// 3. Restore equipment if no other scheduled tasks
if ($remaining === 0) {
    try {
        $pdo->prepare(
            "UPDATE equipment
             SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
                 next_maintenance_date = NULL,
                 updated_at = NOW()
             WHERE id = :eid AND status = 'maintenance'"
        )->execute([':eid' => $equipmentId]);
    } catch (Throwable $e) {
        error_log('maintenance_cancel UPDATE equipment error: ' . $e->getMessage());
    }
}

// 4. Audit
log_audit('cancel', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
    ['status' => 'scheduled'],
    ['status' => 'cancelled']
);

redirect_to('/api/maintenance.php', ['ok' => 'Maintenance task cancelled']);
