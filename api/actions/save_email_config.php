<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);

$host = post_string('host');
$port = post_int('port');
$username = post_string('username') ?: null;
$password = post_string('password');
$fromEmail = post_string('from_email');

// Validate input
if (!$host || !$fromEmail || !$port || $port < 1 || $port > 65535) {
    redirect_to('/api/email_config.php', ['error' => 'Please fill in all required fields with valid values']);
}

if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
    redirect_to('/api/email_config.php', ['error' => 'From email address must be valid']);
}

// If password is empty, use existing password (or null)
if ($password === '') {
    $config = SMTPConfig::load();
    $password = $config ? $config->getPassword() : null;
}

// Save configuration
try {
    $success = SMTPConfig::save($host, $port, $username, $password, $fromEmail);
    
    if ($success) {
        redirect_to('/api/email_config.php', ['ok' => 'Email configuration saved successfully']);
    } else {
        redirect_to('/api/email_config.php', ['error' => 'Failed to save configuration']);
    }
} catch (Throwable $e) {
    redirect_to('/api/email_config.php', ['error' => 'Error: ' . $e->getMessage()]);
}
