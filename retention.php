<?php
session_start(); // Ensure session is started FIRST

// IMPORTANT: Include DB connection and handle login check BEFORE any HTML or 'header.php'
require_once 'includes/db.php'; // Make $pdo available

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php'); // Use BASE_URL from db.php
    exit;
}

$user_id = $_SESSION['user_id'];

// --- All PHP logic (form submissions, AJAX, data fetching) goes here ---

// Get available streams for filter
$stmt = $pdo->prepare("SELECT id, name FROM streams WHERE user_id = ? ORDER BY name");
$stmt->execute([$user_id]);
$streams = $stmt->fetchAll();

// Get available cohorts for filter
// Note: If cohorts are linked to streams, ensure you fetch cohorts relevant to the user's streams
// Your current query `WHERE created_by = ?` is okay if 'created_by' refers to user_id directly.
$stmt = $pdo->prepare("SELECT id, name FROM cohorts WHERE created_by = ? ORDER BY name");
$stmt->execute([$user_id]);
$cohorts = $stmt->fetchAll();

// --- PHP Error Reporting for Debugging (optional, keep at top during dev) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- End PHP Error Reporting ---

// --- Historical Data for Monthly Contact Acquisition Chart (from profile.php example) ---
$monthly_contacts_labels = [];
$monthly_contacts_data = [];

// Get selected date range from GET parameters
$start_date_param = $_GET['start_date'] ?? null;
$end_date_param = $_GET['end_date'] ?? null;

// Use 'current month - 11 months' and 'current date' as defaults
$selected_start_date = new DateTime($start_date_param ?: date('Y-m-d', strtotime('-11 months')));
$selected_end_date = new DateTime($end_date_param ?: date('Y-m-d'));

try {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(c.created_at, '%Y-%m') AS month_year,
            COUNT(c.id) AS contact_count
        FROM contacts c
        JOIN streams s ON c.stream_id = s.id
        WHERE s.user_id = :user_id
        AND c.created_at >= :start_date AND c.created_at <= :end_date
        GROUP BY month_year
        ORDER BY month_year ASC
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':start_date', $selected_start_date->format('Y-m-01')); // Start of month
    $stmt->bindValue(':end_date', $selected_end_date->format('Y-m-t')); // End of month, Y-m-t gets last day of month
    $stmt->execute();
    $raw_monthly_contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare an array for all months in the period, initialized to 0
    $period = new DatePeriod(
        new DateTime($selected_start_date->format('Y-m-01')),
        new DateInterval('P1M'),
        new DateTime($selected_end_date->format('Y-m-01') . ' +1 month') // To include the end month
    );

    $formatted_monthly_contacts_tmp = [];
    foreach ($period as $dt) {
        $month_key = $dt->format('Y-m');
        $formatted_monthly_contacts_tmp[$month_key] = 0; // Initialize all months to 0
    }

    // Populate with actual data
    foreach ($raw_monthly_contacts as $row) {
        $formatted_monthly_contacts_tmp[$row['month_year']] = (int)$row['contact_count'];
    }

    // Now convert to simple arrays for Chart.js, ensuring all months are present
    foreach ($formatted_monthly_contacts_tmp as $month_key => $count) {
        $monthly_contacts_labels[] = (new DateTime($month_key . '-01'))->format('M Y');
        $monthly_contacts_data[] = $count;
    }

} catch (PDOException $e) {
    error_log("Database error fetching monthly contact data: " . $e->getMessage());
    $_SESSION['error'] = 'Error loading monthly contacts chart data: ' . htmlspecialchars($e->getMessage());
    $monthly_contacts_labels = [];
    $monthly_contacts_data = [];
}

