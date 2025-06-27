<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header

$user_id = $_SESSION['user_id'];
$automation_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($automation_id === 0) {
    $_SESSION['error'] = "Automation ID is missing.";
    header('Location: view_automations.php'); // Redirect back to automation list
    exit;
}

// Fetch automation details
$stmt = $pdo->prepare("SELECT * FROM automation_workflows WHERE id = ? AND user_id = ?");
$stmt->execute([$automation_id, $user_id]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$automation) {
    $_SESSION['error'] = "Automation not found or you don't have permission to view it.";
    header('Location: view_automations.php'); // Redirect back to automation list
    exit;
}

// --- Fetch Execution Data for the Graph (Last 30 Days) ---
// This uses your confirmed 'automation_logs' table with 'executed_at' and 'workflow_id'
$stmt = $pdo->prepare("
    SELECT
        DATE(executed_at) as log_date,
        COUNT(id) as total_executions
    FROM automation_logs
    WHERE workflow_id = ? AND executed_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY log_date
    ORDER BY log_date ASC
");
$stmt->execute([$automation_id]);
$execution_data_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for JavaScript chart: fill in missing days with 0 executions
$chart_data = [];
$today = new DateTime(date('Y-m-d')); // Ensure today is just the date
$interval = new DateInterval('P1D'); // 1 day interval
$period = new DatePeriod((new DateTime())->modify('-29 days'), $interval, $today); // Last 30 days (inclusive of today)

$executions_map = [];
foreach ($execution_data_raw as $row) {
    $executions_map[$row['log_date']] = $row['total_executions'];
}

foreach ($period as $date) {
    $formatted_date = $date->format('Y-m-d');
    $chart_data[] = [
        'label' => $date->format('M j'), // e.g., Jan 01
        'value' => $executions_map[$formatted_date] ?? 0
    ];
}

// Fetch summary statistics for the cards
$summary_stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM automation_logs WHERE workflow_id = ? GROUP BY status");
$summary_stmt->execute([$automation_id]);
$summary_stats_raw = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

$summary_stats = [];
foreach ($summary_stats_raw as $row) {
    $summary_stats[$row['status']] = $row['count'];
}

$total_executions_summary = array_sum($summary_stats);
$success_count = $summary_stats['success'] ?? 0;
$failed_count = $summary_stats['failed'] ?? 0;
$pending_count = $summary_stats['pending'] ?? 0; // If you want to show pending separately

// Note: If 'failed_count' should include 'pending', adjust calculation here:
// $failed_count_display = $failed_count + $pending_count;

$success_rate = $total_executions_summary > 0 ? ($success_count / $total_executions_summary) * 100 : 0;


// Decode JSON configurations for display
$source_config = json_decode($automation['source_config_json'], true);
$condition_config = json_decode($automation['condition_config_json'], true);
$action_config = json_decode($automation['action_config_json'], true);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation Details: <?= htmlspecialchars($automation['name']) ?></title>
    <link rel="stylesheet" href="assets/css/automations_builder.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* General Layout & Typography */
        body {
            font-family: 'Inter', sans-serif; /* Using Inter font */
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: flex-start; /* Align to top */
            min-height: 100vh;
        }
        .automation-details-container {
            background-color: #ffffff;
            border: 1px solid #e0e7ff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            max-width: 900px;
            width: 100%;
            margin: 40px auto; /* Adjust margin for spacing */
            padding: 30px;
            border-radius: 1rem;
            box-sizing: border-box;
        }
        .automation-details-container h1 {
            font-size: 2rem;
            color: #333;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .details-section, .config-section, .chart-section, .logs-section {
            margin-bottom: 30px;
        }
        .detail-item, .config-item {
            margin-bottom: 10px;
            color: #555;
        }
        .config-section h2, .chart-section h2, .logs-section h2 {
            font-size: 1.5rem;
            color: #333;
            margin-bottom: 15px;
            border-bottom: 1px dashed #eee;
            padding-bottom: 5px;
        }
        .config-section h3 {
            font-size: 1.2rem;
            color: #444;
            margin-top: 20px;
            margin-bottom: 10px;
        }
        pre {
            background-color: #f4f4f4;
            padding: 10px;
            border-radius: 5px;
            white-space: pre-wrap; /* Ensures long lines wrap */
            word-wrap: break-word; /* Breaks long words */
            font-size: 0.9em;
            color: #333;
            max-height: 200px; /* Limit height for pre-formatted JSON */
            overflow-y: auto; /* Add scroll if content overflows */
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
        }
        .status-badge.active {
            background: #e6f7ee;
            color: #28a745;
        }
        .status-badge.inactive {
            background: #be1313;
            color: #dcdcdc;
        }

        /* Summary Cards */
        .automation-summary {
            display: flex;
            justify-content: space-around;
            gap: 20px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .summary-card {
            background-color: #fff; /* White background */
            padding: 20px;
            border-radius: 10px;
            flex: 1;
            min-width: 200px; /* Adjusted min-width for better fit */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
            border: 1px solid #e0e7ff; /* Consistent border */
        }
        .summary-card h3 {
            color: #6b7280; /* Gray-500 */
            font-size: 1.1em;
            margin-top: 0;
            margin-bottom: 10px;
        }
        .summary-card .value {
            font-size: 2.2em;
            font-weight: 700;
            color: #4f46e5; /* Indigo-600 */
        }
        .summary-card.success .value {
            color: #28a745; /* Green */
        }
        .summary-card.failed .value {
            color: #dc3545; /* Red */
        }
        /* Chart Specific Styles */
        .chart-container {
            position: relative;
            background-color: #ffffff;
            border: 1px solid #e0e7ff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            max-width: 900px;
            width: 100%;
            height: 400px; /* Fixed height */
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 1rem;
            margin: 0 auto; /* Center the chart within its section */
            overflow: hidden; /* Ensure nothing draws outside the rounded corners */
        }
        canvas {
            display: block;
            width: 100%;
            height: 100%;
        }
        /* Tooltip styling */
        .tooltip {
            position: absolute;
            background-color: rgba(30, 41, 59, 0.9);
            color: #ffffff;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            z-index: 100;
            white-space: nowrap;
            font-size: 0.875rem;
            line-height: 1.25rem;
            transform: translate(-50%, -110%);
        }
        .tooltip.visible {
            opacity: 1;
        }

        /* Logs Table Styles */
        .logs-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden; /* For rounded corners */
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-top: 20px;
        }
        .logs-table thead tr {
            background-color: #4f46e5; /* Indigo-600 */
            color: #fff;
            text-align: left;
        }
        .logs-table th, .logs-table td {
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }
        .logs-table tbody tr:nth-child(even) {
            background-color: #f8faff; /* Light background */
        }
        .logs-table tbody tr:hover {
            background-color: #f0f0f0;
        }
        .log-status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85em;
            color: #fff;
            text-transform: capitalize;
        }
        .log-status-badge.success { background-color: #28a745; }
        .log-status-badge.failed { background-color: #dc3545; }
        .log-status-badge.pending { background-color: #ffc107; color: #333;} /* Yellow for pending */

        .logs-table .details-toggle {
            cursor: pointer;
            color: #4f46e5; /* Indigo-600 */
            font-weight: 600;
        }
        .logs-table .details-content {
            display: none;
            margin-top: 5px;
            font-size: 0.85em;
            background-color: #fcfcfc;
            border-left: 3px solid #4f46e5;
            padding: 8px;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 150px;
            overflow-y: auto;
        }
        .logs-table .details-content.active {
            display: block;
        }
        .empty-state {
            padding: 30px;
            text-align: center;
            color: #666;
            border: 1px dashed #ddd;
            border-radius: 8px;
            margin-top: 20px;
        }
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            gap: 10px;
        }
        .pagination a, .pagination .current-page {
            padding: 8px 16px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #4f46e5;
            transition: background-color 0.2s;
        }
        .pagination a:hover {
            background-color: #f0f0f0;
        }
        .pagination .current-page {
            background-color: #4f46e5;
            color: white;
            font-weight: bold;
            cursor: default;
        }

        /* Buttons */
        .back-link {
            margin-top: 30px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            transition: background-color 0.2s ease;
        }
        .btn-primary {
            background-color: #3ac3b8;
            color: white;
        }
        .btn-primary:hover {
            background-color: #2e9f96;
        }
        .btn-secondary {
            background-color: #f0f0f0;
            color: #333;
            border: 1px solid #ddd;
        }
        .btn-secondary:hover {
            background-color: #e0e0e0;
        }

        /* Responsive table for logs */
        @media (max-width: 768px) {
            .automation-details-container {
                padding: 15px;
                margin: 10px auto;
            }
            .automation-summary {
                flex-direction: column;
                align-items: stretch;
            }
            .summary-card {
                min-width: unset;
                flex: none;
                width: 100%;
            }
            .logs-table, .logs-table thead, .logs-table tbody, .logs-table th, .logs-table td, .logs-table tr {
                display: block;
            }
            .logs-table thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            .logs-table tr {
                margin-bottom: 15px;
                border: 1px solid #e0e7ff; /* Consistent border color */
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .logs-table td {
                border: none;
                position: relative;
                padding-left: 50%;
                text-align: right;
                border-bottom: 1px dashed #e0e0e0; /* Consistent dashed border */
            }
            .logs-table td:last-child {
                border-bottom: 0;
            }
            .logs-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: 600;
                color: #6b7280; /* Gray-500 */
            }
            .logs-table .details-toggle {
                text-align: right;
            }
            .logs-table .details-content {
                text-align: left;
            }
        }
    </style>
</head>
<body>
<div class="automation-details-container">
    <h1>Automation: <?= htmlspecialchars($automation['name']) ?></h1>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="details-section">
        <div class="detail-item">
            <strong>Description:</strong> <?= htmlspecialchars($automation['description'] ?? 'N/A') ?>
        </div>
        <div class="detail-item">
            <strong>Status:</strong> <span class="status-badge <?= $automation['is_active'] ? 'active' : 'inactive' ?>"><?= $automation['is_active'] ? 'Active' : 'Inactive' ?></span>
        </div>
        <div class="detail-item">
            <strong>Created At:</strong> <?= (new DateTime($automation['created_at']))->format('Y-m-d H:i:s') ?>
        </div>
        <?php if ($automation['updated_at']): ?>
        <div class="detail-item">
            <strong>Last Updated:</strong> <?= (new DateTime($automation['updated_at']))->format('Y-m-d H:i:s') ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="config-section">
        <h2>Configuration Details</h2>
        
        <h3>Source</h3>
        <div class="config-item">
            <strong>Type:</strong> <?= htmlspecialchars($automation['source_type']) ?><br>
            <strong>Config:</strong> <pre><?= htmlspecialchars(json_encode($source_config, JSON_PRETTY_PRINT)) ?></pre>
        </div>

        <h3>Condition</h3>
        <div class="config-item">
            <strong>Type:</strong> <?= htmlspecialchars($automation['condition_type']) ?><br>
            <strong>Config:</strong> <pre><?= htmlspecialchars(json_encode($condition_config, JSON_PRETTY_PRINT)) ?></pre>
        </div>

        <h3>Action</h3>
        <div class="config-item">
            <strong>Type:</strong> <?= htmlspecialchars($automation['action_type']) ?><br>
            <strong>Config:</strong> <pre><?= htmlspecialchars(json_encode($action_config, JSON_PRETTY_PRINT)) ?></pre>
        </div>
    </div>

    <div class="automation-summary">
        <div class="summary-card">
            <h3>Total Executions</h3>
            <div class="value"><?= $total_executions_summary ?></div>
        </div>
        <div class="summary-card success">
            <h3>Successful</h3>
            <div class="value"><?= $success_count ?></div>
        </div>
        <div class="summary-card failed">
            <h3>Failed</h3>
            <div class="value"><?= $failed_count ?></div>
        </div>
        <div class="summary-card">
            <h3>Pending</h3>
            <div class="value"><?= $pending_count ?></div>
        </div>
        <div class="summary-card">
            <h3>Success Rate</h3>
            <div class="value"><?= round($success_rate, 2) ?>%</div>
        </div>
    </div>


    <div class="chart-section">
        <h2>Last 30 Days Executions</h2>
        <div class="chart-container">
            <canvas id="myLineChart"></canvas>
            <div id="chartTooltip" class="tooltip"></div>
        </div>
    </div>

    <div class="logs-section">
        <h2>Recent Execution Logs</h2>
        <?php if (empty($logs)): ?>
            <div class="empty-state">
                <p>No execution logs found for this automation yet.</p>
            </div>
        <?php else: ?>
            <table class="logs-table">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Contact</th>
                        <th>Stream</th>
                        <th>Status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td data-label="Date/Time"><?= date('M j, Y H:i:s', strtotime($log['executed_at'])) ?></td>
                            <td data-label="Contact"><?= htmlspecialchars($log['username'] ?: $log['email']) ?></td>
                            <td data-label="Stream"><?= htmlspecialchars($log['stream_name']) ?></td>
                            <td data-label="Status">
                                <span class="log-status-badge <?= htmlspecialchars($log['status']) ?>">
                                    <?= htmlspecialchars($log['status']) ?>
                                </span>
                            </td>
                            <td data-label="Details">
                                <?php if (!empty($log['details'])): ?>
                                    <span class="details-toggle" data-target="details-<?= $log['id'] ?>">View Details</span>
                                    <div class="details-content" id="details-<?= $log['id'] ?>">
                                        <?= nl2br(htmlspecialchars($log['details'])) ?>
                                    </div>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?id=<?= $automation_id ?>&page=<?= $current_page - 1 ?>">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="current-page"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?id=<?= $automation_id ?>&page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?id=<?= $automation_id ?>&page=<?= $current_page + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <div class="back-link">
        <a href="view_automations.php" class="btn btn-secondary">Back to All Automations</a>
        <a href="automations.php?edit=<?= $automation['id'] ?>" class="btn btn-primary">Edit Automation</a>
    </div>

</div>

<script>
    window.onload = function() {
        // --- Chart Rendering Logic ---
        const canvas = document.getElementById('myLineChart');
        const ctx = canvas.getContext('2d');
        const tooltip = document.getElementById('chartTooltip');

        // Data from PHP for the chart
        const chartData = <?= json_encode($chart_data); ?>;

        const config = {
            padding: 40,
            pointRadius: 6,
            gridColor: '#e0e7ff',
            axisLabelColor: '#6b7280',
            lineColor: '#4f46e5',
            gradientStartColor: 'rgba(79, 70, 229, 0.2)',
            gradientEndColor: 'rgba(79, 70, 229, 0)',
        };

        function drawChart() {
            const devicePixelRatio = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * devicePixelRatio;
            canvas.height = canvas.offsetHeight * devicePixelRatio;
            ctx.scale(devicePixelRatio, devicePixelRatio);

            ctx.clearRect(0, 0, canvas.offsetWidth, canvas.offsetHeight);

            const width = canvas.offsetWidth;
            const height = canvas.offsetHeight;
            const { padding, pointRadius, gridColor, axisLabelColor, lineColor, gradientStartColor, gradientEndColor } = config;

            const chartWidth = width - 2 * padding;
            const chartHeight = height - 2 * padding;

            const values = chartData.map(d => d.value);
            const maxActualValue = Math.max(...values);
            const minActualValue = Math.min(...values);

            let effectiveMinValue = minActualValue > 0 ? 0 : minActualValue;
            let effectiveMaxValue = maxActualValue * 1.1;

            if (maxActualValue === 0 && minActualValue === 0) {
                effectiveMinValue = 0;
                effectiveMaxValue = 10;
            } else if (effectiveMaxValue === effectiveMinValue) {
                effectiveMaxValue = effectiveMinValue + (effectiveMinValue === 0 ? 10 : effectiveMinValue * 0.1);
            }

            const effectiveValueRange = effectiveMaxValue - effectiveMinValue;

            const getX = (index) => padding + (index / (chartData.length - 1)) * chartWidth;
            const getY = (value) => {
                const normalizedValue = (value - effectiveMinValue) / effectiveValueRange;
                return height - padding - (normalizedValue * chartHeight);
            };

            const numYLabels = 5;
            for (let i = 0; i <= numYLabels; i++) {
                const value = effectiveMinValue + (effectiveValueRange / numYLabels) * i;
                const y = getY(value);

                ctx.beginPath();
                ctx.moveTo(padding, y);
                ctx.lineTo(width - padding, y);
                ctx.strokeStyle = gridColor;
                ctx.lineWidth = 1;
                ctx.globalAlpha = 0.5;
                ctx.stroke();
                ctx.globalAlpha = 1;

                ctx.fillStyle = axisLabelColor;
                ctx.font = '12px Inter';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                ctx.fillText(Math.round(value), padding - 10, y);
            }

            ctx.fillStyle = axisLabelColor;
            ctx.font = '12px Inter';
            ctx.textBaseline = 'top';
            chartData.forEach((d, i) => {
                const x = getX(i);
                ctx.textAlign = (i === 0) ? 'left' : (i === chartData.length - 1) ? 'right' : 'center';
                ctx.fillText(d.label, x, height - padding + 15);
            });

            ctx.beginPath();
            if (chartData.length > 0) {
                ctx.moveTo(getX(0), getY(effectiveMinValue));
                chartData.forEach((d, i) => {
                    const x = getX(i);
                    const y = getY(d.value);
                    if (i === 0) {
                        ctx.lineTo(x, y);
                    } else {
                        const prevX = getX(i - 1);
                        const prevY = getY(chartData[i - 1].value);
                        const cp1x = (prevX + x) / 2;
                        const cp1y = prevY;
                        const cp2x = (prevX + x) / 2;
                        const cp2y = y;
                        ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                    }
                });
                ctx.lineTo(getX(chartData.length - 1), getY(effectiveMinValue));
                ctx.closePath();

                const gradient = ctx.createLinearGradient(0, padding, 0, height - padding);
                gradient.addColorStop(0, gradientStartColor);
                gradient.addColorStop(1, gradientEndColor);
                ctx.fillStyle = gradient;
                ctx.fill();

                ctx.beginPath();
                chartData.forEach((d, i) => {
                    const x = getX(i);
                    const y = getY(d.value);
                    if (i === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        const prevX = getX(i - 1);
                        const prevY = getY(chartData[i - 1].value);
                        const cp1x = (prevX + x) / 2;
                        const cp1y = prevY;
                        const cp2x = (prevX + x) / 2;
                        const cp2y = y;
                        ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                    }
                });
                ctx.strokeStyle = lineColor;
                ctx.lineWidth = 3;
                ctx.stroke();

                chartData.forEach((d, i) => {
                    const x = getX(i);
                    const y = getY(d.value);

                    ctx.beginPath();
                    ctx.arc(x, y, pointRadius, 0, Math.PI * 2);
                    ctx.fillStyle = lineColor;
                    ctx.fill();
                    ctx.strokeStyle = '#ffffff';
                    ctx.lineWidth = 2;
                    ctx.stroke();
                });
            }
        }

        // Interactive Tooltip Logic
        let animationFrameId = null;

        canvas.addEventListener('mousemove', (e) => {
            cancelAnimationFrame(animationFrameId);

            animationFrameId = requestAnimationFrame(() => {
                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;

                let hoveredPoint = null;
                const tolerance = config.pointRadius * 1.5;

                for (let i = 0; i < chartData.length; i++) {
                    const x = getX(i);
                    const y = getY(chartData[i].value);

                    const distance = Math.sqrt(Math.pow(mouseX - x, 2) + Math.pow(mouseY - y, 2));

                    if (distance < tolerance) {
                        hoveredPoint = {
                            data: chartData[i],
                            x: x,
                            y: y
                        };
                        break;
                    }
                }

                if (hoveredPoint) {
                    tooltip.innerHTML = `<strong>${hoveredPoint.data.label}</strong>: ${hoveredPoint.data.value}`;
                    tooltip.style.left = `${hoveredPoint.x}px`;
                    tooltip.style.top = `${hoveredPoint.y}px`;
                    tooltip.classList.add('visible');
                } else {
                    tooltip.classList.remove('visible');
                }
            });
        });

        canvas.addEventListener('mouseleave', () => {
            cancelAnimationFrame(animationFrameId);
            tooltip.classList.remove('visible');
        });

        // Responsiveness
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                drawChart();
            }, 100);
        });

        // Initial draw
        drawChart();

        // --- Log Details Toggle Logic ---
        document.querySelectorAll('.details-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.dataset.target;
                const detailsContent = document.getElementById(targetId);
                if (detailsContent) {
                    detailsContent.classList.toggle('active');
                    this.textContent = detailsContent.classList.contains('active') ? 'Hide Details' : 'View Details';
                }
            });
        });
    };
</script>
</body>
</html>