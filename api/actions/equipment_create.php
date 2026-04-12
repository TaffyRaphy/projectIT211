<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$adminUser = require_login();
$adminId   = (int) $adminUser['id'];

$name = post_string('name');
$category = post_string('category');
$location = post_string('location');
$description = post_string('description');
$quantityTotal = post_int('quantity_total');

if ($name === '' || $category === '' || $location === '' || $quantityTotal === null || $quantityTotal < 0) {
    redirect_to('api/equipment.php', ['error' => 'Invalid equipment input']);
}

function generate_equipment_code(PDO $pdo, string $category): string
{
    // First 3 letters of category, uppercase, letters only
    $prefix = strtoupper(preg_replace('/[^A-Za-z]/', '', $category));
    $prefix = substr($prefix, 0, 3) ?: 'EQP';

    // Find highest existing sequence for this prefix
    $stmt = $pdo->prepare(
        "SELECT code FROM equipment WHERE code LIKE :prefix ORDER BY code DESC"
    );
    $stmt->execute([':prefix' => $prefix . '-%']);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $maxSeq = 0;
    foreach ($rows as $existing) {
        // Extract numeric part after prefix-
        $parts = explode('-', $existing, 2);
        if (isset($parts[1]) && ctype_digit($parts[1])) {
            $maxSeq = max($maxSeq, (int) $parts[1]);
        }
    }

    $newSeq = $maxSeq + 1;

    // Find a unique code (handles collision edge cases)
    $check = $pdo->prepare('SELECT 1 FROM equipment WHERE code = :code LIMIT 1');
    for ($i = 0; $i < 20; $i++) {
        $code = sprintf('%s-%03d', $prefix, $newSeq + $i);
        $check->execute([':code' => $code]);
        if ($check->fetch() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Unable to generate a unique equipment code.');
}

$code = generate_equipment_code(db(), $category);

$stmt = db()->prepare(
    "INSERT INTO equipment (code, name, category, status, quantity_total, quantity_available, location, description)
    VALUES (:code, :name, :category, 'available', :qty, :qty, :location, :description)
    RETURNING id"
);
$stmt->execute([
    'code'     => $code,
    'name'     => $name,
    'category' => $category,
    'qty'      => $quantityTotal,
    'location' => $location,
    'description' => $description !== '' ? $description : null,
]);
$newId = (int) $stmt->fetchColumn();

log_audit('create', 'equipment', $newId, $adminId, null, [
    'code'             => $code,
    'name'             => $name,
    'category'         => $category,
    'quantity_total'   => $quantityTotal,
    'location'         => $location,
    'description'      => $description,
    'status'           => 'available',
]);

redirect_to('/api/equipment.php', ['ok' => 'Equipment added']);
