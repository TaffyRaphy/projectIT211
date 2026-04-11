<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$adminUser     = require_login();
$adminId       = (int) $adminUser['id'];
$allocationId  = int_query_param('id', 0);

if ($allocationId <= 0) {
    redirect_to('/api/admin_requests.php', ['error' => 'Invalid allocation ID']);
}

$pdo = db();
$pdo->beginTransaction();

try {
    // Lock and fetch the allocation
    $stmt = $pdo->prepare(
        'SELECT a.id, a.equipment_id, a.qty_allocated, a.status, a.staff_id,
                e.name AS equipment_name, u.full_name AS staff_name
         FROM allocations a
         JOIN equipment e ON e.id = a.equipment_id
         JOIN users u ON u.id = a.staff_id
         WHERE a.id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $allocationId]);
    $alloc = $stmt->fetch();

    if (!$alloc) {
        $pdo->rollBack();
        redirect_to('/api/admin_requests.php', ['error' => 'Allocation not found']);
    }
    if ((string) $alloc['status'] !== 'active') {
        $pdo->rollBack();
        redirect_to('/api/admin_requests.php', ['error' => 'Allocation already returned or inactive']);
    }

    // Mark allocation as returned
    $pdo->prepare(
        "UPDATE allocations
         SET status = 'returned', actual_return_date = NOW()
         WHERE id = :id"
    )->execute([':id' => $allocationId]);

    // Restore quantity_available & update equipment status
    $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available + :qty,
             status = CASE
                 WHEN status = 'retired' THEN 'retired'
                 WHEN status = 'maintenance' THEN 'maintenance'
                 ELSE 'available'
             END,
             updated_at = NOW()
         WHERE id = :equipment_id"
    )->execute([
        ':qty'           => (int) $alloc['qty_allocated'],
        ':equipment_id'  => (int) $alloc['equipment_id'],
    ]);

    log_audit('update', 'allocations', $allocationId, $adminId,
        ['status' => 'active'],
        [
            'status'           => 'returned',
            'actual_return'    => date('Y-m-d'),
            'equipment_name'   => $alloc['equipment_name'],
            'qty_returned'     => (int) $alloc['qty_allocated'],
        ]
    );

    $pdo->commit();

    redirect_to('/api/admin_requests.php', ['ok' => "Equipment '{$alloc['equipment_name']}' returned by {$alloc['staff_name']} — inventory restored"]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('allocation_return error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'Failed to process return']);
}
