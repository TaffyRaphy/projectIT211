<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
require_role(['admin']);
$userId = (int) $user['id'];

// Filters
$userFilter   = post_string('user_filter')   ?: query_param('user_filter');
$actionFilter = post_string('action_filter') ?: query_param('action_filter');
$tableFilter  = post_string('table_filter')  ?: query_param('table_filter');
$startDate    = post_string('start_date')    ?: query_param('start_date');
$endDate      = post_string('end_date')      ?: query_param('end_date');
$page         = max(1, int_query_param('page', 1));
$perPage      = 50;
$offset       = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($userFilter !== '') {
    $where[]             = "(u.full_name ILIKE :user_filter OR u.email ILIKE :user_filter)";
    $params[':user_filter'] = '%' . $userFilter . '%';
}
if ($actionFilter !== '') {
    $where[]              = "al.action_type = :action_filter";
    $params[':action_filter'] = $actionFilter;
}
if ($tableFilter !== '') {
    $where[]             = "al.table_name = :table_filter";
    $params[':table_filter'] = $tableFilter;
}
if ($startDate !== '') {
    $where[]             = "DATE(al.created_at) >= :start_date";
    $params[':start_date'] = $startDate;
}
if ($endDate !== '') {
    $where[]           = "DATE(al.created_at) <= :end_date";
    $params[':end_date'] = $endDate;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    // Total count for pagination
    $countStmt = db()->prepare(
        "SELECT COUNT(*) FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         {$whereClause}"
    );
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetchColumn();
    $totalPages = (int) ceil($totalCount / $perPage);

    // The actual rows
    $auditStmt = db()->prepare(
        "SELECT al.id, al.action_type, al.table_name, al.record_id, al.new_values, al.old_values, al.created_at,
                u.full_name AS user_name, u.email AS user_email, u.role AS user_role
         FROM audit_logs al
         JOIN users u ON u.id = al.user_id
         {$whereClause}
         ORDER BY al.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $auditStmt->execute($params);
    $auditRows = $auditStmt->fetchAll();

    // Available action types and tables for filter dropdowns
    $actionTypes = db()->query("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type")->fetchAll(PDO::FETCH_COLUMN);
    $tableNames  = db()->query("SELECT DISTINCT table_name FROM audit_logs ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('Audit trail error: ' . $e->getMessage());
    $auditRows = [];
    $totalCount = 0;
    $totalPages = 1;
    $actionTypes = [];
    $tableNames  = [];
}

$actionTypeIcon = [
    'login'    => '🔑', 'create'   => '➕',
    'update'   => '✏️', 'approve'  => '✅',
    'reject'   => '❌', 'complete' => '✔️', 'snapshot' => '📸',
];

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

function buildPageUrl(int $p, array $filters): string {
    $params = array_filter($filters, fn($v) => $v !== '');
    $params['page'] = $p;
    return '/api/audit_trail.php?' . http_build_query($params);
}
$filters = [
    'user_filter'   => $userFilter,
    'action_filter' => $actionFilter,
    'table_filter'  => $tableFilter,
    'start_date'    => $startDate,
    'end_date'      => $endDate,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Full Audit Trail – Equipment Management System</title>
  <meta name="description" content="Complete audit trail of all system actions — searchable and filterable.">
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    .pagination { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; margin: 1rem 0; }
    .pagination a, .pagination span {
      padding: .3rem .75rem; border-radius: 6px; font-size: .85rem; text-decoration: none;
      border: 1px solid var(--border-color, #2a2a2a);
    }
    .pagination a { color: var(--text-color); transition: background .2s; }
    .pagination a:hover { background: rgba(255,255,255,.06); }
    .pagination span { background: var(--accent, #cafd00); color: #111; font-weight: 700; border-color: var(--accent); }
    .audit-meta-row { font-size: .78rem; color: var(--text-muted); }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title" style="text-decoration:none; color:inherit;">
      🏠 Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | admin</span>
    </div>
    <div class="dashboard-topbar-actions">
      <a class="bell-btn" href="/api/my_notifications.php">
        🔔
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <a class="profile-link" href="/api/profile.php">🪪 Profile</a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php">Logout</a>
    </div>
  </div>
</header>

<main class="page">
  <h2>📋 Full Audit Trail</h2>
  <p style="color: var(--text-muted); margin-bottom: 1rem;">
    Complete history of all system actions. Total: <strong style="color: var(--accent)"><?= number_format($totalCount) ?></strong> entries.
  </p>

  <!-- Filters -->
  <section class="card">
    <h2>Filters</h2>
    <form method="post" class="filter-form" style="flex-wrap: wrap;">
      <div class="form-group">
        <label for="user_filter">User (name or email):</label>
        <input type="text" id="user_filter" name="user_filter" value="<?= h($userFilter) ?>" placeholder="Search user...">
      </div>
      <div class="form-group">
        <label for="action_filter">Action:</label>
        <select id="action_filter" name="action_filter">
          <option value="">All Actions</option>
          <?php foreach ($actionTypes as $at): ?>
            <option value="<?= h($at) ?>" <?= $actionFilter === $at ? 'selected' : '' ?>><?= h(ucfirst($at)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="table_filter">Table:</label>
        <select id="table_filter" name="table_filter">
          <option value="">All Tables</option>
          <?php foreach ($tableNames as $tn): ?>
            <option value="<?= h($tn) ?>" <?= $tableFilter === $tn ? 'selected' : '' ?>><?= h($tn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="start_date">From:</label>
        <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>">
      </div>
      <div class="form-group">
        <label for="end_date">To:</label>
        <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>">
      </div>
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="/api/audit_trail.php" class="btn btn-secondary">Clear</a>
    </form>
  </section>

  <!-- Pagination top -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= h(buildPageUrl($page - 1, $filters)) ?>">← Prev</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="<?= h(buildPageUrl($page + 1, $filters)) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Table -->
  <?php if (count($auditRows) === 0): ?>
    <p class="empty-state">No audit entries match your filters.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>#</th>
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
          <td class="audit-meta-row"><?= (int) $entry['id'] ?></td>
          <td><?= $actionTypeIcon[$entry['action_type']] ?? '📌' ?></td>
          <td>
            <strong><?= h($entry['user_name']) ?></strong><br>
            <span class="audit-meta-row"><?= h($entry['user_email']) ?></span>
          </td>
          <td>
            <span class="badge <?= match($entry['user_role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>">
              <?= h(ucfirst($entry['user_role'])) ?>
            </span>
          </td>
          <td style="text-transform:capitalize; font-weight:600;"><?= h($entry['action_type']) ?></td>
          <td><code><?= h($entry['table_name']) ?></code></td>
          <td><?= (int) $entry['record_id'] ?></td>
          <td style="max-width:220px; font-size:.78rem; color:var(--text-muted);">
            <?php
              $nv = is_string($entry['new_values']) ? json_decode($entry['new_values'], true) : null;
              if (is_array($nv)) {
                  $show = array_filter($nv, fn($k) => in_array($k, ['status', 'name', 'equipment_name', 'role', 'email', 'full_name']), ARRAY_FILTER_USE_KEY);
                  foreach ($show as $k => $v) {
                      echo '<strong>' . h($k) . '</strong>: ' . h((string)$v) . ' ';
                  }
              }
            ?>
          </td>
          <td style="white-space:nowrap;"><?= h(utc_to_ph($entry['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <!-- Pagination bottom -->
  <?php if ($totalPages > 1): ?>
  <div class="pagination">
    <?php if ($page > 1): ?><a href="<?= h(buildPageUrl($page - 1, $filters)) ?>">← Prev</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?><a href="<?= h(buildPageUrl($page + 1, $filters)) ?>">Next →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <p class="back-link"><a href="/api/reports.php">← Back to Reports</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
