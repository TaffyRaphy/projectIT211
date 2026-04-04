<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['staff']);
$staffId = (int) require_login()['id'];

$equipmentId = post_int('equipment_id');
$qtyRequested = post_int('qty_requested');
$purpose = post_string('purpose');

if ($equipmentId === null || $qtyRequested === null || $qtyRequested <= 0 || $purpose === '') {
    redirect_to('api/requests.php', ['error' => 'Invalid request input']);
}

$stmt = db()->prepare(
    "INSERT INTO equipment_requests (staff_id, equipment_id, qty_requested, purpose, status)
     VALUES (:staff_id, :equipment_id, :qty_requested, :purpose, 'pending')"
);
$stmt->execute([
    'staff_id' => $staffId,
    'equipment_id' => $equipmentId,
    'qty_requested' => $qtyRequested,
    'purpose' => $purpose,
]);

// Get staff and equipment details for notification
$user = require_login();
$equipStmt = db()->prepare('SELECT name FROM equipment WHERE id = :id');
$equipStmt->execute(['id' => $equipmentId]);
$equipment = $equipStmt->fetch();

// Send notification to all admins
if ($equipment) {
    $adminsEmails = NotificationService::getInstance()->getAdminsEmails();
    foreach ($adminsEmails as $adminId => $adminEmail) {
        NotificationService::getInstance()->send(
            'request_submitted',
            $adminEmail,
            (int) $adminId,
            [
                'staff_name' => $user['full_name'],
                'equipment_name' => $equipment['name'],
                'qty_requested' => $qtyRequested,
                'purpose' => $purpose,
                'admin_link' => 'View Request in Admin Panel',
            ]
        );
    }
}

redirect_to('api/requests.php', ['ok' => 'Request submitted']);
