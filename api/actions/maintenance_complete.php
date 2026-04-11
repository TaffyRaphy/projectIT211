<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser   = require_login();
$maintenanceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Completion details from POST
$workDone        = isset($_POST['work_done'])        ? trim((string) $_POST['work_done'])        : '';
$partsReplaced   = isset($_POST['parts_replaced'])   ? trim((string) $_POST['parts_replaced'])   : '';
$nextScheduleDate= isset($_POST['next_schedule_date'])? trim((string) $_POST['next_schedule_date']): '';
$completionCost  = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float) $_POST['cost'] : null;

// Build completion notes
$completionNotes = '';
if ($workDone !== '')      $completionNotes .= 'Work done: ' . $workDone;
if ($partsReplaced !== '')  $completionNotes .= ($completionNotes !== '' ? ' | ' : '') . 'Parts replaced: ' . $partsReplaced;

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Update log to completed
    $updateLog = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status         = 'completed',
             completed_date = CURRENT_DATE,
             notes          = CASE WHEN :completion_notes <> '' THEN :completion_notes2 ELSE notes END,
             cost           = CASE WHEN :cost_set IS NOT NULL THEN :completion_cost ELSE cost END
         WHERE id = :id AND status = 'scheduled'
         RETURNING equipment_id, cost, maintenance_type, schedule_date"
    );
    $updateLog->execute([
        'completion_notes'  => $completionNotes,
        'completion_notes2' => $completionNotes,
        'cost_set'          => $completionCost,
        'completion_cost'   => $completionCost,
        'id'                => $maintenanceId,
    ]);
    $row = $updateLog->fetch();
    if (!$row) {
        $pdo->rollBack();
        redirect_to('/api/maintenance.php', ['error' => 'Log not found or already completed']);
    }

    $equipmentId = (int) $row['equipment_id'];

    // Restore equipment status if no other scheduled logs remain
    $pdo->prepare(
        "UPDATE equipment
         SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
             next_maintenance_date = :next_maint,
             updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    )->execute([
        'next_maint'   => $nextScheduleDate !== '' ? $nextScheduleDate : null,
        'equipment_id' => $equipmentId,
    ]);

    // Fetch equipment name
    $equipStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :id');
    $equipStmt->execute(['id' => $equipmentId]);
    $equipment = $equipStmt->fetch();

    log_audit('complete', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
        ['status' => 'scheduled'],
        [
            'status'           => 'completed',
            'completed_date'   => date('Y-m-d'),
            'equipment_id'     => $equipmentId,
            'equipment_name'   => $equipment ? $equipment['name'] : null,
            'work_done'        => $workDone,
            'parts_replaced'   => $partsReplaced,
            'next_schedule'    => $nextScheduleDate,
        ]
    );

    $pdo->commit();

    // Notify admins
    if ($equipment) {
        $ns = NotificationService::getInstance();
        foreach ($ns->getAdminsEmails() as $adminId => $adminEmail) {
            $ns->send('maintenance_completed', $adminEmail, (int) $adminId, [
                'equipment_name' => $equipment['name'],
                'completed_date' => date('Y-m-d'),
                'work_done'      => $workDone ?: 'Not specified',
                'parts_replaced' => $partsReplaced ?: 'None',
                'cost'           => $completionCost !== null ? '₱' . number_format($completionCost, 2) : 'Not specified',
            ]);
        }
    }

    redirect_to('/api/maintenance.php', ['ok' => 'Maintenance marked complete']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_complete error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'DB error: ' . $e->getMessage()]);
}
