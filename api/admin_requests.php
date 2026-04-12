<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
$role = require_role(['admin']);
$adminId = (int) $user['id'];
$dashboardTitle = 'Request Workflow & Allocations';
$ok = query_param('ok');
$error = query_param('error');

// Pending requests
$pendingRows = db()->query(
    "SELECT r.id, u.full_name AS staff_name, u.email AS staff_email,
            e.name AS equipment_name, r.qty_requested, r.purpose, r.status,
            r.requested_at
     FROM equipment_requests r
     JOIN users u ON u.id = r.staff_id
     JOIN equipment e ON e.id = r.equipment_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_at ASC"
)->fetchAll();

// All requests (non-pending) - history
$historyRows = db()->query(
    "SELECT r.id, u.full_name AS staff_name, e.name AS equipment_name,
            r.qty_requested, r.status, r.requested_at, r.reviewed_at,
            rv.full_name AS reviewed_by_name
     FROM equipment_requests r
     JOIN users u ON u.id = r.staff_id
     JOIN equipment e ON e.id = r.equipment_id
     LEFT JOIN users rv ON rv.id = r.reviewed_by
     WHERE r.status <> 'pending'
     ORDER BY r.reviewed_at DESC
     LIMIT 30"
)->fetchAll();

// Active allocations (for return processing)
$activeAllocations = db()->query(
    "SELECT a.id, a.qty_allocated, a.checkout_date, a.expected_return_date, a.status,
            u.full_name AS staff_name, u.email AS staff_email,
            e.name AS equipment_name, e.id AS equipment_id
     FROM allocations a
     JOIN users u ON u.id = a.staff_id
     JOIN equipment e ON e.id = a.equipment_id
  WHERE a.actual_return_date IS NULL
     ORDER BY a.expected_return_date ASC NULLS LAST, a.checkout_date DESC"
)->fetchAll();

