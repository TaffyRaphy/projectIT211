<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$maintenanceId = int_query_param('id', 0);

if ($maintenanceId <= 0) {
    redirect_to('api/maintenance.php', ['error' => 'Invalid maintenance id']);
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
        redirect_to('api/maintenance.php', ['error' => 'Log not found or already completed']);
    }

    $updateEquipment = $pdo->prepare(
        "UPDATE equipment
         SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
             next_maintenance_date = (SELECT MIN(schedule_date) FROM maintenance_logs WHERE equipment_id = :equipment_id AND status = 'scheduled'),
             updated_at = NOW()
         WHERE id = :equipment_id AND status <> 'retired'"
    );
    $updateEquipment->execute(['equipment_id' => (int) $row['equipment_id']]);
    
    // Get maintenance log details for notification
    $logStmt = $pdo->prepare('SELECT cost, completed_date FROM maintenance_logs WHERE id = :id');
    $logStmt->execute(['id' => $maintenanceId]);
    $log = $logStmt->fetch();
    
    // Get equipment name
    $equipStmt = $pdo->prepare('SELECT name FROM equipment WHERE id = :id');
    $equipStmt->execute(['id' => (int) $row['equipment_id']]);
    $equipment = $equipStmt->fetch();
    
    $pdo->commit();
    
    // Send notification to admins
    if ($log && $equipment) {
        $adminsEmails = NotificationService::getInstance()->getAdminsEmails();
        foreach ($adminsEmails as $adminId => $adminEmail) {
            NotificationService::getInstance()->send(
                'maintenance_completed',
                $adminEmail,
                (int) $adminId,
                [
                    'equipment_name' => $equipment['name'],
                    'completed_date' => $log['completed_date'],
                    'cost' => $log['cost'] !== null ? '$' . number_format((float) $log['cost'], 2) : 'Not specified',
                ]
            );
        }
    }
    
    redirect_to('api/maintenance.php', ['ok' => 'Maintenance completed']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    redirect_to('api/maintenance.php', ['error' => 'Failed to complete maintenance']);
}
