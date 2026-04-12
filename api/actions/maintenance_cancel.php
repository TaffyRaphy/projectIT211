<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['maintenance']);
$currentUser = require_login();

// Read ID
$maintenanceId = 0;
if (isset($_GET['id']))     $maintenanceId = (int) $_GET['id'];
if ($maintenanceId <= 0 && isset($_POST['id']))    $maintenanceId = (int) $_POST['id'];
if ($maintenanceId <= 0 && isset($_REQUEST['id'])) $maintenanceId = (int) $_REQUEST['id'];

if ($maintenanceId <= 0) {
    redirect_to('/api/maintenance.php', ['error' => 'Invalid maintenance ID']);
}

$pdo = db();

// 1. Fetch full log details BEFORE cancel (for rich audit context)
$logDetails = null;
try {
    $fetchStmt = $pdo->prepare(
        "SELECT m.id, m.equipment_id, m.maintenance_type, m.schedule_date::text AS schedule_date,
                m.notes, m.cost, m.status,
                e.name AS equipment_name
         FROM maintenance_logs m
         JOIN equipment e ON e.id = m.equipment_id
         WHERE m.id = :mid"
    );
    $fetchStmt->execute([':mid' => $maintenanceId]);
    $logDetails = $fetchStmt->fetch();
} catch (Throwable $e) {
    error_log('maintenance_cancel FETCH error: ' . $e->getMessage());
}

if (!$logDetails) {
    redirect_to('/api/maintenance.php', ['error' => 'Maintenance task not found']);
}
if ((string) $logDetails['status'] !== 'scheduled') {
    redirect_to('/api/maintenance.php', ['error' => 'Task is not in scheduled status — cannot cancel']);
}

$equipmentId = (int) $logDetails['equipment_id'];
$equipName   = (string) $logDetails['equipment_name'];

// 2. Cancel the log
try {
    $stmt = $pdo->prepare(
        "UPDATE maintenance_logs SET status = 'cancelled' WHERE id = :mid AND status = 'scheduled'"
    );
    $stmt->execute([':mid' => $maintenanceId]);
} catch (Throwable $e) {
    error_log('maintenance_cancel UPDATE error: ' . $e->getMessage());
    redirect_to('/api/maintenance.php', ['error' => 'Cancel failed: ' . $e->getMessage()]);
}

// 3. Check remaining scheduled logs
$remaining = 0;
try {
    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM maintenance_logs WHERE equipment_id = :eid AND status = 'scheduled'"
    );
    $countStmt->execute([':eid' => $equipmentId]);
    $remaining = (int) $countStmt->fetchColumn();
} catch (Throwable $e) {
    error_log('maintenance_cancel COUNT error: ' . $e->getMessage());
}

// 4. Restore equipment if no other scheduled tasks remain
if ($remaining === 0) {
    try {
        $pdo->prepare(
            "UPDATE equipment
             SET status = CASE WHEN quantity_available > 0 THEN 'available' ELSE 'allocated' END,
                 next_maintenance_date = NULL,
                 updated_at = NOW()
             WHERE id = :eid AND status = 'maintenance'"
        )->execute([':eid' => $equipmentId]);
    } catch (Throwable $e) {
        error_log('maintenance_cancel UPDATE equipment error: ' . $e->getMessage());
    }
}

// 5. Rich audit log — full context for audit trail readability
log_audit('cancel', 'maintenance_logs', $maintenanceId, (int) $currentUser['id'],
    [
        'status'           => 'scheduled',
        'equipment_name'   => $equipName,
        'maintenance_type' => $logDetails['maintenance_type'],
        'schedule_date'    => $logDetails['schedule_date'],
    ],
    [
        'status'           => 'cancelled',
        'equipment_id'     => $equipmentId,
        'equipment_name'   => $equipName,
        'maintenance_type' => $logDetails['maintenance_type'],
        'schedule_date'    => $logDetails['schedule_date'],
        'cancelled_by'     => $currentUser['full_name'],
    ]
);

// 6. Notify maintenance team + admins
try {
    $ns = NotificationService::getInstance();
    $recipients = $ns->getMaintenanceEmails() + $ns->getAdminsEmails();
    foreach ($recipients as $uid => $email) {
        $ns->send('maintenance_cancelled', $email, (int) $uid, [
            'equipment_name'   => $equipName,
            'maintenance_type' => $logDetails['maintenance_type'],
            'schedule_date'    => $logDetails['schedule_date'],
            'cancelled_by'     => $currentUser['full_name'],
        ]);
    }
} catch (Throwable $e) {
    error_log('maintenance_cancel notification error: ' . $e->getMessage());
}

redirect_to('/api/maintenance.php', ['ok' => "Maintenance task for {$equipName} cancelled"]);
