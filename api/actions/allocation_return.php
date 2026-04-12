<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_login();
$role = (string) $user['role'];
if ($role !== 'admin') {
    redirect_to('/api/admin_requests.php', ['error' => 'Only admin can process returns']);
}

$allocationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($allocationId <= 0 && isset($_POST['id'])) {
    $allocationId = (int) $_POST['id'];
}
if ($allocationId <= 0) {
    redirect_to('/api/admin_requests.php', ['error' => 'Invalid allocation ID']);
}

$currentUserId = (int) $user['id'];
$pdo = db();
$dbTrace = 'db:' . db_fingerprint();

try {
    // Fetch allocation row.
    $stmt = $pdo->prepare(
        'SELECT a.id, a.request_id, a.equipment_id, a.qty_allocated, a.status, a.staff_id,
                e.name AS equipment_name, u.full_name AS staff_name, u.email AS staff_email
         FROM allocations a
         JOIN equipment e ON e.id = a.equipment_id
         JOIN users u ON u.id = a.staff_id
         WHERE a.id = :id'
    );
    $stmt->execute([':id' => $allocationId]);
    $alloc = $stmt->fetch();

    if (!$alloc) {
        throw new RuntimeException('Allocation not found');
    }
    if ((string) $alloc['status'] === 'returned') {
        throw new RuntimeException('Allocation already returned');
    }

    // Mark allocation returned — use NOW() (timestamptz), not CURRENT_DATE (date)
    $allocUpdate = $pdo->prepare(
        "UPDATE allocations
            SET status = 'returned',
                actual_return_date = COALESCE(actual_return_date, NOW())
         WHERE id = :id
           AND status <> 'returned'"
    );
    $allocUpdate->execute([':id' => $allocationId]);
    if ($allocUpdate->rowCount() === 0) {
        throw new RuntimeException('Allocation already returned');
    }

    // Restore equipment quantity
    $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available + :qty,
             status = CASE
                 WHEN status = 'retired'     THEN 'retired'
                 WHEN status = 'maintenance' THEN 'maintenance'
                 ELSE 'available'
             END,
             updated_at = NOW()
         WHERE id = :equipment_id"
    )->execute([
        ':qty'          => (int) $alloc['qty_allocated'],
        ':equipment_id' => (int) $alloc['equipment_id'],
    ]);

    // Update request status, if allocation still linked to request row.
    if ($alloc['request_id'] !== null) {
        $pdo->prepare(
            "UPDATE equipment_requests
             SET status = 'returned'
             WHERE id = :request_id
               AND status IN ('allocated', 'approved')"
        )->execute([':request_id' => (int) $alloc['request_id']]);
    }

} catch (Throwable $e) {
    error_log('allocation_return error (' . $dbTrace . '): ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => $e->getMessage() . ' [' . $dbTrace . ']']);
}

// Audit log after status update.
log_audit('update', 'allocations', $allocationId, $currentUserId,
    ['status' => 'active'],
    [
        'status'         => 'returned',
        'actual_return'  => date('Y-m-d'),
        'equipment_name' => $alloc['equipment_name'],
        'staff_name'     => $alloc['staff_name'],
        'qty_returned'   => (int) $alloc['qty_allocated'],
    ]
);

// Dismiss overdue/due notifications for this staff member
try {
    $pdo->prepare(
        "UPDATE notifications
         SET is_read = true
         WHERE user_id = :uid
           AND is_read = false
           AND type IN ('equipment_overdue_return', 'equipment_due_return')"
    )->execute([':uid' => (int) $alloc['staff_id']]);
} catch (Throwable $e) {
    error_log('allocation_return notification dismiss error: ' . $e->getMessage());
}

redirect_to('/api/admin_requests.php', [
    'ok' => "'{$alloc['equipment_name']}' returned by {$alloc['staff_name']} — inventory restored [{$dbTrace}, alloc:{$allocationId}]",
]);
