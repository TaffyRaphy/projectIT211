<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser = require_login();

// Read ID from query string (works for both GET and POST with ?id=X in action URL)
$maintenanceId = 0;
if (isset($_GET['id']))  $maintenanceId = (int) $_GET['id'];
if ($maintenanceId <= 0 && isset($_POST['id'])) $maintenanceId = (int) $_POST['id'];
if ($maintenanceId <= 0 && isset($_REQUEST['id'])) $maintenanceId = (int) $_REQUEST['id'];

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

// ── Completion data from POST ───────────────────────────────────────────────
$workDone         = isset($_POST['work_done'])         ? trim((string) $_POST['work_done'])         : '';
$partsReplaced    = isset($_POST['parts_replaced'])    ? trim((string) $_POST['parts_replaced'])    : '';
$nextScheduleDate = isset($_POST['next_schedule_date']) ? trim((string) $_POST['next_schedule_date']) : '';
$costRaw          = isset($_POST['cost'])              ? trim((string) $_POST['cost'])              : '';

// Build completion notes string
$completionNotes = '';
if ($workDone !== '')       $completionNotes .= 'Work done: ' . $workDone;
if ($partsReplaced !== '')  $completionNotes .= ($completionNotes !== '' ? ' | ' : '') . 'Parts replaced: ' . $partsReplaced;

$pdo = db();
$pdo->beginTransaction();

$equipmentId = 0;
$equipName   = 'Unknown';
$finalCost   = null;

try {
    // 1. Mark log as completed
    $stmt = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'completed', completed_date = CURRENT_DATE
         WHERE id = :mid AND status = 'scheduled'
         RETURNING equipment_id, cost"
    );
    $stmt->execute([':mid' => $maintenanceId]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        redirect_to('/api/maintenance.php', ['error' => 'Log not found or already completed']);
    }

    $equipmentId = (int) $row['equipment_id'];
    $finalCost   = $row['cost'];

    // 2. Update notes if provided
    if ($completionNotes !== '') {
        $pdo->prepare(
            "UPDATE maintenance_logs SET notes = :notes WHERE id = :mid"
        )->execute([':notes' => $completionNotes, ':mid' => $maintenanceId]);
    }

    // 3. Update cost if provided
    if ($costRaw !== '') {
        $pdo->prepare(
            "UPDATE maintenance_logs SET cost = :cost WHERE id = :mid"
        )->execute([':cost' => (float) $costRaw, ':mid' => $maintenanceId]);
        $finalCost = (float) $costRaw;
    }

    // 4. Restore equipment status
    $nextMaint = ($nextScheduleDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextScheduleDate))
        ? $nextScheduleDate
        : null;

    $pdo->prepare(
        "UPDATE equipment
         SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
             next_maintenance_date = :next_maint,
             updated_at = NOW()
         WHERE id = :eid AND status <> 'retired'"
    )->execute([':next_maint' => $nextMaint, ':eid' => $equipmentId]);

    // 5. Get equipment name
    $eqStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :eid');
    $eqStmt->execute([':eid' => $equipmentId]);
    $equipName = (string) ($eqStmt->fetchColumn() ?: 'Unknown');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_complete error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Failed to complete: ' . $e->getMessage()]);
}

// ── After commit: audit + notifications ─────────────────────────────────────
log_audit('complete', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
    ['status' => 'scheduled'],
    [
        'status'          => 'completed',
        'completed_date'  => date('Y-m-d'),
        'equipment_id'    => $equipmentId,
        'equipment_name'  => $equipName,
        'work_done'       => $workDone,
        'parts_replaced'  => $partsReplaced,
        'next_schedule'   => $nextScheduleDate,
    ]
);

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
