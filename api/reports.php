<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_role(['admin']);
$userId = (int) $user['id'];
$role = 'admin';
$dashboardTitle = 'Equipment Reports';
$ok = query_param('ok');
$error = query_param('error');

// Inventory by category
$inventoryRows = db()->query(
    "SELECT category, COUNT(*)::text AS items, COALESCE(SUM(quantity_total), 0)::text AS total_qty,
            COALESCE(SUM(quantity_available), 0)::text AS available_qty,
            COALESCE(SUM(quantity_total - quantity_available), 0)::text AS allocated_qty
     FROM equipment
  WHERE category IS NOT NULL
     GROUP BY category
     ORDER BY category ASC"
)->fetchAll();

// Equipment usage with allocation details (overdue = active allocations past due date)
$usageRows = db()->query(
    "SELECT e.id, e.name AS equipment_name, e.quantity_available,
            COALESCE(COUNT(DISTINCT a.id) FILTER (WHERE a.status = 'active'), 0)::text AS active_allocations,
            COALESCE(COUNT(DISTINCT a.id), 0)::text AS total_allocations,
            COALESCE(SUM(a.qty_allocated) FILTER (WHERE a.status = 'active'), 0)::text AS currently_allocated_qty,
            COALESCE(COUNT(DISTINCT a.id) FILTER (
                WHERE a.status = 'active'
                  AND a.expected_return_date < CURRENT_DATE
                  AND a.expected_return_date IS NOT NULL
            ), 0)::text AS overdue_count
     FROM equipment e
     LEFT JOIN allocations a ON a.equipment_id = e.id
     GROUP BY e.id, e.name, e.quantity_available
     ORDER BY e.name ASC"
)->fetchAll();

// Maintenance history with costs (exclude cancelled from cost totals)
$maintenanceRows = db()->query(
    "SELECT e.name AS equipment_name,
            COUNT(m.id)::text AS total_logs,
            COALESCE(COUNT(m.id) FILTER (WHERE m.status = 'completed'),  0)::text AS completed_logs,
            COALESCE(COUNT(m.id) FILTER (WHERE m.status = 'scheduled'),  0)::text AS scheduled_logs,
            COALESCE(COUNT(m.id) FILTER (WHERE m.status = 'cancelled'),  0)::text AS cancelled_logs,
            COALESCE(SUM(CASE WHEN m.status <> 'cancelled' THEN m.cost ELSE 0 END), 0)::text AS total_cost,
            COALESCE(SUM(CASE WHEN m.status = 'completed'  THEN m.cost ELSE 0 END), 0)::text AS completed_cost
     FROM equipment e
     LEFT JOIN maintenance_logs m ON m.equipment_id = e.id
     GROUP BY e.name
     ORDER BY e.name ASC"
)->fetchAll();

