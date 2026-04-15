<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
require_role(['admin']);
$userId = (int) $user['id'];
$dashboardTitle = 'Metric Snapshots';
$ok    = query_param('ok');
$error = query_param('error');

// Date range filter
$startDate = post_string('start_date') ?: query_param('start_date') ?: date('Y-m-d', strtotime('-90 days'));
$endDate   = post_string('end_date')   ?: query_param('end_date')   ?: date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) { $startDate = date('Y-m-d', strtotime('-90 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   { $endDate   = date('Y-m-d'); }

try {
    $snapshotsStmt = db()->prepare(
        "SELECT al.id, al.created_at, al.new_values, u.full_name AS captured_by, u.role AS captured_role
         FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE al.action_type = 'snapshot'
           AND DATE(al.created_at) BETWEEN :start AND :end
         ORDER BY al.created_at DESC"
    );
    $snapshotsStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $snapshots = $snapshotsStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Snapshots page error: ' . $e->getMessage());
    $snapshots = [];
}

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Metric Snapshots – Equipment Management System</title>
  <meta name="description" content="View captured daily metric snapshots for equipment KPI tracking.">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title site-title-link">
      Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | admin</span>
    </div>
    <div class="dashboard-topbar-actions">
      <a class="bell-btn" href="/api/my_notifications.php" aria-label="Notifications (<?= $unreadCount ?> unread)">
        <i class="fas fa-bell"></i>
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <a class="profile-link" href="/api/profile.php"><i class="fas fa-id-card"></i> Profile</a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme"><i class="fas fa-moon"></i></button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</header>

<main class="page page-snapshots">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <h2>📸 Metric Snapshots</h2>
  <p class="snapshot-intro">
    Snapshots are point-in-time captures of system KPIs. Click "Capture Snapshot" to save current metrics.
  </p>

  <!-- Actions -->
  <div class="snapshot-actions">
    <a href="/api/actions/snapshot_daily.php" class="btn btn-primary">📸 Capture Snapshot Now</a>
    <a href="/api/reports.php" class="btn btn-secondary">← Back to Reports</a>
  </div>

  <!-- Date Filter -->
  <section class="card snapshot-filter-card">
    <h2>Filter by Date Range</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="start_date">From:</label>
        <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>">
      </div>
      <div class="form-group">
        <label for="end_date">To:</label>
        <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Filter</button>
    </form>
  </section>

  <!-- Snapshot List -->
  <?php if (count($snapshots) === 0): ?>
    <p class="empty-state">
      No snapshots captured in this date range.<br>
      <a href="/api/actions/snapshot_daily.php" class="btn btn-primary snapshot-empty-action">📸 Capture First Snapshot</a>
    </p>
  <?php else: ?>
    <p class="snapshot-count"><?= count($snapshots) ?> snapshot(s) found</p>
    <?php foreach ($snapshots as $snap): ?>
    <?php $m = is_string($snap['new_values']) ? json_decode($snap['new_values'], true) : []; ?>
    <div class="snapshot-card">
      <div class="snapshot-when">
        📅 <?= h(utc_to_ph($snap['created_at'], 'M d, Y h:i A')) ?>
        &nbsp;·&nbsp; Captured by: <strong><?= h($snap['captured_by']) ?></strong>
        <span class="badge snapshot-role-badge <?= $snap['captured_role'] === 'admin' ? 'badge-warning' : 'badge-info' ?>"><?= h(ucfirst($snap['captured_role'])) ?></span>
      </div>
      <div class="snapshot-metrics">
        <div class="snap-chip">
          <strong><?= (int) ($m['equipment_total'] ?? 0) ?></strong>
          <span>Total Equipment</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['equipment_available'] ?? 0) ?></strong>
          <span>Available</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['equipment_allocated'] ?? 0) ?></strong>
          <span>Allocated</span>
        </div>
        <?php $maint = (int) ($m['equipment_maintenance'] ?? 0); ?>
        <div class="snap-chip <?= $maint > 0 ? 'warn' : '' ?>">
          <strong><?= $maint ?></strong>
          <span>Under Maintenance</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['requests_pending'] ?? 0) ?></strong>
          <span>Pending Requests</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['requests_approved'] ?? 0) ?></strong>
          <span>Approved Reqs</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['maintenance_scheduled'] ?? 0) ?></strong>
          <span>Scheduled Maintenance</span>
        </div>
        <div class="snap-chip">
          <strong><?= (int) ($m['maintenance_completed'] ?? 0) ?></strong>
          <span>Completed Maintenance</span>
        </div>
        <?php $ov = (int) ($m['allocations_overdue'] ?? 0); ?>
        <div class="snap-chip <?= $ov > 0 ? 'danger' : '' ?>">
          <strong><?= $ov ?></strong>
          <span>Overdue Allocations</span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <p class="back-link"><a href="/api/reports.php">← Back to Reports</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
