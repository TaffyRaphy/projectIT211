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
<main class="page page-admin-requests">
  <?php if ($ok !== ''): ?><p class="alert alert-success"><i class="fas fa-circle-check"></i> <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error"><i class="fas fa-circle-xmark"></i> Error: <?= h($error) ?></p><?php endif; ?>

  <!-- ── Pending Requests Queue ── -->
  <section class="section-container">
    <h2 class="section-heading"><i class="fas fa-hourglass-half"></i> Pending Requests Queue <span class="section-count">(<?= count($pendingRows) ?>)</span></h2>
    <?php if (count($pendingRows) === 0): ?>
      <p class="empty-state">No pending requests. All caught up.</p>
    <?php else: ?>
      <div class="cards-grid">
        <?php foreach ($pendingRows as $item): ?>
        <div class="req-card pending">
          <p class="req-card-title">
            Request #<?= (int) $item['id'] ?> — <?= h((string) $item['equipment_name']) ?>
            <span class="badge badge-warning" style="font-size:.75rem;">Pending</span>
          </p>
          <div class="req-card-meta">
            <div class="req-meta-row"><i class="fas fa-user"></i><span><strong><?= h((string) $item['staff_name']) ?></strong><br><small><?= h((string) $item['staff_email']) ?></small></span></div>
            <div class="req-meta-row"><i class="fas fa-box"></i><span>Qty: <strong><?= (int) $item['qty_requested'] ?></strong></span></div>
            <div class="req-meta-row"><i class="fas fa-bullseye"></i><span><?= h((string) $item['purpose']) ?></span></div>
            <div class="req-meta-row"><i class="fas fa-calendar-days"></i><span><?= h(utc_to_ph((string) $item['requested_at'])) ?></span></div>
          </div>
          <div class="req-actions">
            <form action="/api/actions/request_approve.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post" style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; flex:1;">
              <div class="form-group" style="margin-bottom:0;">
                <label style="font-size:.78rem;">Expected Return Date <span style="color:#ef4444;">*</span></label>
                <input type="date" name="due_date" required min="<?= date('Y-m-d') ?>" style="padding:.25rem .5rem; font-size:.85rem;">
              </div>
              <div class="form-group" style="margin-bottom:0; min-width:220px; flex:1;">
                <label style="font-size:.78rem;">Approval Remarks <small>(optional)</small></label>
                <input type="text" name="remarks" maxlength="255" placeholder="Add note for staff..." style="padding:.25rem .5rem; font-size:.85rem; width:100%;">
              </div>
              <button type="submit" class="btn btn-primary" style="font-size:.85rem;"><i class="fas fa-check"></i> Approve & Allocate</button>
            </form>
            <form action="/api/actions/request_reject.php?<?= http_build_query(['id' => (int) $item['id']]) ?>" method="post" style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap;"
                  onsubmit="return confirm('Reject this request?')">
              <div class="form-group" style="margin-bottom:0; min-width:220px;">
                <label style="font-size:.78rem;">Rejection Remarks <small>(optional)</small></label>
                <input type="text" name="remarks" maxlength="255" placeholder="Reason shown to staff" style="padding:.25rem .5rem; font-size:.85rem; width:100%;">
              </div>
              <button type="submit" class="btn btn-danger" style="font-size:.85rem;"><i class="fas fa-xmark"></i> Reject</button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <!-- ── Active Allocations (Return Queue) ── -->
  <section class="section-container">
    <h2 class="section-heading"><i class="fas fa-box-open"></i> Active Allocations — Process Returns <span class="section-count">(<?= count($activeAllocations) ?>)</span></h2>
    <?php if (count($activeAllocations) === 0): ?>
      <p class="empty-state">No active allocations.</p>
    <?php else: ?>
    <div class="table-responsive allocation-table-wrapper">
      <table class="table allocation-table">
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
          <tr class="<?= $isOverdue ? 'is-overdue' : '' ?>">
            <td class="cell-id"><?= (int) $alloc['id'] ?></td>
            <td class="cell-staff">
              <strong><?= h($alloc['staff_name']) ?></strong><br>
              <small style="color:var(--text-muted)"><?= h($alloc['staff_email']) ?></small>
            </td>
            <td class="cell-equipment"><?= h($alloc['equipment_name']) ?></td>
            <td class="cell-qty"><?= (int) $alloc['qty_allocated'] ?></td>
            <td class="cell-date"><?= h(utc_to_ph($alloc['checkout_date'], 'M d, Y')) ?></td>
            <td class="cell-date">
              <?php if (!empty($alloc['expected_return_date'])): ?>
                <?= h(utc_to_ph($alloc['expected_return_date'], 'M d, Y')) ?>
                <?php if ($isOverdue): ?>
                  <span class="overdue-tag"><i class="fas fa-triangle-exclamation"></i> Overdue</span>
                <?php endif; ?>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td class="cell-status"><span class="badge badge-info">Active</span></td>
            <td class="cell-action">
              <form action="/api/actions/allocation_return.php?<?= http_build_query(['id' => (int) $alloc['id']]) ?>" method="post"
                    onsubmit="return confirm('Mark equipment as returned? This will restore inventory.')">
                <button type="submit" class="btn btn-success btn-sm">
                  <i class="fas fa-inbox"></i> Mark Returned
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </section>

  <!-- ── Request History ── -->
  <section class="section-container">
    <h2 class="section-heading"><i class="fas fa-clock-rotate-left"></i> Recent Request History <span class="section-count">(last 30)</span></h2>
    <?php if (count($historyRows) === 0): ?>
      <p class="empty-state">No processed requests yet.</p>
    <?php else: ?>
    <div class="table-responsive history-table-wrapper">
      <table class="table history-table">
        <thead>
          <tr>
            <th>ID</th><th>Staff</th><th>Equipment</th><th>Qty</th><th>Status</th><th>Reviewed By</th><th>Reviewed</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($historyRows as $item): ?>
          <tr>
            <td class="cell-id"><?= (int) $item['id'] ?></td>
            <td class="cell-staff"><?= h($item['staff_name']) ?></td>
            <td class="cell-equipment"><?= h($item['equipment_name']) ?></td>
            <td class="cell-qty"><?= (int) $item['qty_requested'] ?></td>
          <td>
            <span class="badge <?= match($item['status']) { 'allocated' => 'badge-success', 'rejected' => 'badge-error', 'returned' => 'badge-info', default => 'badge-warning' } ?>">
              <?= h(ucfirst($item['status'])) ?>
            </span>
          </td>
          <td class="cell-reviewer"><?= h($item['reviewed_by_name'] ?? '—') ?></td>
          <td class="cell-date"><?= h(utc_to_ph($item['reviewed_at'] ?? '', 'M d, Y')) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <p class="back-link"><a href="/api/dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
