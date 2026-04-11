<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

$user   = require_login();
$userId = (int) $user['id'];

$notifId  = post_int('notification_id');   // null = mark all
$markAll  = post_string('mark_all') === '1';

if ($markAll) {
    NotificationService::getInstance()->markAsRead($userId);
} elseif ($notifId !== null && $notifId > 0) {
    NotificationService::getInstance()->markAsRead($userId, $notifId);
}

redirect_to('/api/my_notifications.php', ['ok' => 'Marked as read']);
