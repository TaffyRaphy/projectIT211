<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$page_role = require_role(['admin']);

$ok = query_param('ok');
$error = query_param('error');

// Load current configuration
$config = SMTPConfig::load();
$currentHost = $config ? $config->getHost() : '';
$currentPort = $config ? $config->getPort() : 587;
$currentUsername = $config ? $config->getUsername() : '';
$currentFromEmail = $config ? $config->getFromEmail() : '';

$testResult = null;
if (post_string('action') === 'test') {
    $testResult = $config ? $config->testConnection() : ['success' => false, 'error' => 'No SMTP configuration found'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email Configuration - Equipment Management System</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="theme-toolbar">
        <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">??</button>
    </div>
    <div class="container">
        <header>
            <h1>📧 Email Configuration</h1>
            <p>Configure SMTP settings for sending notifications</p>
        </header>

        <?php if ($ok !== ''): ?>
            <p class="alert alert-success"><?= h($ok) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="alert alert-error">Error: <?= h($error) ?></p>
        <?php endif; ?>

        <?php if ($testResult): ?>
            <div class="alert alert-<?= $testResult['success'] ? 'success' : 'error' ?>">
                <strong><?= $testResult['success'] ? 'Success!' : 'Test Failed' ?></strong>
                <p><?= h($testResult['message'] ?? $testResult['error'] ?? 'Unknown result') ?></p>
            </div>
        <?php endif; ?>

        <section class="card">
            <h2>SMTP Configuration</h2>
            <p class="text-muted">Settings are loaded from environment variables. This schema does not include a persistent SMTP configuration table.</p>

            <form method="post" action="/api/actions/save_email_config.php" class="form">
                <div class="form-group">
                    <label for="host">SMTP Host *</label>
                    <input type="text" id="host" name="host" required value="<?= h($currentHost) ?>" placeholder="e.g., smtp.gmail.com">
                    <small>The SMTP server address</small>
                </div>

                <div class="form-group">
                    <label for="port">SMTP Port *</label>
                    <input type="number" id="port" name="port" required value="<?= h((string) $currentPort) ?>" placeholder="587" min="1" max="65535">
                    <small>Common ports: 587 (TLS), 465 (SSL), 25 (unencrypted)</small>
                </div>

                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= h($currentUsername) ?>" placeholder="e.g., your-email@gmail.com">
                    <small>Leave blank if no authentication required</small>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="••••••••">
                    <small>Will be stored securely in database. Leave blank to keep existing password.</small>
                </div>

                <div class="form-group">
                    <label for="from_email">From Email Address *</label>
                    <input type="email" id="from_email" name="from_email" required value="<?= h($currentFromEmail) ?>" placeholder="noreply@example.com">
                    <small>Default sender address for notifications</small>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Save Configuration</button>
                    <button type="submit" name="action" value="test" class="btn btn-secondary">Test Connection</button>
                </div>
            </form>
        </section>

        <section class="card">
            <h2>Environment Variables</h2>
            <p>The system will use environment variables if available:</p>
            <ul>
                <li><code>SMTP_HOST</code> - SMTP server address</li>
                <li><code>SMTP_PORT</code> - SMTP port (default: 587)</li>
                <li><code>SMTP_USERNAME</code> - SMTP authentication username</li>
                <li><code>SMTP_PASSWORD</code> - SMTP authentication password</li>
                <li><code>SMTP_FROM_EMAIL</code> - Default from email address</li>
            </ul>
            <p class="text-muted">On Vercel, set these in your project settings.</p>
        </section>

        <section class="card">
            <h2>Gmail Setup Guide</h2>
            <ol>
                <li>Enable 2-Factor Authentication on your Gmail account</li>
                <li>Generate an <strong>App Password</strong> at <a href="https://myaccount.google.com/apppasswords" target="_blank">myaccount.google.com/apppasswords</a></li>
                <li>Use your full email as <strong>Username</strong></li>
                <li>Use the 16-character app password as <strong>Password</strong></li>
                <li>Set <strong>Host</strong> to <code>smtp.gmail.com</code> and <strong>Port</strong> to <code>587</code></li>
            </ol>
        </section>

        <nav class="breadcrumb">
            <a href="/api/notification_logs.php">← Back to Notification Logs</a>
            <a href="/api/dashboard.php">← Back to Dashboard</a>
        </nav>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>

