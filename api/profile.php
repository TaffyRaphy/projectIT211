<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$currentUser   = require_login();
$currentUserId = (int) $currentUser['id'];
$currentRole   = (string) $currentUser['role'];

// Admins can view any profile; others can only view their own
$viewId = int_query_param('id', $currentUserId);
if ($currentRole !== 'admin' && $viewId !== $currentUserId) {
    redirect_to('/api/profile.php'); // redirect to own profile
}

$ok    = query_param('ok');
$error = query_param('error');

// Fetch the profile user
$profileStmt = db()->prepare('SELECT id, full_name, email, role, created_at FROM users WHERE id = :id');
$profileStmt->execute([':id' => $viewId]);
$profileUser = $profileStmt->fetch();

if (!$profileUser) {
    redirect_to('/api/dashboard.php', ['error' => 'User not found']);
}

// Recent audit log activity (last 15)
$auditStmt = db()->prepare(
    "SELECT al.action_type, al.table_name, al.record_id, al.old_values, al.new_values, al.created_at
     FROM audit_logs al
     WHERE al.user_id = :uid
     ORDER BY al.created_at DESC
     LIMIT 15"
);
$auditStmt->execute([':uid' => $viewId]);
$auditRows = $auditStmt->fetchAll();

// Activity stats
$statsStmt = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM equipment_requests WHERE staff_id = :uid)                    AS requests_made,
       (SELECT COUNT(*) FROM allocations WHERE staff_id = :uid)                           AS allocations,
       (SELECT COUNT(*) FROM maintenance_logs WHERE maintenance_user_id = :uid)           AS maintenance_done,
       (SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = false)      AS unread_notifications,
       (SELECT COUNT(*) FROM audit_logs WHERE user_id = :uid)                             AS audit_entries"
);
$statsStmt->execute([':uid' => $viewId]);
$stats = $statsStmt->fetch();

$isOwnProfile = ($viewId === $currentUserId);

