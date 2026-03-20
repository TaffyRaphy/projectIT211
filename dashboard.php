<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$role = query_param('as');
$userId = int_query_param('userId', 0);

$inventoryCount = (string) db()->query("SELECT COUNT(*)::text AS total FROM equipment")->fetch()['total'];
$pendingRequests = (string) db()->query("SELECT COUNT(*)::text AS total FROM equipment_requests WHERE status = 'pending'")->fetch()['total'];
$maintenanceCount = (string) db()->query("SELECT COUNT(*)::text AS total FROM maintenance_logs WHERE status = 'scheduled'")->fetch()['total'];
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Dashboard</title></head>
<body>
<main>
  <h1>Dashboard</h1>
  <p>Active Role: <?= h($role !== '' ? $role : 'none') ?></p>
  <p>User ID: <?= $userId > 0 ? $userId : 'unknown' ?></p>

  <h2>Quick Summary</h2>
  <p>Total equipment items: <?= h($inventoryCount) ?></p>
  <p>Pending requests: <?= h($pendingRequests) ?></p>
  <p>Scheduled maintenance logs: <?= h($maintenanceCount) ?></p>

  <hr>
  <h2>Workflow Links</h2>
  <p><a href="equipment.php?<?= http_build_query(['as' => $role !== '' ? $role : 'admin']) ?>">Equipment Management (Admin)</a></p>
  <p><a href="requests.php?<?= http_build_query(['as' => $role !== '' ? $role : 'staff', 'staffId' => $userId > 0 ? $userId : 2]) ?>">Equipment Request (Staff)</a></p>
  <p><a href="admin_requests.php?<?= http_build_query(['as' => $role !== '' ? $role : 'admin', 'adminId' => $userId > 0 ? $userId : 1]) ?>">Request Approval and Allocation (Admin)</a></p>
  <p><a href="maintenance.php?<?= http_build_query(['as' => $role !== '' ? $role : 'maintenance', 'maintenanceUserId' => $userId > 0 ? $userId : 3]) ?>">Maintenance Scheduling (Maintenance)</a></p>
  <p><a href="reports.php">Reports</a></p>
  <p><a href="index.php">Logout (workflow mode)</a></p>
</main>
</body>
</html>
