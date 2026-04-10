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

// Filters
$categoryFilter = post_string('category_filter') ?: query_param('category_filter');
$statusFilter = post_string('status_filter') ?: query_param('status_filter');

// Get distinct categories for filter dropdown
$categoryStmt = db()->query("SELECT DISTINCT category FROM equipment WHERE category IS NOT NULL ORDER BY category");
$categories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

// Build category filter where clause
$categoryWhere = $categoryFilter !== '' ? 'AND e.category = ' . db()->quote($categoryFilter) : '';
$statusWhere = $statusFilter !== '' ? 'AND e.status = ' . db()->quote($statusFilter) : '';

// Inventory by category
$inventoryRows = db()->query(
    "SELECT category, COUNT(*)::text AS items, COALESCE(SUM(quantity_total), 0)::text AS total_qty,
            COALESCE(SUM(quantity_available), 0)::text AS available_qty,
            COALESCE(SUM(quantity_total - quantity_available), 0)::text AS allocated_qty
     FROM equipment
     WHERE category IS NOT NULL $categoryWhere
     GROUP BY category
     ORDER BY category ASC"
)->fetchAll();

// Equipment usage with allocation details
$usageRows = db()->query(
    "SELECT e.id, e.name AS equipment_name, e.quantity_available, COUNT(DISTINCT a.id)::text AS allocations, 
            COALESCE(SUM(a.qty_allocated), 0)::text AS allocated_qty,
            COALESCE(COUNT(DISTINCT a.id) FILTER (WHERE a.expected_return_date < CURRENT_DATE AND a.expected_return_date IS NOT NULL), 0)::text AS overdue_count
     FROM equipment e
     LEFT JOIN allocations a ON a.equipment_id = e.id
     WHERE 1=1 $categoryWhere $statusWhere
     GROUP BY e.id, e.name, e.quantity_available
     ORDER BY e.name ASC"
)->fetchAll();

// Maintenance history with costs
$maintenanceRows = db()->query(
    "SELECT e.name AS equipment_name, COUNT(m.id)::text AS total_logs,
            COALESCE(SUM(CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END), 0)::text AS completed_logs,
            COALESCE(SUM(CASE WHEN m.status = 'scheduled' THEN 1 ELSE 0 END), 0)::text AS scheduled_logs,
            COALESCE(SUM(m.cost), 0)::text AS total_cost,
            COALESCE(SUM(CASE WHEN m.status = 'completed' THEN m.cost ELSE 0 END), 0)::text AS completed_cost
     FROM equipment e
     LEFT JOIN maintenance_logs m ON m.equipment_id = e.id
     WHERE 1=1 $categoryWhere $statusWhere
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
    
    // Overdue allocations
    $stmt = db()->query("SELECT COUNT(*) as count FROM allocations WHERE expected_return_date < CURRENT_DATE AND expected_return_date IS NOT NULL");
    $result = $stmt->fetch();
    $slaMetrics['overdue_count'] = $result['count'] ?? 0;
    
    // Utilization rate (allocated / total)
    $stmt = db()->query("SELECT COUNT(DISTINCT equipment_id) as allocated FROM allocations WHERE checkout_date <= CURRENT_DATE AND (expected_return_date IS NULL OR expected_return_date >= CURRENT_DATE)");
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
    <p class="dashboard-topbar-title"><?= h($dashboardTitle) ?></p>
    <div class="dashboard-topbar-meta">
      <span class="chip chip-role">Role: <?= h($role) ?></span>
      <span class="chip chip-id">User ID: <?= $userId ?></span>
    </div>
  </div>
  <div class="dashboard-topbar-actions">
    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
    <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
  </div>
</header>
<main class="page page-reports">

  <?php if ($ok !== ''): ?>
    <p class="alert alert-success"><?= h($ok) ?></p>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <p class="alert alert-error">Error: <?= h($error) ?></p>
  <?php endif; ?>

  <!-- Filters Section -->
  <section class="card">
    <h2>Filters</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="category_filter">Category:</label>
        <select id="category_filter" name="category_filter">
          <option value="">All Categories</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $categoryFilter === $cat ? 'selected' : '' ?>>
              <?= h(ucfirst($cat)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="status_filter">Status:</label>
        <select id="status_filter" name="status_filter">
          <option value="">All Statuses</option>
          <option value="available" <?= $statusFilter === 'available' ? 'selected' : '' ?>>Available</option>
          <option value="allocated" <?= $statusFilter === 'allocated' ? 'selected' : '' ?>>Allocated</option>
          <option value="maintenance" <?= $statusFilter === 'maintenance' ? 'selected' : '' ?>>Under Maintenance</option>
          <option value="retired" <?= $statusFilter === 'retired' ? 'selected' : '' ?>>Retired</option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary">Apply Filters</button>
      <a href="/api/reports.php" class="btn btn-secondary">Clear</a>
    </form>
  </section>

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
            <td><strong><?= h((string) $row['category']) ?></strong></td>
            <td><?= h((string) $row['items']) ?></td>
            <td><?= h((string) $row['total_qty']) ?></td>
            <td><?= h((string) $row['available_qty']) ?></td>
            <td><?= h((string) $row['allocated_qty']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr>

  <!-- Equipment Usage Summary -->
  <h2>Equipment Usage Summary</h2>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Equipment Name</th>
          <th>Available</th>
          <th>Total Allocations</th>
          <th>Total Allocated Qty</th>
          <th>Overdue</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($usageRows as $row): ?>
          <tr>
            <td><strong><?= h((string) $row['equipment_name']) ?></strong></td>
            <td><?= h((string) $row['quantity_available']) ?></td>
            <td><?= h((string) $row['allocations']) ?></td>
            <td><?= h((string) $row['allocated_qty']) ?></td>
            <td>
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
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Equipment Name</th>
          <th>Total Tasks</th>
          <th>Completed</th>
          <th>Scheduled</th>
          <th>Total Cost</th>
          <th>Completed Cost</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($maintenanceRows as $row): ?>
          <tr>
            <td><strong><?= h((string) $row['equipment_name']) ?></strong></td>
            <td><?= h((string) $row['total_logs']) ?></td>
            <td><?= h((string) $row['completed_logs']) ?></td>
            <td><?= h((string) $row['scheduled_logs']) ?></td>
            <td>$<?= number_format((float) $row['total_cost'], 2) ?></td>
            <td>$<?= number_format((float) $row['completed_cost'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <hr>

  <!-- Admin Tools -->
  <section class="card">
    <h2>Admin Tools</h2>
    <p>
      <a href="/api/actions/snapshot_daily.php" class="btn btn-secondary">📸 Capture Metrics Snapshot</a>
      <a href="/api/reports_historical.php" class="btn btn-primary">📈 View Historical Trends</a>
      <a href="/api/actions/check_overdue_allocations.php" class="btn btn-warning">⚠️ Check Overdue Items</a>
    </p>
  </section>

  <p class="back-link"><a href="/api/dashboard.php">← Back to dashboard</a></p>
</main>

<script src="/assets/app.js"></script>
</body>
</html>

