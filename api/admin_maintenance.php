<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$role   = require_role(['admin']);
$userId = (int) $user['id'];

$today = date('Y-m-d');

// ── All scheduled logs ───────────────────────────────────────────────────────
$scheduledLogs = db()->query(
    "SELECT m.id, m.equipment_id, e.name AS equipment_name,
            m.maintenance_type, m.schedule_date::text AS schedule_date,
            m.notes, m.cost, u.full_name AS scheduled_by
     FROM maintenance_logs m
     JOIN equipment e ON e.id = m.equipment_id
     JOIN users u ON u.id = m.maintenance_user_id
     WHERE m.status = 'scheduled'
     ORDER BY m.schedule_date ASC"
)->fetchAll();

// ── All logs paginated ───────────────────────────────────────────────────────
$allLogs = db()->query(
    "SELECT m.id, e.name AS equipment_name, m.maintenance_type,
            m.schedule_date::text, m.completed_date::text,
            m.status, m.notes, m.cost, u.full_name AS scheduled_by
     FROM maintenance_logs m
     JOIN equipment e ON e.id = m.equipment_id
     JOIN users u ON u.id = m.maintenance_user_id
     ORDER BY m.id DESC
     LIMIT 50"
)->fetchAll();

// ── Stats ────────────────────────────────────────────────────────────────────
$statRow = db()->query(
    "SELECT
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date >= CURRENT_DATE) AS upcoming,
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date  = CURRENT_DATE) AS due_today,
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date  < CURRENT_DATE) AS overdue,
       COUNT(*) FILTER (WHERE status = 'completed'
                          AND completed_date >= date_trunc('month', CURRENT_DATE))     AS completed_month,
       COUNT(*) FILTER (WHERE status = 'completed') AS total_completed,
       COUNT(*)                                      AS total_logs
     FROM maintenance_logs"
)->fetch();
$statUpcoming   = (int) ($statRow['upcoming']        ?? 0);
$statDueToday   = (int) ($statRow['due_today']        ?? 0);
$statOverdue    = (int) ($statRow['overdue']           ?? 0);
$statDoneMonth  = (int) ($statRow['completed_month']   ?? 0);
$statTotalDone  = (int) ($statRow['total_completed']   ?? 0);
$statTotal      = (int) ($statRow['total_logs']        ?? 0);

// ── Equipment history ────────────────────────────────────────────────────────
$equipmentRows = db()->query(
    "SELECT id, name FROM equipment WHERE status <> 'retired' ORDER BY name ASC"
)->fetchAll();
$histEquipId = isset($_GET['history_eq']) ? (int) $_GET['history_eq'] : 0;
$historyLogs = [];
if ($histEquipId > 0) {
    $histStmt = db()->prepare(
        "SELECT m.id, m.maintenance_type, m.schedule_date::text, m.completed_date::text,
                m.status, m.notes, m.cost, u.full_name AS scheduled_by
         FROM maintenance_logs m
         JOIN users u ON u.id = m.maintenance_user_id
         WHERE m.equipment_id = :eid
         ORDER BY m.schedule_date DESC"
    );
    $histStmt->execute(['eid' => $histEquipId]);
    $historyLogs = $histStmt->fetchAll();
}

$unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

