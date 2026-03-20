<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

$role = current_role('admin');
$adminId = int_query_param('adminId', 1);
$ok = query_param('ok');
$error = query_param('error');

$rows = db()->query(
    "SELECT r.id, u.full_name AS staff_name, e.name AS equipment_name, r.qty_requested, r.purpose, r.status
     FROM equipment_requests r
     JOIN users u ON u.id = r.staff_id
     JOIN equipment e ON e.id = r.equipment_id
     ORDER BY CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END, r.id DESC"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Admin Requests</title></head>
<body>
<main>
  <h1>Admin Request Approval and Allocation</h1>
  <p>Role in workflow mode: <?= h($role) ?></p>
  <p>Admin ID used for test workflow: <?= $adminId ?></p>
  <?php if ($ok !== ''): ?><p>Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Requests Queue</h2>
  <?php foreach ($rows as $item): ?>
    <section>
      <p>Request #<?= (int) $item['id'] ?> | Staff: <?= h((string) $item['staff_name']) ?> | Equipment: <?= h((string) $item['equipment_name']) ?> | Qty: <?= (int) $item['qty_requested'] ?> | Status: <?= h((string) $item['status']) ?></p>
      <p>Purpose: <?= h((string) $item['purpose']) ?></p>
      <?php if ((string) $item['status'] === 'pending'): ?>
        <form action="actions/request_approve.php?<?= http_build_query(['as' => $role, 'id' => (int) $item['id']]) ?>" method="post">
          <input type="hidden" name="admin_id" value="<?= $adminId ?>">
          <p><label for="due_date_<?= (int) $item['id'] ?>">Due Date (optional)</label></p>
          <p><input id="due_date_<?= (int) $item['id'] ?>" name="due_date" type="date"></p>
          <button type="submit">Approve and Allocate</button>
        </form>
        <form action="actions/request_reject.php?<?= http_build_query(['as' => $role, 'id' => (int) $item['id']]) ?>" method="post">
          <input type="hidden" name="admin_id" value="<?= $adminId ?>">
          <button type="submit" data-confirm="Reject this request?">Reject</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  <p><a href="dashboard.php?<?= http_build_query(['as' => $role, 'userId' => $adminId]) ?>">Back to dashboard</a></p>
</main>
<script src="assets/app.js"></script>
</body>
</html>
