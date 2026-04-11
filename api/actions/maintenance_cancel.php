<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser   = require_login();
$maintenanceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $updateLog = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'cancelled'
         WHERE id = :id AND status = 'scheduled'
         RETURNING equipment_id"
    );
    $updateLog->execute(['id' => $maintenanceId]);
    $row = $updateLog->fetch();
    if (!$row) {
        $pdo->rollBack();
        redirect_to('/api/maintenance.php', ['error' => 'Log not found or already completed/cancelled']);
    }

    $equipmentId = (int) $row['equipment_id'];

    // Check if any other scheduled logs still exist for this equipment
    $remainingStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM maintenance_logs WHERE equipment_id = :eid AND status = 'scheduled'"
    );
    $remainingStmt->execute(['eid' => $equipmentId]);
    $remaining = (int) $remainingStmt->fetchColumn();

    // Restore equipment status only if no other active maintenance logs
    if ($remaining === 0) {
        $pdo->prepare(
            "UPDATE equipment
             SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
                 next_maintenance_date = NULL,
                 updated_at = NOW()
             WHERE id = :equipment_id AND status = 'maintenance'"
        )->execute(['equipment_id' => $equipmentId]);
    }

    log_audit('cancel', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
        ['status' => 'scheduled'],
        ['status' => 'cancelled']
    );

    $pdo->commit();
    redirect_to('/api/maintenance.php', ['ok' => 'Maintenance task cancelled']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_cancel error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'DB error: ' . $e->getMessage()]);
}
