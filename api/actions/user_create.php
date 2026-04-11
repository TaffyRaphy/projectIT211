<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);
$adminUser = require_login();
$adminId   = (int) $adminUser['id'];

$fullName   = post_string('full_name');
$email      = post_string('email');
$password   = post_string('password');
$role       = post_string('role');
$department = post_string('department');
$jobTitle   = post_string('job_title');

// Validate
if ($fullName === '' || $email === '' || $password === '' || !in_array($role, ['admin', 'staff', 'maintenance'], true)) {
    redirect_to('/api/users.php', ['error' => 'Full name, email, password, and role are required']);
}
if (strlen($password) < 8) {
    redirect_to('/api/users.php', ['error' => 'Password must be at least 8 characters']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_to('/api/users.php', ['error' => 'Invalid email address']);
}

if (user_exists_by_email($email)) {
    redirect_to('/api/users.php', ['error' => 'A user with that email already exists']);
}

try {
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Generate employee_id: 402000 + next user id (approximate via sequence)
    $maxIdRow = db()->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM users')->fetch();
    $nextId   = (int) ($maxIdRow['max_id'] ?? 0) + 1;
    $employeeId = (string) ($nextId + 402000);

    db()->prepare(
        "INSERT INTO users (full_name, email, password_hash, role, department, job_title, employee_id)
         VALUES (:full_name, :email, :password_hash, :role, :department, :job_title, :employee_id)"
    )->execute([
        ':full_name'     => $fullName,
        ':email'         => strtolower(trim($email)),
        ':password_hash' => $passwordHash,
        ':role'          => $role,
        ':department'    => $department !== '' ? $department : null,
        ':job_title'     => $jobTitle !== '' ? $jobTitle : null,
        ':employee_id'   => $employeeId,
    ]);

    $newUserId = (int) db()->lastInsertId();

    log_audit('create', 'users', $newUserId, $adminId, null, [
        'full_name' => $fullName,
        'email'     => $email,
        'role'      => $role,
    ]);

    redirect_to('/api/users.php', ['ok' => "User '{$fullName}' created successfully (Employee ID: {$employeeId})"]);
} catch (Throwable $e) {
    error_log('user_create error: ' . $e->getMessage());
    redirect_to('/api/users.php', ['error' => 'Failed to create user: ' . $e->getMessage()]);
}
