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
    $emailExists = user_exists_by_email($email);
    redirect_to('api/index.php', ['error' => $emailExists ? 'Incorrect password' : 'Account not found']);
}

login_user($user);
log_audit('login', 'users', (int) $user['id'], (int) $user['id'], null, [
    'email' => $user['email'],
    'role'  => $user['role'],
]);
redirect_to('api/dashboard.php');
