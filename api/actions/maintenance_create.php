<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser         = require_login();
$maintenanceUserId   = (int) $currentUser['id'];

// ── Read POST directly (filter_input unreliable on Vercel) ──────────────────
$equipmentId     = isset($_POST['equipment_id'])     ? (int) $_POST['equipment_id']          : 0;
$maintenanceType = isset($_POST['maintenance_type']) ? trim((string) $_POST['maintenance_type']) : '';
$scheduleDate    = isset($_POST['schedule_date'])    ? trim((string) $_POST['schedule_date'])    : '';
$notes           = isset($_POST['notes'])            ? trim((string) $_POST['notes'])            : '';
$cost            = isset($_POST['cost']) && $_POST['cost'] !== '' ? (float) $_POST['cost']        : 0.0;

$validTypes = ['scheduled', 'repair', 'inspection', 'calibration'];

if ($equipmentId <= 0 || !in_array($maintenanceType, $validTypes, true) || $scheduleDate === '') {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid input — equipment, type and date required']);
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid date format']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    // Insert maintenance log
    $insert = $pdo->prepare(
        "INSERT INTO maintenance_logs
         (equipment_id, maintenance_user_id, maintenance_type, schedule_date, notes, cost, status)
         VALUES (:equipment_id, :maintenance_user_id, :maintenance_type, :schedule_date, :notes, :cost, 'scheduled')"
    );
    $insert->execute([
        'equipment_id'        => $equipmentId,
        'maintenance_user_id' => $maintenanceUserId,
        'maintenance_type'    => $maintenanceType,
        'schedule_date'       => $scheduleDate,
        'notes'               => $notes !== '' ? $notes : null,
        'cost'                => $cost,
    ]);
    $newLogId = (int) $pdo->lastInsertId();

    // Mark equipment as under maintenance
    $pdo->prepare(
        "UPDATE equipment
         SET status = 'maintenance', next_maintenance_date = :schedule_date, updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    )->execute(['schedule_date' => $scheduleDate, 'equipment_id' => $equipmentId]);

    // Fetch equipment name for notifications/audit
    $equipStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :id');
    $equipStmt->execute(['id' => $equipmentId]);
    $equipment = $equipStmt->fetch();

    log_audit('create', 'maintenance_logs', $newLogId, $maintenanceUserId, null, [
        'equipment_id'     => $equipmentId,
        'equipment_name'   => $equipment ? $equipment['name'] : null,
        'maintenance_type' => $maintenanceType,
        'schedule_date'    => $scheduleDate,
        'cost'             => $cost,
        'status'           => 'scheduled',
    ]);

    $pdo->commit();

    // Notify maintenance team + admins
    if ($equipment) {
        $ns = NotificationService::getInstance();
        $recipients = $ns->getMaintenanceEmails() + $ns->getAdminsEmails();
        foreach ($recipients as $uid => $email) {
            $ns->send('maintenance_scheduled', $email, (int) $uid, [
                'equipment_name'   => $equipment['name'],
                'schedule_date'    => $scheduleDate,
                'maintenance_type' => $maintenanceType,
                'notes'            => $notes !== '' ? $notes : 'No additional notes',
            ]);
        }
    }

    redirect_to('/api/maintenance.php', ['ok' => 'Maintenance scheduled for ' . $scheduleDate]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_create error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'DB error: ' . $e->getMessage()]);
}
