<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_role(['maintenance']);
$role = current_role('maintenance');
$maintenanceId = int_query_param('id', 0);

if ($maintenanceId <= 0) {
    redirect_to('maintenance.php', ['as' => $role, 'error' => 'Invalid maintenance id']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $updateLog = $pdo->prepare(
        "UPDATE maintenance_logs
         SET status = 'completed', completed_date = CURRENT_DATE
         WHERE id = :id AND status = 'scheduled'
         RETURNING equipment_id"
    );
    $updateLog->execute(['id' => $maintenanceId]);
    $row = $updateLog->fetch();
    if (!$row) {
        $pdo->rollBack();
        redirect_to('maintenance.php', ['as' => $role, 'error' => 'Log not found or already completed']);
    }

    $updateEquipment = $pdo->prepare(
        "UPDATE equipment
         SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
             updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    );
    $updateEquipment->execute(['equipment_id' => (int) $row['equipment_id']]);
    $pdo->commit();
    redirect_to('maintenance.php', ['as' => $role, 'ok' => 'Maintenance completed']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_to('maintenance.php', ['as' => $role, 'error' => 'Failed to complete maintenance']);
}
