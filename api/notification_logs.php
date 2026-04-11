<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$page_role = require_role(['admin']);

$ok = query_param('ok');
$error = query_param('error');

// Pagination
$page = max(1, int_query_param('page', 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Filters
$eventFilter = post_string('event_filter') ?: query_param('event_filter');
$statusFilter = post_string('status_filter') ?: query_param('status_filter');
$searchEmail = post_string('search_email') ?: query_param('search_email');

// Build filter query
$where = [];
$params = [];

if ($eventFilter !== '') {
    $where[] = 'type = :type';
    $params[':type'] = $eventFilter;
}

if ($statusFilter !== '') {
    if ($statusFilter === 'read') {
        $where[] = 'is_read = true';
    } elseif ($statusFilter === 'unread') {
        $where[] = 'is_read = false';
    }
}

if ($searchEmail !== '') {
    $where[] = "u.email ILIKE :email";
    $params[':email'] = '%' . $searchEmail . '%';
}

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

// Get distinct event types for filter dropdown
$eventTypes = [
    'request_submitted',
    'request_approved',
    'request_rejected',
    'maintenance_scheduled',
    'maintenance_completed',
    'equipment_due_return',
    'equipment_overdue_return',
];

// Get total count
$countStmt = db()->prepare("SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id $whereClause");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = (int) ceil($totalCount / $perPage);

// Fetch notification logs
$stmt = db()->prepare(
    "SELECT n.id, u.email AS recipient_email, n.type AS event_type, n.message AS subject, CASE WHEN n.is_read THEN 'read' ELSE 'unread' END AS status, n.created_at AS sent_at, NULL AS error_message
     FROM notifications n
     JOIN users u ON u.id = n.user_id
     $whereClause
     ORDER BY n.created_at DESC
     LIMIT :limit OFFSET :offset"
);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$logs = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notification Logs - Equipment Management System</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
    <div class="theme-toolbar">
        <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">??</button>
    </div>
    <div class="container">
        <header>
            <h1>📧 Notification Logs</h1>
            <p>View history of all email notifications sent to users</p>
        </header>

        <?php if ($ok !== ''): ?>
            <p class="alert alert-success"><?= h($ok) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="alert alert-error">Error: <?= h($error) ?></p>
        <?php endif; ?>

        <section class="card">
            <h2>Filters</h2>
            <form method="post" class="filter-form">
                <div class="form-group">
                    <label for="event_filter">Event Type:</label>
                    <select id="event_filter" name="event_filter">
                        <option value="">All Events</option>
                        <?php foreach ($eventTypes as $type): ?>
                            <option value="<?= h($type) ?>" <?= $eventFilter === $type ? 'selected' : '' ?>>
                                <?= h(str_replace('_', ' ', ucwords($type))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status_filter">Status:</label>
                    <select id="status_filter" name="status_filter">
                        <option value="">All Statuses</option>
                        <option value="unread" <?= $statusFilter === 'unread' ? 'selected' : '' ?>>Unread</option>
                        <option value="read" <?= $statusFilter === 'read' ? 'selected' : '' ?>>Read</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="search_email">Recipient Email:</label>
                    <input type="text" id="search_email" name="search_email" placeholder="Search email..." value="<?= h($searchEmail) ?>">
                </div>

                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="notification_logs.php" class="btn btn-secondary">Clear</a>
            </form>
        </section>

        <section class="card">
            <h2>Notification History (<?= $totalCount ?> total)</h2>

            <?php if (count($logs) === 0): ?>
                <p class="empty-state">No notifications found.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Sent At</th>
                                <th>Event Type</th>
                                <th>Recipient Email</th>
                                <th>Status</th>
                                <th>Subject</th>
                                <th>Error</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?= h(utc_to_ph($log['sent_at'])) ?></td>
                                    <td>
                                        <span class="badge badge-info">
                                            <?= h(str_replace('_', ' ', ucwords($log['event_type']))) ?>
                                        </span>
                                    </td>
                                    <td><?= h($log['recipient_email']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= $log['status'] === 'sent' ? 'success' : ($log['status'] === 'failed' ? 'error' : 'warning') ?>">
                                            <?= h(ucfirst($log['status'])) ?>
                                        </span>
                                    </td>
                                    <td><?= h($log['subject'] ?: '-') ?></td>
                                    <td>
                                        <?php if ($log['error_message']): ?>
                                            <span title="<?= h($log['error_message']) ?>" class="error-text">⚠️ Error</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <nav class="pagination">
                        <ul>
                            <?php if ($page > 1): ?>
                                <li><a href="?page=1">« First</a></li>
                                <li><a href="?page=<?= $page - 1 ?>">‹ Previous</a></li>
                            <?php endif; ?>

                            <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                <li>
                                    <a href="?page=<?= $p ?>" <?= $p === $page ? 'class="active"' : '' ?>>
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $totalPages): ?>
                                <li><a href="?page=<?= $page + 1 ?>">Next ›</a></li>
                                <li><a href="?page=<?= $totalPages ?>">Last »</a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Notification Settings</h2>
            <p>
                <a href="/api/email_config.php" class="btn btn-primary">Configure SMTP</a>
                <a href="/api/actions/check_overdue_allocations.php" class="btn btn-secondary">Check Overdue Returns Now</a>
            </p>
        </section>

        <nav class="breadcrumb">
            <a href="/api/dashboard.php">← Back to Dashboard</a>
        </nav>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>

