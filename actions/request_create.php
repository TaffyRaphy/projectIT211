<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_role(['staff']);
$role = current_role('staff');

$staffId = post_int('staff_id');
$equipmentId = post_int('equipment_id');
$qtyRequested = post_int('qty_requested');
$purpose = post_string('purpose');

if ($staffId === null || $equipmentId === null || $qtyRequested === null || $qtyRequested <= 0 || $purpose === '') {
    redirect_to('requests.php', ['as' => $role, 'error' => 'Invalid request input']);
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

redirect_to('requests.php', ['as' => $role, 'staffId' => (string) $staffId, 'ok' => 'Request submitted']);
