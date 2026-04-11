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
$filter = query_param('filter', 'all');
$whereRead = '';
if ($filter === 'unread') {
    $whereRead = 'AND n.is_read = false';
}

$totalCount = (int) db()->prepare(
    "SELECT COUNT(*) FROM notifications n WHERE n.user_id = :uid {$whereRead}"
)->execute([':uid' => $userId]) ? db()->prepare(
    "SELECT COUNT(*) FROM notifications n WHERE n.user_id = :uid {$whereRead}"
) : 0;

// Rerun properly
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

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

// Icon map per notification type
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
  <title>My Notifications – Equipment Management System</title>
  <meta name="description" content="View and manage your equipment management system notifications.">
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    .notif-page-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      flex-wrap: wrap;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .notif-page-header h1 { margin: 0; font-size: 1.6rem; }
    .notif-filters {
      display: flex;
      gap: .5rem;
      flex-wrap: wrap;
      margin-bottom: 1.25rem;
    }
    .notif-filters a {
      padding: .35rem .9rem;
      border-radius: 999px;
      font-size: .85rem;
      font-weight: 600;
      text-decoration: none;
      border: 2px solid transparent;
      transition: all .2s;
    }
    .notif-filters a.active {
      background: var(--accent, #cafd00);
      color: #111;
      border-color: var(--accent, #cafd00);
    }
    .notif-filters a:not(.active) {
      border-color: var(--border-color, #444);
      color: var(--text-muted, #888);
    }
    .notif-list { display: flex; flex-direction: column; gap: .6rem; }
    .notif-item {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      padding: 1rem 1.2rem;
      border-radius: 10px;
      border: 1px solid var(--border-color, #2a2a2a);
      background: var(--card-bg, #1a1a1a);
      transition: background .2s;
      position: relative;
    }
    .notif-item.unread {
      border-left: 4px solid var(--accent, #cafd00);
      background: var(--card-bg-alt, #1e1e1e);
    }
    .notif-icon {
      font-size: 1.5rem;
      flex-shrink: 0;
      width: 2.5rem;
      text-align: center;
      padding-top: .1rem;
    }
    .notif-body { flex: 1; min-width: 0; }
    .notif-message {
      font-size: .95rem;
      margin: 0 0 .3rem;
      word-break: break-word;
    }
    .notif-meta {
      font-size: .78rem;
      color: var(--text-muted, #888);
      display: flex;
      align-items: center;
      gap: .75rem;
      flex-wrap: wrap;
    }
    .notif-type-badge {
      background: var(--badge-bg, #2a2a2a);
      padding: .1rem .55rem;
      border-radius: 999px;
      font-size: .72rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .unread-dot {
      width: 9px; height: 9px;
      background: var(--accent, #cafd00);
      border-radius: 50%;
      flex-shrink: 0;
      margin-top: .45rem;
    }
    .notif-action {
      flex-shrink: 0;
    }
    .notif-action form button {
      background: none;
      border: 1px solid var(--border-color, #444);
      color: var(--text-muted, #888);
      border-radius: 6px;
      padding: .2rem .6rem;
      font-size: .78rem;
      cursor: pointer;
      transition: all .2s;
    }
    .notif-action form button:hover {
      border-color: var(--accent, #cafd00);
      color: var(--accent, #cafd00);
    }
    .empty-notif {
      text-align: center;
      padding: 3rem 1rem;
      color: var(--text-muted, #888);
    }
    .empty-notif .empty-icon { font-size: 3rem; margin-bottom: 1rem; }
    .mark-all-form { margin-bottom: 1rem; }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title">🔔 My Notifications</p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: <?= h($role) ?> | User ID: <?= $userId ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>

<main class="page" style="max-width: 800px; margin: 0 auto; padding: 1.5rem 1rem;">

  <?php if ($ok !== ''): ?>
    <p class="alert alert-success"><?= h($ok) ?></p>
  <?php endif; ?>

  <div class="notif-page-header">
    <h1>🔔 Notifications
      <?php if ($unreadCount > 0): ?>
        <span class="badge badge-error" style="font-size:.9rem; vertical-align: middle;"><?= $unreadCount ?> unread</span>
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
      <a href="/api/dashboard.php" class="btn btn-primary" style="margin-top:.75rem">← Back to Dashboard</a>
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
            <div style="width:9px; flex-shrink:0;"></div>
          <?php endif; ?>

          <div class="notif-icon"><?= $icon ?></div>

          <div class="notif-body">
            <p class="notif-message"><?= h($notif['message']) ?></p>
            <div class="notif-meta">
              <span><?= h(utc_to_ph($notif['created_at'])) ?></span>
              <span class="notif-type-badge"><?= h($typeLabel) ?></span>
              <?php if (!$isUnread): ?>
                <span style="color: var(--text-muted, #888);">✓ Read</span>
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
      <nav class="pagination" style="margin-top: 1.5rem;">
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

  <p class="back-link" style="margin-top: 2rem;"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>

<script src="/assets/app.js"></script>
</body>
</html>
