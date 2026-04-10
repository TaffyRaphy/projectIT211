<?php
declare(strict_types=1);
require dirname(__DIR__) . '/includes/bootstrap.php';

$user = require_login();
require_role(['admin']);
$userId = (int) $user['id'];
$role = 'admin';
$dashboardTitle = 'Historical Reports';
$ok = query_param('ok');
$error = query_param('error');

// Date range filters
$startDate = post_string('start_date') ?: query_param('start_date') ?: date('Y-m-d', strtotime('-30 days'));
$endDate = post_string('end_date') ?: query_param('end_date') ?: date('Y-m-d');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
    $startDate = date('Y-m-d', strtotime('-30 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
    $endDate = date('Y-m-d');
}

// Ensure start is before end
if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

try {
    // Equipment trend by request/allocation activity date.
    $equipmentTrendStmt = db()->prepare(
        "WITH RECURSIVE days AS (
             SELECT :start::date AS day
             UNION ALL
             SELECT day + INTERVAL '1 day' FROM days WHERE day < :end::date
         )
         SELECT
            TO_CHAR(days.day::date, 'YYYY-MM-DD') AS snapshot_date,
            (SELECT COUNT(*) FROM equipment) AS total,
            (SELECT COUNT(*) FROM equipment WHERE status = 'available') AS available,
            (SELECT COUNT(DISTINCT a.equipment_id)
             FROM allocations a
             WHERE DATE(a.checkout_date) <= days.day::date
               AND (a.actual_return_date IS NULL OR DATE(a.actual_return_date) > days.day::date)
            ) AS allocated,
            (SELECT COUNT(DISTINCT m.equipment_id)
             FROM maintenance_logs m
             WHERE DATE(m.schedule_date) <= days.day::date
               AND (m.completed_date IS NULL OR m.completed_date > days.day::date)
            ) AS maintenance
         FROM days
         ORDER BY days.day::date ASC"
    );
    $equipmentTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $equipmentTrend = $equipmentTrendStmt->fetchAll();

    // Request trend derived from equipment_requests dates.
    $requestTrendStmt = db()->prepare(
        "WITH RECURSIVE days AS (
             SELECT :start::date AS day
             UNION ALL
             SELECT day + INTERVAL '1 day' FROM days WHERE day < :end::date
         )
         SELECT
            TO_CHAR(days.day::date, 'YYYY-MM-DD') AS snapshot_date,
            (SELECT COUNT(*)
             FROM equipment_requests r
             WHERE r.status = 'pending' AND DATE(r.requested_at) <= days.day::date
            ) AS pending,
            (SELECT COUNT(*)
             FROM equipment_requests r
             WHERE r.status = 'allocated' AND DATE(r.reviewed_at) = days.day::date
            ) AS approved_today
         FROM days
         ORDER BY days.day::date ASC"
    );
    $requestTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $requestTrend = $requestTrendStmt->fetchAll();

    // Maintenance trend from completed_date.
    $costTrendStmt = db()->prepare(
        "WITH RECURSIVE days AS (
             SELECT :start::date AS day
             UNION ALL
             SELECT day + INTERVAL '1 day' FROM days WHERE day < :end::date
         )
         SELECT
            TO_CHAR(days.day::date, 'YYYY-MM-DD') AS snapshot_date,
            COALESCE((
              SELECT SUM(m.cost)
              FROM maintenance_logs m
              WHERE m.status = 'completed' AND m.completed_date <= days.day::date
            ), 0) AS cost,
            (SELECT COUNT(*)
             FROM maintenance_logs m
             WHERE m.status = 'completed' AND m.completed_date <= days.day::date
            ) AS completed
         FROM days
         ORDER BY days.day::date ASC"
    );
    $costTrendStmt->execute([':start' => $startDate, ':end' => $endDate]);
    $costTrend = $costTrendStmt->fetchAll();

    // Current category breakdown from equipment table.
    $categoryStmt = db()->query(
        "SELECT category, COUNT(*) as total_count
         FROM equipment
         WHERE category IS NOT NULL
         GROUP BY category
         ORDER BY total_count DESC"
    );
    $categoryData = $categoryStmt->fetchAll();
} catch (Throwable $e) {
    error_log('Historical report error: ' . $e->getMessage());
    $equipmentTrend = [];
    $requestTrend = [];
    $costTrend = [];
    $categoryData = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historical Reports - Equipment Management System</title>
        <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
        <header class="dashboard-topbar">
            <div class="dashboard-topbar-left">
                <p class="dashboard-topbar-title"><?= h($dashboardTitle) ?></p>
            </div>
            <div class="dashboard-topbar-right">
                <div class="dashboard-topbar-meta">
                    <span>Role: <?= h($role) ?></span>
                    <span>User ID: <?= $userId ?></span>
                </div>
                <div class="dashboard-topbar-actions">
                    <button type="button" class="theme-toggle" data-theme-toggle aria-pressed="false" aria-label="Switch theme">🌙</button>
                    <a class="dashboard-logout" href="/api/actions/logout.php" aria-label="Logout">Logout</a>
                </div>
            </div>
        </header>
    <div class="container">

        <?php if ($ok !== ''): ?>
            <p class="alert alert-success"><?= h($ok) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="alert alert-error">Error: <?= h($error) ?></p>
        <?php endif; ?>

        <section class="card">
            <h2>Select Date Range</h2>
            <form method="post" class="filter-form">
                <div class="form-group">
                    <label for="start_date">Start Date:</label>
                    <input type="date" id="start_date" name="start_date" value="<?= h($startDate) ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_date">End Date:</label>
                    <input type="date" id="end_date" name="end_date" value="<?= h($endDate) ?>" required>
                </div>

                <button type="submit" class="btn btn-primary">Update Trends</button>
            </form>
        </section>

        <section class="card">
            <h2>Equipment Status Trend</h2>
            <p class="text-muted">Equipment count by status over time from <?= h($startDate) ?> to <?= h($endDate) ?></p>

            <?php if (count($equipmentTrend) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Equipment</th>
                                <th>Available</th>
                                <th>Allocated</th>
                                <th>Under Maintenance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipmentTrend as $row): ?>
                                <tr>
                                    <td><strong><?= h($row['snapshot_date']) ?></strong></td>
                                    <td><?= (int) $row['total'] ?></td>
                                    <td><?= (int) $row['available'] ?></td>
                                    <td><?= (int) $row['allocated'] ?></td>
                                    <td><?= (int) $row['maintenance'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No historical data available for this date range.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Request Processing Trend</h2>
            <p class="text-muted">Request metrics over time</p>

            <?php if (count($requestTrend) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Pending Requests</th>
                                <th>Approved Today</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requestTrend as $row): ?>
                                <tr>
                                    <td><strong><?= h($row['snapshot_date']) ?></strong></td>
                                    <td><?= (int) $row['pending'] ?></td>
                                    <td><?= (int) $row['approved_today'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No data available.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Maintenance Cost Trend</h2>
            <p class="text-muted">Cumulative maintenance costs and completed tasks over time</p>

            <?php if (count($costTrend) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Cost</th>
                                <th>Completed Tasks</th>
                                <th>Cost per Task</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($costTrend as $row): ?>
                                <tr>
                                    <td><strong><?= h($row['snapshot_date']) ?></strong></td>
                                    <td>$<?= number_format((float) $row['cost'], 2) ?></td>
                                    <td><?= (int) $row['completed'] ?></td>
                                    <td>
                                        <?php if ((int) $row['completed'] > 0): ?>
                                            $<?= number_format((float) $row['cost'] / (int) $row['completed'], 2) ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No data available.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Equipment by Category</h2>
            <p class="text-muted">Latest equipment distribution by category</p>

            <?php if (count($categoryData) > 0): ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($categoryData as $row): ?>
                                <tr>
                                    <td><strong><?= h((string) $row['category']) ?></strong></td>
                                    <td><?= (int) $row['total_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="empty-state">No category data available.</p>
            <?php endif; ?>
        </section>

        <section class="card">
            <h2>Data Management</h2>
            <p>
                <a href="/api/actions/snapshot_daily.php" class="btn btn-secondary">📸 Capture Metrics Now</a>
                <a href="/api/reports.php" class="btn btn-primary">← Back to Reports</a>
            </p>
        </section>

        <nav class="breadcrumb">
            <a href="/api/reports.php">← Back to Reports</a>
            <a href="/api/dashboard.php">← Back to Dashboard</a>
        </nav>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>

