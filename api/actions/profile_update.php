<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$currentUser = require_login();
$currentId   = (int) $currentUser['id'];
$currentRole = (string) $currentUser['role'];

// Admins can edit any profile; others only their own
$targetId = post_int('target_id') ?? $currentId;
if ($currentRole !== 'admin' && $targetId !== $currentId) {
    redirect_to('/api/profile.php', ['error' => 'Permission denied']);
}

$fullName   = post_string('full_name');
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

// Handle profile_photo (base64 data URL or URL string)
$profilePhoto = post_string('profile_photo');
if ($profilePhoto !== '') {
    $setClauses[] = 'profile_photo = :profile_photo';
    $params[':profile_photo'] = $profilePhoto;
}

try {
    db()->prepare(
        'UPDATE users SET ' . implode(', ', $setClauses) . ' WHERE id = :id'
    )->execute($params);

    log_audit('update', 'users', $targetId, $currentId, $oldVals ?: null, [
        'full_name'  => $fullName,
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
