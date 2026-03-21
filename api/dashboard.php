<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$role = (string) $user['role'];
$userId = (int) $user['id'];

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
      <span class="chip chip-role">Roleeeeeee: <?= h($role) ?></span>
      <span class="chip chip-id">User ID: <?= $userId ?></span>
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
    <a class="workflow-link" href="/api/equipment.php">Equipment Management (Admin)</a>
    <a class="workflow-link" href="/api/requests.php">Equipment Request (Staff)</a>
    <a class="workflow-link" href="/api/admin_requests.php">Request Approval and Allocation (Admin)</a>
    <a class="workflow-link" href="/api/maintenance.php">Maintenance Scheduling (Maintenance)</a>
    <a class="workflow-link" href="/api/reports.php">Reports (Admin)</a>
  </nav>
  <p class="back-link"><a href="/api/actions/logout.php">Logout</a></p>
</main>
</body>
</html>
