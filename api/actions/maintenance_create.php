<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$role = current_role('maintenance');

$equipmentId = post_int('equipment_id');
$maintenanceUserId = post_int('maintenance_user_id');
$maintenanceType = post_string('maintenance_type');
$scheduleDate = post_string('schedule_date');
$notes = post_string('notes');
$cost = post_float('cost');

if (
    $equipmentId === null || $maintenanceUserId === null ||
    !in_array($maintenanceType, ['scheduled', 'repair'], true) ||
    $scheduleDate === '' || $cost === null
) {
    redirect_to('api/maintenance.php', ['as' => $role, 'error' => 'Invalid maintenance input']);
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
        'equipment_id' => $equipmentId,
        'maintenance_user_id' => $maintenanceUserId,
        'maintenance_type' => $maintenanceType,
        'schedule_date' => $scheduleDate,
        'notes' => $notes !== '' ? $notes : null,
        'cost' => $cost,
    ]);

    $update = $pdo->prepare(
        "UPDATE equipment
         SET status = 'maintenance', updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    );
    $update->execute(['equipment_id' => $equipmentId]);

    $pdo->commit();
    redirect_to('api/maintenance.php', ['as' => $role, 'maintenanceUserId' => (string) $maintenanceUserId, 'ok' => 'Maintenance scheduled']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_to('api/maintenance.php', ['as' => $role, 'error' => 'Failed to schedule maintenance']);
}
