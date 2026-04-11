<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser = require_login();

// Read ID — try query string first, then POST, then REQUEST
$maintenanceId = 0;
if (isset($_GET['id']))     $maintenanceId = (int) $_GET['id'];
if ($maintenanceId <= 0 && isset($_POST['id']))    $maintenanceId = (int) $_POST['id'];
if ($maintenanceId <= 0 && isset($_REQUEST['id'])) $maintenanceId = (int) $_REQUEST['id'];

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

$pdo = db();
$pdo->beginTransaction();

$equipmentId = 0;

try {
    // 1. Cancel the log
    $stmt = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'cancelled'
         WHERE id = :mid AND status = 'scheduled'
         RETURNING equipment_id"
    );
    $stmt->execute([':mid' => $maintenanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        redirect_to('/api/maintenance.php', ['error' => 'Log not found or already completed/cancelled']);
    }

    $equipmentId = (int) $row['equipment_id'];

    // 2. Check if any other scheduled logs remain for this equipment
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM maintenance_logs
         WHERE equipment_id = :eid AND status = 'scheduled'"
    );
    $countStmt->execute([':eid' => $equipmentId]);
    $remaining = (int) $countStmt->fetchColumn();

    // 3. Restore equipment status only if no other active maintenance
    if ($remaining === 0) {
        $pdo->prepare(
            "UPDATE equipment
             SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
                 next_maintenance_date = NULL,
                 updated_at = NOW()
             WHERE id = :eid AND status = 'maintenance'"
        )->execute([':eid' => $equipmentId]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_cancel error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Failed to cancel: ' . $e->getMessage()]);
}

// ── After commit: audit (non-critical) ──────────────────────────────────────
log_audit('cancel', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
    ['status' => 'scheduled'],
    ['status' => 'cancelled']
);

redirect_to('/api/maintenance.php', ['ok' => 'Maintenance task cancelled']);
