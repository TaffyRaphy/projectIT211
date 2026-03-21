<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$requestId = int_query_param('id', 0);
$adminId = (int) require_login()['id'];

if ($requestId <= 0) {
    redirect_to('api/admin_requests.php', ['error' => 'Invalid rejection input']);
}

$stmt = db()->prepare(
    "UPDATE equipment_requests
     SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW()
     WHERE id = :id AND status = 'pending'"
);
$stmt->execute(['admin_id' => $adminId, 'id' => $requestId]);

redirect_to('api/admin_requests.php', ['ok' => 'Request rejected']);
