<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_role(['admin']);

$reportType     = post_string('report_type')     ?: query_param('report_type', 'inventory');
$categoryFilter = post_string('category_filter') ?: query_param('category_filter', '');
$startDate      = post_string('start_date')      ?: query_param('start_date', '');
$trendMetric    = post_string('trend_metric')    ?: query_param('trend_metric', 'cost');

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
            echo '<td>₱' . number_format((float) $row['total_cost'], 2) . '</td>';
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
        $reportingPeriod = date('F Y');
        $reportMonth     = (int) date('n');
        $reportYear      = (int) date('Y');
        $prevMonthLabel  = date('F Y', strtotime('first day of last month'));

        // --- Equipment KPIs ---
        $eqRow = db()->query(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'available')   AS available,
                    COUNT(*) FILTER (WHERE status = 'allocated')   AS allocated,
                    COUNT(*) FILTER (WHERE status = 'maintenance') AS under_repair,
                    COUNT(*) FILTER (WHERE status = 'retired')     AS retired
             FROM equipment"
        )->fetch();
        $eqTotal       = (int) ($eqRow['total'] ?? 0);
        $eqAvailable   = (int) ($eqRow['available'] ?? 0);
        $eqAllocated   = (int) ($eqRow['allocated'] ?? 0);
        $eqUnderRepair = (int) ($eqRow['under_repair'] ?? 0);
        $utilizationRate   = $eqTotal > 0 ? round(($eqAllocated / $eqTotal) * 100, 1) : 0;
        $availabilityRate  = $eqTotal > 0 ? round(($eqAvailable / $eqTotal) * 100, 1) : 0;

        // --- Maintenance KPIs ---
        $mRow = db()->query(
            "SELECT COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                    COUNT(*) FILTER (WHERE status = 'scheduled') AS scheduled,
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN cost ELSE 0 END), 0) AS actual_cost,
                    COALESCE(SUM(cost), 0) AS total_cost_all
             FROM maintenance_logs"
        )->fetch();
        $maintCompleted    = (int) ($mRow['completed'] ?? 0);
        $maintScheduled    = (int) ($mRow['scheduled'] ?? 0);
        $maintTotal        = $maintCompleted + $maintScheduled;
        $maintRate         = $maintTotal > 0 ? round(($maintCompleted / $maintTotal) * 100, 1) : 0;
        $actualCost        = (float) ($mRow['actual_cost'] ?? 0);

        // Previous month cost
        $prevMonth      = $reportMonth === 1 ? 12 : $reportMonth - 1;
        $prevYear       = $reportMonth === 1 ? $reportYear - 1 : $reportYear;
        $prevMonthCostRow = db()->query(
            "SELECT COALESCE(SUM(cost), 0) AS cost
             FROM maintenance_logs
             WHERE status = 'completed'
               AND EXTRACT(MONTH FROM completed_date) = {$prevMonth}
               AND EXTRACT(YEAR FROM completed_date)  = {$prevYear}"
        )->fetch();
        $prevMonthCost = (float) ($prevMonthCostRow['cost'] ?? 0);
        $costTrend      = $actualCost > $prevMonthCost ? '↑ Increasing' : ($actualCost < $prevMonthCost ? '↓ Decreasing' : '→ Stable');
        $costTrendColor = $actualCost > $prevMonthCost ? '#ef4444' : ($actualCost < $prevMonthCost ? '#22c55e' : '#888');

        // Previous month utilization (allocated equipment count vs total)
        $prevMonthAllocRow = db()->query(
            "SELECT COUNT(DISTINCT equipment_id) AS alloc
             FROM allocations
             WHERE EXTRACT(MONTH FROM checkout_date) = {$prevMonth}
               AND EXTRACT(YEAR FROM checkout_date)  = {$prevYear}"
        )->fetch();
        $prevMonthAlloc = (int) ($prevMonthAllocRow['alloc'] ?? 0);
        $prevMonthUtil  = $eqTotal > 0 ? round(($prevMonthAlloc / $eqTotal) * 100, 1) : 0.0;
        $utilTrend      = $utilizationRate > $prevMonthUtil ? '↑ Increasing' : ($utilizationRate < $prevMonthUtil ? '↓ Decreasing' : '→ Stable');
        $utilTrendColor = $utilizationRate > $prevMonthUtil ? '#22c55e' : ($utilizationRate < $prevMonthUtil ? '#ef4444' : '#888');

        // Previous month requests
        $prevMonthReqRow = db()->query(
            "SELECT COUNT(*) AS cnt
             FROM equipment_requests
             WHERE EXTRACT(MONTH FROM requested_at) = {$prevMonth}
               AND EXTRACT(YEAR FROM requested_at)  = {$prevYear}"
        )->fetch();
        $prevMonthReqs = (int) ($prevMonthReqRow['cnt'] ?? 0);
        $thisMonthReqs = (int) db()->query(
            "SELECT COUNT(*) FROM equipment_requests
             WHERE EXTRACT(MONTH FROM requested_at) = {$reportMonth}
               AND EXTRACT(YEAR FROM requested_at)  = {$reportYear}"
        )->fetchColumn();
        $reqTrend      = $thisMonthReqs > $prevMonthReqs ? '↑ Increasing' : ($thisMonthReqs < $prevMonthReqs ? '↓ Decreasing' : '→ Stable');
        $reqTrendColor = '#555';

        // Validate trendMetric
        $validTrendMetrics = ['cost', 'utilization', 'requests'];
        if (!in_array($trendMetric, $validTrendMetrics)) {
            $trendMetric = 'cost';
        }

        // --- Total Allocations ---
        $totalAllocations = (int) db()->query("SELECT COUNT(*) FROM allocations")->fetchColumn();

        // --- Overdue allocations ---
        $overdueRows = db()->query(
            "SELECT a.id, u.full_name AS staff_name, e.name AS equipment_name, a.expected_return_date
             FROM allocations a
             JOIN users u ON u.id = a.staff_id
             JOIN equipment e ON e.id = a.equipment_id
             WHERE a.expected_return_date < CURRENT_DATE
               AND a.expected_return_date IS NOT NULL
               AND a.status = 'active'
             ORDER BY a.expected_return_date ASC"
        )->fetchAll();

        // --- Idle / underutilized ---
        $idleRows = db()->query(
            "SELECT e.name, e.quantity_available, e.quantity_total
             FROM equipment e
             WHERE e.status = 'available'
               AND e.quantity_available = e.quantity_total
               AND e.quantity_total > 0
             ORDER BY e.quantity_total DESC
             LIMIT 10"
        )->fetchAll();

        // --- Who is generating ---
        $generatorRow = db()->query("SELECT full_name, role, department, job_title FROM users WHERE id = " . require_login()['id'])->fetch();

        // ── HTML Output ──
        echo '<style>
            .kpi-grid { display: flex; gap: 20px; flex-wrap: wrap; margin: 20px 0; }
            .kpi-box  { border: 1px solid #ddd; border-radius: 8px; padding: 16px 22px; min-width: 140px; text-align: center; }
            .kpi-val  { font-size: 28px; font-weight: 800; color: #111; display: block; }
            .kpi-lbl  { font-size: 12px; color: #777; margin-top: 4px; }
            .kpi-sub  { font-size: 13px; color: #555; }
            .alert-box { border-left: 4px solid #ef4444; background: #fff5f5; padding: 10px 16px; margin: 8px 0; border-radius: 0 6px 6px 0; }
            .alert-box.warn { border-color: #f59e0b; background: #fffbeb; }
            .section-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; margin: 16px 0; }
            .period-badge { display: inline-block; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 12px; font-size: 13px; color: #374151; margin-bottom: 12px; }
            .prepared-by { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 20px; margin-top: 20px; font-size: 13px; }
        </style>';

        echo '<div class="period-badge">📅 Reporting Period: <strong>' . $reportingPeriod . '</strong></div>';
        echo '<p style="font-size:13px; color:#555;">Compared to previous period: <strong>' . $prevMonthLabel . '</strong></p>';

        // Trend comparison selector note (embedded in exported doc)
        $trendLabels = ['cost' => 'Maintenance Cost', 'utilization' => 'Equipment Utilization Rate', 'requests' => 'Total Requests'];
        echo '<p style="font-size:12px; background:#f3f4f6; border:1px solid #d1d5db; border-radius:6px; padding:6px 12px; display:inline-block; color:#374151;">
              📈 Trend metric: <strong>' . h($trendLabels[$trendMetric] ?? $trendMetric) . '</strong></p>';


        echo '<h2>Enhanced KPIs</h2>';
        echo '<div class="kpi-grid">';
        echo '<div class="kpi-box"><span class="kpi-val">' . $utilizationRate . '%</span><span class="kpi-lbl">Equipment Utilization Rate</span><br><span class="kpi-sub">' . $eqAllocated . ' in use, ' . $eqAvailable . ' idle</span></div>';
        echo '<div class="kpi-box"><span class="kpi-val">' . $availabilityRate . '%</span><span class="kpi-lbl">Equipment Availability</span><br><span class="kpi-sub">Available: ' . $eqAvailable . ' | Deployed: ' . $eqAllocated . ' | Repair: ' . $eqUnderRepair . '</span></div>';
        echo '<div class="kpi-box"><span class="kpi-val">' . $maintRate . '%</span><span class="kpi-lbl">Maintenance Completion Rate</span><br><span class="kpi-sub">' . $maintCompleted . ' of ' . $maintTotal . ' tasks completed</span></div>';
        echo '<div class="kpi-box"><span class="kpi-val">₱' . number_format($actualCost, 2) . '</span><span class="kpi-lbl">Actual Maintenance Cost</span><br><span class="kpi-sub" style="color:' . $costTrendColor . ';">' . $costTrend . ' vs prev month</span></div>';
        echo '<div class="kpi-box"><span class="kpi-val">' . $eqTotal . '</span><span class="kpi-lbl">Total Equipment</span><br><span class="kpi-sub">' . $totalAllocations . ' total allocations</span></div>';
        echo '<div class="kpi-box"><span class="kpi-val">' . $thisMonthReqs . '</span><span class="kpi-lbl">Requests This Month</span><br><span class="kpi-sub">' . $prevMonthReqs . ' prev month</span></div>';
        echo '</div>';

        // Trend indicator — show selected metric comparison
        echo '<div class="section-card">';
        echo '<h3>Trend Comparison: ' . h($trendLabels[$trendMetric] ?? $trendMetric) . '</h3>';
        if ($trendMetric === 'cost') {
            echo '<p>📈 <strong>Maintenance Cost:</strong> <span style="color:' . $costTrendColor . ';">' . $costTrend . '</span><br>';
            echo 'This month: <strong>₱' . number_format($actualCost, 2) . '</strong> &nbsp;•&nbsp; Prev month (' . $prevMonthLabel . '): ₱' . number_format($prevMonthCost, 2) . '</p>';
        } elseif ($trendMetric === 'utilization') {
            echo '<p>📈 <strong>Equipment Utilization Rate:</strong> <span style="color:' . $utilTrendColor . ';">' . $utilTrend . '</span><br>';
            echo 'This month: <strong>' . $utilizationRate . '%</strong> &nbsp;•&nbsp; Prev month (' . $prevMonthLabel . '): ' . $prevMonthUtil . '% (based on new allocations)</p>';
        } elseif ($trendMetric === 'requests') {
            echo '<p>📈 <strong>Equipment Requests:</strong> <span style="color:' . $reqTrendColor . ';">' . $reqTrend . '</span><br>';
            echo 'This month: <strong>' . $thisMonthReqs . ' requests</strong> &nbsp;•&nbsp; Prev month (' . $prevMonthLabel . '): ' . $prevMonthReqs . ' requests</p>';
        }
        echo '<p>📦 <strong>Equipment Availability:</strong> ' . $availabilityRate . '% available — ' . ($availabilityRate >= 50 ? '✅ Healthy' : '⚠️ Low availability') . '</p>';
        echo '<p>🔧 <strong>Pending Maintenance:</strong> ' . $maintScheduled . ' task(s) pending completion.</p>';
        echo '</div>';

        // Alerts & Action Items
        echo '<h2>⚠️ Alerts & Action Items</h2>';
        if (count($overdueRows) > 0) {
            echo '<h3 style="color:#ef4444;">Equipment Overdue for Return (' . count($overdueRows) . ')</h3>';
            foreach ($overdueRows as $r) {
                $daysOver = max(0, (int) ((time() - strtotime($r['expected_return_date'])) / 86400));
                echo '<div class="alert-box">' . h($r['equipment_name']) . ' — allocated to <strong>' . h($r['staff_name']) . '</strong> — overdue by <strong>' . $daysOver . ' day(s)</strong> (due: ' . h($r['expected_return_date']) . ')</div>';
            }
        } else {
            echo '<p style="color:#22c55e;">✅ No overdue equipment returns.</p>';
        }

        if (count($idleRows) > 0) {
            echo '<h3 style="color:#f59e0b;">Underutilized / Idle Equipment (' . count($idleRows) . ')</h3>';
            foreach ($idleRows as $r) {
                echo '<div class="alert-box warn"><strong>' . h($r['name']) . '</strong> — ' . (int) $r['quantity_available'] . ' of ' . (int) $r['quantity_total'] . ' units sitting idle</div>';
            }
        } else {
            echo '<p style="color:#22c55e;">✅ All equipment is actively utilized.</p>';
        }

        // Report Prepared By
        echo '<div class="prepared-by">';
        echo '<strong>Report Prepared By:</strong><br>';
        echo h($generatorRow['full_name'] ?? 'System') . ' &nbsp;·&nbsp; ';
        echo h(ucfirst($generatorRow['role'] ?? 'Admin')) . ' &nbsp;·&nbsp; ';
        echo h($generatorRow['department'] ?? '') . (($generatorRow['job_title'] ?? '') ? ' — ' . h($generatorRow['job_title']) : '');
        echo '<br><small style="color:#888;">Generated: ' . date('F j, Y g:i A') . ' (Asia/Manila)</small>';
        echo '</div>';
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
