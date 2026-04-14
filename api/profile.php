<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$currentUser   = require_login();
$currentUserId = (int) $currentUser['id'];
$currentRole   = (string) $currentUser['role'];

// Admins can view any profile; others can only view their own
$viewId = int_query_param('id', $currentUserId);
if ($currentRole !== 'admin' && $viewId !== $currentUserId) {
    redirect_to('/api/profile.php');
}

$ok    = query_param('ok');
$error = query_param('error');
$setup = query_param('setup'); // '1' = mandatory profile fill mode

// Fetch the profile user (now includes new fields)
$profileStmt = db()->prepare(
    'SELECT id, full_name, email, role, created_at, employee_id, department, job_title
     FROM users WHERE id = :id'
);
$profileStmt->execute([':id' => $viewId]);
$profileUser = $profileStmt->fetch();

if (!$profileUser) {
    redirect_to('/api/dashboard.php', ['error' => 'User not found']);
}

// Detect if profile is incomplete (for Staff and Maintenance)
$profileIncomplete = in_array($profileUser['role'], ['staff', 'maintenance'], true)
    && (empty($profileUser['department']) || empty($profileUser['job_title']));

// If it's own profile and incomplete, force setup mode
if ($viewId === $currentUserId && $profileIncomplete && $setup !== '1') {
    $setup = '1';
}

