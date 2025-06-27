<?php
// MUST BE AT THE VERY TOP - NO WHITESPACE BEFORE THIS
session_start(); // Start session first

// Enable error reporting for debugging, but disable display in production
ini_set('display_errors', 1); // TEMPORARILY SET TO 1 TO DISPLAY ALL ERRORS
ini_set('display_startup_errors', 1); // TEMPORARILY SET TO 1 TO DISPLAY ALL ERRORS
error_reporting(E_ALL);

// Check login status BEFORE including any files that might output content
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

// Now include other files
require_once 'includes/db.php';
require_once 'includes/header.php'; // Assuming this includes necessary HTML header and potentially BASE_URL

$user_id = $_SESSION['user_id'];
$workflow_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($workflow_id === 0) {
    $_SESSION['error'] = "No automation selected.";
    header('Location: view_automations.php');
    exit;
}

// Fetch automation details
$stmt = $pdo->prepare("SELECT id, name, description, is_active FROM automation_workflows WHERE id = ? AND user_id = ?");
$stmt->execute([$workflow_id, $user_id]);
$automation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$automation) {
    $_SESSION['error'] = "Automation not found or you do not have permission to view it.";
    header('Location: view_automations.php');
    exit;
}

// Pagination settings for logs
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Fetch total number of logs for pagination
$total_logs_stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_logs WHERE workflow_id = ?");
$total_logs_stmt->execute([$workflow_id]);
$total_logs = $total_logs_stmt->fetchColumn();
$total_pages = ceil($total_logs / $records_per_page);

