<?php
declare(strict_types=1);

header('Content-Type: application/json');
echo json_encode([
  'status' => 'ok',
  'stack' => 'php',
  'timestamp' => gmdate('c'),
]);
