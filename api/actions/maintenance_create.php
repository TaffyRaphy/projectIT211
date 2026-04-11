<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser       = require_login();
$maintenanceUserId = (int) $currentUser['id'];

// ── Read POST directly ──────────────────────────────────────────────────────
$equipmentId     = isset($_POST['equipment_id'])     ? (int) $_POST['equipment_id']              : 0;
$maintenanceType = isset($_POST['maintenance_type']) ? trim((string) $_POST['maintenance_type']) : '';
$scheduleDate    = isset($_POST['schedule_date'])    ? trim((string) $_POST['schedule_date'])    : '';
$notes           = isset($_POST['notes'])            ? trim((string) $_POST['notes'])            : '';
$costRaw         = isset($_POST['cost'])             ? trim((string) $_POST['cost'])             : '';
$cost            = $costRaw !== '' ? (float) $costRaw : 0.0;

// ── Validate ────────────────────────────────────────────────────────────────
if ($equipmentId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Please select an equipment item']);
}
if (!in_array($maintenanceType, ['scheduled', 'repair'], true)) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance type']);
}
if ($scheduleDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid schedule date']);
}

$pdo = db();
$pdo->beginTransaction();

try {
    // 1. Insert maintenance log — use RETURNING for reliable ID
    $stmt = $pdo->prepare(
        "INSERT INTO maintenance_logs
             (equipment_id, maintenance_user_id, maintenance_type, schedule_date, notes, cost, status)
         VALUES
             (:equipment_id, :user_id, :mtype, :sdate, :notes, :cost, 'scheduled')
         RETURNING id"
    );
    $stmt->execute([
        ':equipment_id' => $equipmentId,
        ':user_id'      => $maintenanceUserId,
        ':mtype'        => $maintenanceType,
        ':sdate'        => $scheduleDate,
        ':notes'        => $notes !== '' ? $notes : null,
        ':cost'         => $cost,
    ]);
    $newLogId = (int) $stmt->fetchColumn();

    // 2. Mark equipment as under maintenance
    $pdo->prepare(
        "UPDATE equipment
         SET status = 'maintenance',
             next_maintenance_date = :sdate,
             updated_at = NOW()
         WHERE id = :eid AND status <> 'retired'"
    )->execute([':sdate' => $scheduleDate, ':eid' => $equipmentId]);

    // 3. Get equipment name (for notifications)
    $eqStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :eid');
    $eqStmt->execute([':eid' => $equipmentId]);
    $equipName = (string) ($eqStmt->fetchColumn() ?: 'Unknown');

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_create error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Failed to schedule: ' . $e->getMessage()]);
}

// ── After commit: audit + notifications (non-critical) ──────────────────────
log_audit('create', 'maintenance_logs', $newLogId, $maintenanceUserId, null, [
    'equipment_id'     => $equipmentId,
    'equipment_name'   => $equipName,
    'maintenance_type' => $maintenanceType,
    'schedule_date'    => $scheduleDate,
    'cost'             => $cost,
    'status'           => 'scheduled',
]);

try {
    $ns = NotificationService::getInstance();
    $recipients = $ns->getMaintenanceEmails() + $ns->getAdminsEmails();
    foreach ($recipients as $uid => $email) {
        $ns->send('maintenance_scheduled', $email, (int) $uid, [
            'equipment_name'   => $equipName,
            'schedule_date'    => $scheduleDate,
            'maintenance_type' => $maintenanceType,
            'notes'            => $notes !== '' ? $notes : 'No additional notes',
        ]);
    }
} catch (Throwable $e) {
    error_log('maintenance_create notification error: ' . $e->getMessage());
}

redirect_to('/api/maintenance.php', ['ok' => 'Maintenance scheduled for ' . $scheduleDate]);
