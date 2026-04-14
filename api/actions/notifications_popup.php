<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
$userId = (int) $user['id'];

$filter = query_param('filter', 'all');
if (!in_array($filter, ['all', 'unread'], true)) {
    $filter = 'all';
}

$perPage = int_query_param('limit', 12) ?? 12;
$perPage = max(1, min(50, $perPage));
$page = int_query_param('page', 1) ?? 1;
$page = max(1, $page);
$offset = ($page - 1) * $perPage;

$persistentTypes = ['equipment_overdue_return', 'maintenance_overdue', 'equipment_due_return'];
$placeholders = implode(',', array_fill(0, count($persistentTypes), '?'));

try {
    // Match existing notifications page behavior: auto-read non-persistent unread items on open.
    $markStmt = db()->prepare(
        "UPDATE notifications SET is_read = true
         WHERE user_id = ? AND is_read = false
         AND type NOT IN ({$placeholders})"
    );
    $markStmt->execute(array_merge([$userId], $persistentTypes));

    $whereRead = $filter === 'unread' ? 'AND n.is_read = false' : '';

    $countStmt = db()->prepare(
        "SELECT COUNT(*)
         FROM notifications n
         WHERE n.user_id = :uid {$whereRead}"
    );
    $countStmt->execute([':uid' => $userId]);
    $total = (int) $countStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT n.id, n.message, n.type, n.is_read, n.created_at
         FROM notifications n
         WHERE n.user_id = :uid {$whereRead}
         ORDER BY n.created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $notifications = [];
    foreach ($stmt->fetchAll() as $row) {
        $type = (string) ($row['type'] ?? '');
        $notifications[] = [
            'id' => (int) ($row['id'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'type' => $type,
            'is_read' => (bool) ($row['is_read'] ?? false),
            'is_persistent' => in_array($type, $persistentTypes, true),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    $unreadCount = NotificationService::getInstance()->getUnreadCount($userId);

    echo json_encode([
        'ok' => true,
        'filter' => $filter,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'unread_count' => $unreadCount,
        'notifications' => $notifications,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('notifications_popup error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Unable to load notifications.',
    ], JSON_UNESCAPED_UNICODE);
}
