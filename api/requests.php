<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user    = require_login();
$role    = require_role(['staff']);
$staffId = (int) $user['id'];
$ok      = isset($_GET['ok'])    && is_string($_GET['ok'])    ? trim($_GET['ok'])    : '';
$error   = isset($_GET['error']) && is_string($_GET['error']) ? trim($_GET['error']) : '';

// No-cache
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Filter state for available equipment
$equipQ = query_param('equip_q');
$equipCategory = query_param('equip_category');

// Filter state for request history
$reqQ = query_param('req_q');
$reqStatus = query_param('req_status');
$reqFromDate = query_param('req_from');
$reqToDate = query_param('req_to');

// If POST, redirect to GET URL to persist filters
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedEquipQ = post_string('equip_q');
    $postedEquipCat = post_string('equip_category');
    $postedReqQ = post_string('req_q');
    $postedReqStatus = post_string('req_status');
    $postedReqFrom = post_string('req_from');
    $postedReqTo = post_string('req_to');
    
    $params = [];
    if ($postedEquipQ !== '') { $params['equip_q'] = $postedEquipQ; }
    if ($postedEquipCat !== '') { $params['equip_category'] = $postedEquipCat; }
    if ($postedReqQ !== '') { $params['req_q'] = $postedReqQ; }
    if ($postedReqStatus !== '') { $params['req_status'] = $postedReqStatus; }
    if ($postedReqFrom !== '') { $params['req_from'] = $postedReqFrom; }
    if ($postedReqTo !== '') { $params['req_to'] = $postedReqTo; }
    
    redirect_to('/api/requests.php', $params);
}

// Get categories from available equipment (for filter dropdown)
$categoryStmt = db()->query(
    "SELECT DISTINCT category FROM equipment WHERE status = 'available' AND category IS NOT NULL ORDER BY category ASC"
);
$categoryOptions = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

// Get allowed request statuses (for filter dropdown)
$statusOptions = ['pending', 'allocated', 'rejected'];

// Build available equipment query
$equipWhere = ["status = 'available' AND quantity_available > 0"];
$equipParams = [];

if ($equipQ !== '') {
    $equipWhere[] = "name ILIKE :equip_q";
    $equipParams[':equip_q'] = '%' . $equipQ . '%';
}
if ($equipCategory !== '' && $equipCategory !== 'all') {
    $equipWhere[] = "category = :equip_category";
    $equipParams[':equip_category'] = $equipCategory;
}

$equipWhereClause = implode(' AND ', $equipWhere);
$equipStmt = db()->prepare(
    "SELECT id, name, quantity_available, status, description, category
     FROM equipment
     WHERE {$equipWhereClause}
     ORDER BY name ASC"
);
$equipStmt->execute($equipParams);
$equipmentRows = $equipStmt->fetchAll();

// Build request history query
$reqWhere = ['r.staff_id = :sid'];
$reqParams = [':sid' => $staffId];

if ($reqQ !== '') {
    $reqWhere[] = "e.name ILIKE :req_q";
    $reqParams[':req_q'] = '%' . $reqQ . '%';
}
if ($reqStatus !== '' && in_array($reqStatus, $statusOptions, true)) {
    $reqWhere[] = "r.status = :req_status";
    $reqParams[':req_status'] = $reqStatus;
}
if ($reqFromDate !== '') {
    $reqWhere[] = "DATE(r.requested_at) >= :req_from";
    $reqParams[':req_from'] = $reqFromDate;
}
if ($reqToDate !== '') {
    $reqWhere[] = "DATE(r.requested_at) <= :req_to";
    $reqParams[':req_to'] = $reqToDate;
}

$reqWhereClause = implode(' AND ', $reqWhere);
$requestStmt = db()->prepare(
    "SELECT r.id, e.name AS equipment_name, r.qty_requested, r.status, r.requested_at::text AS requested_at
     FROM equipment_requests r
     JOIN equipment e ON e.id = r.equipment_id
     WHERE {$reqWhereClause}
     ORDER BY r.id DESC"
);
$requestStmt->execute($reqParams);
$requestRows = $requestStmt->fetchAll();

