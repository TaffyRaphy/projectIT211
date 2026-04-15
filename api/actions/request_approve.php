<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$admin    = require_login();
$adminId  = (int) $admin['id'];

// Direct GET read — filter_input returns null on Vercel
$requestId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($requestId <= 0 && isset($_POST['id'])) $requestId = (int) $_POST['id'];

// Required: return date
$dueDate = isset($_POST['due_date']) ? trim((string) $_POST['due_date']) : '';
$remarks = post_string('remarks');
if (strlen($remarks) > 255) {
    $remarks = substr($remarks, 0, 255);
}

if ($requestId <= 0) {
    redirect_to('/api/admin_requests.php', ['error' => 'Invalid request ID']);
}
if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    redirect_to('/api/admin_requests.php', ['error' => 'Expected return date is required']);
}
if ($dueDate < date('Y-m-d')) {
    redirect_to('/api/admin_requests.php', ['error' => 'Expected return date cannot be in the past']);
}

$pdo = db();

$allocationId = 0;
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT r.id, r.equipment_id, r.staff_id, r.qty_requested, r.status,
                e.quantity_available, e.status AS equipment_status, e.name AS equipment_name,
                u.full_name AS staff_name, u.email AS staff_email
         FROM equipment_requests r
         JOIN equipment e ON e.id = r.equipment_id
         JOIN users u ON u.id = r.staff_id
         WHERE r.id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $requestId]);
    $reqRow = $stmt->fetch();

    if (!$reqRow) {
        throw new RuntimeException('Request not found');
    }
    if ((string) $reqRow['status'] !== 'pending') {
        throw new RuntimeException('Request already processed');
    }
    if ((string) $reqRow['equipment_status'] !== 'available') {
        throw new RuntimeException('Equipment is not currently requestable');
    }

    $eqUpdate = $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available - :qty,
             status = CASE WHEN quantity_available - :qty = 0 THEN 'allocated' ELSE status END,
             updated_at = NOW()
         WHERE id = :eid
           AND status = 'available'
           AND quantity_available >= :qty"
    );
    $eqUpdate->execute([
        ':qty' => (int) $reqRow['qty_requested'],
        ':eid' => (int) $reqRow['equipment_id'],
    ]);

    if ($eqUpdate->rowCount() !== 1) {
        throw new RuntimeException('Not enough stock available');
    }

    $reqUpdate = $pdo->prepare(
        "UPDATE equipment_requests
         SET status = 'allocated', reviewed_by = :admin_id, reviewed_at = NOW()
         WHERE id = :id AND status = 'pending'"
    );
    $reqUpdate->execute([':admin_id' => $adminId, ':id' => $requestId]);

    if ($reqUpdate->rowCount() !== 1) {
        throw new RuntimeException('Request already processed');
    }

    $ins = $pdo->prepare(
        'INSERT INTO allocations
             (request_id, equipment_id, staff_id, qty_allocated, allocated_by, checkout_date, expected_return_date)
         VALUES
             (:request_id, :equipment_id, :staff_id, :qty, :admin_id, NOW(), :due_date)
         RETURNING id'
    );
    $ins->execute([
        ':request_id'  => $requestId,
        ':equipment_id'=> (int) $reqRow['equipment_id'],
        ':staff_id'    => (int) $reqRow['staff_id'],
        ':qty'         => (int) $reqRow['qty_requested'],
        ':admin_id'    => $adminId,
        ':due_date'    => $dueDate,
    ]);
    $allocationId = (int) $ins->fetchColumn();

    if ($allocationId <= 0) {
        throw new RuntimeException('Failed to create allocation');
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $err = $e->getMessage();
    error_log('request_approve error: ' . $err);
    redirect_to('/api/admin_requests.php', ['error' => $err]);
}

// 5. Audit log
log_audit('approve', 'equipment_requests', $requestId, $adminId,
    ['status' => 'pending'],
    [
        'status'               => 'allocated',
        'reviewed_by_id'       => $adminId,
        'allocation_id'        => $allocationId,
        'equipment_id'         => (int) $reqRow['equipment_id'],
        'equipment_name'       => $reqRow['equipment_name'],
        'staff_name'           => $reqRow['staff_name'],
        'qty_allocated'        => (int) $reqRow['qty_requested'],
        'expected_return_date' => $dueDate,
        'remarks'              => $remarks,
    ]
);

// 6. Notify staff
try {
    NotificationService::getInstance()->send(
        'request_approved',
        $reqRow['staff_email'],
        (int) $reqRow['staff_id'],
        [
            'request_id'           => $requestId,
            'staff_name'           => $reqRow['staff_name'],
            'equipment_name'       => $reqRow['equipment_name'],
            'status'               => 'Allocated',
            'qty_allocated'        => (int) $reqRow['qty_requested'],
            'expected_return_date' => $dueDate,
            'remarks'              => $remarks !== '' ? $remarks : 'None',
        ]
    );
} catch (Throwable $e) {
    error_log('request_approve notification error: ' . $e->getMessage());
}

redirect_to('/api/admin_requests.php', ['ok' => "Request #{$requestId} approved — {$reqRow['equipment_name']} allocated to {$reqRow['staff_name']}"]);
