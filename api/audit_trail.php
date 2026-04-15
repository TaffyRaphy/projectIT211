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
$offset       = 0;

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
    $totalPages = max(1, (int) ceil($totalCount / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

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
  'login'    => '<i class="fas fa-key" aria-hidden="true"></i>',
  'create'   => '<i class="fas fa-circle-plus" aria-hidden="true"></i>',
  'update'   => '<i class="fas fa-pen" aria-hidden="true"></i>',
  'approve'  => '<i class="fas fa-circle-check" aria-hidden="true"></i>',
  'reject'   => '<i class="fas fa-circle-xmark" aria-hidden="true"></i>',
  'complete' => '<i class="fas fa-check" aria-hidden="true"></i>',
  'snapshot' => '<i class="fas fa-camera" aria-hidden="true"></i>',
  'cancel'   => '<i class="fas fa-ban" aria-hidden="true"></i>',
];

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

function buildPageUrl(int $p, array $filters): string {
    $params = array_filter($filters, fn($v) => $v !== '');
    $params['page'] = $p;
    return '/api/audit_trail.php?' . http_build_query($params);
}

function buildPaginationItems(int $page, int $totalPages): array {
  if ($totalPages <= 7) {
    return range(1, $totalPages);
  }

  $items = [1];
  $start = max(2, $page - 1);
  $end = min($totalPages - 1, $page + 1);

  if ($start > 2) {
    $items[] = null;
  }

  for ($i = $start; $i <= $end; $i++) {
    $items[] = $i;
  }

  if ($end < $totalPages - 1) {
    $items[] = null;
  }

  $items[] = $totalPages;
  return $items;
}

function extractAuditDetailSummary(?string $jsonText): string {
  if (!is_string($jsonText) || trim($jsonText) === '') {
    return 'No tracked details.';
  }

  $decoded = json_decode($jsonText, true);
  if (!is_array($decoded)) {
    return 'No tracked details.';
  }

  $allowedKeys = [
    'status', 'name', 'equipment_name', 'role', 'email', 'full_name', 'staff_name',
    'maintenance_type', 'schedule_date', 'completed_date', 'qty_requested', 'qty_allocated',
    'qty_returned', 'expected_return_date', 'cancelled_by', 'action', 'category',
    'location', 'code', 'work_done', 'purpose',
  ];

  $pairs = [];
  foreach ($decoded as $key => $value) {
    if (!in_array((string) $key, $allowedKeys, true)) {
      continue;
    }

    $renderValue = is_scalar($value) || $value === null
      ? (string) $value
      : json_encode($value, JSON_UNESCAPED_SLASHES);
    $pairs[] = (string) $key . ': ' . $renderValue;
  }

  return count($pairs) > 0 ? implode(' | ', $pairs) : 'No tracked details.';
}

$filters = [
    'user_filter'   => $userFilter,
    'action_filter' => $actionFilter,
    'table_filter'  => $tableFilter,
    'start_date'    => $startDate,
    'end_date'      => $endDate,
];
$paginationItems = buildPaginationItems($page, $totalPages);
$startItem = $totalCount === 0 ? 0 : (($page - 1) * $perPage) + 1;
$endItem = min($totalCount, $page * $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Full Audit Trail – Equipment Management System</title>
  <meta name="description" content="Complete audit trail of all system actions — searchable and filterable.">
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

<main class="page page-audit-trail">
  <h2><i class="fas fa-clipboard-list" aria-hidden="true"></i> Full Audit Trail</h2>
  <p class="audit-intro">
    Complete history of all system actions. Total: <strong class="total-count-highlight"><?= number_format($totalCount) ?></strong> entries.
  </p>

  <!-- Filters -->
  <section class="section-container audit-filters-container">
    <h2 class="section-heading"><i class="fas fa-filter"></i> Filters</h2>
    <form method="post" class="filter-form audit-filter-form">
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
  <div class="audit-pagination-wrap" aria-label="Audit trail pagination">
    <div class="audit-pagination">
      <?php if ($page > 1): ?>
        <a class="audit-page-link" href="<?= h(buildPageUrl($page - 1, $filters)) ?>" aria-label="Previous page">
          <i class="fas fa-chevron-left" aria-hidden="true"></i> Previous
        </a>
      <?php endif; ?>
      <div class="audit-page-numbers" aria-label="Page numbers">
        <?php foreach ($paginationItems as $item): ?>
          <?php if ($item === null): ?>
            <span class="audit-page-ellipsis" aria-hidden="true">...</span>
          <?php elseif ($item === $page): ?>
            <span class="audit-page-number is-active" aria-current="page"><?= $item ?></span>
          <?php else: ?>
            <a class="audit-page-number" href="<?= h(buildPageUrl($item, $filters)) ?>" aria-label="Go to page <?= $item ?>"><?= $item ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($page < $totalPages): ?>
        <a class="audit-page-link" href="<?= h(buildPageUrl($page + 1, $filters)) ?>" aria-label="Next page">
          Next <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </a>
      <?php endif; ?>
    </div>
    <p class="audit-pagination-summary">Showing <?= number_format($startItem) ?> to <?= number_format($endItem) ?> of <?= number_format($totalCount) ?> entries</p>
  </div>
  <?php endif; ?>

  <!-- Table -->
  <?php if (count($auditRows) === 0): ?>
    <p class="empty-state">No audit entries match your filters.</p>
  <?php else: ?>
  <section class="section-container">
    <h2 class="section-heading"><i class="fas fa-list-check"></i> Audit Entries</h2>
    <div class="table-responsive audit-table-wrapper">
    <table class="table audit-table">
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
        <?php
          $newDetails = extractAuditDetailSummary(is_string($entry['new_values']) ? $entry['new_values'] : null);
          $oldDetails = extractAuditDetailSummary(is_string($entry['old_values']) ? $entry['old_values'] : null);
        ?>
        <tr
          class="audit-row"
          tabindex="0"
          role="button"
          aria-label="Open details for audit entry #<?= (int) $entry['id'] ?>"
          data-audit-id="<?= (int) $entry['id'] ?>"
          data-audit-action="<?= h((string) $entry['action_type']) ?>"
          data-audit-user="<?= h((string) $entry['user_name']) ?>"
          data-audit-email="<?= h((string) $entry['user_email']) ?>"
          data-audit-role="<?= h((string) $entry['user_role']) ?>"
          data-audit-table="<?= h((string) $entry['table_name']) ?>"
          data-audit-record="<?= (int) $entry['record_id'] ?>"
          data-audit-when="<?= h(utc_to_ph((string) $entry['created_at'])) ?>"
          data-audit-new-details="<?= h($newDetails) ?>"
          data-audit-old-details="<?= h($oldDetails) ?>"
        >
          <td class="cell-id audit-meta-row"><?= (int) $entry['id'] ?></td>
          <td class="cell-action-icon"><?= $actionTypeIcon[$entry['action_type']] ?? '<i class="fas fa-circle" aria-hidden="true"></i>' ?></td>
          <td class="cell-user">
            <strong><?= h($entry['user_name']) ?></strong><br>
            <span class="audit-meta-row"><?= h($entry['user_email']) ?></span>
          </td>
          <td class="cell-role">
            <span class="badge <?= match($entry['user_role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>">
              <?= h(ucfirst($entry['user_role'])) ?>
            </span>
          </td>
          <td class="cell-action-type"><?= h($entry['action_type']) ?></td>
          <td class="cell-table"><code><?= h($entry['table_name']) ?></code></td>
          <td class="cell-record"><?= (int) $entry['record_id'] ?></td>
          <td class="cell-details"><?= h($newDetails) ?></td>
          <td class="cell-when"><?= h(utc_to_ph($entry['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>
  <!-- Pagination bottom -->
  <?php if ($totalPages > 1): ?>
  <div class="audit-pagination-wrap" aria-label="Audit trail pagination">
    <div class="audit-pagination">
      <?php if ($page > 1): ?>
        <a class="audit-page-link" href="<?= h(buildPageUrl($page - 1, $filters)) ?>" aria-label="Previous page">
          <i class="fas fa-chevron-left" aria-hidden="true"></i> Previous
        </a>
      <?php endif; ?>
      <div class="audit-page-numbers" aria-label="Page numbers">
        <?php foreach ($paginationItems as $item): ?>
          <?php if ($item === null): ?>
            <span class="audit-page-ellipsis" aria-hidden="true">...</span>
          <?php elseif ($item === $page): ?>
            <span class="audit-page-number is-active" aria-current="page"><?= $item ?></span>
          <?php else: ?>
            <a class="audit-page-number" href="<?= h(buildPageUrl($item, $filters)) ?>" aria-label="Go to page <?= $item ?>"><?= $item ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php if ($page < $totalPages): ?>
        <a class="audit-page-link" href="<?= h(buildPageUrl($page + 1, $filters)) ?>" aria-label="Next page">
          Next <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </a>
      <?php endif; ?>
    </div>
    <p class="audit-pagination-summary">Showing <?= number_format($startItem) ?> to <?= number_format($endItem) ?> of <?= number_format($totalCount) ?> entries</p>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="audit-modal-overlay" data-audit-modal hidden aria-hidden="true">
    <section class="audit-modal" role="dialog" aria-modal="true" aria-labelledby="audit-modal-title">
      <header class="audit-modal-header">
        <div>
          <h3 id="audit-modal-title">Audit Entry Details</h3>
          <p>Double-click a row to inspect its tracked values.</p>
        </div>
        <button type="button" class="audit-modal-close" data-audit-modal-close aria-label="Close details">
          <i class="fas fa-xmark" aria-hidden="true"></i>
        </button>
      </header>
      <div class="audit-modal-grid">
        <div><p class="audit-modal-label">ID</p><p class="audit-modal-value" data-audit-modal-field="id"></p></div>
        <div><p class="audit-modal-label">Action</p><p class="audit-modal-value" data-audit-modal-field="action"></p></div>
        <div><p class="audit-modal-label">User</p><p class="audit-modal-value" data-audit-modal-field="user"></p></div>
        <div><p class="audit-modal-label">Email</p><p class="audit-modal-value" data-audit-modal-field="email"></p></div>
        <div><p class="audit-modal-label">Role</p><p class="audit-modal-value" data-audit-modal-field="role"></p></div>
        <div><p class="audit-modal-label">Table</p><p class="audit-modal-value" data-audit-modal-field="table"></p></div>
        <div><p class="audit-modal-label">Record</p><p class="audit-modal-value" data-audit-modal-field="record"></p></div>
        <div><p class="audit-modal-label">When</p><p class="audit-modal-value" data-audit-modal-field="when"></p></div>
      </div>
      <div class="audit-modal-details">
        <p class="audit-modal-label">Previous Values</p>
        <pre class="audit-modal-block" data-audit-modal-field="old-details"></pre>
      </div>
      <div class="audit-modal-details">
        <p class="audit-modal-label">New Values</p>
        <pre class="audit-modal-block" data-audit-modal-field="new-details"></pre>
      </div>
    </section>
  </div>

  <p class="back-link"><a href="/api/reports.php">← Back to Reports</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
