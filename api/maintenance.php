<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$role   = require_role(['maintenance', 'admin']);

// Prevent browser from caching this page — always show fresh data
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$userId = (int) $user['id'];

$ok    = isset($_GET['ok'])    && is_string($_GET['ok'])    ? trim($_GET['ok'])    : '';
$error = isset($_GET['error']) && is_string($_GET['error']) ? trim($_GET['error']) : '';

$today = date('Y-m-d');

// ── Equipment list (non-retired) ─────────────────────────────────────────────
$equipmentRows = db()->query(
    "SELECT id, name, status FROM equipment WHERE status <> 'retired' ORDER BY name ASC"
)->fetchAll();

// ── Scheduled logs grouped by urgency ───────────────────────────────────────
$scheduledLogs = db()->query(
    "SELECT m.id, m.equipment_id, e.name AS equipment_name, e.status AS equipment_status,
            m.maintenance_type, m.schedule_date::text AS schedule_date,
            m.notes, m.cost,
            u.full_name AS scheduled_by
     FROM maintenance_logs m
     JOIN equipment e ON e.id = m.equipment_id
     JOIN users u ON u.id = m.maintenance_user_id
     WHERE m.status = 'scheduled'
     ORDER BY m.schedule_date ASC"
)->fetchAll();

// ── Recent completed logs ────────────────────────────────────────────────────
$completedLogs = db()->query(
    "SELECT m.id, e.name AS equipment_name, m.maintenance_type,
            m.schedule_date::text AS schedule_date,
            m.completed_date::text AS completed_date,
            m.notes, m.cost,
            u.full_name AS scheduled_by
     FROM maintenance_logs m
     JOIN equipment e ON e.id = m.equipment_id
     JOIN users u ON u.id = m.maintenance_user_id
     WHERE m.status = 'completed'
     ORDER BY m.completed_date DESC
     LIMIT 20"
)->fetchAll();

// ── Stats ────────────────────────────────────────────────────────────────────
$statRow = db()->query(
    "SELECT
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date >= CURRENT_DATE) AS upcoming,
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date  = CURRENT_DATE) AS due_today,
       COUNT(*) FILTER (WHERE status = 'scheduled' AND schedule_date  < CURRENT_DATE) AS overdue,
       COUNT(*) FILTER (WHERE status = 'completed'
                          AND completed_date >= date_trunc('month', CURRENT_DATE))     AS completed_month
     FROM maintenance_logs"
)->fetch();
$statUpcoming = (int) ($statRow['upcoming']        ?? 0);
$statDueToday = (int) ($statRow['due_today']        ?? 0);
$statOverdue  = (int) ($statRow['overdue']           ?? 0);
$statDoneMonth= (int) ($statRow['completed_month']   ?? 0);

// ── Equipment history (per dropdown) ─────────────────────────────────────────
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

// Helper: days diff from today
function days_diff(string $dateStr): int {
    return (int) ceil((strtotime($dateStr) - strtotime(date('Y-m-d'))) / 86400);
}

