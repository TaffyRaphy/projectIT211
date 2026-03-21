<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

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
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Equipment Request</title>
</head>
<body>
<main class="page page-requests">
  <h1>Equipment Request</h1>
  <p class="meta-note">Role in workflow mode: <?= h($role) ?></p>
  <p class="meta-note">Staff ID used for test workflow: <?= $staffId ?></p>
  <?php if ($ok !== ''): ?><p class="alert alert-success">Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p class="alert alert-error">Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Create Request</h2>
  <form class="panel" action="/api/actions/request_create.php?<?= http_build_query(['as' => $role]) ?>" method="post">
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
  <div class="stack-grid">
  <?php foreach ($requestRows as $item): ?>
    <section class="item-card">
      <p class="item-title">Request #<?= (int) $item['id'] ?> | <?= h((string) $item['equipment_name']) ?></p>
      <p>qty: <?= (int) $item['qty_requested'] ?> | <span class="chip chip-status chip-<?= h((string) $item['status']) ?>"><?= h((string) $item['status']) ?></span></p>
      <p>requested_at: <?= h(utc_to_ph((string) $item['requested_at'])) ?></p>
    </section>
  <?php endforeach; ?>
  </div>
  <p class="back-link"><a href="/api/dashboard.php?<?= http_build_query(['as' => $role, 'userId' => $staffId]) ?>">Back to dashboard</a></p>
</main>
</body>
</html>
