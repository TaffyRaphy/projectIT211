<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);

$reportType = post_string('report_type') ?: query_param('report_type', 'inventory');
$categoryFilter = post_string('category_filter') ?: query_param('category_filter', '');
$startDate = post_string('start_date') ?: query_param('start_date', '');

// Validate report type
$validTypes = ['inventory', 'usage', 'maintenance', 'sla', 'summary'];
if (!in_array($reportType, $validTypes)) {
    redirect_to('/api/reports.php', ['error' => 'Invalid report type']);
}

// Build HTML content based on report type
$htmlContent = '';

try {
    ob_start();

    // Common report header
    echo '<!DOCTYPE html>';
    echo '<html><head>';
    echo '<meta charset="UTF-8">';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; color: #333; margin: 20px; }';
    echo 'h1 { color: #333; border-bottom: 3px solid #cafd00; padding-bottom: 10px; }';
    echo 'h2 { color: #555; margin-top: 30px; }';
    echo 'table { width: 100%; border-collapse: collapse; margin: 15px 0; }';
    echo 'th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }';
    echo 'th { background-color: #f5f5f5; font-weight: bold; }';
    echo 'tr:nth-child(even) { background-color: #f9f9f9; }';
    echo '.metric { display: inline-block; margin: 10px 20px 10px 0; }';
    echo '.metric strong { font-size: 24px; color: #cafd00; }';
    echo '.footer { margin-top: 40px; font-size: 12px; color: #999; border-top: 1px solid #ddd; padding-top: 20px; }';
    echo '</style>';
    echo '</head><body>';

    echo '<h1>Equipment Management Report</h1>';
    echo '<p>Generated: ' . date('Y-m-d H:i:s') . '</p>';

    if ($reportType === 'inventory') {
        echo '<h2>Inventory Status Report</h2>';

        // Build filter condition
        $where = '';
        if ($categoryFilter !== '') {
            $where = 'WHERE category = ' . db()->quote($categoryFilter);
        }

        $stmt = db()->query(
            "SELECT category, COUNT(*) as items, 
                    COALESCE(SUM(quantity_total), 0) as total_qty,
                    COALESCE(SUM(quantity_available), 0) as available_qty,
                    COALESCE(SUM(quantity_total - quantity_available), 0) as allocated_qty
             FROM equipment
             $where
             GROUP BY category
             ORDER BY category"
        );
        $rows = $stmt->fetchAll();

        echo '<table>';
        echo '<thead><tr><th>Category</th><th>Items</th><th>Total Qty</th><th>Available</th><th>Allocated</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . h($row['category']) . '</td>';
            echo '<td>' . $row['items'] . '</td>';
            echo '<td>' . $row['total_qty'] . '</td>';
            echo '<td>' . $row['available_qty'] . '</td>';
            echo '<td>' . $row['allocated_qty'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } elseif ($reportType === 'usage') {
        echo '<h2>Equipment Usage Report</h2>';

        $stmt = db()->query(
            "SELECT e.name, e.quantity_available, COUNT(DISTINCT a.id) as allocations,
                    COALESCE(SUM(a.qty_allocated), 0) as allocated_qty
             FROM equipment e
             LEFT JOIN allocations a ON a.equipment_id = e.id
             GROUP BY e.name, e.quantity_available
             ORDER BY e.name"
        );
        $rows = $stmt->fetchAll();

        echo '<table>';
        echo '<thead><tr><th>Equipment</th><th>Available</th><th>Allocations</th><th>Allocated Qty</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . h($row['name']) . '</td>';
            echo '<td>' . $row['quantity_available'] . '</td>';
            echo '<td>' . $row['allocations'] . '</td>';
            echo '<td>' . $row['allocated_qty'] . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } elseif ($reportType === 'maintenance') {
        echo '<h2>Maintenance Report</h2>';

        $stmt = db()->query(
            "SELECT e.name, COUNT(m.id) as total_logs,
                    COUNT(CASE WHEN m.status = 'completed' THEN 1 END) as completed,
                    COUNT(CASE WHEN m.status = 'scheduled' THEN 1 END) as scheduled,
                    COALESCE(SUM(m.cost), 0) as total_cost
             FROM equipment e
             LEFT JOIN maintenance_logs m ON m.equipment_id = e.id
             GROUP BY e.name
             ORDER BY e.name"
        );
        $rows = $stmt->fetchAll();

        echo '<table>';
        echo '<thead><tr><th>Equipment</th><th>Total Tasks</th><th>Completed</th><th>Scheduled</th><th>Cost</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . h($row['name']) . '</td>';
            echo '<td>' . $row['total_logs'] . '</td>';
            echo '<td>' . $row['completed'] . '</td>';
            echo '<td>' . $row['scheduled'] . '</td>';
            echo '<td>$' . number_format((float) $row['total_cost'], 2) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } elseif ($reportType === 'sla') {
        echo '<h2>SLA & Performance Report</h2>';

        // Calculate metrics
        $metrics = [];

        $stmt = db()->query("SELECT COUNT(*) as overdue FROM allocations WHERE expected_return_date < CURRENT_DATE AND expected_return_date IS NOT NULL");
        $metrics['overdue'] = $stmt->fetch()['overdue'] ?? 0;

        $stmt = db()->query("SELECT EXTRACT(EPOCH FROM AVG(reviewed_at - requested_at))/3600 as hours FROM equipment_requests WHERE reviewed_at IS NOT NULL");
        $result = $stmt->fetch();
        $metrics['avg_approval_hours'] = (int) ($result['hours'] ?? 0);

        $stmt = db()->query("SELECT COUNT(DISTINCT equipment_id) as allocated FROM allocations WHERE checkout_date <= CURRENT_DATE AND (expected_return_date IS NULL OR expected_return_date >= CURRENT_DATE)");
        $allocated = $stmt->fetch()['allocated'] ?? 0;
        $stmt = db()->query("SELECT COUNT(*) as total FROM equipment");
        $total = $stmt->fetch()['total'] ?? 1;
        $metrics['utilization'] = $total > 0 ? round(($allocated / $total) * 100, 1) : 0;

        echo '<div class="metrics">';
        echo '<div class="metric"><strong>' . $metrics['overdue'] . '</strong> Overdue Returns</div>';
        echo '<div class="metric"><strong>' . $metrics['avg_approval_hours'] . ' hrs</strong> Avg Approval Time</div>';
        echo '<div class="metric"><strong>' . $metrics['utilization'] . '%</strong> Utilization Rate</div>';
        echo '</div>';

        // Request approval details
        echo '<h3>Request Status Breakdown</h3>';
        $stmt = db()->query(
            "SELECT status, COUNT(*) as count FROM equipment_requests GROUP BY status"
        );
        $rows = $stmt->fetchAll();

        echo '<table>';
        echo '<thead><tr><th>Status</th><th>Count</th></tr></thead>';
        echo '<tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . h($row['status']) . '</td><td>' . $row['count'] . '</td></tr>';
        }
        echo '</tbody></table>';
    } elseif ($reportType === 'summary') {
        echo '<h2>Executive Summary</h2>';

        $stmt = db()->query("SELECT COUNT(*) as total FROM equipment");
        $totalEquipment = $stmt->fetch()['total'] ?? 0;

        $stmt = db()->query("SELECT COUNT(*) as total FROM allocations");
        $totalAllocations = $stmt->fetch()['total'] ?? 0;

        $stmt = db()->query("SELECT COUNT(*) as total FROM maintenance_logs WHERE status = 'completed'");
        $totalMaintenance = $stmt->fetch()['total'] ?? 0;

        $stmt = db()->query("SELECT SUM(cost) as total FROM maintenance_logs WHERE status = 'completed'");
        $maintenanceCost = (float) ($stmt->fetch()['total'] ?? 0);

        echo '<p><strong>Total Equipment:</strong> ' . $totalEquipment . '</p>';
        echo '<p><strong>Total Allocations:</strong> ' . $totalAllocations . '</p>';
        echo '<p><strong>Completed Maintenance Tasks:</strong> ' . $totalMaintenance . '</p>';
        echo '<p><strong>Total Maintenance Cost:</strong> $' . number_format($maintenanceCost, 2) . '</p>';
    }

    echo '<div class="footer">';
    echo '<p>This report was generated by the Equipment Management System.</p>';
    echo '<p>For more detailed analytics, visit the Reports section.</p>';
    echo '</div>';

    echo '</body></html>';

    $htmlContent = ob_get_clean();

    // For MVP: Output HTML directly with download header
    // In production, integrate mPDF library for PDF generation
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="report_' . $reportType . '_' . date('Ymd_His') . '.html"');
    echo $htmlContent;
    exit;
} catch (Throwable $e) {
    error_log('PDF generation error: ' . $e->getMessage());
    redirect_to('/api/reports.php', ['error' => 'Failed to generate report: ' . $e->getMessage()]);
}
