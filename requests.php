<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$role = current_role('staff');
$staffId = int_query_param('staffId', 2);
$ok = query_param('ok');
$error = query_param('error');

$equipmentRows = db()->query(
    "SELECT id, name, quantity_available, status FROM equipment WHERE status <> 'retired' ORDER BY name ASC"
)->fetchAll();

$stmt = db()->prepare(
    'SELECT r.id, e.name AS equipment_name, r.qty_requested, r.status, r.requested_at::text AS requested_at
     FROM equipment_requests r
     JOIN equipment e ON e.id = r.equipment_id
     WHERE r.staff_id = :staff_id
     ORDER BY r.id DESC'
);
$stmt->execute(['staff_id' => $staffId]);
$requestRows = $stmt->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Equipment Request</title></head>
<body>
<main>
  <h1>Equipment Request</h1>
  <p>Role in workflow mode: <?= h($role) ?></p>
  <p>Staff ID used for test workflow: <?= $staffId ?></p>
  <?php if ($ok !== ''): ?><p>Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Create Request</h2>
  <form action="actions/request_create.php?<?= http_build_query(['as' => $role]) ?>" method="post">
    <input type="hidden" name="staff_id" value="<?= $staffId ?>">
    <p><label for="equipment_id">Equipment</label></p>
    <p>
      <select id="equipment_id" name="equipment_id" required>
        <?php foreach ($equipmentRows as $item): ?>
          <option value="<?= (int) $item['id'] ?>"><?= h((string) $item['name']) ?> | available: <?= (int) $item['quantity_available'] ?> | status: <?= h((string) $item['status']) ?></option>
        <?php endforeach; ?>
      </select>
    </p>
    <p><label for="qty_requested">Quantity</label></p>
    <p><input id="qty_requested" name="qty_requested" type="number" min="1" required></p>
    <p><label for="purpose">Purpose</label></p>
    <p><textarea id="purpose" name="purpose" required></textarea></p>
    <p><button type="submit">Submit Request</button></p>
  </form>

  <hr>
  <h2>Your Request History</h2>
  <?php foreach ($requestRows as $item): ?>
    <p>Request #<?= (int) $item['id'] ?> | <?= h((string) $item['equipment_name']) ?> | qty: <?= (int) $item['qty_requested'] ?> | status: <?= h((string) $item['status']) ?> | requested_at: <?= h((string) $item['requested_at']) ?></p>
  <?php endforeach; ?>
  <p><a href="dashboard.php?<?= http_build_query(['as' => $role, 'userId' => $staffId]) ?>">Back to dashboard</a></p>
</main>
</body>
</html>
