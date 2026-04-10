<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$role = require_role(['maintenance']);
$maintenanceUserId = (int) require_login()['id'];
$ok = query_param('ok');
$error = query_param('error');

$equipmentRows = db()->query(
    "SELECT id, name, status FROM equipment WHERE status <> 'retired' ORDER BY name ASC"
)->fetchAll();
$maintenanceRows = db()->query(
    'SELECT m.id, e.name AS equipment_name, m.maintenance_type, m.schedule_date::text AS schedule_date,
            m.completed_date::text AS completed_date, m.status, m.notes
     FROM maintenance_logs m
     JOIN equipment e ON e.id = m.equipment_id
     ORDER BY m.id DESC'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Maintenance Scheduling</title>
</head>
<body>
<div class="theme-toolbar">
  <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">Light mode</button>
</div>
<main class="page page-maintenance">
  <div class="page-intro">
    <h1>Maintenance Management</h1>
    <p class="page-tagline">Track maintenance logs and schedule repairs for higher uptime.</p>
  </div>
  <p class="meta-note">Role: <?= h($role) ?></p>
  <p class="meta-note">Maintenance User ID: <?= $maintenanceUserId ?></p>
  <?php if ($ok !== ''): ?><p class="alert alert-success">Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Schedule Maintenance</h2>
  <form class="panel" action="/api/actions/maintenance_create.php" method="post">
    <p><label for="equipment_id">Equipment</label></p>
    <p><select id="equipment_id" name="equipment_id" required><?php foreach ($equipmentRows as $item): ?><option value="<?= (int) $item['id'] ?>"><?= h((string) $item['name']) ?> | status: <?= h((string) $item['status']) ?></option><?php endforeach; ?></select></p>
    <p><label for="maintenance_type">Maintenance Type</label></p>
    <p><select id="maintenance_type" name="maintenance_type" required><option value="scheduled">scheduled</option><option value="repair">repair</option></select></p>
    <p><label for="schedule_date">Schedule Date</label></p>
    <p><input id="schedule_date" name="schedule_date" type="date" required></p>
    <p><label for="cost">Cost</label></p>
    <p><input id="cost" name="cost" type="number" min="0" step="0.01" value="0"></p>
    <p><label for="notes">Notes</label></p>
    <p><textarea id="notes" name="notes"></textarea></p>
    <p><button type="submit">Schedule</button></p>
  </form>

  <hr>
  <h2>Maintenance Logs</h2>
  <div class="stack-grid">
  <?php foreach ($maintenanceRows as $log): ?>
    <section class="item-card">
      <p class="item-title">Log #<?= (int) $log['id'] ?> | Equipment: <?= h((string) $log['equipment_name']) ?></p>
      <p>Type: <?= h((string) $log['maintenance_type']) ?> | Schedule: <?= h((string) $log['schedule_date']) ?> | <span class="chip chip-status chip-<?= h((string) $log['status']) ?>"><?= h((string) $log['status']) ?></span></p>
      <p>Completed Date: <?= h((string) ($log['completed_date'] ?? '-')) ?></p>
      <p>Notes: <?= h((string) ($log['notes'] ?? '-')) ?></p>
      <?php if ((string) $log['status'] === 'scheduled'): ?>
        <form class="inline-form" action="/api/actions/maintenance_complete.php?<?= http_build_query(['id' => (int) $log['id']]) ?>" method="post">
          <button type="submit">Mark Completed</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <p class="back-link"><a href="/api/dashboard.php">Back to dashboard</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
