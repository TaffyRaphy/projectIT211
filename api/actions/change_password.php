<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$currentUser = require_login();
$userId      = (int) $currentUser['id'];

$currentPassword = post_string('current_password');
$newPassword     = post_string('new_password');
$confirmPassword = post_string('confirm_password');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    redirect_to('/api/profile.php', ['error' => 'All fields are required']);
}
if (strlen($newPassword) < 8) {
    redirect_to('/api/profile.php', ['error' => 'New password must be at least 8 characters']);
}
if ($newPassword !== $confirmPassword) {
    redirect_to('/api/profile.php', ['error' => 'New passwords do not match']);
}

// Verify current password
$verified = validate_login($currentUser['email'], $currentPassword);
if ($verified === null) {
    redirect_to('/api/profile.php', ['error' => 'Current password is incorrect']);
}

// Update password
$newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
   ->execute([':hash' => $newHash, ':id' => $userId]);

// Audit log
log_audit('update', 'users', $userId, $userId,
    ['password_hash' => '[REDACTED]'],
    ['password_hash' => '[CHANGED]']
);

redirect_to('/api/profile.php', ['ok' => 'Password updated successfully']);