// SLA and Performance Metrics
$slaMetrics = [];
try {
    // Average approval time
    $stmt = db()->query("SELECT EXTRACT(EPOCH FROM AVG(reviewed_at - requested_at))/3600 as avg_hours FROM equipment_requests WHERE status IN ('allocated', 'rejected') AND reviewed_at IS NOT NULL");
    $result = $stmt->fetch();
    $slaMetrics['avg_approval_hours'] = $result ? (int) ($result['avg_hours'] ?? 0) : 0;
    
    // Overdue allocations (active only)
    $stmt = db()->query("SELECT COUNT(*) as count FROM allocations WHERE status = 'active' AND expected_return_date < CURRENT_DATE AND expected_return_date IS NOT NULL");
    $result = $stmt->fetch();
    $slaMetrics['overdue_count'] = $result['count'] ?? 0;

    // Utilization rate (active allocations / total equipment)
    $stmt = db()->query("SELECT COUNT(DISTINCT equipment_id) as allocated FROM allocations WHERE status = 'active'");
    $allocated = (int) ($stmt->fetch()['allocated'] ?? 0);
    $stmt = db()->query("SELECT COUNT(*) as total FROM equipment");
    $total = (int) ($stmt->fetch()['total'] ?? 1);
    $slaMetrics['utilization_rate'] = $total > 0 ? round(($allocated / $total) * 100, 1) : 0;
    
    // Request approval rate
    $stmt = db()->query("SELECT COUNT(*) FILTER (WHERE status = 'allocated') as approved, COUNT(*) FILTER (WHERE status IN ('allocated', 'rejected')) as reviewed FROM equipment_requests");
    $result = $stmt->fetch();
    $approved = (int) ($result['approved'] ?? 0);
    $reviewed = (int) ($result['reviewed'] ?? 1);
    $slaMetrics['approval_rate'] = $reviewed > 0 ? round(($approved / $reviewed) * 100, 1) : 0;
} catch (Throwable $e) {
    error_log('SLA metrics error: ' . $e->getMessage());
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Reports</title>
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
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <?php $unreadCount = NotificationService::getInstance()->getUnreadCount($userId); ?>
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
<main class="page page-reports">

  <?php if ($ok !== ''): ?>
    <p class="alert alert-success"><?= h($ok) ?></p>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <p class="alert alert-error">Error: <?= h($error) ?></p>
  <?php endif; ?>

  <!-- SLA & Performance Metrics -->
  <section class="card">
    <h2>Performance Metrics</h2>
    <div class="metrics-grid">
      <div class="metric-card">
        <p class="metric-label">Equipment Utilization Rate</p>
        <p class="metric-value"><?= h((string) $slaMetrics['utilization_rate']) ?>%</p>
        <p class="metric-detail">Percentage of equipment currently allocated</p>
      </div>
      <div class="metric-card">
        <p class="metric-label">Request Approval Rate</p>
        <p class="metric-value"><?= h((string) $slaMetrics['approval_rate']) ?>%</p>
        <p class="metric-detail">Percentage of requests approved vs declined</p>
      </div>
      <div class="metric-card">
        <p class="metric-label">Avg Approval Time</p>
        <p class="metric-value"><?= h((string) $slaMetrics['avg_approval_hours']) ?> hrs</p>
        <p class="metric-detail">Average time to approve/decline requests</p>
      </div>
      <div class="metric-card metric-card-warning">
        <p class="metric-label">Overdue Returns</p>
        <p class="metric-value"><?= h((string) $slaMetrics['overdue_count']) ?></p>
        <p class="metric-detail">Equipment past due return date</p>
      </div>
    </div>
  </section>

  <!-- Inventory Status Summary -->
  <h2>Inventory Status Summary</h2>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Category</th>
          <th>Items</th>
          <th>Total Qty</th>
          <th>Available</th>
          <th>Allocated</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($inventoryRows as $row): ?>
          <tr>
            <td data-label="Category"><strong><?= h((string) $row['category']) ?></strong></td>
            <td data-label="Items"><?= h((string) $row['items']) ?></td>
            <td data-label="Total Qty"><?= h((string) $row['total_qty']) ?></td>
            <td data-label="Available"><?= h((string) $row['available_qty']) ?></td>
            <td data-label="Allocated"><?= h((string) $row['allocated_qty']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr>

  <!-- Equipment Usage Summary -->
  <h2>Equipment Usage Summary</h2>
  <p class="table-note">Active allocations only. Historical total includes returned equipment.</p>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Equipment Name</th>
          <th>Currently Available</th>
          <th>Active Allocations</th>
          <th>Currently Allocated Qty</th>
          <th>Historical Total</th>
          <th>Overdue (Active)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usageRows as $row): ?>
          <tr>
            <td data-label="Equipment Name"><strong><?= h((string) $row['equipment_name']) ?></strong></td>
            <td data-label="Available"><?= h((string) $row['quantity_available']) ?></td>
            <td data-label="Active Allocations"><?= h((string) $row['active_allocations']) ?></td>
            <td data-label="Currently Allocated Qty"><?= h((string) $row['currently_allocated_qty']) ?></td>
            <td data-label="Historical Total" class="table-cell-muted"><?= h((string) $row['total_allocations']) ?></td>
            <td data-label="Overdue (Active)">
              <?php if ((int) $row['overdue_count'] > 0): ?>
                <span class="badge badge-error"><?= h((string) $row['overdue_count']) ?></span>
              <?php else: ?>
                <span class="badge badge-success">0</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr>

  <!-- Maintenance History & Cost Summary -->
  <h2>Maintenance Summary</h2>
  <p class="table-note">Costs exclude cancelled tasks. Total Tasks includes all historical records.</p>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Equipment Name</th>
          <th>Total Tasks</th>
          <th>Completed</th>
          <th>Scheduled</th>
          <th>Cancelled</th>
          <th>Total Cost (excl. cancelled)</th>
          <th>Completed Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($maintenanceRows as $row): ?>
          <?php if ((int)$row['total_logs'] === 0) continue; ?>
          <tr>
            <td data-label="Equipment Name"><strong><?= h((string) $row['equipment_name']) ?></strong></td>
            <td data-label="Total Tasks"><?= h((string) $row['total_logs']) ?></td>
            <td data-label="Completed"><?= h((string) $row['completed_logs']) ?></td>
            <td data-label="Scheduled"><?= h((string) $row['scheduled_logs']) ?></td>
            <td data-label="Cancelled" class="table-cell-muted"><?= h((string) $row['cancelled_logs']) ?></td>
            <td data-label="Total Cost">₱<?= number_format((float) $row['total_cost'], 2) ?></td>
            <td data-label="Completed Cost">₱<?= number_format((float) $row['completed_cost'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr>

  <!-- Admin Tools -->
  <!-- Audit Trail Report -->
  <hr>
  <h2>Audit Trail <small class="audit-title-inline">(Last 50 — <a href="/api/audit_trail.php" class="audit-link">View Full Trail →</a>)</small></h2>
  <p class="audit-intro">Recent system actions across all users.</p>
  <?php
    $auditRows = [];
    try {
        $auditRows = db()->query(
            "SELECT al.id, al.action_type, al.table_name, al.record_id, al.new_values, al.created_at,
                    u.full_name AS user_name, u.email AS user_email, u.role AS user_role
             FROM audit_logs al
             JOIN users u ON u.id = al.user_id
             ORDER BY al.created_at DESC
             LIMIT 50"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('Audit trail query error: ' . $e->getMessage());
    }
    $actionTypeIcon = [
        'login'    => '<i class="fas fa-key"></i>', 'create'   => '<i class="fas fa-plus"></i>',
        'update'   => '<i class="fas fa-pencil"></i>', 'approve'  => '<i class="fas fa-check"></i>',
        'reject'   => '<i class="fas fa-times"></i>', 'complete' => '<i class="fas fa-check-circle"></i>', 'snapshot' => '<i class="fas fa-camera"></i>',
        'cancel'   => '<i class="fas fa-times-circle"></i>',
    ];
  ?>
  <?php if (count($auditRows) === 0): ?>
    <p class="empty-state">No audit log entries yet. Actions will appear here after users interact with the system.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th></th>
          <th>User</th>
          <th>Role</th>
          <th>Action</th>
          <th>Table</th>
          <th>Record</th>
          <th>Details</th>
          <th>When</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($auditRows as $entry): ?>
        <tr>
          <td><?= $actionTypeIcon[$entry['action_type']] ?? '📌' ?></td>
          <td>
            <strong><?= h($entry['user_name']) ?></strong><br>
            <small class="audit-user-email"><?= h($entry['user_email']) ?></small>
          </td>
          <td>
            <span class="badge <?= match($entry['user_role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>">
              <?= h(ucfirst($entry['user_role'])) ?>
            </span>
          </td>
          <td class="audit-action-cell"><?= h($entry['action_type']) ?></td>
          <td><code><?= h($entry['table_name']) ?></code></td>
          <td><?= (int) $entry['record_id'] ?></td>
          <td class="audit-detail-cell">
            <?php
              $nv = is_string($entry['new_values']) ? json_decode($entry['new_values'], true) : null;
              if (is_array($nv)) {
                  $show = array_filter($nv, fn($k) => in_array($k, ['status', 'name', 'equipment_name', 'role', 'email', 'full_name', 'staff_name', 'maintenance_type', 'schedule_date', 'completed_date', 'qty_requested', 'qty_allocated', 'qty_returned', 'expected_return_date', 'cancelled_by', 'action', 'category', 'location', 'code', 'work_done', 'purpose']), ARRAY_FILTER_USE_KEY);
                  foreach ($show as $k => $v) {
                      echo '<strong>' . h($k) . '</strong>: ' . h((string)$v) . ' ';
                  }
              }
            ?>
          </td>
          <td><?= h(utc_to_ph($entry['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <hr>

  <!-- Admin Tools -->
  <section class="card">
    <h2>Admin Tools</h2>
    <div class="reports-actions">
      <a href="/api/actions/snapshot_daily.php"          class="btn btn-secondary"><i class="fas fa-camera"></i> Capture Metrics Snapshot</a>
      <a href="/api/snapshots.php"                       class="btn btn-primary"><i class="fas fa-chart-bar"></i> View All Snapshots</a>
      <a href="/api/audit_trail.php"                     class="btn btn-primary"><i class="fas fa-list"></i> Full Audit Trail</a>
      <a href="/api/reports_historical.php"              class="btn btn-secondary"><i class="fas fa-chart-line"></i> Historical Trends</a>
      <a href="/api/actions/check_overdue_allocations.php" class="btn btn-warning"><i class="fas fa-exclamation-triangle"></i> Check Overdue Items</a>
      <a href="/api/notification_logs.php"               class="btn btn-secondary"><i class="fas fa-envelope"></i> Notification Logs</a>
      <a href="/api/users.php"                           class="btn btn-secondary"><i class="fas fa-users"></i> User Management</a>
      <a href="/api/actions/test_email.php"              class="btn btn-warning"><i class="fas fa-envelope"></i> Test Resend Email</a>
    </div>
    <div class="export-wrap">
      <span class="export-label">📄 Export Summary (HTML)</span>
      <form action="/api/actions/generate_report_pdf.php" method="get" class="export-form">
        <input type="hidden" name="report_type" value="summary">
        <label for="trend_metric_export" class="export-field-label">Trend metric:</label>
        <select id="trend_metric_export" name="trend_metric" class="export-field-select">
          <option value="cost">Maintenance Cost</option>
          <option value="utilization">Equipment Utilization Rate</option>
          <option value="requests">Total Requests</option>
        </select>
        <button type="submit" class="btn btn-primary export-btn">Export</button>
      </form>
    </div>
  </section>

  <p class="back-link"><a href="/api/dashboard.php"><i class="fas fa-arrow-left"></i> Back to dashboard</a></p>
</main>

<script src="/assets/app.js"></script>
</body>
</html>

