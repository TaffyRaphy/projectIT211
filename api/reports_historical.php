<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_role(['admin']);
$userId        = (int) $user['id'];
$dashboardTitle= 'Historical Reports';
$ok    = query_param('ok');
$error = query_param('error');

// Date range — default last 30 days
$startDate = post_string('start_date') ?: query_param('start_date') ?: date('Y-m-d', strtotime('-30 days'));
$endDate   = post_string('end_date')   ?: query_param('end_date')   ?: date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) { $startDate = date('Y-m-d', strtotime('-30 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))   { $endDate   = date('Y-m-d'); }
if ($startDate > $endDate) { [$startDate, $endDate] = [$endDate, $startDate]; }

try {
    // ── Equipment request activity by day ─────────────────────────────────────
    $requestTrendStmt = db()->prepare(
        "SELECT DATE(requested_at)::text AS day,
                COUNT(*) AS requests_submitted,
                COUNT(*) FILTER (WHERE status IN ('allocated')) AS requests_approved,
                COUNT(*) FILTER (WHERE status = 'rejected')    AS requests_rejected
         FROM equipment_requests
         WHERE DATE(requested_at) BETWEEN :start AND :end
         GROUP BY DATE(requested_at)
         ORDER BY DATE(requested_at) ASC"
    );
    $requestTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $requestTrend = $requestTrendStmt->fetchAll();

    // ── Allocations checked out by day ───────────────────────────────────────
    $allocationTrendStmt = db()->prepare(
        "SELECT DATE(checkout_date)::text AS day,
                COUNT(*) AS new_allocations,
                COALESCE(SUM(qty_allocated), 0) AS qty_allocated
         FROM allocations
         WHERE DATE(checkout_date) BETWEEN :start AND :end
         GROUP BY DATE(checkout_date)
         ORDER BY DATE(checkout_date) ASC"
    );
    $allocationTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $allocationTrend = $allocationTrendStmt->fetchAll();

    // ── Maintenance completed by day + cost ───────────────────────────────────
    $maintenanceTrendStmt = db()->prepare(
        "SELECT completed_date::text AS day,
                COUNT(*) AS tasks_completed,
                COALESCE(SUM(cost), 0) AS total_cost
         FROM maintenance_logs
         WHERE status = 'completed'
           AND completed_date BETWEEN :start AND :end
         GROUP BY completed_date
         ORDER BY completed_date ASC"
    );
    $maintenanceTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $maintenanceTrend = $maintenanceTrendStmt->fetchAll();

    // ── Audit activity by user in period ─────────────────────────────────────
    $auditByUserStmt = db()->prepare(
        "SELECT u.full_name, u.role,
                COUNT(*) AS total_actions,
                COUNT(*) FILTER (WHERE al.action_type = 'login')    AS logins,
                COUNT(*) FILTER (WHERE al.action_type = 'create')   AS creates,
                COUNT(*) FILTER (WHERE al.action_type = 'approve')  AS approvals,
                COUNT(*) FILTER (WHERE al.action_type = 'reject')   AS rejections,
                COUNT(*) FILTER (WHERE al.action_type = 'complete') AS completions
         FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE DATE(al.created_at) BETWEEN :start AND :end
         GROUP BY u.id, u.full_name, u.role
         ORDER BY total_actions DESC"
    );
    $auditByUserStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $auditByUser = $auditByUserStmt->fetchAll();

    // ── Daily snapshot history from audit_logs ────────────────────────────────
    $snapshotsStmt = db()->prepare(
        "SELECT al.created_at, al.new_values, u.full_name AS captured_by
         FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         WHERE al.action_type = 'snapshot'
           AND DATE(al.created_at) BETWEEN :start AND :end
         ORDER BY al.created_at DESC
         LIMIT 30"
    );
    $snapshotsStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $snapshots = $snapshotsStmt->fetchAll();

} catch (Throwable $e) {
    error_log('Historical report error: ' . $e->getMessage());
    $requestTrend    = [];
    $allocationTrend = [];
    $maintenanceTrend= [];
    $auditByUser     = [];
    $snapshots       = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Historical Reports – Equipment Management System</title>
  <meta name="description" content="Historical trend analysis for equipment requests, allocations, maintenance costs, and user activity.">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title"><?= h($dashboardTitle) ?></p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: admin | User ID: <?= $userId ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>

<div class="container">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <!-- Date range filter -->
  <section class="card">
    <h2>Select Date Range</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="start_date">Start Date:</label>
        <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>" required>
      </div>
      <div class="form-group">
        <label for="end_date">End Date:</label>
        <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>" required>
      </div>
      <button type="submit" class="btn btn-primary">Update Report</button>
    </form>
  </section>

  <!-- Request Activity Trend -->
  <section class="card">
    <h2>Request Activity Trend</h2>
    <p class="text-muted">Daily equipment request submissions, approvals, and rejections from <?= h($startDate) ?> to <?= h($endDate) ?>.</p>
    <?php if (count($requestTrend) === 0): ?>
      <p class="empty-state">No request activity in this date range.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Date</th><th>Submitted</th><th>Approved</th><th>Rejected</th></tr></thead>
        <tbody>
          <?php foreach ($requestTrend as $row): ?>
          <tr>
            <td><strong><?= h($row['day']) ?></strong></td>
            <td><?= (int) $row['requests_submitted'] ?></td>
            <td><span class="badge badge-success"><?= (int) $row['requests_approved'] ?></span></td>
            <td><span class="badge badge-error"><?= (int) $row['requests_rejected'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- Allocation Trend -->
  <section class="card">
    <h2>Allocation Activity Trend</h2>
    <p class="text-muted">Daily new allocations checked out.</p>
    <?php if (count($allocationTrend) === 0): ?>
      <p class="empty-state">No allocation activity in this date range.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Date</th><th>New Allocations</th><th>Qty Allocated</th></tr></thead>
        <tbody>
          <?php foreach ($allocationTrend as $row): ?>
          <tr>
            <td><strong><?= h($row['day']) ?></strong></td>
            <td><?= (int) $row['new_allocations'] ?></td>
            <td><?= (int) $row['qty_allocated'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- Maintenance Cost Trend -->
  <section class="card">
    <h2>Maintenance Completion Trend</h2>
    <p class="text-muted">Completed maintenance tasks and cumulative cost.</p>
    <?php if (count($maintenanceTrend) === 0): ?>
      <p class="empty-state">No completed maintenance in this date range.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Date</th><th>Tasks Completed</th><th>Total Cost</th><th>Avg Cost/Task</th></tr></thead>
        <tbody>
          <?php foreach ($maintenanceTrend as $row): ?>
          <?php $tasks = (int) $row['tasks_completed']; $cost = (float) $row['total_cost']; ?>
          <tr>
            <td><strong><?= h($row['day']) ?></strong></td>
            <td><?= $tasks ?></td>
            <td>₱<?= number_format($cost, 2) ?></td>
            <td><?= $tasks > 0 ? '₱' . number_format($cost / $tasks, 2) : '–' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- User Activity Summary -->
  <section class="card">
    <h2>User Activity Summary</h2>
    <p class="text-muted">Audit log actions broken down per user in this period.</p>
    <?php if (count($auditByUser) === 0): ?>
      <p class="empty-state">No audit activity in this date range.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Total Actions</th><th>Logins</th><th>Creates</th><th>Approvals</th><th>Rejections</th><th>Completions</th></tr></thead>
        <tbody>
          <?php foreach ($auditByUser as $row): ?>
          <tr>
            <td><strong><?= h($row['full_name']) ?></strong></td>
            <td><span class="badge <?= match($row['role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>"><?= h(ucfirst($row['role'])) ?></span></td>
            <td><strong><?= (int) $row['total_actions'] ?></strong></td>
            <td><?= (int) $row['logins'] ?></td>
            <td><?= (int) $row['creates'] ?></td>
            <td><?= (int) $row['approvals'] ?></td>
            <td><?= (int) $row['rejections'] ?></td>
            <td><?= (int) $row['completions'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- Snapshot History -->
  <section class="card">
    <h2>📸 Metric Snapshots</h2>
    <p class="text-muted">Snapshots captured via "📸 Capture Metrics Snapshot" button.</p>
    <?php if (count($snapshots) === 0): ?>
      <p class="empty-state">No snapshots captured in this range. <a href="/api/actions/snapshot_daily.php">Capture one now →</a></p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Captured At</th><th>By</th><th>Equipment Total</th><th>Pending Requests</th><th>Overdue</th></tr></thead>
        <tbody>
          <?php foreach ($snapshots as $snap): ?>
          <?php $m = is_string($snap['new_values']) ? json_decode($snap['new_values'], true) : []; ?>
          <tr>
            <td><?= h(utc_to_ph($snap['created_at'])) ?></td>
            <td><?= h($snap['captured_by']) ?></td>
            <td><?= (int) ($m['equipment_total'] ?? '–') ?></td>
            <td><?= (int) ($m['requests_pending'] ?? '–') ?></td>
            <td>
              <?php $ov = (int) ($m['allocations_overdue'] ?? 0); ?>
              <?php if ($ov > 0): ?><span class="badge badge-error"><?= $ov ?></span><?php else: ?><span class="badge badge-success">0</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- Tools -->
  <section class="card">
    <h2>Actions</h2>
    <p>
      <a href="/api/actions/snapshot_daily.php" class="btn btn-secondary">📸 Capture Metrics Now</a>
      <a href="/api/reports.php"                class="btn btn-primary">← Back to Reports</a>
    </p>
  </section>

  <nav class="breadcrumb">
    <a href="/api/reports.php">← Reports</a>
    <a href="/api/dashboard.php">← Dashboard</a>
  </nav>
</div>

<script src="/assets/app.js"></script>
</body>
</html>
