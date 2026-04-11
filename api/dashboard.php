<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$role   = (string) $user['role'];
$userId = (int) $user['id'];

$dashboardTitle = match ($role) {
    'admin'       => 'Admin Dashboard',
    'staff'       => 'Staff Dashboard',
    'maintenance' => 'Maintenance Dashboard',
    default       => 'Dashboard',
};

$workflowLinks = match ($role) {
    'admin' => [
        '📦 Equipment Management'         => '/api/equipment.php',
        '✅ Request Approval & Allocation'  => '/api/admin_requests.php',
        '📊 Reports'                       => '/api/reports.php',
        '📧 Notification Logs'             => '/api/notification_logs.php',
        '👥 User Management'               => '/api/users.php',
    ],
    'staff' => [
        '📋 Equipment Request'             => '/api/requests.php',
    ],
    'maintenance' => [
        '🔧 Maintenance Scheduling'        => '/api/maintenance.php',
    ],
    default => [],
};

$inventoryCount  = (string) db()->query("SELECT COUNT(*) FROM equipment")->fetchColumn();
$pendingRequests = (string) db()->query("SELECT COUNT(*) FROM equipment_requests WHERE status = 'pending'")->fetchColumn();
$maintenanceCount= (string) db()->query("SELECT COUNT(*) FROM maintenance_logs WHERE status = 'scheduled'")->fetchColumn();

// Unread notification count for bell icon
$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($dashboardTitle) ?> – Equipment Management System</title>
  <meta name="description" content="Equipment Management System dashboard for <?= h($role) ?> users.">
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    .bell-btn {
      position: relative;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: none;
      border: none;
      font-size: 1.3rem;
      cursor: pointer;
      text-decoration: none;
      padding: .2rem .35rem;
      border-radius: 8px;
      transition: background .2s;
      color: inherit;
    }
    .bell-btn:hover { background: rgba(255,255,255,.08); }
    .bell-badge {
      position: absolute;
      top: -4px; right: -4px;
      background: #ef4444;
      color: #fff;
      font-size: .62rem;
      font-weight: 700;
      min-width: 16px; height: 16px;
      border-radius: 999px;
      display: flex; align-items: center; justify-content: center;
      padding: 0 3px;
      line-height: 1;
    }
    .profile-link {
      font-size:.85rem;
      text-decoration: none;
      color: var(--text-muted, #888);
      border: 1px solid var(--border-color, #333);
      border-radius: 8px;
      padding: .2rem .6rem;
      transition: all .2s;
    }
    .profile-link:hover {
      border-color: var(--accent, #cafd00);
      color: var(--accent, #cafd00);
    }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title"><?= h($dashboardTitle) ?></p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: <?= h($role) ?> | <?= h($user['full_name']) ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <!-- 🔔 Bell icon with unread badge -->
      <a class="bell-btn" href="/api/my_notifications.php" aria-label="Notifications (<?= $unreadCount ?> unread)">
        🔔
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <!-- 🪪 Profile link -->
      <a class="profile-link" href="/api/profile.php">🪪 Profile</a>
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
    <p class="metric-label">SCHEDULED MAINTENANCE</p>
    <p class="metric-value"><?= h($maintenanceCount) ?></p>
  </article>
  <?php if ($unreadCount > 0): ?>
  <article class="metric-card">
    <p class="metric-label">UNREAD NOTIFICATIONS</p>
    <p class="metric-value" style="color: #ef4444;"><?= $unreadCount ?></p>
  </article>
  <?php endif; ?>
</section>

<section class="page page-dashboard dashboard-workflow-panel">
  <h2>Quick Actions</h2>
  <nav class="workflow-grid">
    <?php foreach ($workflowLinks as $label => $url): ?>
      <a class="workflow-link" href="<?= h($url) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
    <a class="workflow-link" href="/api/my_notifications.php">
      🔔 My Notifications
      <?php if ($unreadCount > 0): ?>
        <span style="background:#ef4444; color:#fff; font-size:.7rem; border-radius:999px; padding:.05rem .4rem; margin-left:.3rem;"><?= $unreadCount ?></span>
      <?php endif; ?>
    </a>
  </nav>
</section>

<script src="/assets/app.js"></script>
</body>
</html>