// --- New Chart: Churn Rate Over Time (e.g., last 12 months) (from profile.php example) ---
$monthly_churn_labels = [];
$monthly_churn_values = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            DATE_FORMAT(cs.scored_at, '%Y-%m') AS month_year,
            AVG(cs.score) AS average_score
        FROM churn_scores cs
        JOIN contacts c ON cs.contact_id = c.id
        JOIN streams s ON c.stream_id = s.id
        WHERE s.user_id = :user_id
        AND cs.scored_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY month_year
        ORDER BY month_year ASC
    ");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $raw_monthly_churn = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Prepare an array for all months in the period, initialized to 0
    $period_churn = new DatePeriod(
        new DateTime('-11 months first day of this month'),
        new DateInterval('P1M'),
        new DateTime('first day of next month')
    );

    $formatted_monthly_churn_tmp = [];
    foreach ($period_churn as $dt) {
        $month_key = $dt->format('Y-m');
        $formatted_monthly_churn_tmp[$month_key] = 0; // Initialize all months to 0
    }

    // Populate with actual data
    foreach ($raw_monthly_churn as $row) {
        $formatted_monthly_churn_tmp[$row['month_year']] = (float)$row['average_score'];
    }

    // Now convert to simple arrays for Chart.js, ensuring all months are present
    foreach ($formatted_monthly_churn_tmp as $month_key => $score) {
        $monthly_churn_labels[] = (new DateTime($month_key . '-01'))->format('M Y');
        $monthly_churn_values[] = round($score, 1);
    }

} catch (PDOException $e) {
    error_log("Database error fetching monthly churn data: " . $e->getMessage());
    $_SESSION['error'] = 'Error loading monthly churn chart data: ' . htmlspecialchars($e->getMessage());
    $monthly_churn_labels = [];
    $monthly_churn_values = [];
}

// --- Assuming these summary metrics are for "Retention" not "Profile"
// Re-added the churn_metrics_summary fetching here for retention.php to have this data
$churn_metrics_summary = [
    'total_contacts' => 0,
    'high_risk_count' => 0,
    'medium_risk_count' => 0,
    'low_risk_count' => 0,
    'churned_count' => 0,
    'resurrected_count' => 0,
    'risk_distribution' => ['high' => 0, 'medium' => 0, 'low' => 0] // Percentages
];

try {
    // Get all stream IDs for the current user
    $stmt_streams = $pdo->prepare("SELECT id FROM streams WHERE user_id = :user_id");
    $stmt_streams->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_streams->execute();
    $user_stream_ids = $stmt_streams->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($user_stream_ids)) {
        $stream_ids_placeholder = implode(',', array_fill(0, count($user_stream_ids), '?'));

        // Total contacts for the user's streams
        $stmt = $pdo->prepare("SELECT COUNT(id) FROM contacts WHERE stream_id IN ($stream_ids_placeholder)");
        $stmt->execute($user_stream_ids);
        $churn_metrics_summary['total_contacts'] = $stmt->fetchColumn();

        if ($churn_metrics_summary['total_contacts'] > 0) {
            // Fetch all latest churn scores for contacts in the user's streams
            $stmt = $pdo->prepare("
                SELECT cs.contact_id, cs.score
                FROM churn_scores cs
                JOIN contacts c ON cs.contact_id = c.id
                WHERE c.stream_id IN ($stream_ids_placeholder)
                AND (cs.contact_id, cs.scored_at) IN (
                    SELECT contact_id, MAX(scored_at) FROM churn_scores GROUP BY contact_id
                )
            ");
            $stmt->execute($user_stream_ids);
            $latest_scores = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($latest_scores as $score_data) {
                $score = $score_data['score'];
                if ($score > 70) {
                    $churn_metrics_summary['high_risk_count']++;
                } elseif ($score > 40) {
                    $churn_metrics_summary['medium_risk_count']++;
                } else {
                    $churn_metrics_summary['low_risk_count']++;
                }
            }

            // Calculate percentages for risk distribution
            $total_scored = $churn_metrics_summary['high_risk_count'] + $churn_metrics_summary['medium_risk_count'] + $churn_metrics_summary['low_risk_count'];
            if ($total_scored > 0) {
                $churn_metrics_summary['risk_distribution']['high'] = round(($churn_metrics_summary['high_risk_count'] / $total_scored) * 100, 1);
                $churn_metrics_summary['risk_distribution']['medium'] = round(($churn_metrics_summary['medium_risk_count'] / $total_scored) * 100, 1);
                // Ensure low_risk_percentage accounts for rounding errors to sum to 100%
                $churn_metrics_summary['risk_distribution']['low'] = 100 - ($churn_metrics_summary['risk_distribution']['high'] + $churn_metrics_summary['risk_distribution']['medium']);
            }

            // Count churned contacts
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT cu.contact_id) FROM churned_users cu
                JOIN contacts c ON cu.contact_id = c.id
                WHERE c.stream_id IN ($stream_ids_placeholder)
            ");
            $stmt->execute($user_stream_ids);
            $churn_metrics_summary['churned_count'] = $stmt->fetchColumn();

            // Count resurrected contacts
            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT ru.contact_id) FROM resurrected_users ru
                JOIN contacts c ON ru.contact_id = c.id
                WHERE c.stream_id IN ($stream_ids_placeholder)
            ");
            $stmt->execute($user_stream_ids);
            $churn_metrics_summary['resurrected_count'] = $stmt->fetchColumn();
        }
    }
} catch (PDOException $e) {
    error_log("Database error fetching churn metrics summary for retention.php: " . $e->getMessage());
    $_SESSION['error'] = 'Error loading churn metrics summary: ' . htmlspecialchars($e->getMessage());
}

