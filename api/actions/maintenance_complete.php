<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser = require_login();

// Read ID from query string or POST
$maintenanceId = 0;
if (isset($_GET['id']))     $maintenanceId = (int) $_GET['id'];
if ($maintenanceId <= 0 && isset($_POST['id']))    $maintenanceId = (int) $_POST['id'];
if ($maintenanceId <= 0 && isset($_REQUEST['id'])) $maintenanceId = (int) $_REQUEST['id'];

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

// Completion data
$workDone         = isset($_POST['work_done'])          ? trim((string) $_POST['work_done'])          : '';
$partsReplaced    = isset($_POST['parts_replaced'])     ? trim((string) $_POST['parts_replaced'])     : '';
$nextScheduleDate = isset($_POST['next_schedule_date']) ? trim((string) $_POST['next_schedule_date']) : '';
$costRaw          = isset($_POST['cost'])               ? trim((string) $_POST['cost'])               : '';

$completionNotes = '';
if ($workDone !== '')      $completionNotes .= 'Work done: ' . $workDone;
if ($partsReplaced !== '') $completionNotes .= ($completionNotes !== '' ? ' | ' : '') . 'Parts replaced: ' . $partsReplaced;

$pdo = db();

// 1. Mark log as completed — get equipment_id back
try {
    $stmt = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'completed', completed_date = CURRENT_DATE
         WHERE id = :mid AND status = 'scheduled'
         RETURNING equipment_id, cost"
    );
    $stmt->execute([':mid' => $maintenanceId]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    error_log('maintenance_complete UPDATE status error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Complete failed: ' . $e->getMessage()]);
}

if (!$row) {
    redirect_to('/api/maintenance.php', ['error' => 'Log not found or already completed']);
}

$equipmentId = (int) $row['equipment_id'];
$finalCost   = $row['cost'];

// 2. Update notes if provided
if ($completionNotes !== '') {
    try {
        $pdo->prepare("UPDATE maintenance_logs SET notes = :notes WHERE id = :mid")
            ->execute([':notes' => $completionNotes, ':mid' => $maintenanceId]);
    } catch (Throwable $e) {
        error_log('maintenance_complete UPDATE notes error: ' . $e->getMessage());
    }
}

// 3. Update cost if provided
if ($costRaw !== '') {
    try {
        $pdo->prepare("UPDATE maintenance_logs SET cost = :cost WHERE id = :mid")
            ->execute([':cost' => (float) $costRaw, ':mid' => $maintenanceId]);
        $finalCost = (float) $costRaw;
    } catch (Throwable $e) {
        error_log('maintenance_complete UPDATE cost error: ' . $e->getMessage());
    }
}

// 4. Restore equipment status
$nextMaint = ($nextScheduleDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextScheduleDate))
    ? $nextScheduleDate : null;
try {
    $pdo->prepare(
        "UPDATE equipment
         SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
             next_maintenance_date = :next_maint,
             updated_at = NOW()
         WHERE id = :eid AND status <> 'retired'"
    )->execute([':next_maint' => $nextMaint, ':eid' => $equipmentId]);
} catch (Throwable $e) {
    error_log('maintenance_complete UPDATE equipment error: ' . $e->getMessage());
}

// 5. Equipment name
$equipName = 'Unknown';
try {
    $eqStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :eid');
    $eqStmt->execute([':eid' => $equipmentId]);
    $equipName = (string) ($eqStmt->fetchColumn() ?: 'Unknown');
} catch (Throwable $e) { /* non-fatal */ }

// 6. Audit
log_audit('complete', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
    ['status' => 'scheduled'],
    [
        'status'         => 'completed',
        'completed_date' => date('Y-m-d'),
        'equipment_id'   => $equipmentId,
        'equipment_name' => $equipName,
        'work_done'      => $workDone,
        'parts_replaced' => $partsReplaced,
        'next_schedule'  => $nextScheduleDate,
    ]
);

// 7. Notify admins
try {
    $ns = NotificationService::getInstance();
    foreach ($ns->getAdminsEmails() as $adminId => $adminEmail) {
        $ns->send('maintenance_completed', $adminEmail, (int) $adminId, [
            'equipment_name' => $equipName,
            'completed_date' => date('Y-m-d'),
            'work_done'      => $workDone ?: 'Not specified',
            'parts_replaced' => $partsReplaced ?: 'None',
            'cost'           => $finalCost !== null ? '₱' . number_format((float) $finalCost, 2) : 'Not specified',
        ]);
    }
} catch (Throwable $e) {
    error_log('maintenance_complete notification error: ' . $e->getMessage());
}

redirect_to('/api/maintenance.php', ['ok' => 'Maintenance marked complete']);