$validTypes = ['scheduled' => 'Scheduled', 'repair' => 'Repair'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Maintenance Management – Equipment System</title>
  <meta name="description" content="Schedule, log, and track equipment maintenance.">
  <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<!-- ── Topbar ─────────────────────────────────────────────────────────────── -->
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

<div class="maint-page">

  <!-- Alerts -->
  <?php if ($ok !== ''): ?>
    <p class="alert alert-success">✅ <?= h($ok) ?></p>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <p class="alert alert-error">❌ <?= h($error) ?></p>
  <?php endif; ?>

  <!-- Page heading -->
  <div class="page-heading">
    <h1>🔧 Maintenance Management</h1>
    <p>Schedule, track, and log equipment maintenance tasks.</p>
  </div>

  <!-- ── Mini stats row ── -->
  <?php if ($statOverdue > 0): ?>
  <div class="overdue-banner">
    <span class="ob-icon">🚨</span>
    <span class="ob-text"><?= $statOverdue ?> maintenance task<?= $statOverdue !== 1 ? 's are' : ' is' ?> overdue! Immediate attention required.</span>
  </div>
  <?php endif; ?>

  <div class="stats-row">
    <div class="stat-pill sp-overdue">
      <div>
        <div class="sp-val"><?= $statOverdue ?></div>
        <div class="sp-label">Overdue</div>
      </div>
    </div>
    <div class="stat-pill sp-today">
      <div>
        <div class="sp-val"><?= $statDueToday ?></div>
        <div class="sp-label">Due Today</div>
      </div>
    </div>
    <div class="stat-pill sp-upcoming">
      <div>
        <div class="sp-val"><?= $statUpcoming ?></div>
        <div class="sp-label">Upcoming</div>
      </div>
    </div>
    <div class="stat-pill sp-done">
      <div>
        <div class="sp-val"><?= $statDoneMonth ?></div>
        <div class="sp-label">Done This Month</div>
      </div>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 1. UPCOMING & OVERDUE ALERTS -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('alerts')">
      <h2>🚨 Alerts — Upcoming & Overdue
        <?php if ($statOverdue > 0): ?>
          <span class="chip chip-overdue"><?= $statOverdue ?> overdue</span>
        <?php endif; ?>
      </h2>
      <span class="section-chevron open" id="chevron-alerts">▾</span>
    </div>
    <div class="section-body open" id="body-alerts">
      <?php
      $alertLogs = array_filter($scheduledLogs, fn($l) => $l['schedule_date'] <= date('Y-m-d', strtotime('+30 days')));
      usort($alertLogs, fn($a, $b) => strcmp($a['schedule_date'], $b['schedule_date']));
      ?>
      <?php if (empty($alertLogs)): ?>
        <p class="empty-state">✅ No upcoming or overdue maintenance in the next 30 days.</p>
      <?php else: ?>
        <div class="alert-grid">
          <?php foreach ($alertLogs as $log):
            $diff = days_diff($log['schedule_date']);
            if ($diff < 0)       { $urgencyClass = 'overdue';  $urgencyLabel = abs($diff) . 'd overdue';  $chipClass = 'urgency-overdue'; }
            elseif ($diff === 0) { $urgencyClass = 'due-today';$urgencyLabel = 'Due Today';                $chipClass = 'urgency-today'; }
            else                 { $urgencyClass = 'upcoming'; $urgencyLabel = 'In ' . $diff . ' day' . ($diff !== 1 ? 's' : ''); $chipClass = 'urgency-upcoming'; }
          ?>
          <div class="maint-alert-card <?= $urgencyClass ?>">
            <span class="alert-urgency <?= $chipClass ?>"><?= $urgencyLabel ?></span>
            <div class="alert-card-title"><?= h((string) $log['equipment_name']) ?></div>
            <div class="alert-card-meta">
              <span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span><br>
              📅 Scheduled: <strong><?= h((string) $log['schedule_date']) ?></strong><br>
              👤 By: <?= h((string) $log['scheduled_by']) ?><br>
              <?php if (!empty($log['notes'])): ?>📝 <?= h((string) $log['notes']) ?><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 2. SCHEDULE NEW MAINTENANCE -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <?php if ($role === 'maintenance'): ?>
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('schedule')">
      <h2>➕ Schedule New Maintenance</h2>
      <span class="section-chevron" id="chevron-schedule">▾</span>
    </div>
    <div class="section-body" id="body-schedule">
      <form action="/api/actions/maintenance_create.php" method="post" id="schedule-form">
        <div class="schedule-form-grid">
          <div class="form-group">
            <label for="equipment_id">Equipment *</label>
            <select id="equipment_id" name="equipment_id" required>
              <option value="">— Select equipment —</option>
              <?php foreach ($equipmentRows as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>">
                  <?= h((string) $eq['name']) ?> [<?= h((string) $eq['status']) ?>]
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="maintenance_type">Maintenance Type *</label>
            <select id="maintenance_type" name="maintenance_type" required>
              <option value="">— Select type —</option>
              <?php foreach ($validTypes as $val => $label): ?>
                <option value="<?= $val ?>"><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label for="schedule_date">Schedule Date *</label>
            <input id="schedule_date" name="schedule_date" type="date"
                   min="<?= $today ?>" required>
          </div>

          <div class="form-group">
            <label for="cost">Estimated Cost (₱)</label>
            <input id="cost" name="cost" type="number" min="0" step="0.01" value="0" placeholder="0.00">
          </div>

          <div class="form-group span-2">
            <label for="notes">Notes / Instructions</label>
            <textarea id="notes" name="notes" placeholder="Describe what needs to be done, special instructions..."></textarea>
          </div>
        </div>
        <div class="schedule-submit-row">
          <button type="submit" id="schedule-submit-btn">🗓️ Schedule Maintenance</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 3. ACTIVE / PENDING LOGS -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('active')">
      <h2>⏳ Active Maintenance Tasks (<?= count($scheduledLogs) ?>)</h2>
      <span class="section-chevron open" id="chevron-active">▾</span>
    </div>
    <div class="section-body open" id="body-active">
      <?php if (empty($scheduledLogs)): ?>
        <p class="empty-state">No active maintenance tasks. All clear! ✅</p>
      <?php else: ?>
        <div class="log-list">
          <?php foreach ($scheduledLogs as $log):
            $diff = days_diff($log['schedule_date']);
            $isOverdue  = $diff < 0;
            $isDueToday = $diff === 0;
          ?>
          <div class="log-card">
            <div class="log-card-header">
              <div>
                <div class="log-card-title">
                  #<?= (int) $log['id'] ?> — <?= h((string) $log['equipment_name']) ?>
                </div>
                <div class="log-card-badges">
                  <span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span>
                  <span class="chip chip-<?= $isOverdue ? 'overdue' : 'scheduled' ?>">
                    <?= $isOverdue ? 'Overdue ' . abs($diff) . 'd' : ($isDueToday ? 'Due Today' : 'Scheduled') ?>
                  </span>
                </div>
              </div>
              <div class="log-card-side">
                📅 <?= h((string) $log['schedule_date']) ?><br>
                👤 <?= h((string) $log['scheduled_by']) ?>
              </div>
            </div>
            <?php if (!empty($log['notes'])): ?>
              <div class="log-card-meta">📝 <?= h((string) $log['notes']) ?></div>
            <?php endif; ?>
            <?php if ($log['cost'] !== null && (float) $log['cost'] > 0): ?>
              <div class="log-card-meta">💰 Est. cost: ₱<?= number_format((float) $log['cost'], 2) ?></div>
            <?php endif; ?>

            <?php if ($role === 'maintenance'): ?>
            <div class="log-actions">
              <button type="button" class="btn-sm btn-complete"
                onclick="toggleCompleteForm(<?= (int) $log['id'] ?>)">
                ✅ Mark Completed
              </button>
              <form action="/api/actions/maintenance_cancel.php?id=<?= (int) $log['id'] ?>" method="post" class="form-zero"
                    onsubmit="return confirm('Cancel this maintenance task? Equipment status will be restored if no other tasks remain.')">
                <button type="submit" class="btn-sm btn-cancel">✖ Cancel Task</button>
              </form>
            </div>

            <!-- Inline completion form -->
            <div class="complete-form-wrap" id="complete-form-<?= (int) $log['id'] ?>">
              <form action="/api/actions/maintenance_complete.php?id=<?= (int) $log['id'] ?>" method="post">
                <div class="complete-form-grid">
                  <div class="form-group span-2">
                    <label for="work_done_<?= (int) $log['id'] ?>">What was done? *</label>
                    <textarea id="work_done_<?= (int) $log['id'] ?>" name="work_done"
                              placeholder="Describe the work performed..." required></textarea>
                  </div>
                  <div class="form-group">
                    <label for="parts_<?= (int) $log['id'] ?>">Parts Replaced</label>
                    <input id="parts_<?= (int) $log['id'] ?>" name="parts_replaced"
                           placeholder="e.g. Filter, belt, fuse — or None">
                  </div>
                  <div class="form-group">
                    <label for="next_sched_<?= (int) $log['id'] ?>">Next Maintenance Date</label>
                    <input id="next_sched_<?= (int) $log['id'] ?>" name="next_schedule_date"
                           type="date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                  </div>
                  <div class="form-group">
                    <label for="cost_done_<?= (int) $log['id'] ?>">Actual Cost (₱)</label>
                          <input id="cost_done_<?= (int) $log['id'] ?>" name="cost"
                            type="number" min="0" step="0.01" placeholder="Leave blank to keep estimated cost">
                  </div>
                </div>
                <div class="log-actions log-actions-top">
                  <button type="submit" class="btn-sm btn-complete">💾 Submit Completion Log</button>
                  <button type="button" class="btn-sm btn-secondary"
                          onclick="toggleCompleteForm(<?= (int) $log['id'] ?>)">Cancel</button>
                </div>
              </form>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 4. EQUIPMENT HISTORY VIEWER -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('history')">
      <h2>📋 Equipment Maintenance History</h2>
      <span class="section-chevron" id="chevron-history">▾</span>
    </div>
    <div class="section-body" id="body-history">
      <form method="get" action="/api/maintenance.php" id="history-form">
        <div class="history-select-row">
          <div class="form-group history-eq-group">
            <label for="history_eq">Select Equipment</label>
            <select id="history_eq" name="history_eq">
              <option value="">— Choose equipment to view history —</option>
              <?php foreach ($equipmentRows as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= $histEquipId === (int) $eq['id'] ? 'selected' : '' ?>>
                  <?= h((string) $eq['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit">🔍 View History</button>
        </div>
      </form>

      <?php if ($histEquipId > 0): ?>
        <?php if (empty($historyLogs)): ?>
          <p class="empty-state">No maintenance history for this equipment.</p>
        <?php else: ?>
          <div class="hist-table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Type</th>
                  <th>Scheduled</th>
                  <th>Completed</th>
                  <th>Status</th>
                  <th>Notes</th>
                  <th>Cost</th>
                  <th>By</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($historyLogs as $h): ?>
                <tr>
                  <td><?= (int) $h['id'] ?></td>
                  <td><span class="chip chip-type"><?= h((string) $h['maintenance_type']) ?></span></td>
                  <td><?= h((string) $h['schedule_date']) ?></td>
                  <td><?= h((string) ($h['completed_date'] ?? '—')) ?></td>
                  <td><span class="chip chip-<?= h((string) $h['status']) ?>"><?= h((string) $h['status']) ?></span></td>
                  <td class="note-cell"><?= h((string) ($h['notes'] ?? '—')) ?></td>
                  <td><?= $h['cost'] !== null ? '₱' . number_format((float) $h['cost'], 2) : '—' ?></td>
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

  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <!-- 5. RECENT COMPLETED LOGS -->
  <!-- ═══════════════════════════════════════════════════════════════════════ -->
  <div class="maint-section">
    <div class="section-header" onclick="toggleSection('completed')">
      <h2>✅ Recently Completed (last 20)</h2>
      <span class="section-chevron" id="chevron-completed">▾</span>
    </div>
    <div class="section-body" id="body-completed">
      <?php if (empty($completedLogs)): ?>
        <p class="empty-state">No completed maintenance logs yet.</p>
      <?php else: ?>
        <div class="hist-table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Equipment</th>
                <th>Type</th>
                <th>Scheduled</th>
                <th>Completed</th>
                <th>Notes</th>
                <th>Cost</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($completedLogs as $log): ?>
              <tr>
                <td><?= (int) $log['id'] ?></td>
                <td><strong><?= h((string) $log['equipment_name']) ?></strong></td>
                <td><span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span></td>
                <td><?= h((string) $log['schedule_date']) ?></td>
                <td><?= h((string) ($log['completed_date'] ?? '—')) ?></td>
                <td class="note-cell"><?= h((string) ($log['notes'] ?? '—')) ?></td>
                <td><?= $log['cost'] !== null ? '₱' . number_format((float) $log['cost'], 2) : '—' ?></td>
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

function toggleCompleteForm(id) {
  const wrap = document.getElementById('complete-form-' + id);
  wrap.classList.toggle('open');
}

<?php if ($role === 'maintenance'): ?>
// Auto-open schedule section if there was an error (user needs to re-schedule)
<?php if ($error !== ''): ?>
document.addEventListener('DOMContentLoaded', function() {
  const body    = document.getElementById('body-schedule');
  const chevron = document.getElementById('chevron-schedule');
  if (body && !body.classList.contains('open')) {
    body.classList.add('open');
    chevron && chevron.classList.add('open');
  }
});
<?php endif; ?>

// Prevent double-submit
const scheduleForm = document.getElementById('schedule-form');
if (scheduleForm) {
  scheduleForm.addEventListener('submit', function() {
    const btn = document.getElementById('schedule-submit-btn');
    if (btn) { btn.disabled = true; btn.textContent = 'Scheduling…'; }
  });
}
<?php endif; ?>
</script>
<script src="/assets/app.js"></script>
</body>
</html>
