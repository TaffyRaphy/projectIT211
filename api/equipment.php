<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$role = current_role('admin');
$ok = query_param('ok');
$error = query_param('error');
$rows = db()->query(
    'SELECT id, code, name, category, status, quantity_total, quantity_available, location FROM equipment ORDER BY id DESC'
)->fetchAll();
$locationRows = db()->query(
  "SELECT DISTINCT location FROM equipment WHERE location <> '' ORDER BY location ASC"
)->fetchAll();
$hasLocations = count($locationRows) > 0;
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Equipment Management</title></head>
<body>
<main>
  <h1>Equipment Management</h1>
  <p>Role in workflow mode: <?= h($role) ?></p>
  <?php if ($ok !== ''): ?><p>Success: <?= h($ok) ?></p><?php endif; ?>
  <?php if ($error !== ''): ?><p>Error: <?= h($error) ?></p><?php endif; ?>

  <h2>Add Equipment</h2>
  <form action="/api/actions/equipment_create.php?<?= http_build_query(['as' => $role]) ?>" method="post">
    <p>Code is auto-generated when you add equipment.</p>
    <p><label for="name">Name</label></p><p><input id="name" name="name" required></p>
    <p><label for="category">Category</label></p><p><input id="category" name="category" required></p>
    <p><label for="quantity_total">Total Quantity</label></p><p><input id="quantity_total" name="quantity_total" type="number" min="0" required></p>
    <p><label for="location">Location</label></p>
    <p>
      <select id="location" name="location" required<?= $hasLocations ? '' : ' disabled' ?>>
        <option value="" selected disabled><?= $hasLocations ? 'Select location' : 'No saved locations available' ?></option>
        <?php foreach ($locationRows as $locationItem): ?>
          <option value="<?= h((string) $locationItem['location']) ?>"><?= h((string) $locationItem['location']) ?></option>
        <?php endforeach; ?>
      </select>
    </p>
    <?php if (!$hasLocations): ?>
      <p>Add at least one equipment record with a location in the database first.</p>
    <?php endif; ?>
    <p><button type="submit"<?= $hasLocations ? '' : ' disabled' ?>>Add Equipment</button></p>
  </form>

  <hr>
  <h2>Current Inventory</h2>
  <?php foreach ($rows as $item): ?>
    <section>
      <p>#<?= (int) $item['id'] ?> | <?= h((string) $item['code']) ?> | <?= h((string) $item['name']) ?> | <?= h((string) $item['category']) ?> | status: <?= h((string) $item['status']) ?></p>
      <p>Total: <?= (int) $item['quantity_total'] ?> | Available: <?= (int) $item['quantity_available'] ?> | Location: <?= h((string) $item['location']) ?></p>
      <form action="/api/actions/equipment_update.php?<?= http_build_query(['as' => $role, 'id' => (int) $item['id']]) ?>" method="post">
        <input type="hidden" name="name" value="<?= h((string) $item['name']) ?>">
        <input type="hidden" name="category" value="<?= h((string) $item['category']) ?>">
        <input type="hidden" name="status" value="<?= h((string) $item['status']) ?>">
        <input type="hidden" name="quantity_total" value="<?= (int) $item['quantity_total'] ?>">
        <input type="hidden" name="quantity_available" value="<?= (int) $item['quantity_available'] ?>">
        <input type="hidden" name="location" value="<?= h((string) $item['location']) ?>">
        <button type="submit" name="action" value="retire" data-confirm="Retire this equipment?">Retire</button>
      </form>
    </section>
  <?php endforeach; ?>
  <p><a href="/api/dashboard.php?<?= http_build_query(['as' => $role]) ?>">Back to dashboard</a></p>
</main>
<script src="/assets/app.js"></script>
</body>
</html>
