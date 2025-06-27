<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php';

// Pagination setup
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$records_per_page = 20;
$offset = ($current_page - 1) * $records_per_page;

// Handle metric selection
$selected_metric_id = isset($_GET['metric_id']) ? $_GET['metric_id'] : null;
$selected_metric_type = isset($_GET['metric_type']) ? $_GET['metric_type'] : null;
$metric_details = null;
$metric_records = [];
$total_records = 0;

// Get all standard metrics with their individual contact counts
$standard_metrics = $pdo->query("
    SELECT 
        m.id, 
        m.name, 
        m.category, 
        m.description,
        (SELECT COUNT(DISTINCT md.contact_id)
        FROM metric_data md
        JOIN contacts c ON md.contact_id = c.id
        JOIN streams s ON c.stream_id = s.id
        WHERE md.metric_id = m.id AND s.user_id = {$_SESSION['user_id']}
        ) as contact_count
    FROM churn_metrics m
    ORDER BY m.category, m.name
");

// Get custom metrics for this user
$custom_metrics = $pdo->prepare("
    SELECT 
        cm.id, 
        cm.name, 
        'custom' as category,
        cm.description,
        (SELECT COUNT(DISTINCT md.contact_id)
         FROM metric_data md
         JOIN contacts c ON md.contact_id = c.id
         JOIN streams s ON c.stream_id = s.id
         WHERE md.custom_metric_id = cm.id AND s.user_id = ?
        ) as contact_count
    FROM custom_metrics cm
    WHERE cm.user_id = ?
    ORDER BY cm.name
");
$custom_metrics->execute([$_SESSION['user_id'], $_SESSION['user_id']]);

// Handle metric detail view
if ($selected_metric_id && $selected_metric_type) {
    if ($selected_metric_type === 'standard') {
        $stmt = $pdo->prepare("SELECT * FROM churn_metrics WHERE id = ?");
        $stmt->execute([$selected_metric_id]);
        $metric_details = $stmt->fetch();
        
        if ($metric_details) {
            try {
                // First get the count to determine if there's any data
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*) as count
                    FROM metric_data md
                    JOIN contacts c ON md.contact_id = c.id
                    JOIN streams s ON c.stream_id = s.id
                    WHERE md.metric_id = ? AND s.user_id = ?
                ");
                $count_stmt->execute([$selected_metric_id, $_SESSION['user_id']]);
                $total_records = (int)$count_stmt->fetchColumn();
                
                // Only execute the full query if there are records
                if ($total_records > 0) {
                    $stmt = $pdo->prepare("
                        SELECT 
                            c.id, c.username, c.email, md.value, md.recorded_at
                        FROM metric_data md
                        JOIN contacts c ON md.contact_id = c.id
                        JOIN streams s ON c.stream_id = s.id
                        WHERE md.metric_id = ? AND s.user_id = ?
                        ORDER BY md.recorded_at DESC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->execute([$selected_metric_id, $_SESSION['user_id'], $records_per_page, $offset]);
                    $metric_records = $stmt->fetchAll();
                }
            } catch (PDOException $e) {
                // Handle any database errors gracefully
                error_log("Database error: " . $e->getMessage());
                $total_records = 0;
                $metric_records = [];
            }
        }
    } elseif ($selected_metric_type === 'custom') {
        $stmt = $pdo->prepare("SELECT * FROM custom_metrics WHERE id = ? AND user_id = ?");
        $stmt->execute([$selected_metric_id, $_SESSION['user_id']]);
        $metric_details = $stmt->fetch();
        
        if ($metric_details) {
            try {
                // First get the count to determine if there's any data
                $count_stmt = $pdo->prepare("
                    SELECT COUNT(*) as count
                    FROM metric_data md
                    JOIN contacts c ON md.contact_id = c.id
                    JOIN streams s ON c.stream_id = s.id
                    WHERE md.custom_metric_id = ? AND s.user_id = ?
                ");
                $count_stmt->execute([$selected_metric_id, $_SESSION['user_id']]);
                $total_records = (int)$count_stmt->fetchColumn();
                
                // Only execute the full query if there are records
                if ($total_records > 0) {
                    $stmt = $pdo->prepare("
                        SELECT 
                            c.id, c.username, c.email, md.value, md.recorded_at
                        FROM metric_data md
                        JOIN contacts c ON md.contact_id = c.id
                        JOIN streams s ON c.stream_id = s.id
                        WHERE md.custom_metric_id = ? AND s.user_id = ?
                        ORDER BY md.recorded_at DESC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->execute([$selected_metric_id, $_SESSION['user_id'], $records_per_page, $offset]);
                    $metric_records = $stmt->fetchAll();
                }
            } catch (PDOException $e) {
                // Handle any database errors gracefully
                error_log("Database error: " . $e->getMessage());
                $total_records = 0;
                $metric_records = [];
            }
        }
    }
}

// Handle new metric creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_metric'])) {
    $name = trim($_POST['name']);
    $description = trim($_POST['description'] ?? '');
    
    if (!empty($name)) {
        $stmt = $pdo->prepare("INSERT INTO custom_metrics (user_id, name, description) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $name, $description]);
        header("Location: metrics.php");
        exit;
    }
}

function getMetricIcon($category) {
    $icons = [
        'activity' => 'activity',
        'subscription' => 'credit-card',
        'engagement' => 'bar-chart-2',
        'custom' => 'plus-circle'
    ];
    return $icons[$category] ?? 'circle';
}

function getMetricColor($category) {
    $colors = [
        'activity' => '#3b82f6',
        'subscription' => '#10b981',
        'engagement' => '#f59e0b',
        'custom' => '#8b5cf6'
    ];
    return $colors[$category] ?? '#6b7280';
}

// Organize metrics by category
$metrics_by_category = [
    'activity' => [],
    'subscription' => [],
    'engagement' => [],
    'custom' => []
];

foreach ($standard_metrics as $metric) {
    $metrics_by_category[$metric['category']][] = $metric;
}

foreach ($custom_metrics as $metric) {
    $metrics_by_category['custom'][] = $metric;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Metrics | Churn Analytics</title>
    <link rel="stylesheet" href="assets/css/metrics.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="metrics-container">
        <div class="metrics-header">
            <h1 class="metrics-title">Metrics Dashboard</h1>
            <button id="addMetricBtn" class="btn btn-primary">
                <i data-feather="plus"></i> Add Custom Metric
            </button>
        </div>

        <div id="addMetricForm" class="add-metric-form">
            <h3>Create New Custom Metric</h3>
            <form method="POST">
                <div class="form-group">
                    <label for="metricName" class="form-label">Metric Name</label>
                    <input type="text" id="metricName" name="name" class="form-input" required>
                </div>
                <div class="form-group">
                    <label for="metricDescription" class="form-label">Description (Optional)</label>
                    <textarea id="metricDescription" name="description" class="form-input" rows="3"></textarea>
                </div>
                <div class="form-actions">
                    <button type="button" id="cancelMetricBtn" class="btn">Cancel</button>
                    <button type="submit" name="create_metric" class="btn btn-primary">Create Metric</button>
                </div>
            </form>
        </div>

        <?php foreach ($metrics_by_category as $category => $metrics): ?>
            <?php if (!empty($metrics)): ?>
                <h2><?= ucfirst($category) ?> Metrics</h2>
                <div class="metrics-grid">
                    <?php foreach ($metrics as $metric): ?>
                        <a href="metrics.php?metric_id=<?= $metric['id'] ?>&metric_type=<?= $category === 'custom' ? 'custom' : 'standard' ?>" 
                           class="metric-card">
                            <div class="metric-card-header">
                                <div class="metric-icon" style="background-color: <?= getMetricColor($category) ?>">
                                    <i data-feather="<?= getMetricIcon($category) ?>"></i>
                                </div>
                                <div>
                                    <h3 class="metric-name"><?= htmlspecialchars($metric['name']) ?></h3>
                                    <span class="metric-category"><?= ucfirst($category) ?></span>
                                </div>
                            </div>
                            <div class="metric-stats">
                                <span class="metric-count"><?= $metric['contact_count'] ?> contacts</span>
                                <i data-feather="chevron-right"></i>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <!-- Metric Detail View -->
        <?php if ($selected_metric_id && $metric_details): ?>
            <div class="metric-detail" id="metricDetail">
                <div class="detail-header">
                    <h2 class="detail-title">
                        <?= htmlspecialchars($metric_details['name']) ?>
                        <small><?= $total_records ?> total records</small>
                    </h2>
                    <a href="metrics.php" class="btn">
                        <i data-feather="x"></i> Close
                    </a>
                </div>
                
                <?php if ($total_records > 0): ?>
                    <table class="records-table">
                        <thead>
                            <tr>
                                <th>Contact</th>
                                <th>Value</th>
                                <th>Recorded At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($metric_records as $record): ?>
                                <tr>
                                    <td class="contact-cell">
                                        <div class="contact-avatar">
                                            <?= strtoupper(substr($record['username'] ?: $record['email'], 0, 1)) ?>
                                        </div>
                                        <div class="contact-info">
                                            <span class="contact-name"><?= htmlspecialchars($record['username'] ?: 'No Name') ?></span>
                                            <span class="contact-email"><?= htmlspecialchars($record['email']) ?></span>
                                        </div>
                                    </td>
                                    <td class="value-cell"><?= htmlspecialchars($record['value']) ?></td>
                                    <td><?= date('M j, Y g:i a', strtotime($record['recorded_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($total_records > $records_per_page): ?>
                        <div class="pagination">
                            <?php if ($current_page > 1): ?>
                                <a href="metrics.php?metric_id=<?= $selected_metric_id ?>&metric_type=<?= $selected_metric_type ?>&page=1" class="pagination-button">
                                    <i data-feather="chevrons-left"></i>
                                </a>
                                <a href="metrics.php?metric_id=<?= $selected_metric_id ?>&metric_type=<?= $selected_metric_type ?>&page=<?= $current_page - 1 ?>" class="pagination-button">
                                    <i data-feather="chevron-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $total_pages = ceil($total_records / $records_per_page);
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="metrics.php?metric_id=<?= $selected_metric_id ?>&metric_type=<?= $selected_metric_type ?>&page=<?= $i ?>" 
                                class="pagination-button <?= $i == $current_page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="metrics.php?metric_id=<?= $selected_metric_id ?>&metric_type=<?= $selected_metric_type ?>&page=<?= $current_page + 1 ?>" class="pagination-button">
                                    <i data-feather="chevron-right"></i>
                                </a>
                                <a href="metrics.php?metric_id=<?= $selected_metric_id ?>&metric_type=<?= $selected_metric_type ?>&page=<?= $total_pages ?>" class="pagination-button">
                                    <i data-feather="chevrons-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="no-records-message">
                        <div class="empty-state">
                            <i data-feather="bar-chart-2" class="empty-icon"></i>
                            <h3>No data available</h3>
                            <p>There are currently no records for this metric. Records will appear here once data is collected.</p>
                            <?php if (isset($metric_details['description']) && !empty($metric_details['description'])): ?>
                                <div class="metric-description">
                                    <h4>Metric Description</h4>
                                    <p><?= htmlspecialchars($metric_details['description']) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <style>
                .no-records-message {
                    padding: 2rem;
                    background-color: #f9fafb;
                    border-radius: 8px;
                    margin-top: 1rem;
                }
                
                .empty-state {
                    text-align: center;
                    padding: 3rem 1rem;
                }
                
                .empty-icon {
                    width: 64px;
                    height: 64px;
                    stroke: #9ca3af;
                    margin-bottom: 1rem;
                }
                
                .empty-state h3 {
                    font-size: 1.25rem;
                    color: #1f2937;
                    margin-bottom: 0.5rem;
                }
                
                .empty-state p {
                    color: #6b7280;
                    max-width: 500px;
                    margin: 0 auto;
                }
                
                .metric-description {
                    margin-top: 2rem;
                    padding-top: 1.5rem;
                    border-top: 1px solid #e5e7eb;
                    text-align: left;
                }
                
                .metric-description h4 {
                    font-size: 1rem;
                    color: #1f2937;
                    margin-bottom: 0.5rem;
                }
                
                .metric-description p {
                    color: #4b5563;
                    text-align: left;
                }
            </style>

            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const metricDetail = document.getElementById('metricDetail');
                    if (metricDetail) {
                        metricDetail.style.display = 'block';
                        metricDetail.scrollIntoView({
                            behavior: 'smooth'
                        });
                    }
                });
            </script>
        <?php endif; ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            feather.replace();
            
            const addMetricBtn = document.getElementById('addMetricBtn');
            const addMetricForm = document.getElementById('addMetricForm');
            const cancelMetricBtn = document.getElementById('cancelMetricBtn');
            
            if (addMetricBtn) {
                addMetricBtn.addEventListener('click', function() {
                    addMetricForm.style.display = 'block';
                    this.style.display = 'none';
                });
            }
            
            if (cancelMetricBtn) {
                cancelMetricBtn.addEventListener('click', function() {
                    addMetricForm.style.display = 'none';
                    addMetricBtn.style.display = 'inline-flex';
                });
            }
        });
    </script>
</body>
</html>