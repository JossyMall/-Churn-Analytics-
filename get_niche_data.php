<?php
// get_niche_data.php
// This file handles AJAX requests for niche-specific data based on date ranges.

session_start();
// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'User not authenticated.']);
    exit;
}

require_once 'includes/db.php'; // Adjust path if necessary
require_once 'includes/functions.php'; // For utility functions if any are needed here

// Set header for JSON response
header('Content-Type: application/json');

// !!! IMPORTANT DEBUGGING LINES - Keep for development !!!
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Do not display errors to user, log them
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_ajax_errors.log'); // Log errors to a specific file
// !!! END IMPORTANT DEBUGGING LINES !!!


$niche_id = isset($_GET['niche_id']) ? intval($_GET['niche_id']) : 0;
$start_month_year = isset($_GET['start_month']) ? $_GET['start_month'] : null;
$end_month_year = isset($_GET['end_month']) ? $_GET['end_month'] : null;

// Basic validation
if ($niche_id <= 0 || !$start_month_year || !$end_month_year) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid parameters provided.']);
    exit;
}

try {
    $start_dt = new DateTime($start_month_year . '-01');
    $end_dt = new DateTime($end_month_year . '-01');
    $end_dt->modify('last day of this month');

    // Fetch niche details to get creation date if needed for earliest data point
    $stmt_niche_info = $pdo->prepare("SELECT created_at FROM niches WHERE id = ? AND is_active = 1");
    $stmt_niche_info->execute([$niche_id]);
    $niche_info = $stmt_niche_info->fetch(PDO::FETCH_ASSOC);

    if (!$niche_info) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Niche not found or inactive.']);
        exit;
    }

    $niche_created_at = new DateTime($niche_info['created_at']);

    // Determine earliest possible month for this niche based on stream creation dates
    $stmt_earliest_stream = $pdo->prepare("
        SELECT MIN(created_at)
        FROM streams
        WHERE niche_id = ? AND created_at IS NOT NULL
    ");
    $stmt_earliest_stream->execute([$niche_id]);
    $earliest_stream_date_str = $stmt_earliest_stream->fetchColumn();

    $earliest_available_month_obj = $niche_created_at;
    if ($earliest_stream_date_str) {
        $stream_creation_date = new DateTime($earliest_stream_date_str);
        $earliest_available_month_obj = max($niche_created_at, $stream_creation_date);
    }

    // Adjust start_dt if it's before the earliest available month for this niche
    if ($start_dt < $earliest_available_month_obj) {
        $start_dt_for_data_fetch = $earliest_available_month_obj;
    } else {
        $start_dt_for_data_fetch = $start_dt;
    }

    $end_dt_for_data_fetch = $end_dt;


    // --- Calculate CURRENT AGGREGATE METRICS for the selected period ---

    // Avg Acquisition Cost & Revenue Per User (These are generally stable for a niche, but can be averaged over period)
    $stmt_streams = $pdo->prepare("
        SELECT AVG(acquisition_cost) as avg_acq_cost, AVG(revenue_per_user) as avg_rev_per_user
        FROM streams
        WHERE niche_id = ?
        AND created_at <= ? -- Consider streams active/created up to the end of the period
    ");
    $stmt_streams->execute([$niche_id, $end_dt_for_data_fetch->format('Y-m-d H:i:s')]);
    $stream_averages = $stmt_streams->fetch(PDO::FETCH_ASSOC);

    $avg_acquisition_cost = $stream_averages['avg_acq_cost'] ?: 0;
    $avg_revenue_per_user = $stream_averages['avg_rev_per_user'] ?: 0;

    // Calculate average churn rate for this niche within the period
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
        $start_dt_for_data_fetch->format('Y-m-d H:i:s'),
        $end_dt_for_data_fetch->format('Y-m-d H:i:s')
    ]);
    $avg_churn_rate = $stmt_avg_churn->fetchColumn() ?: 0;
    $avg_retention_rate = 100 - $avg_churn_rate;

    // Calculate acquisition rate (new contacts per month) within the period
    $stmt_acquisition = $pdo->prepare("
        SELECT COUNT(*)
        FROM contacts c
        JOIN streams s ON c.stream_id = s.id
        WHERE s.niche_id = ?
        AND c.created_at BETWEEN ? AND ?
    ");
    $stmt_acquisition->execute([
        $niche_id,
        $start_dt_for_data_fetch->format('Y-m-d H:i:s'),
        $end_dt_for_data_fetch->format('Y-m-d H:i:s')
    ]);
    $total_acquisitions = $stmt_acquisition->fetchColumn() ?: 0;

    // Calculate number of months in the requested period for accurate average calculation
    $interval = DateInterval::createFromDateString('1 month');
    $period = new DatePeriod($start_dt_for_data_fetch, $interval, $end_dt_for_data_fetch->modify('+1 day')); // +1 day to make end date inclusive
    $months_in_period_count = 0;
    foreach ($period as $dt) {
        $months_in_period_count++;
    }
    // Correct for the last month modification for period end
    $end_dt_for_data_fetch->modify('-1 day');

    $avg_acquisition_rate = $months_in_period_count > 0 ? round($total_acquisitions / $months_in_period_count) : 0;

    // Calculate winback rate within the period
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
        $start_dt_for_data_fetch->format('Y-m-d H:i:s'),
        $end_dt_for_data_fetch->format('Y-m-d H:i:s')
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
        $start_dt_for_data_fetch->format('Y-m-d H:i:s'),
        $end_dt_for_data_fetch->format('Y-m-d H:i:s')
    ]);
    $churned_count_for_winback = max(1, $stmt_churned_for_winback_calc->fetchColumn() ?: 0); // Avoid division by zero

    $avg_winback_rate = round(($winback_count / $churned_count_for_winback) * 100, 1);


    // --- Generate HISTORICAL DATA for the chart (monthly values) ---
    $chart_labels = [];
    $history_acquisition_cost = [];
    $history_revenue_per_user = [];
    $history_churn_rate = [];
    $history_retention_rate = [];
    $history_winback_rate = [];
    $history_acquisition_rate = [];

    $current_month_iter = clone $start_dt_for_data_fetch;

    while ($current_month_iter <= $end_dt_for_data_fetch) {
        $month_start = $current_month_iter->format('Y-m-01 00:00:00');
        $month_end = $current_month_iter->format('Y-m-t 23:59:59');

        $chart_labels[] = $current_month_iter->format('M Y');

        // Churn rate for this specific month
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

        // Acquisitions for this specific month
        $stmt_month_acq = $pdo->prepare("
            SELECT COUNT(*)
            FROM contacts c
            JOIN streams s ON c.stream_id = s.id
            WHERE s.niche_id = ?
            AND c.created_at BETWEEN ? AND ?
        ");
        $stmt_month_acq->execute([$niche_id, $month_start, $month_end]);
        $month_acq = $stmt_month_acq->fetchColumn() ?: 0;

        // Winbacks for this specific month
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

        // Churned count for winback rate calculation for this specific month
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

        // For historical acquisition cost and revenue per user, you'd need to calculate averages
        // based on streams active/created in that specific month. For simplicity, we'll
        // re-fetch the stream averages for the current iteration month.
        $stmt_month_stream_avgs = $pdo->prepare("
            SELECT AVG(acquisition_cost) as acq_cost_month, AVG(revenue_per_user) as rev_per_user_month
            FROM streams
            WHERE niche_id = ?
            AND created_at <= ? -- Consider streams active/created up to this month
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
    }

    $response_data = [
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
        ]
    ];

    echo json_encode($response_data);

} catch (PDOException $e) {
    error_log("Database error in get_niche_data.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Database error occurred.']);
} catch (Exception $e) {
    error_log("General error in get_niche_data.php: " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An unexpected error occurred.']);
}
exit;
?>