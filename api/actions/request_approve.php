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

if ($requestId <= 0) {
    redirect_to('/api/admin_requests.php', ['error' => 'Invalid request ID']);
}
if ($dueDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    redirect_to('/api/admin_requests.php', ['error' => 'Expected return date is required']);
}

$pdo = db();

// 1. Fetch request — NO FOR UPDATE (breaks Neon pooler)
try {
    $stmt = $pdo->prepare(
        'SELECT r.id, r.equipment_id, r.staff_id, r.qty_requested, r.status,
                e.quantity_available, e.name AS equipment_name,
                u.full_name AS staff_name, u.email AS staff_email
         FROM equipment_requests r
         JOIN equipment e ON e.id = r.equipment_id
         JOIN users u ON u.id = r.staff_id
         WHERE r.id = :id'
    );
    $stmt->execute([':id' => $requestId]);
    $reqRow = $stmt->fetch();
} catch (Throwable $e) {
    error_log('request_approve FETCH error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'DB error: ' . $e->getMessage()]);
}

if (!$reqRow) {
    redirect_to('/api/admin_requests.php', ['error' => 'Request not found']);
}
if ((string) $reqRow['status'] !== 'pending') {
    redirect_to('/api/admin_requests.php', ['error' => 'Request already processed']);
}
if ((int) $reqRow['quantity_available'] < (int) $reqRow['qty_requested']) {
    redirect_to('/api/admin_requests.php', ['error' => 'Not enough stock available']);
}

// 2. Decrement equipment qty
try {
    $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available - :qty,
             status = CASE WHEN quantity_available - :qty = 0 THEN 'allocated' ELSE status END,
             updated_at = NOW()
         WHERE id = :eid"
    )->execute([
        ':qty' => (int) $reqRow['qty_requested'],
        ':eid' => (int) $reqRow['equipment_id'],
    ]);
} catch (Throwable $e) {
    error_log('request_approve UPDATE equipment error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'Failed to update equipment: ' . $e->getMessage()]);
}

// 3. Mark request as allocated
try {
    $pdo->prepare(
        "UPDATE equipment_requests
         SET status = 'allocated', reviewed_by = :admin_id, reviewed_at = NOW()
         WHERE id = :id"
    )->execute([':admin_id' => $adminId, ':id' => $requestId]);
} catch (Throwable $e) {
    error_log('request_approve UPDATE request error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'Failed to update request: ' . $e->getMessage()]);
}

// 4. Create allocation — RETURNING id (lastInsertId unreliable on Postgres)
$allocationId = 0;
try {
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
} catch (Throwable $e) {
    error_log('request_approve INSERT allocation error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'Failed to create allocation: ' . $e->getMessage()]);
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
    ]
);

// 6. Notify staff
try {
    NotificationService::getInstance()->send(
        'request_approved',
        $reqRow['staff_email'],
        (int) $reqRow['staff_id'],
        [
            'staff_name'           => $reqRow['staff_name'],
            'equipment_name'       => $reqRow['equipment_name'],
            'qty_allocated'        => (int) $reqRow['qty_requested'],
            'expected_return_date' => $dueDate,
        ]
    );
} catch (Throwable $e) {
    error_log('request_approve notification error: ' . $e->getMessage());
}

redirect_to('/api/admin_requests.php', ['ok' => "Request #{$requestId} approved — {$reqRow['equipment_name']} allocated to {$reqRow['staff_name']}"]);