// Fetch automation logs
// Note: JOIN with contacts to get contact details for better logs
$logs_stmt = $pdo->prepare("SELECT al.*, c.email, c.username, s.name as stream_name
                             FROM automation_logs al
                             JOIN contacts c ON al.contact_id = c.id
                             JOIN streams s ON c.stream_id = s.id
                             WHERE al.workflow_id = ?
                             ORDER BY al.executed_at DESC
                             LIMIT ?, ?");
$logs_stmt->bindValue(1, $workflow_id, PDO::PARAM_INT);
$logs_stmt->bindValue(2, $offset, PDO::PARAM_INT);
$logs_stmt->bindValue(3, $records_per_page, PDO::PARAM_INT);
$logs_stmt->execute();
$logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Optional: Fetch success/failure counts for a simple summary
$summary_stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM automation_logs WHERE workflow_id = ? GROUP BY status");
$summary_stmt->execute([$workflow_id]);
$summary_stats = $summary_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$total_executions = array_sum($summary_stats);
$success_count = $summary_stats['success'] ?? 0;
$failed_count = ($summary_stats['failed'] ?? 0) + ($summary_stats['pending'] ?? 0); // Consider pending as not-yet-successful
$success_rate = $total_executions > 0 ? ($success_count / $total_executions) * 100 : 0;

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Automation Analytics: <?= htmlspecialchars($automation['name']) ?></title>
    <link rel="stylesheet" href="assets/css/automations_builder.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* Reusing global styles from automations_builder.css */

        .analytics-container {
            padding: 30px;
            border-radius: 12px;
            width: 100%;
            max-width: 1000px; /* Slightly wider for analytics */
            box-sizing: border-box;
            margin: 20px auto;
        }

        .analytics-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid var(--primary-color);
            padding-bottom: 15px;
        }

        .analytics-header h1 {
            color: var(--primary-color);
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .analytics-header p {
            color: var(--text-color);
            font-size: 1.1em;
            max-width: 700px;
            margin: 0 auto;
        }

        .automation-summary {
            display: flex;
            justify-content: space-around;
            gap: 20px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }

        .summary-card {
            background-color: var(--background-dark);
            padding: 20px;
            border-radius: 10px;
            flex: 1;
            min-width: 250px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            text-align: center;
        }

        .summary-card h3 {
            color: var(--secondary-color);
            font-size: 1.2em;
            margin-top: 0;
            margin-bottom: 10px;
        }

        .summary-card .value {
            font-size: 2.2em;
            font-weight: 700;
            color: var(--primary-color);
        }

        .summary-card.success .value {
            color: var(--success-color);
        }

        .summary-card.failed .value {
            color: var(--error-color);
        }

        /* Logs Section */
        .logs-section {
            margin-top: 40px;
        }

        .logs-section h2 {
            color: var(--text-color);
            font-size: 1.8em;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 10px;
        }

        .logs-table {
            width: 100%;
            border-collapse: collapse;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }

        .logs-table thead tr {
            background-color: var(--primary-dark-color);
            color: #fff;
            text-align: left;
        }

        .logs-table th, .logs-table td {
            padding: 12px 15px;
            border: 1px solid #e0e0e0;
            vertical-align: top;
        }

        .logs-table tbody tr:nth-child(even) {
            background-color: var(--background-light);
        }

        .logs-table tbody tr:hover {
            background-color: #f6f6f6;
        }

        .log-status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.85em;
            color: #fff;
            text-transform: capitalize;
        }

        .log-status-badge.success { background-color: var(--success-color); }
        .log-status-badge.failed { background-color: var(--error-color); }
        .log-status-badge.pending { background-color: var(--secondary-color); }

        .logs-table .details-toggle {
            cursor: pointer;
            color: var(--primary-color);
            font-weight: 600;
        }
        .logs-table .details-content {
            display: none;
            margin-top: 5px;
            font-size: 0.85em;
            background-color: #fcfcfc;
            border-left: 3px solid var(--primary-color);
            padding: 8px;
            white-space: pre-wrap; /* Preserve whitespace and break lines */
            word-break: break-word; /* Break long words */
            max-height: 150px; /* Limit height */
            overflow-y: auto; /* Scroll if content overflows */
        }
        .logs-table .details-content.active {
            display: block;
        }

        /* Pagination styles are already in automations_builder.css */

        .back-button-container {
            margin-top: 30px;
            text-align: center;
        }

        /* Responsive table for logs */
        @media (max-width: 768px) {
            .analytics-container {
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
                border: 1px solid var(--border-color);
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            .logs-table td {
                border: none;
                position: relative;
                padding-left: 50%;
                text-align: right;
                border-bottom: 1px dashed var(--border-color);
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
                color: var(--secondary-color);
            }
            .logs-table .details-toggle {
                 text-align: right; /* Keep toggle right-aligned */
            }
            .logs-table .details-content {
                text-align: left; /* Align content inside details to left */
            }
        }
    </style>
</head>
<body>
<div class="analytics-container">
    <div class="analytics-header">
        <h1>Analytics for: <?= htmlspecialchars($automation['name']) ?></h1>
        <p><?= htmlspecialchars($automation['description']) ?: 'No description provided.' ?></p>
        <p>Status: <span class="status-badge <?= $automation['is_active'] ? 'active' : 'inactive' ?>">
            <?= $automation['is_active'] ? 'Active' : 'Inactive' ?>
        </span></p>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="automation-summary">
        <div class="summary-card">
            <h3>Total Executions</h3>
            <div class="value"><?= $total_executions ?></div>
        </div>
        <div class="summary-card success">
            <h3>Successful</h3>
            <div class="value"><?= $success_count ?></div>
        </div>
        <div class="summary-card failed">
            <h3>Failed/Pending</h3>
            <div class="value"><?= $failed_count ?></div>
        </div>
        <div class="summary-card">
            <h3>Success Rate</h3>
            <div class="value"><?= round($success_rate, 2) ?>%</div>
        </div>
    </div>

    <div class="logs-section">
        <h2>Execution Logs</h2>
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
                        <a href="?id=<?= $workflow_id ?>&page=<?= $current_page - 1 ?>">Previous</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="current-page"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?id=<?= $workflow_id ?>&page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?id=<?= $workflow_id ?>&page=<?= $current_page + 1 ?>">Next</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <div class="back-button-container">
        <a href="view_automations.php" class="btn btn-secondary">Back to Automations List</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Toggle details section visibility
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
});
</script>
</body>
</html>
