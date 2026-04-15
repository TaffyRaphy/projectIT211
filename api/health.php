<?php
declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

header('Content-Type: application/json');

$dbStatus = 'ok';
$dbError = '';

try {
  db()->query('SELECT 1');
} catch (Throwable $e) {
  $dbStatus = 'error';
  $dbError = $e->getMessage();
}

echo json_encode([
  'status' => $dbStatus === 'ok' ? 'ok' : 'degraded',
  'stack' => 'php',
  'db' => [
    'status' => $dbStatus,
    'error' => $dbError,
  ],
  'timestamp' => gmdate('c'),
]);