$unreadCount = NotificationService::getInstance()->getUnreadCount($adminId);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Request Workflow – Equipment Management System</title>
  <style>
    .req-card {
      background: var(--card-bg, #1a1a1a);
      border: 1px solid var(--border-color, #2a2a2a);
      border-radius: 12px;
      padding: 1.2rem 1.4rem;
      margin-bottom: 1rem;
    }
    .req-card.pending { border-left: 4px solid #f59e0b; }
    .req-card-title { font-weight: 700; font-size: 1rem; margin: 0 0 .4rem; }
    .req-card-meta { font-size: .82rem; color: var(--text-muted, #888); }
    .req-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .8rem; }
    .overdue-tag { color: #ef4444; font-weight: 700; font-size: .78rem; }
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
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
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
  <?php if ($ok !== ''): ?><p class="alert alert-success">✅ <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">❌ Error: <?= h($error) ?></p><?php endif; ?>

  <!-- ── Pending Requests Queue ── -->
  <h2>⏳ Pending Requests Queue (<?= count($pendingRows) ?>)</h2>
  <?php if (count($pendingRows) === 0): ?>
    <p class="empty-state" style="margin-bottom: 2rem;">No pending requests. All caught up! 🎉</p>
  <?php else: ?>
    <?php foreach ($pendingRows as $item): ?>
    <div class="req-card pending">
      <p class="req-card-title">
        Request #<?= (int) $item['id'] ?> — <?= h((string) $item['equipment_name']) ?>
        <span class="badge badge-warning" style="font-size:.75rem;">Pending</span>
      </p>
      <div class="req-card-meta">
        <div>👤 <strong><?= h((string) $item['staff_name']) ?></strong> &lt;<?= h((string) $item['staff_email']) ?>&gt;</div>
        <div>📦 Qty Requested: <strong><?= (int) $item['qty_requested'] ?></strong></div>
        <div>🎯 Purpose: <?= h((string) $item['purpose']) ?></div>
        <div>📅 Submitted: <?= h(utc_to_ph((string) $item['requested_at'])) ?></div>
      </div>
      <div class="req-actions">
        <form action="/api/actions/request_approve.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post" style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap;">
          <div class="form-group" style="margin-bottom:0;">
            <label style="font-size:.78rem;">Expected Return Date <span style="color:#ef4444;">*</span></label>
            <input type="date" name="due_date" required min="<?= date('Y-m-d') ?>" style="padding:.25rem .5rem; font-size:.85rem;">
          </div>
          <button type="submit" class="btn btn-primary" style="font-size:.85rem;">✅ Approve & Allocate</button>
        </form>
        <form action="/api/actions/request_reject.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post"
              onsubmit="return confirm('Reject this request?')">
          <button type="submit" class="btn btn-danger" style="font-size:.85rem;">❌ Reject</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- ── Active Allocations (Return Queue) ── -->
  <h2>📦 Active Allocations — Process Returns (<?= count($activeAllocations) ?>)</h2>
  <?php if (count($activeAllocations) === 0): ?>
    <p class="empty-state" style="margin-bottom: 2rem;">No active allocations.</p>
  <?php else: ?>
  <div class="table-responsive" style="margin-bottom: 2rem;">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th>
          <th>Staff</th>
          <th>Equipment</th>
          <th>Qty</th>
          <th>Checked Out</th>
          <th>Expected Return</th>
          <th>Status</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($activeAllocations as $alloc): ?>
        <?php
          $isOverdue = !empty($alloc['expected_return_date']) &&
                       $alloc['expected_return_date'] < date('Y-m-d');
        ?>
        <tr <?= $isOverdue ? 'style="background: rgba(239,68,68,.07);"' : '' ?>>
          <td><?= (int) $alloc['id'] ?></td>
          <td>
            <strong><?= h($alloc['staff_name']) ?></strong><br>
            <small style="color:var(--text-muted)"><?= h($alloc['staff_email']) ?></small>
          </td>
          <td><?= h($alloc['equipment_name']) ?></td>
          <td><?= (int) $alloc['qty_allocated'] ?></td>
          <td><?= h(utc_to_ph($alloc['checkout_date'], 'M d, Y')) ?></td>
          <td>
            <?php if (!empty($alloc['expected_return_date'])): ?>
              <?= h(utc_to_ph($alloc['expected_return_date'], 'M d, Y')) ?>
              <?php if ($isOverdue): ?>
                <span class="overdue-tag">OVERDUE</span>
              <?php endif; ?>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-info">Active</span></td>
          <td>
            <form action="/api/actions/allocation_return.php?<?= http_build_query(['id' => (int) $alloc['id']]) ?>" method="post"
                  onsubmit="return confirm('Mark equipment as returned? This will restore inventory.')">
              <button type="submit" class="btn btn-success" style="font-size:.8rem; padding:.25rem .6rem;">
                📥 Mark Returned
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ── Request History ── -->
  <h2>📋 Recent Request History (last 30)</h2>
  <?php if (count($historyRows) === 0): ?>
    <p class="empty-state">No processed requests yet.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>ID</th><th>Staff</th><th>Equipment</th><th>Qty</th><th>Status</th><th>Reviewed By</th><th>Reviewed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($historyRows as $item): ?>
        <tr>
          <td><?= (int) $item['id'] ?></td>
          <td><?= h($item['staff_name']) ?></td>
          <td><?= h($item['equipment_name']) ?></td>
          <td><?= (int) $item['qty_requested'] ?></td>
          <td>
            <span class="badge <?= match($item['status']) { 'allocated' => 'badge-success', 'rejected' => 'badge-error', 'returned' => 'badge-info', default => 'badge-warning' } ?>">
              <?= h(ucfirst($item['status'])) ?>
            </span>
          </td>
          <td><?= h($item['reviewed_by_name'] ?? '—') ?></td>
          <td><?= h(utc_to_ph($item['reviewed_at'] ?? '', 'M d, Y')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <p class="back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
