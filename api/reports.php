<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$inventoryRows = db()->query(
    'SELECT category, COUNT(*)::text AS items, COALESCE(SUM(quantity_total), 0)::text AS total_qty,
            COALESCE(SUM(quantity_available), 0)::text AS available_qty
     FROM equipment
     GROUP BY category
     ORDER BY category ASC'
)->fetchAll();
$usageRows = db()->query(
    'SELECT e.name AS equipment_name, COUNT(a.id)::text AS allocations, COALESCE(SUM(a.qty_allocated), 0)::text AS allocated_qty
     FROM equipment e
     LEFT JOIN allocations a ON a.equipment_id = e.id
     GROUP BY e.name
     ORDER BY e.name ASC'
)->fetchAll();
$maintenanceRows = db()->query(
    "SELECT e.name AS equipment_name, COUNT(m.id)::text AS total_logs,
            COALESCE(SUM(CASE WHEN m.status = 'completed' THEN 1 ELSE 0 END), 0)::text AS completed_logs
     FROM equipment e
     LEFT JOIN maintenance_logs m ON m.equipment_id = e.id
     GROUP BY e.name
     ORDER BY e.name ASC"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="/assets/style.css">
  <title>Reports</title>
</head>
<body>
<main class="page page-reports">
  <h1>Reports</h1>
  <h2>Inventory Status Summary</h2>
  <div class="stack-grid">
    <?php foreach ($inventoryRows as $row): ?>
      <section class="item-card">
        <p class="item-title">Category: <?= h((string) $row['category']) ?></p>
        <p>Items: <?= h((string) $row['items']) ?> | Total Qty: <?= h((string) $row['total_qty']) ?> | Available Qty: <?= h((string) $row['available_qty']) ?></p>
      </section>
    <?php endforeach; ?>
  </div>
  <hr>
  <h2>Equipment Usage Summary</h2>
  <div class="stack-grid">
    <?php foreach ($usageRows as $row): ?>
      <section class="item-card">
        <p class="item-title">Equipment: <?= h((string) $row['equipment_name']) ?></p>
        <p>Allocations: <?= h((string) $row['allocations']) ?> | Allocated Qty: <?= h((string) $row['allocated_qty']) ?></p>
      </section>
    <?php endforeach; ?>
  </div>
  <hr>
  <h2>Maintenance History Summary</h2>
  <div class="stack-grid">
    <?php foreach ($maintenanceRows as $row): ?>
      <section class="item-card">
        <p class="item-title">Equipment: <?= h((string) $row['equipment_name']) ?></p>
        <p>Total Logs: <?= h((string) $row['total_logs']) ?> | Completed: <?= h((string) $row['completed_logs']) ?></p>
      </section>
    <?php endforeach; ?>
  </div>
  <p class="back-link"><a href="/api/dashboard.php?as=admin&userId=1">Back to dashboard</a></p>
</main>
</body>
</html>
