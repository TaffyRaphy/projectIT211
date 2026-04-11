<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$role   = (string) $user['role'];
$userId = (int) $user['id'];

// ── Mandatory profile check for staff/maintenance ──────────────────────────
if (in_array($role, ['staff', 'maintenance'], true)) {
    $profileCheckStmt = db()->prepare('SELECT department, job_title FROM users WHERE id = :id');
    $profileCheckStmt->execute([':id' => $userId]);
    $profileCheck = $profileCheckStmt->fetch();
    if ($profileCheck && (empty($profileCheck['department']) || empty($profileCheck['job_title']))) {
        redirect_to('/api/profile.php', ['setup' => '1']);
    }
}

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
        '📸 Metric Snapshots'              => '/api/snapshots.php',
        '📋 Full Audit Trail'              => '/api/audit_trail.php',
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

// ── Core metrics ────────────────────────────────────────────────────────────
$equipRow = db()->query(
    "SELECT
       COUNT(*) AS total,
       COUNT(*) FILTER (WHERE status = 'available')   AS available,
       COUNT(*) FILTER (WHERE status = 'allocated')   AS allocated,
       COUNT(*) FILTER (WHERE status = 'maintenance') AS under_maintenance,
       COUNT(*) FILTER (WHERE status = 'retired')     AS retired
     FROM equipment"
)->fetch();

$totalEq       = (int) ($equipRow['total']             ?? 0);
$availableEq   = (int) ($equipRow['available']          ?? 0);
$allocatedEq   = (int) ($equipRow['allocated']          ?? 0);
$maintenanceEq = (int) ($equipRow['under_maintenance']  ?? 0);

$utilizationRate = $totalEq > 0 ? round(($allocatedEq / $totalEq) * 100, 1) : 0;
$availabilityRate = $totalEq > 0 ? round(($availableEq / $totalEq) * 100, 1) : 0;
$downtimeRate    = $totalEq > 0 ? round(($maintenanceEq / $totalEq) * 100, 1) : 0;

$pendingRequests  = (string) db()->query("SELECT COUNT(*) FROM equipment_requests WHERE status = 'pending'")->fetchColumn();

$maintRow = db()->query(
    "SELECT
       COUNT(*) FILTER (WHERE status = 'scheduled')  AS scheduled,
       COUNT(*) FILTER (WHERE status = 'completed')  AS completed
     FROM maintenance_logs"
)->fetch();
$maintScheduled = (int) ($maintRow['scheduled'] ?? 0);
$maintCompleted = (int) ($maintRow['completed'] ?? 0);
$maintTotal     = $maintScheduled + $maintCompleted;
$maintCompletionRate = $maintTotal > 0 ? round(($maintCompleted / $maintTotal) * 100, 1) : 0;

// Overdue allocations
$overdueCount = (int) db()->query(
    "SELECT COUNT(*) FROM allocations WHERE expected_return_date < CURRENT_DATE AND expected_return_date IS NOT NULL AND status = 'active'"
)->fetchColumn();

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
    .site-title-link {
      text-decoration: none;
      color: inherit;
      font-weight: 700;
      font-size: inherit;
      transition: color .2s;
    }
    .site-title-link:hover { color: var(--accent, #cafd00); }
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
    .metrics-row {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
      gap: .75rem;
      margin-bottom: 1.5rem;
    }
    .metric-card-sm {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 12px;
      padding: 1rem 1.2rem;
    }
    .metric-card-sm .mc-label { font-size: .78rem; color: var(--text-muted, #888); text-transform: uppercase; letter-spacing: .05em; }
    .metric-card-sm .mc-value { font-size: 2rem; font-weight: 800; color: var(--accent, #cafd00); }
    .metric-card-sm .mc-sub   { font-size: .78rem; color: var(--text-muted, #888); margin-top: .15rem; }
    .metric-card-sm.warn  .mc-value { color: #f59e0b; }
    .metric-card-sm.danger .mc-value { color: #ef4444; }
    .progress-bar-wrap { background: rgba(255,255,255,.08); border-radius: 999px; height: 6px; margin-top: .5rem; overflow: hidden; }
    .progress-bar-fill { height: 100%; border-radius: 999px; background: var(--accent, #cafd00); transition: width .5s; }
    .progress-bar-fill.warn   { background: #f59e0b; }
    .progress-bar-fill.danger { background: #ef4444; }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title site-title-link">
      🏠 Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
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
  <div class="metrics-row">
    <div class="metric-card-sm">
      <div class="mc-label">Total Equipment</div>
      <div class="mc-value"><?= $totalEq ?></div>
      <div class="mc-sub"><?= $availableEq ?> available · <?= $allocatedEq ?> deployed</div>
      <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $availabilityRate ?>%"></div></div>
    </div>

    <div class="metric-card-sm <?= $availabilityRate < 30 ? 'warn' : '' ?>">
      <div class="mc-label">Availability Rate</div>
      <div class="mc-value"><?= $availabilityRate ?>%</div>
      <div class="mc-sub"><?= $availableEq ?> of <?= $totalEq ?> units available</div>
      <div class="progress-bar-wrap"><div class="progress-bar-fill <?= $availabilityRate < 30 ? 'warn' : '' ?>" style="width:<?= $availabilityRate ?>%"></div></div>
    </div>

    <div class="metric-card-sm">
      <div class="mc-label">Utilization Rate</div>
      <div class="mc-value"><?= $utilizationRate ?>%</div>
      <div class="mc-sub"><?= $allocatedEq ?> items currently deployed</div>
      <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $utilizationRate ?>%"></div></div>
    </div>

    <?php if ($maintenanceEq > 0): ?>
    <div class="metric-card-sm <?= $downtimeRate > 20 ? 'warn' : '' ?>">
      <div class="mc-label">Downtime / Under Repair</div>
      <div class="mc-value <?= $downtimeRate > 20 ? 'warn' : '' ?>"><?= $downtimeRate ?>%</div>
      <div class="mc-sub"><?= $maintenanceEq ?> items under maintenance</div>
      <div class="progress-bar-wrap"><div class="progress-bar-fill warn" style="width:<?= $downtimeRate ?>%"></div></div>
    </div>
    <?php endif; ?>

    <div class="metric-card-sm <?= (int) $pendingRequests > 0 ? 'warn' : '' ?>">
      <div class="mc-label">Pending Requests</div>
      <div class="mc-value"><?= h($pendingRequests) ?></div>
      <div class="mc-sub">Awaiting admin review</div>
    </div>

    <div class="metric-card-sm">
      <div class="mc-label">Maintenance Tasks</div>
      <div class="mc-value"><?= $maintCompleted ?> / <?= $maintTotal ?></div>
      <div class="mc-sub"><?= $maintCompletionRate ?>% completion rate — <?= $maintScheduled ?> pending</div>
      <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:<?= $maintCompletionRate ?>%"></div></div>
    </div>

    <?php if ($overdueCount > 0): ?>
    <div class="metric-card-sm danger">
      <div class="mc-label">Overdue Returns</div>
      <div class="mc-value"><?= $overdueCount ?></div>
      <div class="mc-sub">Equipment past return date!</div>
    </div>
    <?php endif; ?>

    <?php if ($unreadCount > 0): ?>
    <div class="metric-card-sm">
      <div class="mc-label">Unread Notifications</div>
      <div class="mc-value" style="color:#ef4444;"><?= $unreadCount ?></div>
      <div class="mc-sub"><a href="/api/my_notifications.php" style="color:var(--accent)">View all →</a></div>
    </div>
    <?php endif; ?>
  </div>
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
