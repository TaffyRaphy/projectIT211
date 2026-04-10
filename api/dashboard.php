<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$role = (string) $user['role'];
$userId = (int) $user['id'];
$dashboardTitle = match ($role) {
  'admin' => 'Admin Dashboard',
  'staff' => 'Staff Dashboard',
  'maintenance' => 'Maintenance Dashboard',
  default => 'Dashboard',
};

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
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title"><?= h($dashboardTitle) ?></p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: <?= h($role) ?> | User ID: <?= $userId ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>
<section class="page page-dashboard page-dashboard-summary">
  <h2>Quick Summary</h2>
  <article class="metric-card metric-card-hero">
    <p class="metric-label">TOTAL EQUIPMENT</p>
    <p class="metric-value"><?= h($inventoryCount) ?></p>
  </article>
  <article class="metric-card metric-card-warning">
    <p class="metric-label">PENDING REQUESTS</p>
    <p class="metric-value"><?= h($pendingRequests) ?></p>
  </article>
  <article class="metric-card metric-card-cool">
    <p class="metric-label">SCHEDULE MAINTENANCE</p>
    <p class="metric-value"><?= h($maintenanceCount) ?></p>
  </article>
</section>

<section class="page page-dashboard dashboard-workflow-panel">
  <h2>Workflow Links</h2>
  <nav class="workflow-grid">
    <a class="workflow-link" href="/api/equipment.php">Equipment Management (Admin)</a>
    <a class="workflow-link" href="/api/requests.php">Equipment Request (Staff)</a>
    <a class="workflow-link" href="/api/admin_requests.php">Request Approval and Allocation (Admin)</a>
    <a class="workflow-link" href="/api/maintenance.php">Maintenance Scheduling (Maintenance)</a>
    <a class="workflow-link" href="/api/reports.php">Reports (Admin)</a>
  </nav>

  <?php if ($role === 'admin'): ?>
  <hr>
  <h2>Administration</h2>
  <nav class="workflow-grid">
    <a class="workflow-link" href="/api/notification_logs.php">📧 Notification Logs</a>
    <a class="workflow-link" href="/api/email_config.php">⚙️ Email Configuration</a>
  </nav>
  <?php endif; ?>
</section>
<script src="/assets/app.js"></script>
</body>
</html>