function adm_days_diff(string $dateStr): int {
    return (int) ceil((strtotime($dateStr) - strtotime(date('Y-m-d'))) / 86400);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance Overview (Admin) – Equipment System</title>
  <meta name="description" content="Admin view of all maintenance records and alerts.">
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
    <div class="dashboard-topbar-meta"><span><?= h($user['full_name']) ?> | Admin</span></div>
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

<div class="maint-page">

  <div class="page-heading">
    <h1>🔧 Maintenance Overview</h1>
    <p>Admin read-only view of all maintenance records and alerts.</p>
  </div>

  <div class="admin-note">👁️ Read-only. Scheduling and completion managed by Maintenance Personnel.</div>

  <!-- Overdue banner -->
  <?php if ($statOverdue > 0): ?>
  <div class="overdue-banner">
    <span class="ob-icon">🚨</span>
    <span class="ob-text"><?= $statOverdue ?> maintenance task<?= $statOverdue !== 1 ? 's are' : ' is' ?> overdue! Follow up with maintenance team.</span>
  </div>
  <?php endif; ?>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-pill sp-overdue"><div><div class="sp-val"><?= $statOverdue ?></div><div class="sp-label">Overdue</div></div></div>
    <div class="stat-pill sp-today"  ><div><div class="sp-val"><?= $statDueToday ?></div><div class="sp-label">Due Today</div></div></div>
    <div class="stat-pill sp-upcoming"><div><div class="sp-val"><?= $statUpcoming ?></div><div class="sp-label">Upcoming</div></div></div>
    <div class="stat-pill sp-done"   ><div><div class="sp-val"><?= $statDoneMonth ?></div><div class="sp-label">Done This Month</div></div></div>
    <div class="stat-pill"           ><div><div class="sp-val admin-total-pill"><?= $statTotalDone ?> / <?= $statTotal ?></div><div class="sp-label">All-Time Completion</div></div></div>
  </div>

  <!-- ── Overdue/Upcoming Alerts ── -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('alerts')">
      <h2>🚨 Scheduled Tasks
        <?php if ($statOverdue > 0): ?>
          <span class="chip chip-overdue"><?= $statOverdue ?> overdue</span>
        <?php endif; ?>
      </h2>
      <span class="section-chevron open" id="chevron-alerts">▾</span>
    </div>
    <div class="section-body open" id="body-alerts">
      <?php if (empty($scheduledLogs)): ?>
        <p class="empty-state">✅ No active scheduled maintenance tasks.</p>
      <?php else: ?>
        <div class="alert-grid">
          <?php foreach ($scheduledLogs as $log):
            $diff = adm_days_diff($log['schedule_date']);
            if ($diff < 0)      { $urgencyClass='overdue';   $urgencyLabel=abs($diff).'d overdue'; $chipClass='urgency-overdue'; }
            elseif($diff===0)   { $urgencyClass='due-today'; $urgencyLabel='Due Today';             $chipClass='urgency-today'; }
            else                { $urgencyClass='upcoming';  $urgencyLabel='In '.$diff.' day'.($diff!==1?'s':''); $chipClass='urgency-upcoming'; }
          ?>
          <div class="maint-alert-card <?= $urgencyClass ?>">
            <span class="alert-urgency <?= $chipClass ?>"><?= $urgencyLabel ?></span>
            <div class="alert-card-title"><?= h((string) $log['equipment_name']) ?></div>
            <div class="alert-card-meta">
              <span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span><br>
              📅 <?= h((string) $log['schedule_date']) ?><br>
              👤 <?= h((string) $log['scheduled_by']) ?><br>
              <?php if (!empty($log['notes'])): ?>📝 <?= h(mb_strimwidth((string)$log['notes'], 0, 80, '…')) ?><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Equipment History ── -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('history')">
      <h2>📋 Equipment Maintenance History</h2>
      <span class="section-chevron" id="chevron-history">▾</span>
    </div>
    <div class="section-body" id="body-history">
      <form method="get" action="/api/admin_maintenance.php">
        <div class="history-select-row">
          <div class="form-group history-eq-group">
            <label for="history_eq">Select Equipment</label>
            <select id="history_eq" name="history_eq">
              <option value="">— Choose equipment —</option>
              <?php foreach ($equipmentRows as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= $histEquipId===(int)$eq['id']?'selected':'' ?>>
                  <?= h((string) $eq['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit">🔍 View</button>
        </div>
      </form>
      <?php if ($histEquipId > 0): ?>
        <?php if (empty($historyLogs)): ?>
          <p class="empty-state">No history for this equipment.</p>
        <?php else: ?>
          <div class="hist-table-wrap">
            <table>
              <thead><tr><th>#</th><th>Type</th><th>Scheduled</th><th>Completed</th><th>Status</th><th>Notes</th><th>Cost</th><th>By</th></tr></thead>
              <tbody>
                <?php foreach ($historyLogs as $h): ?>
                <tr>
                  <td><?= (int) $h['id'] ?></td>
                  <td><span class="chip chip-type"><?= h((string) $h['maintenance_type']) ?></span></td>
                  <td><?= h((string) $h['schedule_date']) ?></td>
                  <td><?= h((string) ($h['completed_date'] ?? '—')) ?></td>
                  <td><span class="chip chip-<?= h((string) $h['status']) ?>"><?= h((string) $h['status']) ?></span></td>
                  <td class="note-cell"><?= h((string) ($h['notes'] ?? '—')) ?></td>
                  <td><?= $h['cost']!==null ? '₱'.number_format((float)$h['cost'],2) : '—' ?></td>
                  <td><?= h((string) $h['scheduled_by']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── All Logs ── -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('alllogs')">
      <h2>📄 All Maintenance Logs (latest 50)</h2>
      <span class="section-chevron" id="chevron-alllogs">▾</span>
    </div>
    <div class="section-body" id="body-alllogs">
      <?php if (empty($allLogs)): ?>
        <p class="empty-state">No maintenance logs found.</p>
      <?php else: ?>
        <div class="hist-table-wrap">
          <table>
            <thead><tr><th>#</th><th>Equipment</th><th>Type</th><th>Scheduled</th><th>Completed</th><th>Status</th><th>Cost</th><th>By</th></tr></thead>
            <tbody>
              <?php foreach ($allLogs as $log): ?>
              <tr>
                <td><?= (int) $log['id'] ?></td>
                <td><strong><?= h((string) $log['equipment_name']) ?></strong></td>
                <td><span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span></td>
                <td><?= h((string) $log['schedule_date']) ?></td>
                <td><?= h((string) ($log['completed_date'] ?? '—')) ?></td>
                <td>
                  <?php
                  $st = (string) $log['status'];
                  $isOdInTable = $st === 'scheduled' && isset($log['schedule_date']) && $log['schedule_date'] < date('Y-m-d');
                  ?>
                  <span class="chip chip-<?= $isOdInTable ? 'overdue' : h($st) ?>">
                    <?= $isOdInTable ? 'overdue' : h($st) ?>
                  </span>
                </td>
                <td><?= $log['cost']!==null ? '₱'.number_format((float)$log['cost'],2) : '—' ?></td>
                <td><?= h((string) $log['scheduled_by']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <p class="back-link maint-back-link"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
</div>

<script>
function toggleSection(id) {
  const body    = document.getElementById('body-' + id);
  const chevron = document.getElementById('chevron-' + id);
  const isOpen  = body.classList.toggle('open');
  chevron.classList.toggle('open', isOpen);
}
</script>
<script src="/assets/app.js"></script>
</body>
</html>
