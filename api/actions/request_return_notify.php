<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

// Staff only — request admin to process return
require_role(['staff']);
$user    = require_login();
$staffId = (int) $user['id'];

$allocationId = isset($_POST['allocation_id']) ? (int) $_POST['allocation_id'] : 0;
if ($allocationId <= 0) {
    redirect_to('/api/requests.php', ['error' => 'Invalid allocation']);
}

$pdo = db();

// Verify allocation belongs to this staff + is active
try {
    $stmt = $pdo->prepare(
        "SELECT a.id, a.equipment_id, e.name AS equipment_name
         FROM allocations a
         JOIN equipment e ON e.id = a.equipment_id
         WHERE a.id = :id AND a.staff_id = :sid AND a.status = 'active'"
    );
    $stmt->execute([':id' => $allocationId, ':sid' => $staffId]);
    $alloc = $stmt->fetch();
} catch (Throwable $e) {
    redirect_to('/api/requests.php', ['error' => 'DB error: ' . $e->getMessage()]);
}

if (!$alloc) {
    redirect_to('/api/requests.php', ['error' => 'Allocation not found or already returned']);
}

// Notify all admins to process the return
try {
    $ns = NotificationService::getInstance();
    $adminEmails = $ns->getAdminsEmails();
    foreach ($adminEmails as $adminId => $adminEmail) {
        $ns->send('request_return_notify', $adminEmail, (int) $adminId, [
            'staff_name'     => $user['full_name'],
            'equipment_name' => $alloc['equipment_name'],
            'allocation_id'  => $allocationId,
        ]);
    }
} catch (Throwable $e) {
    error_log('request_return_notify error: ' . $e->getMessage());
}

log_audit('update', 'allocations', $allocationId, $staffId, null, [
    'action'         => 'return_requested',
    'equipment_name' => $alloc['equipment_name'],
]);

redirect_to('/api/requests.php', ['ok' => 'Return request sent to admin for ' . $alloc['equipment_name']]);
