<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$requestId = int_query_param('id', 0);
$adminId = (int) require_login()['id'];

if ($requestId <= 0) {
    redirect_to('api/admin_requests.php', ['error' => 'Invalid rejection input']);
}

// Get request details before updating (for notification)
$getStmt = db()->prepare(
    'SELECT r.staff_id, r.equipment_id, e.name as equipment_name, e.quantity_available, u.email, u.full_name
     FROM equipment_requests r
     JOIN equipment e ON e.id = r.equipment_id
     JOIN users u ON u.id = r.staff_id
     WHERE r.id = :id AND r.status = :status'
);
$getStmt->execute(['id' => $requestId, 'status' => 'pending']);
$request = $getStmt->fetch();

$stmt = db()->prepare(
    "UPDATE equipment_requests
     SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW()
     WHERE id = :id AND status = 'pending'"
);
$stmt->execute(['admin_id' => $adminId, 'id' => $requestId]);

// Send rejection notification
if ($request) {
    NotificationService::getInstance()->send(
        'request_rejected',
        $request['email'],
        (int) $request['staff_id'],
        [
            'staff_name' => $request['full_name'],
            'equipment_name' => $request['equipment_name'],
            'qty_available' => (int) $request['quantity_available'],
            'request_link' => 'View Request Details',
        ]
    );
}

redirect_to('api/admin_requests.php', ['ok' => 'Request rejected']);
