<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$email = post_string('email');
$password = post_string('password');

if ($email === '' || $password === '') {
    redirect_to('api/index.php', ['error' => 'Missing credentials']);
}

$user = validate_login($email, $password);
if ($user === null) {
    redirect_to('api/index.php', ['error' => 'Invalid email or password']);
}

redirect_to('api/dashboard.php', ['as' => $user['role'], 'userId' => (string) $user['id']]);
