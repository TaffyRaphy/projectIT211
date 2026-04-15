<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$userId = (int) $user['id'];
$role   = (string) $user['role'];
$ok     = query_param('ok');
$error  = query_param('error');

// Pagination
$page    = max(1, int_query_param('page', 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Filter: all | unread
$filter = query_param('filter', 'unread');
$whereRead = '';
if ($filter === 'unread') {
    $whereRead = 'AND n.is_read = false';
}

// Persistent types: stay unread until the underlying issue is resolved
$persistentTypes = ['equipment_overdue_return', 'maintenance_overdue', 'equipment_due_return'];

// Auto-mark non-persistent unread notifications as read on page open
try {
    $placeholders = implode(',', array_fill(0, count($persistentTypes), '?'));
    $markStmt = db()->prepare(
        "UPDATE notifications SET is_read = true
         WHERE user_id = ? AND is_read = false
         AND type NOT IN ({$placeholders})"
    );
    $markStmt->execute(array_merge([$userId], $persistentTypes));
} catch (Throwable $e) {
    error_log('auto-read notifications error: ' . $e->getMessage());
}

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

$cntStmt = db()->prepare("SELECT COUNT(*) FROM notifications n WHERE n.user_id = :uid {$whereRead}");
$cntStmt->execute([':uid' => $userId]);
$totalCount = (int) $cntStmt->fetchColumn();
$totalPages = (int) ceil($totalCount / $perPage);

$stmt = db()->prepare(
  "SELECT n.id, n.message, n.type, n.is_read, n.created_at
   FROM notifications n
   WHERE n.user_id = :uid {$whereRead}
   ORDER BY n.created_at DESC
   LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':uid',    $userId,  PDO::PARAM_INT);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

// Icon map per notification type
$typeIcons = [
    'request_submitted'        => '📋',
    'request_approved'         => '✅',
    'request_rejected'         => '❌',
    'request_return_notify'    => '📦',
    'maintenance_scheduled'    => '🔧',
    'maintenance_completed'    => '✔️',
    'maintenance_cancelled'    => '✖️',
    'maintenance_due'          => '⏰',
    'maintenance_overdue'      => '🚨',
    'equipment_due_return'     => '⏰',
    'equipment_overdue_return' => '🚨',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Notifications – Equipment Management System</title>
  <meta name="description" content="View and manage your equipment management system notifications.">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title site-title-link">Equipment Management System</a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
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

<main class="page page-my-notifications">

  <?php if ($ok !== ''): ?>
    <p class="alert alert-success"><?= h($ok) ?></p>
  <?php endif; ?>

  <div class="notif-page-header">
    <h1>🔔 Notifications
      <?php if ($unreadCount > 0): ?>
        <span class="badge badge-error notif-unread-badge"><?= $unreadCount ?> unread</span>
      <?php endif; ?>
    </h1>
    <?php if ($unreadCount > 0): ?>
      <form class="mark-all-form" action="/api/actions/mark_notification_read.php" method="post">
        <input type="hidden" name="mark_all" value="1">
        <button type="submit" class="btn btn-secondary">✓ Mark all as read</button>
      </form>
    <?php endif; ?>
  </div>

  <!-- Filters -->
  <nav class="notif-filters" aria-label="Notification filter">
    <a href="?filter=all"    class="<?= $filter === 'all'    ? 'active' : '' ?>">All (<?= $totalCount ?>)</a>
    <a href="?filter=unread" class="<?= $filter === 'unread' ? 'active' : '' ?>">Unread (<?= $unreadCount ?>)</a>
  </nav>

  <!-- Notification list -->
  <?php if (count($notifications) === 0): ?>
    <div class="empty-notif">
      <div class="empty-icon">🔕</div>
      <p><?= $filter === 'unread' ? 'No unread notifications. You\'re all caught up! 🎉' : 'No notifications yet.' ?></p>
        <a href="/api/dashboard.php" class="btn btn-primary notif-empty-action">← Back to Dashboard</a>
    </div>
  <?php else: ?>
    <div class="notif-list" role="list">
      <?php foreach ($notifications as $notif): ?>
        <?php
          $isUnread = !(bool) $notif['is_read'];
          $icon     = $typeIcons[$notif['type']] ?? '📢';
          $typeLabel = ucwords(str_replace('_', ' ', $notif['type']));
        ?>
        <article class="notif-item <?= $isUnread ? 'unread' : '' ?>" role="listitem">
          <?php if ($isUnread): ?>
            <div class="unread-dot" title="Unread"></div>
          <?php else: ?>
            <div class="notif-spacer"></div>
          <?php endif; ?>

          <div class="notif-icon"><?= $icon ?></div>

          <div class="notif-body">
            <p class="notif-message"><?= h($notif['message']) ?></p>
            <div class="notif-meta">
              <span><?= h(utc_to_ph($notif['created_at'])) ?></span>
              <span class="notif-type-badge"><?= h($typeLabel) ?></span>
              <?php if (!$isUnread): ?>
                <span class="notif-read-text">✓ Read</span>
              <?php endif; ?>
            </div>
          </div>

          <?php if ($isUnread): ?>
            <div class="notif-action">
              <form action="/api/actions/mark_notification_read.php" method="post">
                <input type="hidden" name="notification_id" value="<?= (int) $notif['id'] ?>">
                <button type="submit" title="Mark as read">✓ Read</button>
              </form>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <nav class="pagination notif-pagination-wrap">
        <ul>
          <?php if ($page > 1): ?>
            <li><a href="?page=1&filter=<?= h($filter) ?>">« First</a></li>
            <li><a href="?page=<?= $page - 1 ?>&filter=<?= h($filter) ?>">‹ Prev</a></li>
          <?php endif; ?>
          <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <li>
              <a href="?page=<?= $p ?>&filter=<?= h($filter) ?>" <?= $p === $page ? 'class="active"' : '' ?>>
                <?= $p ?>
              </a>
            </li>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <li><a href="?page=<?= $page + 1 ?>&filter=<?= h($filter) ?>">Next ›</a></li>
            <li><a href="?page=<?= $totalPages ?>&filter=<?= h($filter) ?>">Last »</a></li>
          <?php endif; ?>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <p class="back-link notif-back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>

<script src="/assets/app.js"></script>
</body>
</html>
