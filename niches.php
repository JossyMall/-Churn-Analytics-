<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php'; // Ensure this path is correct for your setup
require_once 'includes/functions.php'; // Make sure BASE_URL is defined here, or in db.php

// !!! IMPORTANT DEBUGGING LINES - KEEP AT VERY TOP !!!
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_app_errors.log');
// !!! END IMPORTANT DEBUGGING LINES !!!

ob_start(); // Start output buffering to prevent headers already sent errors


// Pagination settings
$per_page = 12;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Data structures to hold niche information and categorized lists
$niches_data = [];
$categories = [
    'top_performing' => [],
    'underperforming' => [],
    'emerging' => [],
    'declining' => []
];

try {
    // Get total count of niches for pagination
    $stmt_count = $pdo->query("SELECT COUNT(*) FROM niches WHERE is_active = 1");
    $total_niches = $stmt_count->fetchColumn();
    $total_pages = ceil($total_niches / $per_page);

    // Get all active niches with pagination
    $stmt_niches = $pdo->prepare("
        SELECT id, name, description, created_at
        FROM niches
        WHERE is_active = 1
        ORDER BY name ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt_niches->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt_niches->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt_niches->execute();
    $all_niches = $stmt_niches->fetchAll(PDO::FETCH_ASSOC);

    // Define default date range for the initial chart view (last 12 months)
    $default_end_dt = new DateTime('first day of this month');
    $default_start_dt = (new DateTime('first day of this month'))->modify('-11 months');
    $filter_start_month_year = $default_start_dt->format('Y-m');
    $filter_end_month_year = $default_end_dt->format('Y-m');

    foreach ($all_niches as $niche) {
        $niche_id = $niche['id'];
        $niche_name = htmlspecialchars($niche['name']);
        $niche_created_at = new DateTime($niche['created_at']);

        // Determine earliest possible month for this niche based on stream creation dates
        $stmt_earliest_stream = $pdo->prepare("
            SELECT MIN(created_at)
            FROM streams
            WHERE niche_id = ? AND created_at IS NOT NULL
        ");
        $stmt_earliest_stream->execute([$niche_id]);
        $earliest_stream_date_str = $stmt_earliest_stream->fetchColumn();

        if ($earliest_stream_date_str) {
            $stream_creation_date = new DateTime($earliest_stream_date_str);
            // The earliest available month for data should be the later of niche creation or stream creation
            $earliest_available_month_obj = max($niche_created_at, $stream_creation_date);
            $earliest_available_month = $earliest_available_month_obj->format('Y-m');
        } else {
            // If no streams, default to niche creation date or a generic fallback
            $earliest_available_month = $niche_created_at->format('Y-m');
        }

        // Adjust start_dt if it's before the earliest available month for this niche
        $current_start_dt = new DateTime($filter_start_month_year . '-01');
        if ($current_start_dt < new DateTime($earliest_available_month . '-01')) {
            $start_dt_for_niche_data = new DateTime($earliest_available_month . '-01');
        } else {
            $start_dt_for_niche_data = $current_start_dt;
        }
        
        $end_dt_for_niche_data = new DateTime($filter_end_month_year . '-01');
        $end_dt_for_niche_data->modify('last day of this month');

        // Generate chart labels (monthly intervals)
        $chart_labels = [];
        $interval = DateInterval::createFromDateString('1 month');
        // DatePeriod needs a start, end, and interval. The end date for DatePeriod is exclusive.
        // To include the end month, we need to add one month to it for the loop.
        $period_end_inclusive = clone $end_dt_for_niche_data;
        $period_end_inclusive->modify('+1 day'); // Move to the first day of next month for DatePeriod exclusivity

        $period = new DatePeriod($start_dt_for_niche_data, $interval, $period_end_inclusive);
        foreach ($period as $dt) {
            $chart_labels[] = $dt->format('M Y');
        }

        // Fetch aggregate data for averages (current period)
        $stmt_streams = $pdo->prepare("
            SELECT AVG(acquisition_cost) as avg_acq_cost, AVG(revenue_per_user) as avg_rev_per_user
            FROM streams
            WHERE niche_id = ?
            AND created_at <= ? -- Consider streams active/created up to the end of the period
        ");
        $stmt_streams->execute([$niche_id, $end_dt_for_niche_data->format('Y-m-d H:i:s')]);
        $stream_averages = $stmt_streams->fetch(PDO::FETCH_ASSOC);
        
        $avg_acquisition_cost = $stream_averages['avg_acq_cost'] ?: 0;
        $avg_revenue_per_user = $stream_averages['avg_rev_per_user'] ?: 0;
        
        // Calculate average churn rate for this niche
        $stmt_avg_churn = $pdo->prepare("
            SELECT AVG(cs.score)
            FROM churn_scores cs
            JOIN contacts c ON cs.contact_id = c.id
            JOIN streams s ON c.stream_id = s.id
            WHERE s.niche_id = ?
            AND cs.scored_at BETWEEN ? AND ?
        ");
        $stmt_avg_churn->execute([
            $niche_id,
            $start_dt_for_niche_data->format('Y-m-d H:i:s'),
            $end_dt_for_niche_data->format('Y-m-d H:i:s')
        ]);
        $avg_churn_rate = $stmt_avg_churn->fetchColumn() ?: 0;
        $avg_retention_rate = 100 - $avg_churn_rate;

        // Calculate acquisition rate (new contacts per month)
        $stmt_acquisition = $pdo->prepare("
            SELECT COUNT(*)
            FROM contacts c
            JOIN streams s ON c.stream_id = s.id
            WHERE s.niche_id = ?
            AND c.created_at BETWEEN ? AND ?
        ");
        $stmt_acquisition->execute([
            $niche_id,
            $start_dt_for_niche_data->format('Y-m-d H:i:s'),
            $end_dt_for_niche_data->format('Y-m-d H:i:s')
        ]);
        $total_acquisitions = $stmt_acquisition->fetchColumn() ?: 0;
        
        $months_in_period_count = count($chart_labels); // Use calculated labels count for accurate month count
        $avg_acquisition_rate = $months_in_period_count > 0 ? round($total_acquisitions / $months_in_period_count) : 0;

        // Calculate winback rate
        $stmt_winback = $pdo->prepare("
            SELECT COUNT(*)
            FROM resurrected_users ru
            JOIN contacts c ON ru.contact_id = c.id
            JOIN streams s ON c.stream_id = s.id
            WHERE s.niche_id = ?
            AND ru.resurrected_at BETWEEN ? AND ?
        ");
        $stmt_winback->execute([
            $niche_id,
            $start_dt_for_niche_data->format('Y-m-d H:i:s'),
            $end_dt_for_niche_data->format('Y-m-d H:i:s')
        ]);
        $winback_count = $stmt_winback->fetchColumn() ?: 0;
        
        $stmt_churned_for_winback_calc = $pdo->prepare("
            SELECT COUNT(*)
            FROM churned_users cu
            JOIN contacts c ON cu.contact_id = c.id
            JOIN streams s ON c.stream_id = s.id
            WHERE s.niche_id = ?
            AND cu.churned_at BETWEEN ? AND ?
        ");
        $stmt_churned_for_winback_calc->execute([
            $niche_id,
            $start_dt_for_niche_data->format('Y-m-d H:i:s'),
            $end_dt_for_niche_data->format('Y-m-d H:i:s')
        ]);
        $churned_count_for_winback = max(1, $stmt_churned_for_winback_calc->fetchColumn() ?: 0); // Avoid division by zero
        
        $avg_winback_rate = round(($winback_count / $churned_count_for_winback) * 100, 1);

        // Generate historical data for the chart (monthly values)
        $history_acquisition_cost = [];
        $history_revenue_per_user = [];
        $history_churn_rate = [];
        $history_retention_rate = [];
        $history_winback_rate = [];
        $history_acquisition_rate = [];

        // Re-calculate period for data fetching, ensure it aligns with $chart_labels
        $current_month_iter = clone $start_dt_for_niche_data;
        $idx = 0;
        while ($current_month_iter <= $end_dt_for_niche_data) {
            $month_start = $current_month_iter->format('Y-m-01 00:00:00');
            $month_end = $current_month_iter->format('Y-m-t 23:59:59');
            
            // Get churn rate for this month
            $stmt_month_churn = $pdo->prepare("
                SELECT AVG(cs.score)
                FROM churn_scores cs
                JOIN contacts c ON cs.contact_id = c.id
                JOIN streams s ON c.stream_id = s.id
                WHERE s.niche_id = ?
                AND cs.scored_at BETWEEN ? AND ?
            ");
            $stmt_month_churn->execute([$niche_id, $month_start, $month_end]);
            $month_churn = $stmt_month_churn->fetchColumn() ?: 0;
            
            // Get acquisitions for this month
            $stmt_month_acq = $pdo->prepare("
                SELECT COUNT(*)
                FROM contacts c
                JOIN streams s ON c.stream_id = s.id
                WHERE s.niche_id = ?
                AND c.created_at BETWEEN ? AND ?
            ");
            $stmt_month_acq->execute([$niche_id, $month_start, $month_end]);
            $month_acq = $stmt_month_acq->fetchColumn() ?: 0;
            
            // Get winbacks for this month
            $stmt_month_winback = $pdo->prepare("
                SELECT COUNT(*)
                FROM resurrected_users ru
                JOIN contacts c ON ru.contact_id = c.id
                JOIN streams s ON c.stream_id = s.id
                WHERE s.niche_id = ?
                AND ru.resurrected_at BETWEEN ? AND ?
            ");
            $stmt_month_winback->execute([$niche_id, $month_start, $month_end]);
            $month_winback = $stmt_month_winback->fetchColumn() ?: 0;
            
            // Get churned for winback rate this month
            $stmt_month_churned_winback = $pdo->prepare("
                SELECT COUNT(*)
                FROM churned_users cu
                JOIN contacts c ON cu.contact_id = c.id
                JOIN streams s ON c.stream_id = s.id
                WHERE s.niche_id = ?
                AND cu.churned_at BETWEEN ? AND ?
            ");
            $stmt_month_churned_winback->execute([$niche_id, $month_start, $month_end]);
            $month_churned_for_winback = max(1, $stmt_month_churned_winback->fetchColumn() ?: 0);
            
            // Get average revenue and acquisition cost for active streams in this month
            $stmt_month_stream_avgs = $pdo->prepare("
                SELECT AVG(acquisition_cost) as acq_cost_month, AVG(revenue_per_user) as rev_per_user_month
                FROM streams
                WHERE niche_id = ?
                AND created_at <= ?
            ");
            $stmt_month_stream_avgs->execute([$niche_id, $month_end]);
            $month_stream_avgs = $stmt_month_stream_avgs->fetch(PDO::FETCH_ASSOC);

            $month_acq_cost = $month_stream_avgs['acq_cost_month'] ?: 0;
            $month_revenue = $month_stream_avgs['rev_per_user_month'] ?: 0;


            $history_acquisition_cost[] = round($month_acq_cost, 2);
            $history_revenue_per_user[] = round($month_revenue, 2);
            $history_churn_rate[] = round($month_churn, 1);
            $history_retention_rate[] = round(100 - $month_churn, 1);
            $history_winback_rate[] = round(($month_winback / $month_churned_for_winback) * 100, 1);
            $history_acquisition_rate[] = $month_acq;

            $current_month_iter->modify('+1 month');
            $idx++;
        }


        $niche_data = [
            'id' => $niche_id,
            'name' => $niche_name,
            'description' => $niche['description'],
            'created_at' => $niche_created_at->format('Y-m-d H:i:s'),
            'earliest_available_month' => $earliest_available_month,
            'metrics' => [
                'acquisition_cost' => ['value' => '$' . number_format($avg_acquisition_cost, 2)],
                'revenue_per_user' => ['value' => '$' . number_format($avg_revenue_per_user, 2)],
                'churn_rate' => ['value' => number_format($avg_churn_rate, 1) . '%'],
                'retention_rate' => ['value' => number_format($avg_retention_rate, 1) . '%'],
                'winback_rate' => ['value' => number_format($avg_winback_rate, 1) . '%'],
                'acquisition_rate' => ['value' => number_format($avg_acquisition_rate, 0)]
            ],
            'chart_data' => [
                'labels' => $chart_labels,
                'datasets' => [
                    [ 'label' => 'Churn Rate (%)', 'data' => $history_churn_rate, 'borderColor' => '#e53e3e', 'backgroundColor' => 'rgba(229, 62, 62, 0.1)', 'fill' => true, 'tension' => 0.4, 'yAxisID' => 'y' ],
                    [ 'label' => 'Retention Rate (%)', 'data' => $history_retention_rate, 'borderColor' => '#68d391', 'backgroundColor' => 'rgba(104, 211, 145, 0.1)', 'fill' => true, 'tension' => 0.4, 'yAxisID' => 'y' ],
                    [ 'label' => 'Revenue/User ($)', 'data' => $history_revenue_per_user, 'borderColor' => '#4299e1', 'backgroundColor' => 'rgba(66, 153, 225, 0.1)', 'fill' => true, 'tension' => 0.4, 'yAxisID' => 'y1' ],
                    [ 'label' => 'Acquisition Rate (Contacts)', 'data' => $history_acquisition_rate, 'borderColor' => '#f6ad55', 'backgroundColor' => 'rgba(246, 173, 85, 0.1)', 'fill' => true, 'tension' => 0.4, 'yAxisID' => 'y2' ],
                    [ 'label' => 'Acquisition Cost ($)', 'data' => $history_acquisition_cost, 'borderColor' => '#805ad5', 'backgroundColor' => 'rgba(128, 90, 213, 0.1)', 'fill' => true, 'tension' => 0.4, 'yAxisID' => 'y1' ] // Added Acquisition Cost
                ]
            ],
            'raw_metrics' => [
                'churn_rate' => $avg_churn_rate,
                'acquisition_rate' => $avg_acquisition_rate,
                'winback_rate' => $avg_winback_rate
            ]
        ];

        $niches_data[$niche_id] = $niche_data;

        // Categorize the niche
        // Note: These conditions will apply to the metrics calculated for the *default* 12-month period.
        if ($avg_churn_rate > 30) {
            $categories['declining'][] = $niche_data;
        } elseif ($avg_churn_rate < 15 && $avg_acquisition_rate > 50) {
            $categories['top_performing'][] = $niche_data;
        } elseif ($avg_churn_rate > 20 && $avg_acquisition_rate < 20) {
            $categories['underperforming'][] = $niche_data;
        } elseif ($avg_acquisition_rate > 30 && ($avg_acquisition_rate > ($avg_churn_rate * 2))) { // Added parentheses for clarity
            $categories['emerging'][] = $niche_data;
        }
    }
} catch (PDOException $e) {
    error_log("Database error in niches.php: " . $e->getMessage());
    $niches_data = [];
    $categories = [
        'top_performing' => [],
        'underperforming' => [],
        'emerging' => [],
        'declining' => []
    ];
}

// Sort categories
usort($categories['top_performing'], function($a, $b) {
    return $b['raw_metrics']['acquisition_rate'] <=> $a['raw_metrics']['acquisition_rate'];
});

usort($categories['underperforming'], function($a, $b) {
    return $b['raw_metrics']['churn_rate'] <=> $a['raw_metrics']['churn_rate'];
});

usort($categories['emerging'], function($a, $b) {
    return $b['raw_metrics']['acquisition_rate'] <=> $a['raw_metrics']['acquisition_rate'];
});

usort($categories['declining'], function($a, $b) {
    return $b['raw_metrics']['churn_rate'] <=> $a['raw_metrics']['churn_rate'];
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Niche Market Overview | Churn Analytics</title>
    <style>
        :root {
            --primary: #3ac3b8; --secondary: #4299e1; --danger: #e53e3e;
            --warning: #f6ad55; --success: #68d391; --info: #4299e1;
            --dark: #1a202c; --light: #f7fafc; --white: #ffffff;
            --gray-100: #f7fafc; --gray-200: #e2e8f0; --gray-300: #cbd5e0;
            --gray-400: #a0aec0; --gray-500: #718096; --gray-600: #4a5568;
            --gray-700: #2d3748; --gray-800: #1a202c; --gray-900: #171923;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0; background-color: var(--gray-100); color: var(--gray-800); line-height: 1.6;
        }
        .header {
            background-color: var(--dark); color: var(--white); padding: 15px 25px;
            display: flex; justify-content: space-between; align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header h1 { margin: 0; font-size: 1.8em; display: flex; align-items: center; }
        .header h1 a { color: var(--white); text-decoration: none; display: flex; align-items: center; }
        .header h1 img { height: 30px; margin-right: 8px; }
        .header h1 sup { font-size: 0.5em; vertical-align: super; margin-left: 2px; color: var(--primary); }
        .header nav a { color: var(--white); text-decoration: none; margin-left: 20px; font-weight: 500; transition: color 0.2s ease; }
        .header nav a:hover { color: var(--primary); }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .section-title { text-align: center; font-size: 2.5em; color: var(--dark); margin-bottom: 30px; }
        .category-title {
            font-size: 1.8em; color: var(--dark); margin-top: 40px; margin-bottom: 20px;
            padding-bottom: 10px; border-bottom: 2px solid var(--primary);
        }
        .category-description {
            font-size: 1em; color: var(--gray-600); margin-bottom: 20px;
            padding: 10px; background-color: var(--gray-200); border-radius: 6px;
        }
        .niches-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 30px; }
        .niche-card {
            background-color: var(--white); border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05);
            padding: 20px; display: flex; flex-direction: column; justify-content: space-between;
            transition: transform 0.2s ease, box-shadow 0.2s ease; position: relative;
        }
        .niche-card:hover { transform: translateY(-5px); box-shadow: 0 6px 20px rgba(0,0,0,0.1); }
        .niche-card h2 {
            color: var(--primary); font-size: 1.6em; margin-top: 0; margin-bottom: 10px;
            border-bottom: 1px solid var(--gray-200); padding-bottom: 8px;
        }
        .niche-card p.description {
            color: var(--gray-600); font-size: 0.9em; margin-bottom: 15px;
        }
        .niche-controls { display: flex; gap: 10px; margin-bottom: 15px; }
        .niche-controls .form-group { flex: 1; }
        .niche-controls label { font-size: 0.8em; color: var(--gray-500); margin-bottom: 2px; display: block;}
        .niche-controls select { width: 100%; padding: 6px; border: 1px solid var(--gray-300); border-radius: 6px; font-size: 0.9em; }
        .niche-metrics-summary { margin-bottom: 15px; }
        .niche-metric {
            display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;
            font-size: 0.95em; color: var(--gray-700); padding: 2px 0;
        }
        .niche-metric span:first-child { font-weight: 500; }
        .niche-metric span:last-child { font-weight: 600; color: var(--dark); }
        .niche-chart-container { position: relative; height: 180px; width: 100%; margin-top: 10px; }
        .loading-overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(255, 255, 255, 0.7);
            display: none; /* Hidden by default */
            justify-content: center; align-items: center;
            border-radius: 12px; z-index: 10; font-size: 1.2em; color: var(--primary);
        }
        .loading-overlay.visible { display: flex; }
        .no-data-message { text-align: center; color: var(--gray-500); padding: 50px 0; font-style: italic; }
        .pagination {
            display: flex; justify-content: center; margin-top: 30px; margin-bottom: 50px;
        }
        .pagination a, .pagination span {
            padding: 8px 16px; margin: 0 4px; border-radius: 4px;
            text-decoration: none; color: var(--dark); border: 1px solid var(--gray-300);
        }
        .pagination a:hover {
            background-color: var(--primary); color: var(--white); border-color: var(--primary);
        }
        .pagination .current {
            background-color: var(--primary); color: var(--white); border-color: var(--primary);
        }
        .category-toggle {
            display: flex; justify-content: center; margin-bottom: 20px; flex-wrap: wrap;
        }
        .category-toggle button {
            padding: 8px 16px; margin: 0 5px; border-radius: 20px; border: none;
            background-color: var(--gray-200); color: var(--dark); cursor: pointer;
            transition: all 0.2s ease; font-weight: 500;
        }
        .category-toggle button:hover {
            background-color: var(--gray-300);
        }
        .category-toggle button.active {
            background-color: var(--primary); color: var(--white);
        }
        @media (max-width: 768px) {
            .header { flex-direction: column; align-items: flex-start; }
            .header nav { margin-top: 10px; }
            .header nav a { margin-left: 0; margin-right: 15px; }
            .niches-grid { grid-template-columns: 1fr; }
            .category-toggle button { margin-bottom: 5px; }
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="header">
        <h1>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/index.php">
                <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/images/logo.png" alt="Churn Analytics Logo">
                <sup>Niches</sup>
            </a>
        </h1>
        <nav>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/documentation">API</a>
            <a href="<?= htmlspecialchars(BASE_URL) ?>/dashboard.php">Dashboard</a>
        </nav>
    </div>

    <div class="container">
        <h2 class="section-title">Niche Market Performance Overview</h2>

        <div class="category-toggle">
            <button class="active" data-category="all">All Niches</button>
            <button data-category="top_performing">Top Performing</button>
            <button data-category="underperforming">Underperforming</button>
            <button data-category="emerging">Emerging</button>
            <button data-category="declining">Declining</button>
        </div>

        <?php if (!empty($niches_data)): ?>
            <div class="category-section" id="category-all">
                <div class="niches-grid">
                    <?php foreach ($niches_data as $niche): ?>
                        <div class="niche-card" id="niche-card-<?= $niche['id'] ?>" data-niche-id="<?= $niche['id'] ?>" data-earliest-month="<?= htmlspecialchars($niche['earliest_available_month']) ?>">
                            <div class="loading-overlay"><span>Loading...</span></div>
                            <h2><?= $niche['name'] ?></h2>
                            <?php if (!empty($niche['description'])): ?>
                                <p class="description"><?= htmlspecialchars($niche['description']) ?></p>
                            <?php endif; ?>
                            
                            <div class="niche-controls">
                                <div class="form-group">
                                    <label for="start_month_<?= $niche['id'] ?>">Start Month</label>
                                    <select name="start_month_year" id="start_month_<?= $niche['id'] ?>" class="chart-date-select-start">
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="end_month_<?= $niche['id'] ?>">End Month</label>
                                    <select name="end_month_year" id="end_month_<?= $niche['id'] ?>" class="chart-date-select-end">
                                    </select>
                                </div>
                            </div>

                            <div class="niche-metrics-summary">
                                <?php foreach ($niche['metrics'] as $key => $metric): ?>
                                    <div class="niche-metric" data-metric-key="<?= $key ?>">
                                        <span>Avg. <?= ucwords(str_replace('_', ' ', $key)) ?></span>
                                        <span class="metric-value"><?= is_array($metric) ? $metric['value'] : $metric ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="niche-chart-container">
                                <canvas id="nicheChart_<?= $niche['id'] ?>" data-chart-data="<?= htmlspecialchars(json_encode($niche['chart_data'])) ?>"></canvas>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($categories as $category => $items): ?>
                <?php if (!empty($items)): ?>
                    <div class="category-section" id="category-<?= $category ?>" style="display:none;">
                        <h3 class="category-title">
                            <?= ucwords(str_replace('_', ' ', $category)) ?> Niches
                        </h3>
                        <p class="category-description">
                            <?php
                            switch($category) {
                                case 'top_performing':
                                    echo "Niches with low churn rates (<15%) and high acquisition rates (>50 new contacts/month)";
                                    break;
                                case 'underperforming':
                                    echo "Niches with high churn rates (>20%) and low acquisition rates (<20 new contacts/month)";
                                    break;
                                case 'emerging':
                                    echo "Niches with growing acquisition rates (>30/month) that are at least double their churn rate";
                                    break;
                                case 'declining':
                                    echo "Niches experiencing significant churn (>30%) that may need intervention";
                                    break;
                                default:
                                    echo "";
                            }
                            ?>
                        </p>
                        <div class="niches-grid">
                            <?php foreach ($items as $niche): ?>
                                <div class="niche-card" id="niche-card-<?= $niche['id'] ?>-<?= $category ?>" data-niche-id="<?= $niche['id'] ?>" data-earliest-month="<?= htmlspecialchars($niche['earliest_available_month']) ?>">
                                    <div class="loading-overlay"><span>Loading...</span></div>
                                    <h2><?= $niche['name'] ?></h2>
                                    <?php if (!empty($niche['description'])): ?>
                                        <p class="description"><?= htmlspecialchars($niche['description']) ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="niche-controls">
                                        <div class="form-group">
                                            <label for="start_month_<?= $niche['id'] ?>_<?= $category ?>">Start Month</label>
                                            <select name="start_month_year" id="start_month_<?= $niche['id'] ?>_<?= $category ?>" class="chart-date-select-start">
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label for="end_month_<?= $niche['id'] ?>_<?= $category ?>">End Month</label>
                                            <select name="end_month_year" id="end_month_<?= $niche['id'] ?>_<?= $category ?>" class="chart-date-select-end">
                                            </select>
                                        </div>
                                    </div>

                                    <div class="niche-metrics-summary">
                                        <?php foreach ($niche['metrics'] as $key => $metric): ?>
                                            <div class="niche-metric" data-metric-key="<?= $key ?>">
                                                <span>Avg. <?= ucwords(str_replace('_', ' ', $key)) ?></span>
                                                <span class="metric-value"><?= is_array($metric) ? $metric['value'] : $metric ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    
                                    <div class="niche-chart-container">
                                        <canvas id="nicheChart_<?= $niche['id'] ?>_<?= $category ?>" data-chart-data="<?= htmlspecialchars(json_encode($niche['chart_data'])) ?>"></canvas>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($current_page > 1): ?>
                        <a href="?page=<?= $current_page - 1 ?>">&laquo; Previous</a>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $current_page): ?>
                            <span class="current"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($current_page < $total_pages): ?>
                        <a href="?page=<?= $current_page + 1 ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="no-data-message">No niche data available. Please add niches to view statistics.</p>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const chartInstances = {}; // Store chart instances for all niches (main view)
        const categoryCharts = {}; // Store chart instances for categorized niches

        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true, position: 'bottom',
                    labels: { boxWidth: 10, padding: 10, font: { size: 10 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) { label += ': '; }
                            if (context.parsed.y !== null) {
                                let value = context.parsed.y.toFixed(1);
                                if (label.includes('(%)')) label += value + '%';
                                else if (label.includes('($)')) label += '$' + value;
                                else label += context.parsed.y.toFixed(0);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 } }
                },
                y: { // Primary Y-axis for %
                    type: 'linear', position: 'left',
                    title: { display: true, text: '%', font: { size: 10 } },
                    beginAtZero: true, max: 100,
                    grid: { color: 'rgba(0, 0, 0, 0.05)' },
                    ticks: { font: { size: 10 }, callback: value => value + '%' }
                },
                y1: { // Secondary Y-axis for Revenue & Acquisition Cost
                    type: 'linear', position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: '$', font: { size: 10 } },
                    beginAtZero: true,
                    ticks: { font: { size: 10 }, callback: value => '$' + value }
                },
                y2: { // Third Y-axis for Contacts
                    type: 'linear', position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Contacts', font: { size: 10 } },
                    beginAtZero: true,
                    ticks: { font: { size: 10 }, precision: 0 }
                }
            }
        };

        // Function to initialize a single niche card with its chart and date selectors
        function initNicheCard(cardElement, chartStore) {
            const nicheId = cardElement.dataset.nicheId;
            const earliestMonth = cardElement.dataset.earliestMonth;
            const startMonthSelect = cardElement.querySelector('.chart-date-select-start');
            const endMonthSelect = cardElement.querySelector('.chart-date-select-end');
            const canvas = cardElement.querySelector('canvas');
            const initialChartData = JSON.parse(canvas.dataset.chartData);
            
            // 1. Populate Date Selectors for this card
            populateMonthSelectors(startMonthSelect, endMonthSelect, earliestMonth);

            // 2. Initialize Chart for this card
            const ctx = canvas.getContext('2d');
            chartStore[nicheId] = new Chart(ctx, {
                type: 'line',
                data: initialChartData,
                options: chartOptions
            });

            // 3. Add Event Listeners for date changes
            [startMonthSelect, endMonthSelect].forEach(select => {
                select.addEventListener('change', () => {
                    handleDateChange(nicheId, cardElement, chartStore);
                });
            });
        }

        // Function to populate month/year dropdowns
        function populateMonthSelectors(startSelect, endSelect, earliestMonthStr) {
            const options = [];
            // Parse earliestMonthStr correctly as UTC to avoid timezone issues affecting month
            const earliestDate = new Date(earliestMonthStr + '-01T12:00:00Z');
            const now = new Date(); // Current date for comparison

            // Create a copy of earliestDate to iterate
            let currentDate = new Date(earliestDate.getUTCFullYear(), earliestDate.getUTCMonth(), 1);

            while (currentDate <= now) {
                const year = currentDate.getFullYear();
                const month = currentDate.getMonth() + 1; // getMonth() is 0-indexed
                const monthYearValue = year + '-' + String(month).padStart(2, '0');
                const monthYearLabel = currentDate.toLocaleString('default', { month: 'short', year: 'numeric' });
                options.push(`<option value="${monthYearValue}">${monthYearLabel}</option>`);
                currentDate.setMonth(currentDate.getMonth() + 1); // Move to the next month
            }
            
            startSelect.innerHTML = options.join('');
            endSelect.innerHTML = options.join('');
            
            // Set defaults (last 12 months or since niche creation if less than 12 months)
            const defaultEndDate = new Date();
            const defaultStartDate = new Date();
            defaultStartDate.setMonth(defaultStartDate.getMonth() - 11);

            // Ensure default start date isn't before earliest available date
            if (defaultStartDate < earliestDate) {
                defaultStartDate.setTime(earliestDate.getTime());
            }

            const defaultStartValue = defaultStartDate.getFullYear() + '-' + String(defaultStartDate.getMonth() + 1).padStart(2, '0');
            const defaultEndValue = defaultEndDate.getFullYear() + '-' + String(defaultEndDate.getMonth() + 1).padStart(2, '0');

            startSelect.value = defaultStartValue;
            endSelect.value = defaultEndValue;
        }

        // Function to handle AJAX data update for a niche card
        async function handleDateChange(nicheId, cardElement, chartStore) {
            const startMonth = cardElement.querySelector('.chart-date-select-start').value;
            const endMonth = cardElement.querySelector('.chart-date-select-end').value;

            // Basic date validation
            if (new Date(endMonth) < new Date(startMonth)) {
                alert('End date cannot be before start date. Please adjust the end date.');
                // Optionally reset endMonthSelect to startMonth or another valid value
                cardElement.querySelector('.chart-date-select-end').value = startMonth;
                return; // Stop execution if dates are invalid
            }

            const loadingOverlay = cardElement.querySelector('.loading-overlay');
            loadingOverlay.classList.add('visible');

            try {
                // Fetch data from the new AJAX endpoint (get_niche_data.php)
                const response = await fetch(`get_niche_data.php?niche_id=${nicheId}&start_month=${startMonth}&end_month=${endMonth}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                if (data.error) {
                    throw new Error(data.error);
                }

                // Update metrics summary
                for (const [key, metric] of Object.entries(data.metrics)) {
                    const metricEl = cardElement.querySelector(`.niche-metric[data-metric-key="${key}"] .metric-value`);
                    if (metricEl) {
                        metricEl.textContent = metric.value;
                    }
                }

                // Update chart
                const chart = chartStore[nicheId];
                if (chart) {
                    chart.data.labels = data.chart_data.labels;
                    // Update datasets: assuming order and labels match between old and new data
                    // Ensure you handle cases where datasets might change or be added/removed dynamically
                    data.chart_data.datasets.forEach(newDataset => {
                        const existingDatasetIndex = chart.data.datasets.findIndex(ds => ds.label === newDataset.label);
                        if (existingDatasetIndex !== -1) {
                            chart.data.datasets[existingDatasetIndex].data = newDataset.data;
                        } else {
                            // If a new dataset is returned (e.g., Acquisition Cost was added), add it
                            chart.data.datasets.push(newDataset);
                        }
                    });
                    // Remove datasets that might no longer be in the new data (if necessary, though less common here)
                    chart.data.datasets = chart.data.datasets.filter(existingDs =>
                        data.chart_data.datasets.some(newDs => newDs.label === existingDs.label)
                    );

                    chart.update();
                }

            } catch (error) {
                console.error('Failed to update niche data:', error);
                alert('Could not update the chart for this niche. Please check the console for details.');
            } finally {
                loadingOverlay.classList.remove('visible');
            }
        }

        // Initialize all niche cards in the "All Niches" section on page load
        document.querySelectorAll('#category-all .niche-card').forEach(card => {
            initNicheCard(card, chartInstances);
        });

        // Category toggle functionality
        const categoryButtons = document.querySelectorAll('.category-toggle button');
        const categorySections = document.querySelectorAll('.category-section');

        categoryButtons.forEach(button => {
            button.addEventListener('click', () => {
                const category = button.dataset.category;
                
                // Update button states
                categoryButtons.forEach(btn => btn.classList.remove('active'));
                button.classList.add('active');
                
                // Show/hide sections
                categorySections.forEach(section => {
                    if (section.id === `category-${category}` || (category === 'all' && section.id === 'category-all')) {
                        section.style.display = 'block';
                        // If charts are in this newly visible section, initialize them now if not already
                        section.querySelectorAll('.niche-card').forEach(card => {
                            const nicheId = card.dataset.nicheId;
                            // Determine which chart store to use (main or category-specific)
                            const currentChartStore = (section.id === 'category-all') ? chartInstances : (categoryCharts[category] = categoryCharts[category] || {});

                            if (!currentChartStore[nicheId]) { // Check if chart already initialized
                                initNicheCard(card, currentChartStore);
                            } else {
                                // If already initialized but was hidden, ensure it's redrawn if needed
                                currentChartStore[nicheId].resize(); // Important for charts in hidden elements
                                currentChartStore[nicheId].update();
                            }
                        });
                    } else {
                        section.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>
</body>
</html>