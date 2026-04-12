<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['staff']);
$staffId = (int) require_login()['id'];

$equipmentId  = post_int('equipment_id');
$qtyRequested = post_int('qty_requested');
$purpose      = post_string('purpose');

if ($equipmentId === null || $qtyRequested === null || $qtyRequested <= 0 || $purpose === '') {
    redirect_to('api/requests.php', ['error' => 'Invalid request input']);
}

$eqStmt = db()->prepare(
    'SELECT id, name, status, quantity_available
     FROM equipment
     WHERE id = :id
     LIMIT 1'
);
$eqStmt->execute([':id' => $equipmentId]);
$equipment = $eqStmt->fetch();

if (!$equipment) {
    redirect_to('/api/requests.php', ['error' => 'Selected equipment does not exist']);
}

if ((string) $equipment['status'] !== 'available' || (int) $equipment['quantity_available'] <= 0) {
    redirect_to('/api/requests.php', ['error' => 'Selected equipment is not currently requestable']);
}

try {
    $stmt = db()->prepare(
        "INSERT INTO equipment_requests (staff_id, equipment_id, qty_requested, purpose, status)
         VALUES (:staff_id, :equipment_id, :qty_requested, :purpose, 'pending')
         RETURNING id"
    );
    $stmt->execute([
        'staff_id'      => $staffId,
        'equipment_id'  => $equipmentId,
        'qty_requested' => $qtyRequested,
        'purpose'       => $purpose,
    ]);
    $newRequestId = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('request_create INSERT error: ' . $e->getMessage());
    redirect_to('/api/requests.php', ['error' => 'Failed to create request']);
}

// Get equipment name for audit context
$eqName = 'Unknown';
try {
    $eqStmt = db()->prepare('SELECT name FROM equipment WHERE id = :id');
    $eqStmt->execute([':id' => $equipmentId]);
    $eqName = (string) ($eqStmt->fetchColumn() ?: 'Unknown');
} catch (Throwable $e) {}

// Audit log
log_audit('create', 'equipment_requests', $newRequestId, $staffId, null, [
    'equipment_id'   => $equipmentId,
    'equipment_name' => $eqName,
    'qty_requested'  => $qtyRequested,
    'purpose'        => $purpose,
    'status'         => 'pending',
]);

// Notify all admins (in-app + email)
$user = require_login();
$equipStmt = db()->prepare('SELECT name FROM equipment WHERE id = :id');
$equipStmt->execute(['id' => $equipmentId]);
$equipment = $equipStmt->fetch();

if ($equipment) {
    $adminsEmails = NotificationService::getInstance()->getAdminsEmails();
    foreach ($adminsEmails as $adminId => $adminEmail) {
        NotificationService::getInstance()->send(
            'request_submitted',
            $adminEmail,
            (int) $adminId,
            [
                'staff_name'     => $user['full_name'],
                'equipment_name' => $equipment['name'],
                'qty_requested'  => $qtyRequested,
                'purpose'        => $purpose,
                'admin_link'     => 'View Request in Admin Panel',
            ]
        );
    }
}

redirect_to('/api/requests.php', ['ok' => 'Request submitted']);
