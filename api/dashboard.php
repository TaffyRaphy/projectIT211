<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$role = query_param('as');
$userId = int_query_param('userId', 0);

$inventoryCount = (string) db()->query("SELECT COUNT(*)::text AS total FROM equipment")->fetch()['total'];
$pendingRequests = (string) db()->query("SELECT COUNT(*)::text AS total FROM equipment_requests WHERE status = 'pending'")->fetch()['total'];
$maintenanceCount = (string) db()->query("SELECT COUNT(*)::text AS total FROM maintenance_logs WHERE status = 'scheduled'")->fetch()['total'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Dashboard</title>
</head>
<body>
<main class="page page-dashboard">
  <header class="page-header">
    <h1>Dashboard</h1>
    <div class="meta-row">
      <span class="chip chip-role">Role: <?= h($role !== '' ? $role : 'none') ?></span>
      <span class="chip chip-id">User ID: <?= $userId > 0 ? $userId : 'unknown' ?></span>
    </div>
  </header>

  <h2>Quick Summary</h2>
  <section class="metrics-grid">
    <article class="metric-card metric-card-hero">
      <p class="metric-label">Total Equipment</p>
      <p class="metric-value"><?= h($inventoryCount) ?></p>
    </article>
    <article class="metric-card metric-card-warning">
      <p class="metric-label">Pending Requests</p>
      <p class="metric-value"><?= h($pendingRequests) ?></p>
    </article>
    <article class="metric-card metric-card-cool">
      <p class="metric-label">Scheduled Maintenance</p>
      <p class="metric-value"><?= h($maintenanceCount) ?></p>
    </article>
  </section>

  <hr>
  <h2>Workflow Links</h2>
  <nav class="workflow-grid">
    <a class="workflow-link" href="/api/equipment.php?<?= http_build_query(['as' => $role !== '' ? $role : 'admin']) ?>">Equipment Management (Admin)</a>
    <a class="workflow-link" href="/api/requests.php?<?= http_build_query(['as' => $role !== '' ? $role : 'staff', 'staffId' => $userId > 0 ? $userId : 2]) ?>">Equipment Request (Staff)</a>
    <a class="workflow-link" href="/api/admin_requests.php?<?= http_build_query(['as' => $role !== '' ? $role : 'admin', 'adminId' => $userId > 0 ? $userId : 1]) ?>">Request Approval and Allocation (Admin)</a>
    <a class="workflow-link" href="/api/maintenance.php?<?= http_build_query(['as' => $role !== '' ? $role : 'maintenance', 'maintenanceUserId' => $userId > 0 ? $userId : 3]) ?>">Maintenance Scheduling (Maintenance)</a>
    <a class="workflow-link" href="/api/reports.php">Reports</a>
  </nav>
  <p class="back-link"><a href="/api/index.php">Logout (workflow mode)</a></p>
</main>
</body>
</html>
