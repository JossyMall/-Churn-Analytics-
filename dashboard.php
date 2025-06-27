<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
// Assuming header.php handles initial HTML setup like <head> tags,
// but the main <body> and content structure will be controlled here for consistency.
require_once 'includes/header.php';

$current_user_id = $_SESSION['user_id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$current_user_id]);
$user = $stmt->fetch();

// --- Stats Grid Data ---
// Total Active Streams
$stmt = $pdo->prepare("SELECT COUNT(*) FROM streams WHERE user_id = ?");
$stmt->execute([$current_user_id]);
$streams_count = $stmt->fetchColumn();

// Total Contacts
$stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE stream_id IN (SELECT id FROM streams WHERE user_id = ?)");
$stmt->execute([$current_user_id]);
$contacts_count = $stmt->fetchColumn();

// High Risk Users (contacts with churn score > 70 for simplicity, adjust threshold as needed)
$stmt = $pdo->prepare("
    SELECT c.id, c.username, c.email, cs.score
    FROM contacts c
    JOIN churn_scores cs ON c.id = cs.contact_id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND cs.score > 70  -- Example threshold for high risk
    ORDER BY cs.score DESC, cs.scored_at DESC
    LIMIT 5
");
$stmt->execute([$current_user_id]);
$high_risk_users = $stmt->fetchAll();

// Churn Index for the current user for the most recent month
$stmt = $pdo->prepare("
    SELECT index_value FROM churn_index
    WHERE user_id = ?
    ORDER BY month DESC
    LIMIT 1
");
$stmt->execute([$current_user_id]);
$churn_index = $stmt->fetchColumn() ?? 0.0; // Default to 0.0 if no data

// Recovered Users (from resurrected_users table, last 7 days)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM resurrected_users ru
    JOIN contacts c ON ru.contact_id = c.id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND ru.resurrected_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->execute([$current_user_id]);
$recovered_this_week = $stmt->fetchColumn();

// Low Risk Accounts (contacts with churn score <= 40 for simplicity, last 30 days)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM contacts c
    JOIN churn_scores cs ON c.id = cs.contact_id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND cs.score <= 40
    AND cs.scored_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$current_user_id]);
$low_risk_accounts = $stmt->fetchColumn();

// Revenue at Risk (sum of revenue_impact from churned_users related to user's streams, last 30 days)
$stmt = $pdo->prepare("
    SELECT SUM(cu.revenue_impact) FROM churned_users cu
    JOIN contacts c ON cu.contact_id = c.id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND cu.churned_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$stmt->execute([$current_user_id]);
$revenue_at_risk = $stmt->fetchColumn() ?? 0;


// --- Monthly Churn Rate Chart Data ---
$monthly_churn_data = [];
// Fetch churn scores aggregated by month for the last 7 months
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(cs.scored_at, '%Y-%m') AS month_key, AVG(cs.score) AS avg_score
    FROM churn_scores cs
    JOIN contacts c ON cs.contact_id = c.id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND cs.scored_at >= DATE_SUB(NOW(), INTERVAL 7 MONTH)
    GROUP BY month_key
    ORDER BY month_key ASC
");
$stmt->execute([$current_user_id]);
$raw_churn_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_churn_labels = [];
$monthly_churn_values = [];
$period = new DatePeriod(
    new DateTime('-6 months first day of this month'), // Start 6 months ago from the first day of current month
    new DateInterval('P1M'),
    new DateTime('first day of next month') // End on the first day of next month to include current month
);

foreach ($period as $dt) {
    $month_label = $dt->format('M Y');
    $monthly_churn_labels[] = $month_label;
    $found = false;
    foreach ($raw_churn_data as $row) {
        if ($row['month_key'] === $dt->format('Y-m')) {
            $monthly_churn_values[] = round($row['avg_score'], 1);
            $found = true;
            break;
        }
    }
    if (!$found) {
        $monthly_churn_values[] = 0; // No data for this month
    }
}


// --- Risk Distribution Chart Data ---
// Calculate risk distribution based on churn_scores
$risk_distribution_values = [0, 0, 0]; // High, Medium, Low
$total_scored_contacts = 0;
$stmt = $pdo->prepare("
    SELECT cs.score
    FROM churn_scores cs
    JOIN contacts c ON cs.contact_id = c.id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND cs.scored_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) -- Consider recent scores
");
$stmt->execute([$current_user_id]);
$all_scores = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($all_scores as $score) {
    $total_scored_contacts++;
    if ($score > 70) { // High Risk
        $risk_distribution_values[0]++;
    } elseif ($score > 40) { // Medium Risk
        $risk_distribution_values[1]++;
    } else { // Low Risk
        $risk_distribution_values[2]++;
    }
}


// --- Feature Engagement Chart Data (Sparklines for each feature) ---
$feature_engagement_data = [];
// Fetch features created by the user's streams
$stmt = $pdo->prepare("
    SELECT f.id, f.name
    FROM features f
    JOIN streams s ON f.stream_id = s.id
    WHERE s.user_id = ?
");
$stmt->execute([$current_user_id]);
$features_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($features_raw as $feature) {
    $feature_id = $feature['id'];
    $feature_name = $feature['name'];

    // Get engagement data (count of 'feature_usage' metric for this feature over last 7 days)
    // We assume `metric_data.value` stores the feature `name` for 'feature_usage' metric.
    $stmt = $pdo->prepare("
        SELECT COUNT(md.id) as usage_count, DATE_FORMAT(md.recorded_at, '%Y-%m-%d') as day
        FROM metric_data md
        WHERE md.metric_id = (SELECT id FROM churn_metrics WHERE name = 'feature_usage' LIMIT 1)
        AND md.value = ?
        AND md.contact_id IN (SELECT id FROM contacts WHERE stream_id IN (SELECT id FROM streams WHERE user_id = ?))
        AND md.recorded_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY day
        ORDER BY day ASC
    ");
    $stmt->execute([$feature_name, $current_user_id]);
    $raw_feature_usage = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sparkline_data = [];
    $today = new DateTime();
    for ($i = 6; $i >= 0; $i--) { // Last 7 days
        $date = (clone $today)->modify("-$i days")->format('Y-m-d');
        $found = false;
        foreach ($raw_feature_usage as $row) {
            if ($row['day'] === $date) {
                $sparkline_data[] = (int)$row['usage_count'];
                $found = true;
                break;
            }
        }
        if (!$found) {
            $sparkline_data[] = 0; // No data for this day
        }
    }

    $feature_engagement_data[] = [
        'name' => $feature_name,
        'data' => $sparkline_data,
        'total_usage' => array_sum($sparkline_data)
    ];
}

// Sort features by total usage (most used first)
usort($feature_engagement_data, function($a, $b) {
    return $b['total_usage'] <=> $a['total_usage'];
});


// --- Contacts Activity Map Data (Github-like heatmap) ---
// This will represent daily activity across all contacts for the user's streams
// Activity is defined as any 'metric_data' entry for contacts belonging to the user's streams.
$activity_data_map = []; // YYYY-MM-DD => count of activities
$stmt = $pdo->prepare("
    SELECT DATE_FORMAT(md.recorded_at, '%Y-%m-%d') AS activity_date, COUNT(md.id) AS daily_activity_count
    FROM metric_data md
    JOIN contacts c ON md.contact_id = c.id
    WHERE c.stream_id IN (SELECT id FROM streams WHERE user_id = ?)
    AND md.recorded_at >= DATE_SUB(NOW(), INTERVAL 365 DAY) -- Last 1 year for heatmap
    GROUP BY activity_date
    ORDER BY activity_date ASC
");
$stmt->execute([$current_user_id]);
$raw_activity_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // 'activity_date' => 'daily_activity_count'

$start_date_activity = new DateTime('-365 days');
$end_date_activity = new DateTime('now');
$interval_activity = new DateInterval('P1D');
$date_range_activity = new DatePeriod($start_date_activity, $interval_activity, $end_date_activity->modify('+1 day')); // Include today

$all_activity_counts = [];
foreach ($date_range_activity as $date) {
    $date_str = $date->format('Y-m-d');
    $count = $raw_activity_data[$date_str] ?? 0;
    $activity_data_map[$date_str] = $count;
    $all_activity_counts[] = $count;
}

// Determine max activity for color scaling, ensuring it's at least 1 to avoid division by zero
$max_activity = max($all_activity_counts) > 0 ? max($all_activity_counts) : 1;


// --- Experiment Metrics (for "metric cards" section) ---
$experiment_metrics_data = [];
$stmt = $pdo->prepare("
    SELECT e.id AS experiment_id, e.name AS experiment_name, es.channel_type, es.source_type, es.source_id, es.specific_value
    FROM experiments e
    JOIN experiment_sources es ON e.id = es.experiment_id
    WHERE e.user_id = ?
    ORDER BY e.created_at DESC
    LIMIT 3 -- Show top 3 recent experiments on dashboard
");
$stmt->execute([$current_user_id]);
$recent_experiments = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Calculates a confidence level for experiment results.
 * @param array $contactIds Array of contact IDs involved in the experiment.
 * @param int $userId The ID of the current logged-in user.
 * @param PDO $pdo The PDO database connection object.
 * @return float Confidence level between 0 and 100.
 */
function calculateConfidenceLevel($contactIds, $userId, $pdo) {
    if (empty($contactIds)) return 0;

    $totalContacts = count($contactIds);
    $placeholders = implode(',', array_fill(0, count($contactIds), '?'));

    // Get data consistency (variance in churn scores)
    // Using COALESCE for STDDEV and AVG to handle cases where no scores exist, returning 0
    $stmt = $pdo->prepare("
        SELECT COALESCE(STDDEV(score), 0) as std_dev, COALESCE(AVG(score), 0) as avg_score
        FROM churn_scores
        WHERE contact_id IN ($placeholders)
    ");
    $stmt->execute($contactIds);
    $consistencyData = $stmt->fetch(PDO::FETCH_ASSOC);
    $stdDev = $consistencyData['std_dev'];
    $avgScore = $consistencyData['avg_score'];

    // Consistency score: lower std dev = higher consistency. Max 100 if no std dev.
    $consistencyScore = $avgScore > 0 ? max(0, 100 - (($stdDev / $avgScore) * 100)) : 100;

    // Get duration of experiment (days since first data point)
    $stmt = $pdo->prepare("
        SELECT DATEDIFF(NOW(), MIN(scored_at)) as days_running
        FROM churn_scores
        WHERE contact_id IN ($placeholders)
    ");
    $stmt->execute($contactIds);
    $daysRunning = $stmt->fetchColumn() ?? 0;
    $durationScore = min(100, $daysRunning); // Cap at 100 days for max score impact

    // Get completeness of data (% of contacts with recent scores, e.g., last 7 days)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT contact_id) as recent_contacts
        FROM churn_scores
        WHERE contact_id IN ($placeholders)
        AND scored_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    $stmt->execute($contactIds);
    $recentContacts = $stmt->fetchColumn() ?? 0;
    $completenessScore = $totalContacts > 0 ? ($recentContacts / $totalContacts) * 100 : 0;

    // Calculate weighted confidence score
    $sampleSizeScore = min(100, ($totalContacts / 1000) * 100); // Cap at 1000 contacts for max score impact
    $confidenceScore = (
        ($sampleSizeScore * 0.4) +
        ($consistencyScore * 0.3) +
        ($durationScore * 0.2) +
        ($completenessScore * 0.1)
    );

    return round(min(100, max(0, $confidenceScore)), 1); // Ensure between 0-100 and round to 1 decimal
}

foreach ($recent_experiments as $experiment) {
    $experiment_id = $experiment['experiment_id'];
    $source_type = $experiment['source_type'];
    $source_entity_id = $experiment['source_id'];

    $contacts_for_experiment = [];
    if ($source_type === 'stream') {
        $stmt = $pdo->prepare("SELECT id FROM contacts WHERE stream_id = ?");
        $stmt->execute([$source_entity_id]);
        $contacts_for_experiment = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($source_type === 'cohort') {
        $stmt = $pdo->prepare("SELECT contact_id FROM contact_cohorts WHERE cohort_id = ?");
        $stmt->execute([$source_entity_id]);
        $contacts_for_experiment = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($source_type === 'contact') {
        $contacts_for_experiment = [$source_entity_id];
    }

    if (empty($contacts_for_experiment)) {
        continue;
    }

    $placeholders_contacts = implode(',', array_fill(0, count($contacts_for_experiment), '?'));

    // Fetch current churn score
    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(cs.score), 0) AS avg_score
        FROM churn_scores cs
        WHERE cs.contact_id IN ($placeholders_contacts)
        AND cs.scored_at = (SELECT MAX(scored_at) FROM churn_scores WHERE contact_id IN ($placeholders_contacts))
    ");
    $stmt->execute(array_merge($contacts_for_experiment, $contacts_for_experiment)); // Pass contacts twice for IN clause
    $current_churn = $stmt->fetchColumn() ?? 0;

    // Fetch previous churn score (e.g., average of scores recorded in the previous month)
    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(cs.score), 0) AS avg_score
        FROM churn_scores cs
        WHERE cs.contact_id IN ($placeholders_contacts)
        AND cs.scored_at BETWEEN DATE_SUB(NOW(), INTERVAL 2 MONTH) AND DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ");
    $stmt->execute($contacts_for_experiment);
    $previous_churn = $stmt->fetchColumn() ?? $current_churn;

    $churn_change = $current_churn - $previous_churn;

    // Fetch churn trend for sparkline (last 10 data points/weeks/months, or specific dates if available)
    // For simplicity, let's fetch the last 10 available average scores.
    $stmt = $pdo->prepare("
        SELECT COALESCE(AVG(cs.score), 0) AS avg_score, DATE_FORMAT(cs.scored_at, '%Y-%m-%d') AS date_key
        FROM churn_scores cs
        WHERE cs.contact_id IN ($placeholders_contacts)
        GROUP BY date_key
        ORDER BY date_key DESC
        LIMIT 10
    ");
    $stmt->execute($contacts_for_experiment);
    $raw_trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $churn_trend_values = array_reverse(array_column($raw_trend_data, 'avg_score'));
    if (empty($churn_trend_values)) $churn_trend_values = [0]; // Ensure at least one value for sparkline

    $confidence_level = calculateConfidenceLevel($contacts_for_experiment, $current_user_id, $pdo);

    $experiment_metrics_data[] = [
        'id' => $experiment_id,
        'name' => $experiment['experiment_name'],
        'channel_type' => $experiment['channel_type'],
        'specific_value' => $experiment['specific_value'],
        'current_churn' => $current_churn,
        'churn_change' => $churn_change,
        'churn_trend' => $churn_trend_values,
        'confidence_level' => $confidence_level
    ];
}

function getExperimentColor($experimentId) {
    // Generate consistent colors based on experiment ID
    $colors = [
        '#3ac3b8', '#4299e1', '#ed64a6', '#9f7aea', '#f6ad55',
        '#68d391', '#f687b3', '#63b3ed', '#f6e05e', '#81e6d9'
    ];
    return $colors[$experimentId % count($colors)];
}

function getConfidenceBadge($confidenceLevel) {
    if ($confidenceLevel >= 80) {
        return '<span class="badge badge-high">High</span>';
    } elseif ($confidenceLevel >= 50) {
        return '<span class="badge badge-medium">Medium</span>';
    } else {
        return '<span class="badge badge-low">Low</span>';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Churn Analytics</title>
    <link rel="stylesheet" href="assets/css/dashboard.css">
    <link rel="stylesheet" href="experiment/experiment.css"> <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        /* Specific dashboard styles to match experiment.css aesthetic */
        :root {
            --primary: #3ac3b8; /* Matching the experiment.css primary color */
            --secondary: #4299e1;
            --danger: #e53e3e;
            --warning: #f6ad55;
            --success: #68d391;
            --info: #4299e1;
            --dark: #1a202c;
            --light: #f7fafc;
            --white: #ffffff;
            --gray-100: #f7fafc;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e0;
            --gray-400: #a0aec0;
            --gray-500: #718096;
            --gray-600: #4a5568;
            --gray-700: #2d3748;
            --gray-800: #1a202c;
            --gray-900: #171923;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            
            color: var(--gray-700);
            line-height: 1.6;
        }

        .dashboard {
            display: flex; /* Use flexbox for sidebar and content layout */
            min-height: 100vh;
        }

        /* Sidebar Styling */
        .sidebar {
            width: 220px; /* Fixed width for the sidebar */
            color: var(--white);
            padding: 2px;
            position: fixed; /* Keep sidebar fixed */
            height: 100%;
            margin-top: -40px;
            overflow-y: auto; /* Enable scrolling for sidebar content */

            /* Hide scrollbar for Webkit browsers (Chrome, Safari) */
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }

        /* Webkit specific scrollbar hide */
        .sidebar::-webkit-scrollbar {
            display: none;
        }

        .sidebar h2 {
            text-align: center;
            margin-bottom: 30px;
            color: var(--primary);
            font-size: 1.8rem;
            font-weight: 700;
        }

        .sidebar ul {
            list-style: none;
            padding: 0;
        }

        .sidebar ul li {
            margin-bottom: 10px;
        }

        .sidebar ul li a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #000000;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 8px;
            margin-top: 10px;
            margin-top: 10px;
            transition: background-color 0.2s ease, color 0.2s ease;
        }

        .sidebar ul li a:hover,
        .sidebar ul li a.active {
            background-color: var(--gray-700);
            color: var(--white);
        }

        .sidebar ul li a i {
            width: 20px;
            height: 20px;
        }

        /* Horizontal scrollbar removal and minimal scrollbar for activity grid */
        .dashboard-content {
            overflow-x: hidden; /* Prevent horizontal scrolling for the entire content area */
            padding: 10px;
            background-color: #f1f1f1;
            margin-left: 250px; /* Account for fixed sidebar width */
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
        }

        .dashboard-title {
            font-size: 2.2rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .dashboard-actions {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            font-size: 0.95rem;
            border: 1px solid var(--gray-300);
            background-color: var(--white);
            color: var(--gray-700);
        }

        .btn:hover {
            background-color: var(--gray-200);
            border-color: var(--gray-400);
        }

        .btn-primary {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background-color: #2da89e; /* Darker shade of primary */
            border-color: #2da89e;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--white);
            padding: 24px;
            border-radius: 12px;
            border: 2px solid #3ac3b8;
            text-align: center;
        }

        .stat-card h3 {
            font-size: 1rem;
            color: var(--gray-600);
            margin-bottom: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card p {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .chart-card {
            background: #68d3711f;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            /* Fixed height for charts to prevent expanding */
            height: 400px; /* Adjust as needed */
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .chart-card canvas {
            max-height: 100%; /* Ensure canvas doesn't overflow */
        }
        .chart-card .no-data-message {
            text-align: center;
            color: var(--gray-500);
            font-style: italic;
            margin-top: 20px;
        }

        .chart-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 20px;
        }

        /* High Risk Users Section */
        .high-risk-section {
            background: var(--white);
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 2px solid #ff0000;
            margin-bottom: 40px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: var(--danger);
        }

        .risk-table {
            width: 100%;
            border-collapse: collapse;
        }

        .risk-table th {
            text-align: left;
            padding: 12px 16px;
            border-bottom: 2px solid var(--gray-200);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .risk-table td {
            padding: 16px;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.9rem;
            color: var(--gray-700);
        }

        .risk-table tbody tr:hover {
            background: var(--gray-100);
        }

        .risk-meter {
            width: 100px;
            height: 8px;
            background-color: var(--gray-200);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }

        .meter-fill {
            height: 100%;
            border-radius: 4px;
        }
        .risk-value {
            font-size: 0.8rem;
            color: var(--gray-600);
            margin-top: 4px;
        }

        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .summary-card {
            background: var(--white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            text-align: center;
        }

        .summary-card h4 {
            font-size: 1rem;
            color: var(--gray-600);
            margin-bottom: 8px;
        }

        .summary-card p {
            font-size: 2rem;
            font-weight: 700;
            color: var(--gray-800);
        }

        .summary-card.recovered { background-color: #e6f7ee; border-color: #28a745; }
        .summary-card.recovered p { color: #28a745; }
        .summary-card.high-risk { background-color: #f8e6e6; border-color: #dc3545; }
        .summary-card.high-risk p { color: #dc3545; }
        .summary-card.low-risk { background-color: #e3f2fd; border-color: #4299e1; }
        .summary-card.low-risk p { color: #4299e1; }


        /* Feature Engagement Sparkline Container */
        .feature-sparkline-section {
            padding: 24px;
            border-radius: 12px;
            margin-bottom: 40px;
        }

        .feature-sparkline-container {
            /*display: flex;*/
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid var(--gray-100);
        }
        .feature-sparkline-container:last-child {
            border-bottom: none;
        }
        .feature-sparkline-container span {
            font-weight: 500;
            color: var(--gray-700);
        }
        .feature-sparkline {
            width: 150px;
            height: 40px;
            display: block; /* Ensure it takes up space for drawing */
        }

        /* Contacts Activity Map */
        .activity-map-container {
            background: #3ac3b8;
            padding: 24px;
            border-radius: 12px;
            border: 2px solid #000000;
            margin-bottom: 40px;
        }
        
        .footer-content {
            margin-left: 300px !important;
        }
        
        .footer {
           padding: 0px 0 !important;
           margin-top: 0px !important;
        }

        .activity-grid {
            display: flex; /* Use flexbox for horizontal layout */
            flex-wrap: wrap; /* Allow wrapping to next line */
            gap: 3px; /* Smaller gap for tighter grid */
            padding: 10px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            max-width: 100%; /* Ensure it doesn't overflow parent */

            /* Allow horizontal scroll within the grid ONLY if necessary */
            overflow-x: auto; 
            padding-bottom: 5px; /* Add a little padding to make room for scrollbar if it appears */
        }

        /* Hide the default scrollbar for .activity-grid for a cleaner look */
        .activity-grid::-webkit-scrollbar {
            height: 6px; /* Adjust height for horizontal scrollbar */
        }

        .activity-grid::-webkit-scrollbar-thumb {
            background-color: var(--gray-400); /* Color of the scroll thumb */
            border-radius: 3px; /* Rounded corners for the thumb */
        }

        .activity-grid::-webkit-scrollbar-track {
            background-color: var(--gray-100); /* Color of the scroll track */
        }

        /* For Firefox */
        .activity-grid {
            scrollbar-width: thin; /* "auto" or "thin" */
            scrollbar-color: var(--gray-400) var(--gray-100); /* thumb color track color */
        }

        /* Tooltip for activity map */
        .activity-tooltip {
            position: absolute;
            background: var(--dark);
            color: var(--white);
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 999;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            transform: translate(-50%, -110%);
        }
        .activity-day:hover .activity-tooltip {
            opacity: 1;
        }

        /* Experiment Metrics specific overrides (from experiment.css) */
        .metric-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 2px solid #1a202c;
            position: relative;
        }

        .metric-label {
            font-size: 0.85rem;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }

        .metric-change {
            font-size: 0.85rem;
            font-weight: 500;
        }

        .metric-change.positive {
            color: #3ac3b8;
        }

        .metric-change.negative {
            color: #e53e3e;
        }

        /* Sparklines in metric cards */
        .sparkline-container {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 100px;
            height: 40px;
        }

        .sparkline {
            width: 100%;
            height: 100%;
            overflow: visible; /* Allow circles to extend beyond SVG bounds slightly if needed */
        }

        .sparkline path {
            fill: none;
            stroke-width: 2;
        }

        .sparkline circle {
            fill: currentColor; /* Use the stroke color of the path */
            opacity: 0;
            transition: opacity 0.2s;
        }
        .sparkline:hover circle {
            opacity: 1;
        }

        .confidence-meter {
            display: flex;
            align-items: center;
            margin-top: 8px;
        }
        .confidence-fill {
            height: 6px;
            background: linear-gradient(90deg, #f56565, #f6e05e, #68d391);
            border-radius: 3px;
            margin-right: 8px;
        }
        .confidence-value {
            font-size: 12px;
            font-weight: bold;
            color: #4a5568;
        }
        .badge-high { background-color: #68d391; }
        .badge-medium { background-color: #f6e05e; }
        .badge-low { background-color: #f56565; }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            text-transform: uppercase;
        }

        /* Info card for empty states */
        .info-card {
            background: #e3f2fd;
            color: #2196f3;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #90caf9;
            text-align: center;
            margin-bottom: 40px;
        }
        .info-card a {
            color: #1976d2;
            text-decoration: underline;
        }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .dashboard {
                flex-direction: column;
            }
            .sidebar {
                position: relative; /* Allow sidebar to stack */
                width: 100%;
                height: auto;
                padding: 15px;
            }
            .dashboard-content {
                margin-left: 0; /* Remove margin when stacked */
                padding: 20px;
            }
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            .stats-grid, .charts-container, .summary-grid {
                grid-template-columns: 1fr;
            }
            .activity-grid {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
           
            <ul>
                <li><a href="dashboard.php" class="active"><i data-feather="home"></i> Dashboard</a></li>
                <li><a href="streams.php"><i data-feather="monitor"></i> Streams</a></li>
                <li><a href="contacts.php"><i data-feather="users"></i> Contacts</a></li>
                <li><a href="cohorts.php"><i data-feather="layers"></i> Cohorts</a></li>
                <li><a href="features.php"><i data-feather="zap"></i> Features</a></li>
                <li><a href="competitors.php"><i data-feather="shield"></i> Competitors</a></li>
                <li><a href="helpdesk.php"><i data-feather="life-buoy"></i> Helpdesk</a></li>
                <li><a href="experiment/index.php"><i data-feather="bar-chart-2"></i> Experiments</a></li>
                <li><a href="winback.php"><i data-feather="send"></i> Win-back Campaigns</a></li>
                <li><a href="reports.php"><i data-feather="file-text"></i> Reports</a></li>
                <li><a href="retention.php"><i data-feather="activity"></i> Retention Flows</a></li>
                <li><a href="trends.php"><i data-feather="trending-up"></i> Trends</a></li>
                <li><a href="membership.php"><i data-feather="award"></i> Membership</a></li>
                <li><a href="affiliates.php"><i data-feather="link-2"></i> Affiliates</a></li>
                <li><a href="settings.php"><i data-feather="settings"></i> Settings</a></li>
                <li><a href="profile.php"><i data-feather="user"></i> Profile</a></li>
                <li><a href="auth/logout.php"><i data-feather="log-out"></i> Logout</a></li>
            </ul>
        </div>
        <div class="dashboard-content">
            <div class="dashboard-header">
                <h1 class="dashboard-title">Welcome back, <?= htmlspecialchars($user['username']) ?></h1>
                <div class="dashboard-actions">
                    <a href="streams.php" class="btn btn-primary">
                        <i data-feather="plus"></i> New Stream
                    </a>
                    <a href="dashboard.php" class="btn">
                        <i data-feather="refresh-cw"></i> Refresh
                    </a>
                </div>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Active Streams</h3>
                    <p><?= $streams_count ?></p>
                </div>
                <div class="stat-card">
                    <h3>Total Contacts</h3>
                    <p><?= $contacts_count ?></p>
                </div>
                <div class="stat-card">
                    <h3>High Risk Users</h3>
                    <p><?= count($high_risk_users) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Churn Index</h3>
                    <p><?= number_format($churn_index, 1) ?></p>
                </div>
            </div>

            <div class="charts-container">
                <div class="chart-card">
                    <h3 class="chart-title">Monthly Churn Rate</h3>
                    <canvas id="churnRateChart"></canvas>
                </div>
                <div class="chart-card">
                    <h3 class="chart-title">Risk Distribution</h3>
                    <?php if ($total_scored_contacts > 0): ?>
                        <canvas id="riskDistributionChart"></canvas>
                    <?php else: ?>
                        <div class="no-data-message">
                            <p>No churn score data available yet for risk distribution.</p>
                            <p>Once your contacts are scored, this chart will appear.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($experiment_metrics_data)): ?>
                <div class="experiment-metrics">
                    <?php foreach ($experiment_metrics_data as $data): ?>
                    <div class="metric-card">
                        <div class="metric-label">
                            Experiment: <?= htmlspecialchars($data['name']) ?>
                            <br><small><?= ucfirst($data['channel_type']) ?> <?php if (!empty($data['specific_value'])) echo "(".htmlspecialchars($data['specific_value']).")"; ?></small>
                        </div>
                        <div class="metric-value"><?= number_format($data['current_churn'], 1) ?>%</div>
                        <div class="metric-change <?= $data['churn_change'] < 0 ? 'positive' : 'negative' ?>">
                            <?= $data['churn_change'] < 0 ? '↓' : '↑' ?> <?= number_format(abs($data['churn_change']), 1) ?>% vs prev.
                        </div>
                        <div class="confidence-meter">
                            <div class="confidence-fill" style="width: <?= $data['confidence_level'] ?>%;"></div>
                            <div class="confidence-value"><?= round($data['confidence_level']) ?>% confidence</div>
                        </div>
                        <div class="sparkline-container">
                            <svg class="sparkline" id="experiment-<?= $data['id'] ?>-sparkline"></svg>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="info-card">
                    <p>No experiments found. <a href="experiment/index.php">Create your first experiment</a> to see its performance here!</p>
                </div>
            <?php endif; ?>


            <div class="high-risk-section">
                <h2 class="section-title">
                    <i data-feather="alert-triangle"></i>
                    High Risk Users
                </h2>
                <?php if (!empty($high_risk_users)): ?>
                <table class="risk-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Email</th>
                            <th>Risk Level</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($high_risk_users as $user_contact): ?>
                        <tr>
                            <td><?= htmlspecialchars($user_contact['username'] ?: 'N/A') ?></td>
                            <td><?= htmlspecialchars($user_contact['email'] ?: 'N/A') ?></td>
                            <td>
                                <div class="risk-meter">
                                    <div class="meter-fill" style="width: <?= $user_contact['score'] ?>%;
                                        background-color: <?= $user_contact['score'] > 70 ? 'var(--danger)' : ($user_contact['score'] > 40 ? 'var(--warning)' : 'var(--success)') ?>">
                                    </div>
                                </div>
                                <div class="risk-value"><?= round($user_contact['score']) ?>%</div>
                            </td>
                            <td>
                                <a href="contacts.php?view=<?= $user_contact['id'] ?>" class="btn">
                                    <i data-feather="eye"></i> View
                                </a>
                                <a href="winback.php?contact=<?= $user_contact['id'] ?>" class="btn btn-primary">
                                    <i data-feather="mail"></i> Win-back
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p>No high-risk users detected at the moment. Keep up the good work!</p>
                <?php endif; ?>
            </div>


            <div class="summary-grid">
                <div class="summary-card recovered">
                    <h4>Recovered This Week</h4>
                    <p><?= $recovered_this_week ?></p>
                </div>
                <div class="summary-card high-risk">
                    <h4>High Risk Accounts</h4>
                    <p><?= count($high_risk_users) ?></p>
                </div>
                <div class="summary-card low-risk">
                    <h4>Low Risk Accounts</h4>
                    <p><?= $low_risk_accounts ?></p>
                </div>
                <div class="summary-card">
                    <h4>Revenue at Risk</h4>
                    <p>$<?= number_format($revenue_at_risk, 2) ?></p>
                </div>
            </div>

            <div class="feature-sparkline-section">
                <h3 class="chart-title">Feature Engagement (Last 7 Days)</h3>
                <?php if (!empty($feature_engagement_data)): ?>
                    <?php foreach ($feature_engagement_data as $feature): ?>
                    <div class="feature-sparkline-container">
                        <span><?= htmlspecialchars($feature['name']) ?> (Total: <?= $feature['total_usage'] ?> uses)</span>
                        <svg class="sparkline feature-sparkline" id="feature-<?= htmlspecialchars(str_replace(' ', '-', $feature['name'])) ?>-sparkline"></svg>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="no-data-message">
                        <p>No features defined or tracked yet.</p>
                        <p><a href="features.php">Define features</a> to track their engagement.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="activity-map-container">
                <h3 class="chart-title">Contacts Activity Heatmap (Last 1 Year)</h3>
                <div class="activity-grid">
                    <?php
                    // GitHub-like activity levels (0-4)
                    function getActivityLevel($count, $max_val) {
                        if ($count == 0) return 0;
                        $thresholds = [
                            $max_val * 0.1, // Level 1: > 10% of max
                            $max_val * 0.3, // Level 2: > 30% of max
                            $max_val * 0.6, // Level 3: > 60% of max
                            $max_val * 0.9  // Level 4: > 90% of max
                        ];
                        if ($count >= $thresholds[3]) return 4;
                        if ($count >= $thresholds[2]) return 3;
                        if ($count >= $thresholds[1]) return 2;
                        if ($count >= $thresholds[0]) return 1;
                        return 0; // Fallback for small non-zero values
                    }

                    foreach ($activity_data_map as $date_str => $activity_count):
                        $activity_level = getActivityLevel($activity_count, $max_activity);
                        ?>
                        <div class="activity-day"
                             data-level="<?= $activity_level ?>"
                             data-activity-count="<?= $activity_count ?>"
                             data-date="<?= $date_str ?>">
                             <div class="activity-tooltip"><?= $date_str ?>: <?= $activity_count ?> activities</div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p style="text-align: center; font-size: 0.85rem; color: var(--gray-600); margin-top: 15px;">
                    Each square represents a day's total activity from all your contacts. Darker shades mean more activity.
                </p>
            </div>


        </div>
    </div>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Monthly Churn Rate Chart
        const churnCtx = document.getElementById('churnRateChart');
        new Chart(churnCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($monthly_churn_labels) ?>,
                datasets: [{
                    label: 'Avg. Churn Score %',
                    data: <?= json_encode($monthly_churn_values) ?>,
                    borderColor: 'var(--danger)',
                    backgroundColor: 'rgba(247, 37, 133, 0.1)',
                    tension: 0.4,
                    fill: true,
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: 'var(--danger)',
                    pointBorderColor: 'var(--white)',
                    pointBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Combined with fixed parent height, this prevents expansion
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: 'var(--gray-800)',
                        bodyColor: 'var(--gray-700)',
                        borderColor: 'var(--gray-200)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100, // Churn score is 0-100%
                        grid: { color: 'var(--gray-200)', drawBorder: false },
                        ticks: {
                            color: 'var(--gray-600)',
                            font: { size: 12 },
                            callback: function(value) { return value + '%'; }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: 'var(--gray-600)', font: { size: 12 } }
                    }
                }
            }
        });

        // Risk Distribution Chart (only if data exists)
        <?php if ($total_scored_contacts > 0): ?>
        const riskCtx = document.getElementById('riskDistributionChart');
        new Chart(riskCtx, {
            type: 'doughnut',
            data: {
                labels: ['High Risk', 'Medium Risk', 'Low Risk'],
                datasets: [{
                    data: <?= json_encode($risk_distribution_values) ?>,
                    backgroundColor: [
                        'var(--danger)',
                        'var(--warning)',
                        'var(--success)'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            padding: 20,
                            color: 'var(--gray-700)',
                            font: { size: 13 }
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(255, 255, 255, 0.95)',
                        titleColor: 'var(--gray-800)',
                        bodyColor: 'var(--gray-700)',
                        borderColor: 'var(--gray-200)',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: true,
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed;
                                const total = context.dataset.data.reduce((acc, current) => acc + current, 0);
                                const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>

        // --- Sparkline Function (reused from experiment.js logic) ---
        function createSparkline(containerId, data, color = 'var(--primary)') {
            const svg = document.getElementById(containerId);
            if (!svg) return; // Exit if element not found

            const width = svg.clientWidth;
            const height = 40;
            const padding = 5;

            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.innerHTML = '';

            if (data.length < 2) {
                // If only one data point, draw a circle at the center
                if (data.length === 1) {
                    const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                    circle.setAttribute('cx', width / 2);
                    circle.setAttribute('cy', height / 2);
                    circle.setAttribute('r', 3);
                    circle.setAttribute('fill', color);
                    svg.appendChild(circle);
                }
                return;
            }

            const min = Math.min(...data);
            const max = Math.max(...data);
            const range = max - min || 1; // Prevent division by zero if all data points are same

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            let pathData = '';

            data.forEach((value, index) => {
                const x = (index / (data.length - 1)) * (width - 2 * padding) + padding;
                const y = height - padding - ((value - min) / range) * (height - 2 * padding);

                if (index === 0) {
                    pathData += `M ${x} ${y}`;
                } else {
                    pathData += ` L ${x} ${y}`;
                }
            });

            path.setAttribute('d', pathData);
            path.setAttribute('stroke', color);
            path.setAttribute('stroke-width', 2);
            path.setAttribute('fill', 'none'); // Ensure no fill for sparklines
            svg.appendChild(path);

            // Add circles for hover tooltips
            data.forEach((value, index) => {
                const x = (index / (data.length - 1)) * (width - 2 * padding) + padding;
                const y = height - padding - ((value - min) / range) * (height - 2 * padding);

                const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circle.setAttribute('cx', x);
                circle.setAttribute('cy', y);
                circle.setAttribute('r', 3);
                circle.setAttribute('fill', color); // Match line color
                circle.setAttribute('data-value', value);
                circle.setAttribute('data-index', index);
                circle.classList.add('sparkline-point'); // Add class for specific styling/hover
                svg.appendChild(circle);

                // Basic tooltip for sparkline points
                circle.addEventListener('mouseenter', (e) => {
                    const tooltip = document.createElement('div');
                    tooltip.classList.add('tooltip'); // Use a generic tooltip class
                    tooltip.innerHTML = `Value: ${value}`;
                    document.body.appendChild(tooltip);

                    const rect = e.target.getBoundingClientRect();
                    tooltip.style.left = (rect.left + window.scrollX + rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                    tooltip.style.top = (rect.top + window.scrollY - tooltip.offsetHeight - 5) + 'px';
                    tooltip.style.opacity = '1';
                });

                circle.addEventListener('mouseleave', () => {
                    const existingTooltip = document.querySelector('.tooltip');
                    if (existingTooltip) {
                        existingTooltip.remove();
                    }
                });
            });
        }

        // Initialize Experiment Sparklines
        <?php foreach ($experiment_metrics_data as $data): ?>
            createSparkline('experiment-<?= $data['id'] ?>-sparkline', <?= json_encode($data['churn_trend']) ?>, '<?= getExperimentColor($data['id']) ?>');
        <?php endforeach; ?>

        // Initialize Feature Engagement Sparklines
        <?php foreach ($feature_engagement_data as $feature): ?>
            createSparkline('feature-<?= htmlspecialchars(str_replace(' ', '-', $feature['name'])) ?>-sparkline', <?= json_encode($feature['data']) ?>, 'var(--primary)');
        <?php endforeach; ?>

        // Contacts Activity Map Tooltip (Github-like)
        document.querySelectorAll('.activity-day').forEach(dayElement => {
            let tooltipTimeout;

            dayElement.addEventListener('mouseenter', (e) => {
                const activityCount = dayElement.getAttribute('data-activity-count');
                const date = dayElement.getAttribute('data-date');
                const tooltipText = `${date}: ${activityCount} activities`;

                const tooltip = dayElement.querySelector('.activity-tooltip');
                if (tooltip) {
                    tooltip.innerHTML = tooltipText;
                    // Position tooltip relative to mouse, with some offset
                    tooltip.style.left = '50%'; // Centered horizontally
                    tooltip.style.top = '-10px'; // Above the square
                    tooltip.style.transform = 'translate(-50%, -100%)';

                    clearTimeout(tooltipTimeout);
                    tooltipTimeout = setTimeout(() => {
                        tooltip.style.opacity = '1';
                    }, 100); // Small delay to show tooltip
                }
            });

            dayElement.addEventListener('mouseleave', () => {
                clearTimeout(tooltipTimeout);
                const tooltip = dayElement.querySelector('.activity-tooltip');
                if (tooltip) {
                    tooltip.style.opacity = '0';
                }
            });
        });

    </script>
</body>
</html>
<?php require_once 'includes/footer.php'; ?>