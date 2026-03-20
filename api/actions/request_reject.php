<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$role = current_role('admin');
$requestId = int_query_param('id', 0);
$adminId = post_int('admin_id');

if ($requestId <= 0 || $adminId === null) {
    redirect_to('api/admin_requests.php', ['as' => $role, 'error' => 'Invalid rejection input']);
}

$stmt = db()->prepare(
    "UPDATE equipment_requests
     SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW()
     WHERE id = :id AND status = 'pending'"
);
$stmt->execute(['admin_id' => $adminId, 'id' => $requestId]);

redirect_to('api/admin_requests.php', ['as' => $role, 'ok' => 'Request rejected']);
