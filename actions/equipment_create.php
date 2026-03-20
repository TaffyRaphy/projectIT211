<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

require_role(['admin']);
$role = current_role('admin');

$name = post_string('name');
$category = post_string('category');
$location = post_string('location');
$quantityTotal = post_int('quantity_total');

if ($name === '' || $category === '' || $location === '' || $quantityTotal === null || $quantityTotal < 0) {
    redirect_to('equipment.php', ['as' => $role, 'error' => 'Invalid equipment input']);
}

function generate_equipment_code(PDO $pdo): string
{
    $datePart = date('Ymd');
    $check = $pdo->prepare('SELECT 1 FROM equipment WHERE code = :code LIMIT 1');

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $randomSuffix = strtoupper(bin2hex(random_bytes(2)));
        $code = sprintf('EQ-%s-%s', $datePart, $randomSuffix);
        $check->execute(['code' => $code]);
        if ($check->fetch() === false) {
            return $code;
        }
    }

    throw new RuntimeException('Unable to generate a unique equipment code.');
}

$code = generate_equipment_code(db());

$stmt = db()->prepare(
    "INSERT INTO equipment (code, name, category, status, quantity_total, quantity_available, location)
     VALUES (:code, :name, :category, 'available', :qty, :qty, :location)"
);
$stmt->execute([
    'code' => $code,
    'name' => $name,
    'category' => $category,
    'qty' => $quantityTotal,
    'location' => $location,
]);

redirect_to('equipment.php', ['as' => $role, 'ok' => 'Equipment added']);