$actionTypeIcon = [
    'login'    => '🔑',
    'create'   => '➕',
    'update'   => '✏️',
    'approve'  => '✅',
    'reject'   => '❌',
    'complete' => '✔️',
    'snapshot' => '📸',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($profileUser['full_name']) ?> – Profile</title>
  <meta name="description" content="User profile and activity history for <?= h($profileUser['full_name']) ?>.">
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    .profile-header {
      display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 14px;
      padding: 1.5rem 2rem;
      margin-bottom: 1.5rem;
    }
    .profile-avatar {
      width: 72px; height: 72px;
      border-radius: 50%;
      background: var(--accent, #cafd00);
      display: flex; align-items: center; justify-content: center;
      font-size: 2rem; font-weight: 700; color: #111;
      flex-shrink: 0;
    }
    .profile-info h1 { margin: 0 0 .3rem; font-size: 1.4rem; }
    .profile-info p  { margin: 0; font-size: .9rem; color: var(--text-muted, #888); }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: .75rem;
      margin-bottom: 1.5rem;
    }
    .stat-card {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 10px;
      padding: .9rem 1rem;
      text-align: center;
    }
    .stat-card .stat-value { font-size: 1.8rem; font-weight: 700; color: var(--accent, #cafd00); }
    .stat-card .stat-label { font-size: .78rem; color: var(--text-muted, #888); margin-top: .2rem; }
    .audit-row td:first-child { font-size: 1.1rem; text-align: center; }
    .audit-action { text-transform: capitalize; font-weight: 600; }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <p class="dashboard-topbar-title">🪪 <?= $isOwnProfile ? 'My Profile' : 'User Profile' ?></p>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span>Role: <?= h($currentRole) ?> | User ID: <?= $currentUserId ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>

<main class="page">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <!-- Profile header card -->
  <div class="profile-header">
    <div class="profile-avatar">
      <?= mb_strtoupper(mb_substr($profileUser['full_name'], 0, 1)) ?>
    </div>
    <div class="profile-info">
      <h1><?= h($profileUser['full_name']) ?></h1>
      <p><?= h($profileUser['email']) ?></p>
      <p style="margin-top:.4rem;">
        <span class="badge <?= match($profileUser['role']) { 'admin' => 'badge-warning', 'maintenance' => 'badge-success', default => 'badge-info' } ?>">
          <?= match($profileUser['role']) { 'admin' => '👑', 'maintenance' => '🔧', default => '👤' } ?>
          <?= h(ucfirst($profileUser['role'])) ?>
        </span>
        <span style="margin-left:.5rem; font-size:.82rem; color: var(--text-muted, #888);">
          Member since <?= h(utc_to_ph($profileUser['created_at'], 'M d, Y')) ?>
        </span>
      </p>
    </div>
  </div>

  <!-- Activity Stats -->
  <h2>Activity Stats</h2>
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-value"><?= (int) ($stats['requests_made'] ?? 0) ?></div>
      <div class="stat-label">Requests Made</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int) ($stats['allocations'] ?? 0) ?></div>
      <div class="stat-label">Allocations</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int) ($stats['maintenance_done'] ?? 0) ?></div>
      <div class="stat-label">Maintenance Tasks</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int) ($stats['unread_notifications'] ?? 0) ?></div>
      <div class="stat-label">Unread Notifications</div>
    </div>
    <div class="stat-card">
      <div class="stat-value"><?= (int) ($stats['audit_entries'] ?? 0) ?></div>
      <div class="stat-label">Audit Entries</div>
    </div>
  </div>

  <!-- Change Password (only for own profile) -->
  <?php if ($isOwnProfile): ?>
  <section class="card">
    <h2>🔑 Change Password</h2>
    <form action="/api/actions/change_password.php" method="post" class="form" autocomplete="off">
      <div class="form-group">
        <label for="current_password">Current Password *</label>
        <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="form-group">
        <label for="new_password">New Password * (min 8 characters)</label>
        <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label for="confirm_password">Confirm New Password *</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Update Password</button>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <!-- Recent Audit Activity -->
  <section class="card">
    <h2>📋 Recent Activity</h2>
    <?php if (count($auditRows) === 0): ?>
      <p class="empty-state">No audit activity recorded yet.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th></th>
            <th>Action</th>
            <th>Table</th>
            <th>Record ID</th>
            <th>Details</th>
            <th>When</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($auditRows as $entry): ?>
          <tr>
            <td><?= $actionTypeIcon[$entry['action_type']] ?? '📌' ?></td>
            <td class="audit-action"><?= h($entry['action_type']) ?></td>
            <td><code><?= h($entry['table_name']) ?></code></td>
            <td><?= (int) $entry['record_id'] ?></td>
            <td style="max-width: 260px; font-size: .8rem; color: var(--text-muted); white-space: pre-wrap; word-break: break-all;">
              <?php
                $newVals = is_string($entry['new_values']) ? json_decode($entry['new_values'], true) : null;
                if (is_array($newVals)) {
                    // Show key details only
                    $show = array_filter($newVals, fn($k) => in_array($k, ['status', 'name', 'equipment_name', 'role', 'email']), ARRAY_FILTER_USE_KEY);
                    if (!empty($show)) {
                        foreach ($show as $k => $v) {
                            echo '<span style="margin-right:.4rem;"><strong>' . h($k) . ':</strong> ' . h((string)$v) . '</span>';
                        }
                    } else {
                        echo '<span style="color:var(--text-muted)">—</span>';
                    }
                } else {
                    echo '<span style="color:var(--text-muted)">—</span>';
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
  </section>

  <p class="back-link">
    <?php if ($currentRole === 'admin' && !$isOwnProfile): ?>
      <a href="/api/users.php">← Back to User Management</a>
    <?php else: ?>
      <a href="/api/dashboard.php">← Back to Dashboard</a>
    <?php endif; ?>
  </p>
</main>

<script src="/assets/app.js"></script>
</body>
</html>
