<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user = require_login();
require_role(['admin']);

// Fetch admin email
$adminEmail = $user['email'];
$testRecipient = app_env('RESEND_TEST_EMAIL');
$recipient = $testRecipient !== '' ? $testRecipient : $adminEmail;

$resendApiKey = app_env('RESEND_API_KEY');
if ($resendApiKey === '') {
    redirect_to('/api/reports.php', ['error' => 'RESEND_API_KEY is not configured in the environment variables.']);
}

$fromEmail = app_env('RESEND_FROM_EMAIL');
if ($fromEmail === '') {
    $fromEmail = 'onboarding@resend.dev';
}

$fromName = app_env('RESEND_FROM_NAME');
if ($fromName === '') {
    $fromName = 'Equipment Management System';
}

try {
    $subject = "EMS Test Configuration";
    $htmlBody = "<h2>System Email Test Successful</h2><p>Your Equipment Management System is successfully connected to Resend!</p><p>Time of test: " . date('Y-m-d H:i:s') . " (UTC)</p>";

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $resendApiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);

    $payload = [
        'from'    => sprintf('%s <%s>', $fromName, $fromEmail),
        'to'      => [$recipient],
        'subject' => $subject,
        'html'    => $htmlBody
    ];

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 || $httpCode === 201) {
        log_audit('create', 'notifications', 0, (int)$user['id'], null, ['type' => 'test_email', 'status' => 'success', 'to' => $recipient]);
        redirect_to('/api/reports.php', ['ok' => "Test email successfully sent to {$recipient}! Check your inbox."]);
    } else {
        $responseBody = is_string($response) ? $response : '';
        $errorMsg = 'Failed to send test email. HTTP Code: ' . $httpCode . ' Response: ' . h($responseBody);

        if ($httpCode === 403 && stripos($responseBody, 'You can only send testing emails to your own email address') !== false) {
            $errorMsg = 'Resend sandbox restriction: with onboarding@resend.dev you can only send to your own Resend account email. Set RESEND_TEST_EMAIL to that address, or verify domain in Resend and set RESEND_FROM_EMAIL to that domain.';
        }

        log_audit('create', 'notifications', 0, (int)$user['id'], null, ['type' => 'test_email', 'status' => 'failed', 'details' => $errorMsg]);
        redirect_to('/api/reports.php', ['error' => $errorMsg]);
    }
} catch (Throwable $e) {
    redirect_to('/api/reports.php', ['error' => 'Exception during email test: ' . $e->getMessage()]);
}
