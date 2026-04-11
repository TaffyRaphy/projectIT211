<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_role(['admin']);
$adminUser = require_login();

$ok    = query_param('ok');
$error = query_param('error');

// Pagination
$page    = max(1, int_query_param('page', 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// Filters
$typeFilter   = post_string('type_filter')   ?: query_param('type_filter');
$statusFilter = post_string('status_filter') ?: query_param('status_filter');
$searchEmail  = post_string('search_email')  ?: query_param('search_email');

$where  = ['1=1'];
$params = [];

if ($typeFilter !== '') {
    $where[]        = 'n.type = :type';
    $params[':type'] = $typeFilter;
}
if ($statusFilter === 'read') {
    $where[] = 'n.is_read = true';
} elseif ($statusFilter === 'unread') {
    $where[] = 'n.is_read = false';
}
if ($searchEmail !== '') {
    $where[]          = 'u.email ILIKE :email';
    $params[':email'] = '%' . $searchEmail . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Total count
$cntStmt = db()->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id {$whereClause}");
$cntStmt->execute($params);
$totalCount = (int) $cntStmt->fetchColumn();
$totalPages = (int) ceil($totalCount / $perPage);

// Fetch logs — join users so we get full_name
$stmt = db()->prepare(
    "SELECT n.id, n.message, n.type, n.is_read, n.created_at,
            u.email AS recipient_email, u.full_name AS recipient_name, u.role AS recipient_role
     FROM notifications n
     JOIN users u ON u.id = n.user_id
     {$whereClause}
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$logs = $stmt->fetchAll();

// Summary stats
$unreadTotal  = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE is_read = false")->fetchColumn();
$readTotal    = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE is_read = true")->fetchColumn();
$totalNotifs  = $unreadTotal + $readTotal;

$eventTypes = [
    'request_submitted',       'request_approved',      'request_rejected',
    'maintenance_scheduled',   'maintenance_completed',
    'equipment_due_return',    'equipment_overdue_return',
];

$typeIcons = [
    'request_submitted'        => '📋',
    'request_approved'         => '✅',
    'request_rejected'         => '❌',
    'maintenance_scheduled'    => '🔧',
    'maintenance_completed'    => '✔️',
    'equipment_due_return'     => '⏰',
    'equipment_overdue_return' => '🚨',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Notification Logs – Equipment Management System</title>
  <meta name="description" content="Admin view of all in-app and email notifications sent in the system.">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title">📧 Notification Logs</p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: admin | User ID: <?= (int) $adminUser['id'] ?></span>
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

  <!-- Summary cards -->
  <div class="metrics-grid" style="margin-bottom: 1.5rem;">
    <div class="metric-card">
      <p class="metric-label">Total Notifications</p>
      <p class="metric-value"><?= $totalNotifs ?></p>
    </div>
    <div class="metric-card metric-card-warning">
      <p class="metric-label">Unread</p>
      <p class="metric-value"><?= $unreadTotal ?></p>
    </div>
    <div class="metric-card">
      <p class="metric-label">Read</p>
      <p class="metric-value"><?= $readTotal ?></p>
    </div>
  </div>

  <!-- Filters -->
  <section class="card">
    <h2>Filters</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="type_filter">Event Type:</label>
        <select id="type_filter" name="type_filter">
          <option value="">All Events</option>
          <?php foreach ($eventTypes as $type): ?>
            <option value="<?= h($type) ?>" <?= $typeFilter === $type ? 'selected' : '' ?>>
              <?= $typeIcons[$type] ?? '' ?> <?= h(ucwords(str_replace('_', ' ', $type))) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="status_filter">Read Status:</label>
        <select id="status_filter" name="status_filter">
          <option value="">All</option>
          <option value="unread" <?= $statusFilter === 'unread' ? 'selected' : '' ?>>🔴 Unread</option>
          <option value="read"   <?= $statusFilter === 'read'   ? 'selected' : '' ?>>✅ Read</option>
        </select>
      </div>
      <div class="form-group">
        <label for="search_email">Recipient Email:</label>
        <input type="text" id="search_email" name="search_email" placeholder="Search email..." value="<?= h($searchEmail) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Apply Filters</button>
      <a href="/api/notification_logs.php" class="btn btn-secondary">Clear</a>
    </form>
  </section>

  <!-- Logs table -->
  <section class="card">
    <h2>Notification History (<?= $totalCount ?> total)</h2>
    <?php if (count($logs) === 0): ?>
      <p class="empty-state">No notifications found matching your filters.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>When</th>
            <th>Type</th>
            <th>Recipient</th>
            <th>Role</th>
            <th>Status</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td><?= h(utc_to_ph($log['created_at'])) ?></td>
            <td>
              <span class="badge badge-info">
                <?= $typeIcons[$log['type']] ?? '📢' ?>
                <?= h(ucwords(str_replace('_', ' ', $log['type']))) ?>
              </span>
            </td>
            <td>
              <strong><?= h($log['recipient_name']) ?></strong><br>
              <small style="color:var(--text-muted)"><?= h($log['recipient_email']) ?></small>
            </td>
            <td>
              <span class="badge <?= match($log['recipient_role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>">
                <?= h(ucfirst($log['recipient_role'])) ?>
              </span>
            </td>
            <td>
              <?php if ($log['is_read']): ?>
                <span class="badge badge-success">✓ Read</span>
              <?php else: ?>
                <span class="badge badge-error">● Unread</span>
              <?php endif; ?>
            </td>
            <td style="max-width: 280px; font-size:.85rem; word-break: break-word;">
              <?= h($log['message']) ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="pagination">
      <ul>
        <?php if ($page > 1): ?>
          <li><a href="?page=1&type_filter=<?= h($typeFilter) ?>&status_filter=<?= h($statusFilter) ?>&search_email=<?= h($searchEmail) ?>">« First</a></li>
          <li><a href="?page=<?= $page - 1 ?>&type_filter=<?= h($typeFilter) ?>&status_filter=<?= h($statusFilter) ?>&search_email=<?= h($searchEmail) ?>">‹ Prev</a></li>
        <?php endif; ?>
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
          <li>
            <a href="?page=<?= $p ?>&type_filter=<?= h($typeFilter) ?>&status_filter=<?= h($statusFilter) ?>&search_email=<?= h($searchEmail) ?>"
               <?= $p === $page ? 'class="active"' : '' ?>>
              <?= $p ?>
            </a>
          </li>
        <?php endfor; ?>
        <?php if ($page < $totalPages): ?>
          <li><a href="?page=<?= $page + 1 ?>&type_filter=<?= h($typeFilter) ?>&status_filter=<?= h($statusFilter) ?>&search_email=<?= h($searchEmail) ?>">Next ›</a></li>
          <li><a href="?page=<?= $totalPages ?>&type_filter=<?= h($typeFilter) ?>&status_filter=<?= h($statusFilter) ?>&search_email=<?= h($searchEmail) ?>">Last »</a></li>
        <?php endif; ?>
      </ul>
    </nav>
    <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- Settings links -->
  <section class="card">
    <h2>Notification Actions</h2>
    <p>
      <a href="/api/email_config.php"                              class="btn btn-primary">Configure SMTP / Resend</a>
      <a href="/api/actions/check_overdue_allocations.php"         class="btn btn-secondary">Check Overdue Returns Now</a>
      <a href="/api/my_notifications.php"                          class="btn btn-secondary">My Notification Inbox</a>
    </p>
  </section>

  <nav class="breadcrumb">
    <a href="/api/dashboard.php">← Back to Dashboard</a>
  </nav>
</div>

<script src="/assets/app.js"></script>
</body>
</html>
