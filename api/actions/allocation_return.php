<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Both admin and staff can access (admin processes, staff can request)
$user = require_login();
$role = (string) $user['role'];
if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403); echo 'Forbidden'; exit;
}

$allocationId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($allocationId <= 0 && isset($_POST['id'])) $allocationId = (int) $_POST['id'];
if ($allocationId <= 0) {
    redirect_to('/api/admin_requests.php', ['error' => 'Invalid allocation ID']);
}

$currentUserId = (int) $user['id'];
$pdo = db();
$alloc = null;

// Admin only can actually process the return
if ($role !== 'admin') {
    redirect_to('/api/admin_requests.php', ['error' => 'Only admin can process returns']);
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        'SELECT a.id, a.request_id, a.equipment_id, a.qty_allocated, a.status, a.staff_id,
                e.name AS equipment_name, u.full_name AS staff_name, u.email AS staff_email
         FROM allocations a
         JOIN equipment e ON e.id = a.equipment_id
         JOIN users u ON u.id = a.staff_id
         WHERE a.id = :id
         FOR UPDATE'
    );
    $stmt->execute([':id' => $allocationId]);
    $alloc = $stmt->fetch();

    if (!$alloc) {
        throw new RuntimeException('Allocation not found');
    }
    if ((string) $alloc['status'] !== 'active') {
        throw new RuntimeException('Allocation already returned or inactive');
    }

    $markReturned = $pdo->prepare(
        "UPDATE allocations
            SET status = 'returned', actual_return_date = CURRENT_DATE
         WHERE id = :id AND status = 'active'"
    );
    $markReturned->execute([':id' => $allocationId]);

    if ($markReturned->rowCount() !== 1) {
        throw new RuntimeException('Allocation already returned or inactive');
    }

    $restoreEquipment = $pdo->prepare(
        "UPDATE equipment
         SET quantity_available = quantity_available + :qty,
             status = CASE
                 WHEN status = 'retired' THEN 'retired'
                 WHEN status = 'maintenance' THEN 'maintenance'
                 ELSE 'available'
             END,
             updated_at = NOW()
         WHERE id = :equipment_id"
    );
    $restoreEquipment->execute([
        ':qty'          => (int) $alloc['qty_allocated'],
        ':equipment_id' => (int) $alloc['equipment_id'],
    ]);

    $pdo->prepare(
        "UPDATE equipment_requests
         SET status = 'returned'
         WHERE id = :request_id AND status = 'allocated'"
    )->execute([':request_id' => (int) $alloc['request_id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('allocation_return error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => $e->getMessage()]);
}

// 6. Audit
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

try {
    $pdo->prepare(
        "UPDATE notifications
         SET is_read = true
         WHERE user_id = :uid
           AND is_read = false
           AND type IN ('equipment_overdue_return', 'equipment_due_return')"
    )->execute([':uid' => (int) $alloc['staff_id']]);
} catch (Throwable $e) {
    error_log('allocation_return notification update error: ' . $e->getMessage());
}

try {
    $statusCheck = $pdo->prepare('SELECT status FROM allocations WHERE id = :id');
    $statusCheck->execute([':id' => $allocationId]);
    $savedStatus = (string) ($statusCheck->fetchColumn() ?: '');
    if ($savedStatus !== 'returned') {
        error_log('allocation_return post-commit mismatch: allocation ' . $allocationId . ' status=' . $savedStatus);
        redirect_to('/api/admin_requests.php', ['error' => 'Return did not persist. Please refresh and try again.']);
    }
} catch (Throwable $e) {
    error_log('allocation_return verify status error: ' . $e->getMessage());
    redirect_to('/api/admin_requests.php', ['error' => 'Unable to verify return status. Please refresh and check allocation list.']);
}

redirect_to('/api/admin_requests.php', ['ok' => "'{$alloc['equipment_name']}' returned by {$alloc['staff_name']} — inventory restored"]);
