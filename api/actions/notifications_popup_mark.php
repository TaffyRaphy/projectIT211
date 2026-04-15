<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = require_login();
$userId = (int) $user['id'];

$markAll = post_string('mark_all') === '1';
$notificationId = post_int('notification_id');

try {
    if ($markAll) {
        NotificationService::getInstance()->markAsRead($userId);
    } elseif ($notificationId !== null && $notificationId > 0) {
        NotificationService::getInstance()->markAsRead($userId, $notificationId);
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Invalid payload'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

    echo json_encode([
        'ok' => true,
        'unread_count' => $unreadCount,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('notifications_popup_mark error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to update notification status'], JSON_UNESCAPED_UNICODE);
}
