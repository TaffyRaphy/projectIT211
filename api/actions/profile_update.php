<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$currentUser = require_login();
$currentId   = (int) $currentUser['id'];
$currentRole = (string) $currentUser['role'];

// Admins can edit any profile; others only their own
// Direct POST read — filter_input/post_int returns null on Vercel
$targetId = isset($_POST['target_id']) && $_POST['target_id'] !== '' ? (int) $_POST['target_id'] : $currentId;
if ($currentRole !== 'admin' && $targetId !== $currentId) {
    redirect_to('/api/profile.php', ['error' => 'Permission denied']);
}

$fullName   = post_string('full_name');
$email      = post_string('email');
$department = post_string('department');
$jobTitle   = post_string('job_title');
$redirectTo = $currentRole === 'admin' && $targetId !== $currentId
    ? '/api/users.php'
    : '/api/profile.php';

// Validate mandatory fields for non-admin users or when completing profile
$isMandatoryFill = (bool) ($_POST['mandatory_fill'] ?? false);
if ($fullName === '') {
    redirect_to('/api/profile.php', ['error' => 'Full name is required']);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirect_to('/api/profile.php', ['error' => 'Invalid email address']);
}
if ($email !== '' && user_exists_by_email($email)) {
    // Check if it's already their email
    $checkStmt = db()->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(:email)');
    $checkStmt->execute([':email' => $email]);
    $existing = $checkStmt->fetch();
    if ($existing && (int)$existing['id'] !== $targetId) {
        redirect_to('/api/profile.php', ['error' => 'Email is already in use by another account']);
    }
}
if ($isMandatoryFill && ($department === '' || $jobTitle === '')) {
    redirect_to('/api/profile.php', ['error' => 'Department and Job Title are required to complete your profile', 'setup' => '1']);
}

// Fetch old values for audit
$oldStmt = db()->prepare('SELECT full_name, department, job_title FROM users WHERE id = :id');
$oldStmt->execute([':id' => $targetId]);
$oldVals = $oldStmt->fetch() ?: [];

// Build update (only update department/job_title if they are provided)
$setClauses = ['full_name = :full_name'];
$params     = [':full_name' => $fullName, ':id' => $targetId];

if ($department !== '') {
    $setClauses[] = 'department = :department';
    $params[':department'] = $department;
}
if ($jobTitle !== '') {
    $setClauses[] = 'job_title = :job_title';
    $params[':job_title'] = $jobTitle;
}
if ($email !== '') {
    $setClauses[] = 'email = :email';
    $params[':email'] = $email;
}

try {
    db()->prepare(
        'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
    )->execute($params);

    log_audit('update', 'users', $targetId, $currentId, $oldVals ?: null, [
        'full_name'  => $fullName,
        'email'      => $email !== '' ? $email : null,
        'department' => $department,
        'job_title'  => $jobTitle,
    ]);

    // If own profile: refresh auth cookie so topbar name updates
    if ($targetId === $currentId) {
        $refreshStmt = db()->prepare('SELECT id, full_name, email, role FROM users WHERE id = :id');
        $refreshStmt->execute([':id' => $currentId]);
        $freshUser = $refreshStmt->fetch();
        if ($freshUser) {
            login_user($freshUser);
        }
    }

    redirect_to('/api/profile.php', ['ok' => 'Profile updated successfully']);
} catch (Throwable $e) {
    error_log('profile_update error: ' . $e->getMessage());
    redirect_to('/api/profile.php', ['error' => 'Failed to update profile']);
}
