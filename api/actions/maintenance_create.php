<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$maintenanceUserId = (int) require_login()['id'];

$equipmentId     = post_int('equipment_id');
$maintenanceType = post_string('maintenance_type');
$scheduleDate    = post_string('schedule_date');
$notes           = post_string('notes');
$cost            = post_float('cost');

if (
    $equipmentId === null ||
    !in_array($maintenanceType, ['scheduled', 'repair'], true) ||
    $scheduleDate === '' || $cost === null
) {
    redirect_to('api/maintenance.php', ['error' => 'Invalid maintenance input']);
}

$pdo = db();
$pdo->beginTransaction();
try {
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

    $update = $pdo->prepare(
        "UPDATE equipment
         SET status = 'maintenance', next_maintenance_date = :schedule_date, updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    );
    $update->execute(['equipment_id' => $equipmentId, 'schedule_date' => $scheduleDate]);

    // Get equipment name for notification
    $equipStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :id');
    $equipStmt->execute(['id' => $equipmentId]);
    $equipment = $equipStmt->fetch();

    // Audit log
    log_audit('create', 'maintenance_logs', $newLogId, $maintenanceUserId, null, [
        'equipment_id'    => $equipmentId,
        'equipment_name'  => $equipment ? $equipment['name'] : null,
        'maintenance_type'=> $maintenanceType,
        'schedule_date'   => $scheduleDate,
        'cost'            => $cost,
        'status'          => 'scheduled',
    ]);

    $pdo->commit();

    // Notify maintenance team (in-app + email)
    if ($equipment) {
        $maintEmails = NotificationService::getInstance()->getMaintenanceEmails();
        foreach ($maintEmails as $maintId => $maintEmail) {
            NotificationService::getInstance()->send(
                'maintenance_scheduled',
                $maintEmail,
                (int) $maintId,
                [
                    'equipment_name'  => $equipment['name'],
                    'schedule_date'   => $scheduleDate,
                    'maintenance_type'=> $maintenanceType,
                    'notes'           => $notes !== '' ? $notes : 'No additional notes',
                ]
            );
        }
    }

    redirect_to('api/maintenance.php', ['ok' => 'Maintenance scheduled']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('maintenance_create error: ' . $e->getMessage());
    redirect_to('api/maintenance.php', ['error' => 'Failed to schedule maintenance']);
}
