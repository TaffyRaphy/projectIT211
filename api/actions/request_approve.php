<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$requestId = int_query_param('id', 0);
$adminId   = (int) require_login()['id'];
$dueDate   = post_string('due_date');

if ($requestId <= 0) {
    redirect_to('api/admin_requests.php', ['error' => 'Invalid approval input']);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.equipment_id, r.staff_id, r.qty_requested, r.status, e.quantity_available, e.name AS equipment_name
         FROM equipment_requests r
         JOIN equipment e ON e.id = r.equipment_id
         JOIN users u ON u.id = r.staff_id
         WHERE r.id = :id
         FOR UPDATE'
    );
    $stmt->execute(['id' => $requestId]);
    $reqRow = $stmt->fetch();

    if (!$reqRow) {
        $pdo->rollBack();
        redirect_to('api/admin_requests.php', ['error' => 'Request not found']);
    }
    if ((string) $reqRow['status'] !== 'pending') {
        $pdo->rollBack();
        redirect_to('api/admin_requests.php', ['error' => 'Request already processed']);
    }
    if ((int) $reqRow['quantity_available'] < (int) $reqRow['qty_requested']) {
        $pdo->rollBack();
        redirect_to('api/admin_requests.php', ['error' => 'Not enough stock']);
    }

    $updateEquipment = $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available - :qty,
             status = CASE WHEN quantity_available - :qty = 0 THEN 'allocated' ELSE status END,
             updated_at = NOW()
         WHERE id = :equipment_id"
    );
    $updateEquipment->execute([
        'qty'          => (int) $reqRow['qty_requested'],
        'equipment_id' => (int) $reqRow['equipment_id'],
    ]);

    $updateRequest = $pdo->prepare(
        "UPDATE equipment_requests
         SET status = 'allocated', reviewed_by = :admin_id, reviewed_at = NOW()
         WHERE id = :id"
    );
    $updateRequest->execute(['admin_id' => $adminId, 'id' => $requestId]);

    $insertAllocation = $pdo->prepare(
        'INSERT INTO allocations (request_id, equipment_id, staff_id, qty_allocated, allocated_by, checkout_date, expected_return_date)
         VALUES (:request_id, :equipment_id, :staff_id, :qty_allocated, :allocated_by, NOW(), :expected_return_date)'
    );
    $insertAllocation->execute([
        'request_id'           => $requestId,
        'equipment_id'         => (int) $reqRow['equipment_id'],
        'staff_id'             => (int) $reqRow['staff_id'],
        'qty_allocated'        => (int) $reqRow['qty_requested'],
        'allocated_by'         => $adminId,
        'expected_return_date' => $dueDate !== '' ? $dueDate : null,
    ]);
    $allocationId = (int) $pdo->lastInsertId();

    // Audit log — request approval + allocation created
    log_audit('approve', 'equipment_requests', $requestId, $adminId, ['status' => 'pending'], [
        'status'               => 'allocated',
        'reviewed_by'          => $adminId,
        'allocation_id'        => $allocationId,
        'equipment_id'         => (int) $reqRow['equipment_id'],
        'equipment_name'       => $reqRow['equipment_name'],
        'staff_name'           => $reqRow['full_name'] ?? 'Unknown',
        'qty_allocated'        => (int) $reqRow['qty_requested'],
        'expected_return_date' => $dueDate !== '' ? $dueDate : null,
    ]);

    $pdo->commit();

    // Notify staff member (in-app + email)
    $staffUser = $reqRow;

    if ($staffUser && isset($staffUser['email'])) {
        NotificationService::getInstance()->send(
            'request_approved',
            $staffUser['email'],
            (int) $reqRow['staff_id'],
            [
                'staff_name'           => $staffUser['full_name'],
                'equipment_name'       => $reqRow['equipment_name'],
                'qty_allocated'        => (int) $reqRow['qty_requested'],
                'expected_return_date' => $dueDate !== '' ? $dueDate : 'To be determined',
            ]
        );
    }

    redirect_to('api/admin_requests.php', ['ok' => 'Request approved and allocated']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_approve error: ' . $e->getMessage());
    redirect_to('api/admin_requests.php', ['error' => 'Failed to approve request']);
}
