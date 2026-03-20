<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$role = current_role('maintenance');
$maintenanceUserId = int_query_param('maintenanceUserId', 3);
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
<head><meta charset="utf-8"><title>Maintenance Scheduling</title></head>
<body>
<main>
  <h1>Maintenance Scheduling</h1>
  <p>Role in workflow mode: <?= h($role) ?></p>
  <p>Maintenance User ID used for test workflow: <?= $maintenanceUserId ?></p>
  <?php if ($ok !== ''): ?><p>Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Schedule Maintenance</h2>
  <form action="/api/actions/maintenance_create.php?<?= http_build_query(['as' => $role]) ?>" method="post">
    <input type="hidden" name="maintenance_user_id" value="<?= $maintenanceUserId ?>">
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
  <?php foreach ($maintenanceRows as $log): ?>
    <section>
      <p>Log #<?= (int) $log['id'] ?> | Equipment: <?= h((string) $log['equipment_name']) ?> | Type: <?= h((string) $log['maintenance_type']) ?> | Schedule: <?= h((string) $log['schedule_date']) ?> | Status: <?= h((string) $log['status']) ?></p>
      <p>Completed Date: <?= h((string) ($log['completed_date'] ?? '-')) ?></p>
      <p>Notes: <?= h((string) ($log['notes'] ?? '-')) ?></p>
      <?php if ((string) $log['status'] === 'scheduled'): ?>
        <form action="/api/actions/maintenance_complete.php?<?= http_build_query(['as' => $role, 'id' => (int) $log['id']]) ?>" method="post">
          <button type="submit">Mark Completed</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <p><a href="/api/dashboard.php?<?= http_build_query(['as' => $role, 'userId' => $maintenanceUserId]) ?>">Back to dashboard</a></p>
</main>
</body>
</html>