// Active allocations for this staff
$allocRows = db()->prepare(
    "SELECT a.id, a.equipment_id, e.name AS equipment_name,
            a.qty_allocated, a.checkout_date::text AS checkout_date,
            a.expected_return_date::text AS expected_return_date,
            a.status
     FROM allocations a
     JOIN equipment e ON e.id = a.equipment_id
     WHERE a.staff_id = :sid AND a.actual_return_date IS NULL
     ORDER BY a.expected_return_date ASC NULLS LAST"
);
$allocRows->execute([':sid' => $staffId]);
$allocRows = $allocRows->fetchAll();

$unreadCount = NotificationService::getInstance()->getUnreadCount($staffId);
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Equipment Requests – Equipment Management System</title>
  <meta name="description" content="Submit equipment requests and view your allocation history.">
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
      <span><?= h($user['full_name']) ?> | Staff</span>
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

<main class="page page-requests">
  <?php if ($ok !== ''): ?><p class="alert alert-success">✅ <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">❌ <?= h($error) ?></p><?php endif; ?>

  <!-- ── Filter Available Equipment ───────────────────────────────────────── -->
  <section class="card">
    <h2>🔍 Filter Available Equipment</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="equip_q">Search by equipment name:</label>
        <input type="text" id="equip_q" name="equip_q" placeholder="Search..." value="<?= h($equipQ) ?>">
      </div>
      <div class="form-group">
        <label for="equip_category">Category:</label>
        <select id="equip_category" name="equip_category">
          <option value="">All Categories</option>
          <?php foreach ($categoryOptions as $cat): ?>
            <option value="<?= h($cat) ?>" <?= $equipCategory === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display: flex; gap: 0.75rem; align-items: flex-end;">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="/api/requests.php" class="filter-clear">Clear</a>
      </div>
    </form>
  </section>

  <!-- ── New Request ──────────────────────────────────────────────────────── -->
  <section class="card">
    <h2>📋 Create Equipment Request</h2>
    <form action="/api/actions/request_create.php" method="post" class="form">
      <div class="form-group">
        <label for="equipment_id">Equipment *</label>
        <select id="equipment_id" name="equipment_id" required onchange="showEquipDesc(this)">
          <option value="" data-desc="">— Select equipment —</option>
          <?php foreach ($equipmentRows as $item): ?>
            <option value="<?= (int) $item['id'] ?>"
              data-desc="<?= h((string) ($item['description'] ?? '')) ?>">
              <?= h((string) $item['name']) ?>
              (<?= (int) $item['quantity_available'] ?> available — <?= h((string) $item['status']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <div id="equip-desc-box" style="display:none; margin-top:.5rem; padding:.6rem .9rem;
             background:var(--bg-alt,#111); border:1px solid var(--border-color,#2a2a2a);
             border-radius:7px; font-size:.85rem; color:var(--text-muted);"
             aria-live="polite">
        </div>
      </div>
      <div class="form-group">
        <label for="qty_requested">Quantity *</label>
        <input id="qty_requested" name="qty_requested" type="number" min="1" required placeholder="1">
      </div>
      <div class="form-group">
        <label for="purpose">Purpose *</label>
        <textarea id="purpose" name="purpose" required placeholder="Describe why you need this equipment..."></textarea>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Submit Request</button>
      </div>
    </form>
  </section>

  <!-- ── Currently Holding (Active Allocations) ────────────────────────────── -->
  <section class="card">
    <h2>📦 Equipment You're Currently Holding (<?= count($allocRows) ?>)</h2>
    <?php if (empty($allocRows)): ?>
      <p class="empty-state">No active allocations. You have no equipment checked out.</p>
    <?php else: ?>
      <p style="font-size:.85rem; color:var(--text-muted); margin-bottom:1rem;">
        To return equipment, click "Request Return" — admin will process it and restore inventory.
      </p>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>#</th>
              <th>Equipment</th>
              <th>Qty</th>
              <th>Checkout Date</th>
              <th>Expected Return</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allocRows as $a):
              $dueDateStr  = $a['expected_return_date'] ?? null;
              $isOverdue   = $dueDateStr && $dueDateStr < $today;
              $isDueToday  = $dueDateStr && $dueDateStr === $today;
            ?>
            <tr>
              <td>#<?= (int) $a['id'] ?></td>
              <td><strong><?= h((string) $a['equipment_name']) ?></strong></td>
              <td><?= (int) $a['qty_allocated'] ?></td>
              <td><?= h((string) ($a['checkout_date'] ?? '—')) ?></td>
              <td>
                <?php if ($isOverdue): ?>
                  <span class="badge badge-error">⚠ <?= h((string) $dueDateStr) ?> (overdue)</span>
                <?php elseif ($isDueToday): ?>
                  <span class="badge badge-warning">⏰ Due Today</span>
                <?php else: ?>
                  <?= h((string) ($dueDateStr ?? '—')) ?>
                <?php endif; ?>
              </td>
              <td><span class="chip chip-status chip-active">Active</span></td>
              <td>
                <form action="/api/actions/request_return_notify.php" method="post"
                      onsubmit="const btn = this.querySelector('button'); if (!confirm('Request admin to process return of this equipment?')) return false; if (btn) { btn.disabled = true; btn.textContent = 'Sending…'; } return true;">
                  <input type="hidden" name="allocation_id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn btn-secondary" style="font-size:.8rem; padding:.3rem .8rem;">
                    📤 Request Return
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

  <!-- ── Filter Request History ──────────────────────────────────────────── -->
  <section class="card">
    <h2>🔍 Filter Request History</h2>
    <form method="post" class="filter-form">
      <div class="form-group">
        <label for="req_q">Equipment name:</label>
        <input type="text" id="req_q" name="req_q" placeholder="Search..." value="<?= h($reqQ) ?>">
      </div>
      <div class="form-group">
        <label for="req_status">Status:</label>
        <select id="req_status" name="req_status">
          <option value="">All Statuses</option>
          <?php foreach ($statusOptions as $status): ?>
            <option value="<?= h($status) ?>" <?= $reqStatus === $status ? 'selected' : '' ?>><?= ucfirst(h($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="req_from">From Date:</label>
        <input type="date" id="req_from" name="req_from" value="<?= h($reqFromDate) ?>">
      </div>
      <div class="form-group">
        <label for="req_to">To Date:</label>
        <input type="date" id="req_to" name="req_to" value="<?= h($reqToDate) ?>">
      </div>
      <div style="display: flex; gap: 0.75rem; align-items: flex-end;">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="/api/requests.php" class="filter-clear">Clear</a>
      </div>
    </form>
  </section>

  <!-- ── Request History ───────────────────────────────────────────────────── -->
  <section class="card">
    <h2>📜 Your Request History (<?= count($requestRows) ?> request<?= count($requestRows) === 1 ? '' : 's' ?><?= ($reqQ || $reqStatus || $reqFromDate || $reqToDate) ? ' matching filters' : '' ?>)</h2>
    <?php if (empty($requestRows)): ?>
      <p class="empty-state">No requests submitted yet.</p>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr><th>#</th><th>Equipment</th><th>Qty</th><th>Status</th><th>Requested</th></tr>
          </thead>
          <tbody>
            <?php foreach ($requestRows as $item): ?>
            <tr>
              <td>#<?= (int) $item['id'] ?></td>
              <td><strong><?= h((string) $item['equipment_name']) ?></strong></td>
              <td><?= (int) $item['qty_requested'] ?></td>
              <td><span class="chip chip-status chip-<?= h((string) $item['status']) ?>"><?= h((string) $item['status']) ?></span></td>
              <td><?= h(utc_to_ph((string) $item['requested_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <p class="back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</main>
<script>
function showEquipDesc(sel) {
  var box  = document.getElementById('equip-desc-box');
  var desc = sel.options[sel.selectedIndex].dataset.desc || '';
  if (desc.trim() !== '') {
    box.textContent = '📄 ' + desc;
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }
}
</script>
<script src="/assets/app.js"></script>
</body>
</html>