// --- End PHP logic, begin HTML output ---
?>

<?php require_once 'includes/header.php'; ?>
<link rel="stylesheet" href="assets/css/retention.css">

<div class="retention-container">
    <div class="retention-header">
        <h1 class="retention-title">Customer Retention Analytics</h1>
        <p class="retention-subtitle">Analyze user retention patterns and churn risks</p>
    </div>

    <div class="filters-container">
        <div class="filter-group">
            <label for="dateRange" class="filter-label">Date Range</label>
            <select id="dateRange" class="filter-select">
                <option value="7">Last 7 Days</option>
                <option value="30" selected>Last 30 Days</option>
                <option value="90">Last 90 Days</option>
                <option value="180">Last 6 Months</option>
                <option value="365">Last Year</option>
                <option value="custom">Custom Range</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="streamFilter" class="filter-label">Stream</label>
            <select id="streamFilter" class="filter-select">
                <option value="">All Streams</option>
                <?php foreach ($streams as $stream): ?>
                    <option value="<?= $stream['id'] ?>"><?= htmlspecialchars($stream['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="cohortFilter" class="filter-label">Cohort</label>
            <select id="cohortFilter" class="filter-select">
                <option value="">All Cohorts</option>
                <?php foreach ($cohorts as $cohort): ?>
                    <option value="<?= $cohort['id'] ?>"><?= htmlspecialchars($cohort['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-value" id="retentionRateValue">--%</div>
            <div class="metric-label">Retention Rate</div>
            <div class="metric-change positive">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
                <span id="retentionChange">0%</span> vs previous period
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-value" id="churnRateValue">--%</div>
            <div class="metric-label">Churn Rate</div>
            <div class="metric-change negative">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 18 13.5 8.5 8.5 13.5 1 6"></polyline>
                    <polyline points="17 18 23 18 23 12"></polyline>
                </svg>
                <span id="churnChange">0%</span> vs previous period
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-value" id="resurrectionRateValue">--%</div>
            <div class="metric-label">Resurrection Rate</div>
            <div class="metric-change positive">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="23 6 13.5 15.5 8.5 10.5 1 18"></polyline>
                    <polyline points="17 6 23 6 23 12"></polyline>
                </svg>
                <span id="resurrectionChange">0%</span> vs previous period
            </div>
        </div>
    </div>

    <div class="chart-container">
        <div class="chart-header">
            <h3 class="chart-title">Retention Rate Over Time</h3>
            <div class="chart-actions">
                <button class="chart-action-btn">30D</button>
                <button class="chart-action-btn">90D</button>
                <button class="chart-action-btn">1Y</button>
            </div>
        </div>
        <div class="chart-wrapper">
            <canvas id="retentionChart"></canvas>
        </div>
    </div>

    <div class="chart-container">
        <div class="chart-header">
            <h3 class="chart-title">Churn Rate Distribution</h3>
            <div class="chart-actions">
                <button class="chart-action-btn">By Week</button>
                <button class="chart-action-btn">By Month</button>
            </div>
        </div>
        <div class="chart-wrapper">
            <canvas id="churnChart"></canvas>
        </div>
    </div>

    <div class="chart-container">
        <div class="chart-header">
            <h3 class="chart-title">Cohort Retention Analysis</h3>
            <div class="chart-actions">
                <button class="chart-action-btn">Weekly</button>
                <button class="chart-action-btn">Monthly</button>
            </div>
        </div>
        <div id="heatmapContainer" class="heatmap-container">
            <div class="loading-state" id="loadingIndicator">
                <div class="spinner"></div>
                <span>Loading retention data...</span>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="assets/js/retention.js"></script>

<?php
require_once 'includes/footer.php';
?>