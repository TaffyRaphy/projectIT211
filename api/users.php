<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_role(['admin']);
$user   = require_login();
$userId = (int) $user['id'];
$ok     = query_param('ok');
$error  = query_param('error');

// Search
$search     = post_string('search') ?: query_param('search');
$roleFilter = post_string('role_filter') ?: query_param('role_filter');

$where  = [];
$params = [];

if ($search !== '') {
    $where[]           = "(u.full_name ILIKE :search OR u.email ILIKE :search OR u.employee_id ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}
if ($roleFilter !== '' && in_array($roleFilter, ['admin', 'staff', 'maintenance'], true)) {
    $where[]         = "u.role = :role";
    $params[':role'] = $roleFilter;
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = db()->prepare(
    "SELECT u.id, u.full_name, u.email, u.role, u.created_at, u.employee_id, u.department, u.job_title,
            COUNT(DISTINCT er.id) AS request_count,
            COUNT(DISTINCT al.id) AS allocation_count,
            COUNT(DISTINCT ml.id) AS maintenance_count
     FROM users u
     LEFT JOIN equipment_requests er ON er.staff_id = u.id
     LEFT JOIN allocations         al ON al.staff_id = u.id
     LEFT JOIN maintenance_logs    ml ON ml.maintenance_user_id = u.id
     {$whereClause}
     GROUP BY u.id, u.full_name, u.email, u.role, u.created_at, u.employee_id, u.department, u.job_title
     ORDER BY u.created_at DESC"
);
$stmt->execute($params);
$users = $stmt->fetchAll();

$roleBadge = [
    'admin'       => 'badge-warning',
    'staff'       => 'badge-info',
    'maintenance' => 'badge-success',
];
$roleIcon = [
    'admin'       => '👑',
    'staff'       => '👤',
    'maintenance' => '🔧',
];

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>User Management – Equipment Management System</title>
  <meta name="description" content="View and manage all system users, roles, and activity.">
  <link rel="stylesheet" href="/assets/style.css">
  <style>
    .user-stats { display: flex; gap: .5rem; flex-wrap: wrap; }
    .user-stat-chip {
      background: var(--card-bg, #1e1e1e);
      border: 1px solid var(--border-color, #2a2a2a);
      padding: .15rem .5rem;
      border-radius: 6px;
      font-size: .78rem;
      color: var(--text-muted, #888);
    }
    .user-stat-chip span { font-weight: 700; color: var(--text-color, #eee); }
    .user-row-email { font-size: .82rem; color: var(--text-muted, #888); }
    .user-row-meta  { font-size: .78rem; color: var(--text-muted, #888); }
    .summary-bar {
      display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem;
    }
    .summary-chip {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      padding: .4rem .9rem;
      border-radius: 8px;
      font-size: .85rem;
    }
    .summary-chip strong { color: var(--accent, #cafd00); font-size: 1.1rem; }
    .add-user-toggle {
      display: flex; align-items: center; gap: .5rem;
      cursor: pointer; user-select: none;
    }
    .add-user-toggle h2 { margin: 0; }
    .create-user-form { display: none; }
    .create-user-form.open { display: block; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 640px) { .form-grid-2 { grid-template-columns: 1fr; } }
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
      <span>Role: <?= h($user['role']) ?> | <?= h($user['full_name']) ?></span>
    </div>
    <div class="dashboard-topbar-actions">
      <a class="bell-btn" href="/api/my_notifications.php" aria-label="Notifications">
        🔔
        <?php if ($unreadCount > 0): ?>
          <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : $unreadCount ?></span>
        <?php endif; ?>
      </a>
      <a class="profile-link" href="/api/profile.php">🪪 Profile</a>
      <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
      <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
    </div>
  </div>
</header>

<main class="page">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <!-- Summary bar -->
  <?php
    $totalUsers = count($users);
    $adminCount = count(array_filter($users, fn($u) => $u['role'] === 'admin'));
    $staffCount = count(array_filter($users, fn($u) => $u['role'] === 'staff'));
    $maintCount = count(array_filter($users, fn($u) => $u['role'] === 'maintenance'));
  ?>
  <div class="summary-bar">
    <div class="summary-chip">Total Users: <strong><?= $totalUsers ?></strong></div>
    <div class="summary-chip">👑 Admins: <strong><?= $adminCount ?></strong></div>
    <div class="summary-chip">👤 Staff: <strong><?= $staffCount ?></strong></div>
    <div class="summary-chip">🔧 Maintenance: <strong><?= $maintCount ?></strong></div>
  </div>

  <!-- ── Add New User ── -->
  <section class="card">
    <div class="add-user-toggle" id="add-user-toggle" onclick="toggleAddUser()" aria-expanded="false">
      <h2>➕ Add New User</h2>
      <span id="add-user-chevron" style="font-size:1.2rem; transition: transform .2s;">▼</span>
    </div>
    <div class="create-user-form" id="create-user-form">
      <br>
      <form action="/api/actions/user_create.php" method="post" class="form">
        <div class="form-grid-2">
          <div class="form-group">
            <label for="cu_full_name">Full Name *</label>
            <input type="text" id="cu_full_name" name="full_name" required placeholder="e.g. Juan Dela Cruz">
          </div>
          <div class="form-group">
            <label for="cu_email">Email Address *</label>
            <input type="email" id="cu_email" name="email" required placeholder="e.g. juan@example.com">
          </div>
          <div class="form-group">
            <label for="cu_password">Temporary Password * (min 8 chars)</label>
            <input type="password" id="cu_password" name="password" required minlength="8" placeholder="Temporary password">
          </div>
          <div class="form-group">
            <label for="cu_role">Role *</label>
            <select id="cu_role" name="role" required>
              <option value="">Select role…</option>
              <option value="admin">👑 Admin</option>
              <option value="staff">👤 Staff</option>
              <option value="maintenance">🔧 Maintenance</option>
            </select>
          </div>
          <div class="form-group">
            <label for="cu_department">Department</label>
            <input type="text" id="cu_department" name="department" placeholder="e.g. IT, Operations">
          </div>
          <div class="form-group">
            <label for="cu_job_title">Job Title / Position</label>
            <input type="text" id="cu_job_title" name="job_title" placeholder="e.g. IT Technician">
          </div>
        </div>
        <p style="font-size:.82rem; color: var(--text-muted, #888); margin-top:.5rem;">
          💡 An Employee ID will be automatically generated upon creation. The user will be prompted to complete their profile on first login.
        </p>
        <div class="form-actions">
          <button type="submit" class="btn btn-primary">➕ Create User</button>
          <button type="button" class="btn btn-secondary" onclick="toggleAddUser()">Cancel</button>
        </div>
      </form>
    </div>
  </section>

  <!-- Search + Filter -->
  <section class="card">
    <h2>Search Users</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="search">Name / Email / Employee ID:</label>
        <input type="text" id="search" name="search" placeholder="Search..." value="<?= h($search) ?>">
      </div>
      <div class="form-group">
        <label for="role_filter">Role:</label>
        <select id="role_filter" name="role_filter">
          <option value="">All Roles</option>
          <option value="admin"       <?= $roleFilter === 'admin'       ? 'selected' : '' ?>>👑 Admin</option>
          <option value="staff"       <?= $roleFilter === 'staff'       ? 'selected' : '' ?>>👤 Staff</option>
          <option value="maintenance" <?= $roleFilter === 'maintenance' ? 'selected' : '' ?>>🔧 Maintenance</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Search</button>
      <a href="/api/users.php" class="btn btn-secondary">Clear</a>
    </form>
  </section>

  <!-- Users Table -->
  <h2>All Users (<?= count($users) ?>)</h2>
  <?php if (count($users) === 0): ?>
    <p class="empty-state">No users found.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Emp ID</th>
          <th>Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Dept / Title</th>
          <th>Activity</th>
          <th>Joined</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
        <tr>
          <td style="font-size:.82rem; font-weight:600; color:var(--accent);"><?= h($u['employee_id'] ?? '—') ?></td>
          <td>
            <strong><?= h($u['full_name']) ?></strong>
            <?php if ((int) $u['id'] === $userId): ?>
              <span class="badge badge-info" style="font-size:.7rem;">You</span>
            <?php endif; ?>
          </td>
          <td class="user-row-email"><?= h($u['email']) ?></td>
          <td>
            <span class="badge <?= $roleBadge[$u['role']] ?? 'badge-info' ?>">
              <?= $roleIcon[$u['role']] ?? '' ?> <?= h(ucfirst($u['role'])) ?>
            </span>
          </td>
          <td class="user-row-meta">
            <?php if (!empty($u['department'])): ?>
              <div><?= h($u['department']) ?></div>
            <?php endif; ?>
            <?php if (!empty($u['job_title'])): ?>
              <div style="color: var(--text-muted);"><?= h($u['job_title']) ?></div>
            <?php endif; ?>
            <?php if (empty($u['department']) && empty($u['job_title'])): ?>
              <span style="color: var(--text-muted);">—</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="user-stats">
              <div class="user-stat-chip">Requests: <span><?= (int) $u['request_count'] ?></span></div>
              <div class="user-stat-chip">Allocations: <span><?= (int) $u['allocation_count'] ?></span></div>
              <?php if ($u['role'] === 'maintenance'): ?>
                <div class="user-stat-chip">Maintenance: <span><?= (int) $u['maintenance_count'] ?></span></div>
              <?php endif; ?>
            </div>
          </td>
          <td><?= h(utc_to_ph($u['created_at'], 'Y-m-d')) ?></td>
          <td>
            <a href="/api/profile.php?id=<?= (int) $u['id'] ?>" class="btn btn-secondary" style="font-size:.8rem; padding:.2rem .6rem;">
              View Profile
            </a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <p class="back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>

<script>
function toggleAddUser() {
  const form     = document.getElementById('create-user-form');
  const toggle   = document.getElementById('add-user-toggle');
  const chevron  = document.getElementById('add-user-chevron');
  const isOpen   = form.classList.toggle('open');
  toggle.setAttribute('aria-expanded', isOpen);
  chevron.style.transform = isOpen ? 'rotate(180deg)' : '';
}
</script>
<script src="/assets/app.js"></script>
</body>
</html>
