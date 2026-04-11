<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user   = require_login();
$role   = require_role(['maintenance', 'admin']);
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
  <style>
    /* ── Page layout ── */
    .maint-page {
      width: min(1100px, 94%);
      margin: 0 auto;
      padding: 1.5rem 0 4rem;
    }

    /* ── Section cards ── */
    .maint-section {
      background: var(--surface-1);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-lg);
      padding: 1.5rem 1.75rem;
      margin-bottom: 1.5rem;
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: var(--shadow-standard);
      transition: border-color .25s;
    }
    .maint-section:hover { border-color: var(--border-strong); }

    .section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 1.25rem;
      cursor: pointer;
      user-select: none;
    }
    .section-header h2 {
      margin: 0;
      font-size: 1.15rem;
      font-family: var(--font-heading);
      color: var(--text-heading);
      display: flex;
      align-items: center;
      gap: .6rem;
    }
    .section-chevron {
      font-size: .9rem;
      color: var(--text-muted);
      transition: transform .25s;
    }
    .section-chevron.open { transform: rotate(180deg); }
    .section-body { display: none; }
    .section-body.open { display: block; }

    /* ── Alert cards ── */
    .alert-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
      gap: 1rem;
    }
    .maint-alert-card {
      border-radius: var(--radius-md);
      padding: 1rem 1.25rem;
      border-left: 4px solid;
      background: var(--surface-2);
      border-color: var(--border-subtle);
      position: relative;
    }
    .maint-alert-card.overdue {
      border-color: var(--accent-danger);
      background: color-mix(in srgb, var(--accent-danger) 6%, var(--surface-2));
    }
    .maint-alert-card.due-today {
      border-color: var(--accent-warning);
      background: color-mix(in srgb, var(--accent-warning) 6%, var(--surface-2));
    }
    .maint-alert-card.upcoming {
      border-color: var(--accent-primary);
      background: color-mix(in srgb, var(--accent-primary) 4%, var(--surface-2));
    }
    .alert-card-title {
      font-weight: 700;
      font-size: .95rem;
      color: var(--text-heading);
      margin-bottom: .3rem;
    }
    .alert-card-meta {
      font-size: .8rem;
      color: var(--text-muted);
      line-height: 1.6;
    }
    .alert-urgency {
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .08em;
      text-transform: uppercase;
      padding: .15rem .5rem;
      border-radius: 999px;
      display: inline-block;
      margin-bottom: .4rem;
    }
    .urgency-overdue  { background: color-mix(in srgb, var(--accent-danger) 16%, transparent); color: var(--accent-danger); }
    .urgency-today    { background: color-mix(in srgb, var(--accent-warning) 18%, transparent); color: var(--accent-warning); }
    .urgency-upcoming { background: color-mix(in srgb, var(--accent-primary) 14%, transparent); color: var(--accent-primary); }

    /* ── Schedule form ── */
    .schedule-form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
    }
    @media (max-width: 600px) { .schedule-form-grid { grid-template-columns: 1fr; } }
    .form-group { display: flex; flex-direction: column; gap: .35rem; }
    .span-2 { grid-column: 1 / -1; }

    /* ── Log cards ── */
    .log-list { display: flex; flex-direction: column; gap: .9rem; }
    .log-card {
      background: var(--surface-2);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: 1rem 1.25rem;
      transition: border-color .2s;
    }
    .log-card:hover { border-color: var(--border-strong); }
    .log-card-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: .75rem;
      flex-wrap: wrap;
      margin-bottom: .5rem;
    }
    .log-card-title {
      font-weight: 700;
      font-size: .95rem;
      color: var(--text-heading);
    }
    .log-card-meta { font-size: .8rem; color: var(--text-muted); line-height: 1.7; }

    /* ── Status chips ── */
    .chip {
      display: inline-block;
      font-size: .72rem;
      font-weight: 700;
      letter-spacing: .07em;
      text-transform: uppercase;
      padding: .15rem .55rem;
      border-radius: 999px;
    }
    .chip-scheduled  { background: color-mix(in srgb, var(--accent-warning) 16%, transparent); color: var(--accent-warning); }
    .chip-completed  { background: color-mix(in srgb, var(--accent-primary) 14%, transparent); color: var(--accent-primary); }
    .chip-cancelled  { background: color-mix(in srgb, var(--text-muted) 16%, transparent);    color: var(--text-muted); }
    .chip-overdue    { background: color-mix(in srgb, var(--accent-danger) 16%, transparent);  color: var(--accent-danger); }
    .chip-maintenance{ background: color-mix(in srgb, var(--accent-warning) 14%, transparent); color: var(--accent-warning); }
    .chip-type       { background: color-mix(in srgb, var(--accent-teal) 12%, transparent);    color: var(--accent-teal); }

    /* ── Completion form (inline expand) ── */
    .complete-form-wrap { display: none; margin-top: 1rem; border-top: 1px dashed var(--border-subtle); padding-top: 1rem; }
    .complete-form-wrap.open { display: block; }
    .complete-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
    @media (max-width: 540px) { .complete-form-grid { grid-template-columns: 1fr; } }

    /* ── Log actions ── */
    .log-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
    .btn-sm {
      font-size: .75rem;
      padding: .45rem 1rem;
      border-radius: 999px;
      font-family: var(--font-label);
      font-weight: 700;
      letter-spacing: .07em;
      text-transform: uppercase;
      cursor: pointer;
      border: 1px solid transparent;
      display: inline-flex;
      align-items: center;
      gap: .35rem;
      transition: var(--spring);
    }
    .btn-complete {
      background: linear-gradient(135deg, color-mix(in srgb, var(--accent-primary) 90%, #fff), var(--accent-primary-strong));
      color: #01130b;
      border-color: color-mix(in srgb, var(--accent-primary) 48%, transparent);
      box-shadow: 0 4px 12px color-mix(in srgb, var(--accent-primary) 20%, transparent);
    }
    .btn-complete:hover { transform: translateY(-1px); box-shadow: 0 6px 16px color-mix(in srgb, var(--accent-primary) 28%, transparent); }
    .btn-cancel {
      background: color-mix(in srgb, var(--accent-danger) 10%, transparent);
      color: var(--accent-danger);
      border-color: color-mix(in srgb, var(--accent-danger) 30%, transparent);
    }
    .btn-cancel:hover { background: color-mix(in srgb, var(--accent-danger) 18%, transparent); transform: translateY(-1px); }
    .btn-secondary {
      background: var(--surface-3);
      color: var(--text-main);
      border-color: var(--border-subtle);
    }
    .btn-secondary:hover { border-color: var(--border-strong); transform: translateY(-1px); }

    /* ── History table ── */
    .hist-table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    th, td { padding: .6rem .85rem; text-align: left; border-bottom: 1px solid var(--border-subtle); }
    th { font-family: var(--font-label); font-size: .72rem; text-transform: uppercase; letter-spacing: .1em; color: var(--text-muted); font-weight: 600; }
    tr:hover td { background: color-mix(in srgb, var(--accent-primary) 4%, transparent); }
    td { color: var(--text-main); }

    /* ── Empty state ── */
    .empty-state {
      text-align: center;
      padding: 2rem 1rem;
      color: var(--text-muted);
      font-style: italic;
    }

    /* ── Stats mini row ── */
    .stats-row {
      display: flex;
      flex-wrap: wrap;
      gap: .75rem;
      margin-bottom: 1.5rem;
    }
    .stat-pill {
      display: flex;
      align-items: center;
      gap: .5rem;
      background: var(--surface-2);
      border: 1px solid var(--border-subtle);
      border-radius: var(--radius-md);
      padding: .6rem 1.1rem;
      font-size: .85rem;
    }
    .stat-pill .sp-val {
      font-size: 1.3rem;
      font-weight: 800;
      line-height: 1;
    }
    .stat-pill .sp-label { color: var(--text-muted); font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; }
    .sp-overdue  .sp-val { color: var(--accent-danger); }
    .sp-today    .sp-val { color: var(--accent-warning); }
    .sp-upcoming .sp-val { color: var(--accent-teal); }
    .sp-done     .sp-val { color: var(--accent-primary); }

    /* ── Page heading ── */
    .page-heading {
      margin-bottom: 1.5rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid var(--border-subtle);
    }
    .page-heading h1 {
      font-size: clamp(1.5rem, 3vw, 2rem);
      font-family: var(--font-display);
      background: linear-gradient(135deg, var(--text-heading), color-mix(in srgb, var(--text-heading) 68%, var(--accent-primary)));
      background-clip: text;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      margin-bottom: .25rem;
    }
    .page-heading p { margin: 0; font-size: .9rem; }

    /* ── History select row ── */
    .history-select-row {
      display: flex;
      gap: .75rem;
      align-items: flex-end;
      flex-wrap: wrap;
      margin-bottom: 1.25rem;
    }
    .history-select-row select { flex: 1; min-width: 200px; }
    .history-select-row button { flex-shrink: 0; }

    /* ── Overdue banner ── */
    .overdue-banner {
      display: flex;
      align-items: center;
      gap: .75rem;
      background: color-mix(in srgb, var(--accent-danger) 10%, var(--surface-2));
      border: 1px solid color-mix(in srgb, var(--accent-danger) 35%, transparent);
      border-radius: var(--radius-md);
      padding: .85rem 1.25rem;
      margin-bottom: 1.25rem;
      animation: slideIn .4s ease-out;
    }
    .overdue-banner .ob-icon { font-size: 1.4rem; }
    .overdue-banner .ob-text { font-size: .88rem; color: var(--accent-danger); font-weight: 600; }
  </style>
</head>
<body>

<!-- ── Topbar ─────────────────────────────────────────────────────────────── -->
<header class="dashboard-topbar">
  <div class="dashboard-topbar-left">
    <a href="/api/dashboard.php" class="dashboard-topbar-title" style="text-decoration:none;color:inherit;">
      🏠 Equipment Management System
    </a>
  </div>
  <div class="dashboard-topbar-right">
    <div class="dashboard-topbar-meta">
      <span><?= h($user['full_name']) ?> | <?= h($role) ?></span>
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
        <div style="margin-top: 1rem;">
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
                <div style="margin-top:.3rem; display:flex; gap:.4rem; flex-wrap:wrap;">
                  <span class="chip chip-type"><?= h((string) $log['maintenance_type']) ?></span>
                  <span class="chip chip-<?= $isOverdue ? 'overdue' : 'scheduled' ?>">
                    <?= $isOverdue ? 'Overdue ' . abs($diff) . 'd' : ($isDueToday ? 'Due Today' : 'Scheduled') ?>
                  </span>
                </div>
              </div>
              <div style="text-align:right; font-size:.8rem; color:var(--text-muted); flex-shrink:0;">
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
              <form action="/api/actions/maintenance_cancel.php?id=<?= (int) $log['id'] ?>" method="post" style="margin:0;"
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
                           type="number" min="0" step="0.01" placeholder="0.00">
                  </div>
                </div>
                <div class="log-actions" style="margin-top:.75rem;">
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
          <div class="form-group" style="flex:1;">
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
                  <td style="max-width:200px; word-break:break-word;"><?= h((string) ($h['notes'] ?? '—')) ?></td>
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
                <td style="max-width:200px; word-break:break-word;"><?= h((string) ($log['notes'] ?? '—')) ?></td>
                <td><?= $log['cost'] !== null ? '₱' . number_format((float) $log['cost'], 2) : '—' ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <p style="margin-top:1rem;"><a href="/api/dashboard.php">← Back to Dashboard</a></p>
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
document.getElementById('schedule-form')?.addEventListener('submit', function() {
  const btn = document.getElementById('schedule-submit-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Scheduling…'; }
});
</script>
<script src="/assets/app.js"></script>
</body>
</html>
