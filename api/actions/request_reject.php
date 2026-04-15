<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$requestId = int_query_param('id', 0);
$adminId   = (int) require_login()['id'];
$remarks   = post_string('remarks');

if (strlen($remarks) > 255) {
    $remarks = substr($remarks, 0, 255);
}

if ($requestId <= 0) {
    redirect_to('api/admin_requests.php', ['error' => 'Invalid rejection input']);
}

// Get request + staff details before updating (for notification + audit)
$getStmt = db()->prepare(
    'SELECT r.staff_id, r.equipment_id, r.qty_requested,
            e.name AS equipment_name, e.quantity_available,
            u.email, u.full_name
     FROM equipment_requests r
     JOIN equipment e ON e.id = r.equipment_id
     JOIN users     u ON u.id = r.staff_id
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

// Audit log
log_audit('reject', 'equipment_requests', $requestId, $adminId,
    ['status' => 'pending'],
    [
        'status'         => 'rejected',
        'reviewed_by'    => $adminId,
        'equipment_id'   => $request ? (int) $request['equipment_id'] : null,
        'equipment_name' => $request ? $request['equipment_name'] : null,
        'qty_requested'  => $request ? (int) $request['qty_requested'] : null,
        'staff_name'     => $request ? $request['full_name'] : null,
        'email'          => $request ? $request['email'] : null,
        'remarks'        => $remarks,
    ]
);

// Notify staff (in-app + email)
if ($request) {
    NotificationService::getInstance()->send(
        'request_rejected',
        $request['email'],
        (int) $request['staff_id'],
        [
            'request_id'     => $requestId,
            'staff_name'     => $request['full_name'],
            'equipment_name' => $request['equipment_name'],
            'status'         => 'Rejected',
            'qty_requested'  => (int) $request['qty_requested'],
            'qty_available'  => (int) $request['quantity_available'],
            'remarks'        => $remarks !== '' ? $remarks : 'No additional remarks provided.',
        ]
    );
}

redirect_to('api/admin_requests.php', ['ok' => 'Request rejected']);