// Auto-generate employee_id if missing
if (empty($profileUser['employee_id'])) {
    $generatedEmpId = (string) ((int) $profileUser['id'] + 113000);
    try {
        db()->prepare('UPDATE users SET employee_id = :eid WHERE id = :id')
            ->execute([':eid' => $generatedEmpId, ':id' => $viewId]);
        $profileUser['employee_id'] = $generatedEmpId;
    } catch (Throwable) {}
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

// Activity stats — role-specific
$statsStmt = db()->prepare(
    "SELECT
       (SELECT COUNT(*) FROM equipment_requests  WHERE staff_id = :uid)                AS requests_made,
       (SELECT COUNT(*) FROM allocations         WHERE staff_id = :uid)                AS allocations_received,
       (SELECT COUNT(*) FROM allocations         WHERE staff_id = :uid AND status = 'active') AS active_allocations,
       (SELECT COUNT(*) FROM maintenance_logs    WHERE maintenance_user_id = :uid)     AS maintenance_done,
       (SELECT COUNT(*) FROM maintenance_logs    WHERE maintenance_user_id = :uid AND status = 'scheduled') AS maintenance_pending,
       (SELECT COUNT(*) FROM equipment_requests  WHERE reviewed_by = :uid)             AS requests_reviewed,
       (SELECT COUNT(*) FROM notifications       WHERE user_id = :uid AND is_read = false) AS unread_notifications,
       (SELECT COUNT(*) FROM audit_logs          WHERE user_id = :uid)                 AS audit_entries"
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
    'cancel'   => '✖️',
];

$unreadCount = NotificationService::getInstance()->getUnreadCount($currentUserId);
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
      width: 80px; height: 80px;
      border-radius: 50%;
      background: var(--accent, #cafd00);
      display: flex; align-items: center; justify-content: center;
      font-size: 2.2rem; font-weight: 700; color: #111;
      flex-shrink: 0;
      overflow: hidden;
    }
    .profile-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .profile-info h1 { margin: 0 0 .3rem; font-size: 1.4rem; }
    .profile-info p  { margin: 0; font-size: .9rem; color: var(--text-muted, #888); }
    .profile-info .info-row { display: flex; gap: 1.2rem; flex-wrap: wrap; margin-top: .4rem; }
    .profile-info .info-chip {
      font-size: .82rem; color: var(--text-muted, #888);
      background: var(--bg-alt, #111);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 6px; padding: .15rem .55rem;
    }
    .profile-info .info-chip strong { color: var(--text-color, #eee); margin-right: .3rem; }
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
    .mandatory-banner {
      background: linear-gradient(135deg, #1a1a1a, #111);
      border: 2px solid var(--accent, #cafd00);
      border-radius: 14px;
      padding: 1.5rem 2rem;
      margin-bottom: 1.5rem;
      text-align: center;
    }
    .mandatory-banner h2 { color: var(--accent, #cafd00); margin: 0 0 .5rem; }
    .mandatory-banner p  { color: var(--text-muted, #888); margin: 0 0 1rem; }
    .edit-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    @media (max-width: 640px) { .edit-form-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title" style="text-decoration:none; color:inherit;">
      Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($profileUser['employee_id'] ?? '') ?> | Role: <?= h($currentRole) ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <a class="bell-btn" href="/api/my_notifications.php" aria-label="Notifications">
        🔔
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>

<main class="page">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <?php if ($setup === '1' && $isOwnProfile && $profileIncomplete): ?>
  <!-- ── Mandatory Profile Completion Banner ── -->
  <div class="mandatory-banner">
    <h2>👤 Complete Your Profile</h2>
    <p>Your profile is incomplete. Please fill in the required fields below before using the system.</p>
  </div>
  <?php endif; ?>

  <!-- Profile header card -->
  <div class="profile-header">
    <div class="profile-avatar">
        <?= mb_strtoupper(mb_substr($profileUser['full_name'], 0, 1)) ?>
    </div>
    <div class="profile-info">
      <h1><?= h($profileUser['full_name']) ?></h1>
      <p><?= h($profileUser['email']) ?></p>
      <div class="info-row">
        <span class="info-chip"><strong>Employee ID:</strong><?= h($profileUser['employee_id'] ?? '—') ?></span>
        <?php if (!empty($profileUser['department'])): ?>
        <span class="info-chip"><strong>Dept:</strong><?= h($profileUser['department']) ?></span>
        <?php endif; ?>
        <?php if (!empty($profileUser['job_title'])): ?>
        <span class="info-chip"><strong>Title:</strong><?= h($profileUser['job_title']) ?></span>
        <?php endif; ?>
      </div>
      <p style="margin-top:.5rem;">
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

  <!-- Edit / Complete Profile Form -->
  <?php if ($isOwnProfile || $currentRole === 'admin'): ?>
  <section class="card">
    <h2>
      <?= ($setup === '1' && $profileIncomplete) ? '📝 Complete Profile (Required)' : '✏️ Edit Profile' ?>
    </h2>
    <form action="/api/actions/profile_update.php" method="post" class="form">
      <input type="hidden" name="target_id" value="<?= $viewId ?>">
      <?php if ($setup === '1' && $profileIncomplete): ?>
        <input type="hidden" name="mandatory_fill" value="1">
      <?php endif; ?>
      <div class="edit-form-grid">
        <div class="form-group">
          <label for="full_name">Full Name *</label>
          <input type="text" id="full_name" name="full_name" required
                 value="<?= h($profileUser['full_name']) ?>"
                 placeholder="Enter full name">
        </div>
        <div class="form-group">
          <label for="email">Email Address *</label>
          <input type="email" id="email" name="email" required
                 value="<?= h($profileUser['email']) ?>"
                 placeholder="name@example.com">
        </div>
        <?php if (in_array($profileUser['role'], ['staff', 'maintenance'], true)): ?>
        <div class="form-group">
          <label for="department">Department <?= ($setup === '1') ? '*' : '' ?></label>
          <input type="text" id="department" name="department"
                 <?= ($setup === '1') ? 'required' : '' ?>
                 value="<?= h($profileUser['department'] ?? '') ?>"
                 placeholder="e.g. IT, Operations, Engineering">
        </div>
        <div class="form-group">
          <label for="job_title">Job Title / Position <?= ($setup === '1') ? '*' : '' ?></label>
          <input type="text" id="job_title" name="job_title"
                 <?= ($setup === '1') ? 'required' : '' ?>
                 value="<?= h($profileUser['job_title'] ?? '') ?>"
                 placeholder="e.g. IT Technician, Lab Manager">
        </div>
        <?php endif; ?>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">
          <?= ($setup === '1' && $profileIncomplete) ? '💾 Save & Continue' : '💾 Save Changes' ?>
        </button>
      </div>
    </form>
  </section>
  <?php endif; ?>

  <!-- Rest of profile only shown when profile is complete OR admin -->
  <?php if (!($setup === '1' && $profileIncomplete)): ?>

  <!-- Activity Stats — role-specific -->
  <h2>Activity Stats</h2>
  <div class="stats-grid">
    <?php if ($profileUser['role'] === 'staff'): ?>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['requests_made'] ?? 0) ?></div>
        <div class="stat-label">Requests Made</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['allocations_received'] ?? 0) ?></div>
        <div class="stat-label">Equipment Allocated</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['active_allocations'] ?? 0) ?></div>
        <div class="stat-label">Currently Holding</div>
      </div>
    <?php elseif ($profileUser['role'] === 'maintenance'): ?>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['maintenance_done'] ?? 0) ?></div>
        <div class="stat-label">Tasks Completed</div>
      </div>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['maintenance_pending'] ?? 0) ?></div>
        <div class="stat-label">Tasks Pending</div>
      </div>
    <?php elseif ($profileUser['role'] === 'admin'): ?>
      <div class="stat-card">
        <div class="stat-value"><?= (int) ($stats['requests_reviewed'] ?? 0) ?></div>
        <div class="stat-label">Requests Reviewed</div>
      </div>
    <?php endif; ?>
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
                    $show = array_filter($newVals, fn($k) => in_array($k, ['status', 'name', 'equipment_name', 'role', 'email', 'full_name', 'staff_name', 'maintenance_type', 'schedule_date', 'completed_date', 'qty_requested', 'qty_allocated', 'qty_returned', 'expected_return_date', 'cancelled_by', 'action', 'category', 'location', 'code', 'work_done', 'purpose']), ARRAY_FILTER_USE_KEY);
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

  <?php endif; // end profile complete check ?>

  <p class="back-link">
    <?php if ($currentRole === 'admin' && !$isOwnProfile): ?>
      <a href="/api/users.php">← Back to User Management</a>
    <?php elseif (!($setup === '1' && $profileIncomplete)): ?>
      <a href="/api/dashboard.php">← Back to Dashboard</a>
    <?php endif; ?>
  </p>
</main>

<script>
  // Warn user on navigation away if in mandatory fill mode and form not submitted
  <?php if ($setup === '1' && $profileIncomplete): ?>
  function warnLeave(e) {
    e.preventDefault();
    e.returnValue = '';
  }

  window.addEventListener('beforeunload', warnLeave);

  const profileForm = document.querySelector('form[action="/api/actions/profile_update.php"]');
  if (profileForm) {
    profileForm.addEventListener('submit', function() {
      window.removeEventListener('beforeunload', warnLeave);
    });
  }
  <?php endif; ?>
</script>
<script src="/assets/app.js"></script>
</body>
</html>
