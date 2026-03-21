<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$equipmentId = int_query_param('id', 0);
if ($equipmentId <= 0) {
    redirect_to('api/equipment.php', ['error' => 'Invalid equipment id']);
}

$action = post_string('action');
if ($action === 'retire') {
    $stmt = db()->prepare(
        "UPDATE equipment
         SET status = 'retired', quantity_available = 0, updated_at = NOW()
         WHERE id = :id"
    );
    $stmt->execute(['id' => $equipmentId]);
    redirect_to('api/equipment.php', ['ok' => 'Equipment retired']);
}

$name = post_string('name');
$category = post_string('category');
$status = post_string('status');
$quantityTotal = post_int('quantity_total');
$quantityAvailable = post_int('quantity_available');
$location = post_string('location');

if (
    $name === '' || $category === '' || $location === '' ||
    !in_array($status, ['available', 'allocated', 'maintenance', 'retired'], true) ||
    $quantityTotal === null || $quantityAvailable === null ||
    $quantityTotal < 0 || $quantityAvailable < 0 || $quantityAvailable > $quantityTotal
) {
    redirect_to('api/equipment.php', ['error' => 'Invalid equipment update']);
}

$stmt = db()->prepare(
    'UPDATE equipment
     SET name = :name,
         category = :category,
         status = :status,
         quantity_total = :quantity_total,
         quantity_available = :quantity_available,
         location = :location,
         updated_at = NOW()
     WHERE id = :id'
);
$stmt->execute([
    'name' => $name,
    'category' => $category,
    'status' => $status,
    'quantity_total' => $quantityTotal,
    'quantity_available' => $quantityAvailable,
    'location' => $location,
    'id' => $equipmentId,
]);

redirect_to('api/equipment.php', ['ok' => 'Equipment updated']);
