<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // This should contain global HTML head, meta, and possibly global CSS/JS.

$current_user_id = $_SESSION['user_id'];
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$min_churn_prob = isset($_GET['min_churn_prob']) && is_numeric($_GET['min_churn_prob']) ? (int)$_GET['min_churn_prob'] : 0; // New: Churn probability filter

// --- PHP Error Reporting for Debugging ---
// IMPORTANT: Remove or set to 0 in production environments for security.
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- End PHP Error Reporting ---


// --- Fetch Contacts with Pagination, Search, and Churn Probability Filtering ---
$contacts_data = [];
$total_contacts = 0;

$where_conditions = ["s.user_id = :user_id"];
$query_params = [':user_id' => $current_user_id];

if (!empty($search_query)) {
    $where_conditions[] = "(c.username LIKE :search_query_username OR c.email LIKE :search_query_email)";
    $query_params[':search_query_username'] = '%' . $search_query . '%';
    $query_params[':search_query_email'] = '%' . $search_query . '%';
}

// Join with churn_scores for filtering and sorting
$join_churn_scores = "
    LEFT JOIN (
        SELECT contact_id, score, scored_at
        FROM churn_scores
        WHERE (contact_id, scored_at) IN (
            SELECT contact_id, MAX(scored_at)
            FROM churn_scores
            GROUP BY contact_id
        )
    ) AS latest_churn_scores ON c.id = latest_churn_scores.contact_id
";

// Add churn probability filter condition
$where_conditions[] = "COALESCE(latest_churn_scores.score, 0) >= :min_churn_prob";
$query_params[':min_churn_prob'] = $min_churn_prob;


$where_clause = "WHERE " . implode(" AND ", $where_conditions);

try {
    // Get total contacts for pagination with filter
    $stmt_count = $pdo->prepare("
        SELECT COUNT(c.id)
        FROM contacts c
        JOIN streams s ON c.stream_id = s.id
        $join_churn_scores
        $where_clause
    ");
    $stmt_count->execute($query_params);
    $total_contacts = $stmt_count->fetchColumn();

    $total_pages = ceil($total_contacts / $records_per_page);

    // Get contacts for the current page, including 'last_activity', 'stream_name', and latest churn score
    $stmt_main = $pdo->prepare("
        SELECT c.id, c.username, c.email, c.last_activity, c.custom_data, s.name AS stream_name,
               COALESCE(latest_churn_scores.score, 0) AS current_churn_score,
               latest_churn_scores.scored_at AS last_churn_analysis_date
        FROM contacts c
        JOIN streams s ON c.stream_id = s.id
        $join_churn_scores
        $where_clause
        ORDER BY current_churn_score ASC, c.created_at DESC -- Sort by churn score, then creation date
        LIMIT :limit OFFSET :offset
    ");

    // Bind all parameters
    foreach ($query_params as $param_name => $param_value) {
        $stmt_main->bindValue($param_name, $param_value);
    }
    $stmt_main->bindValue(':limit', (int)$records_per_page, PDO::PARAM_INT);
    $stmt_main->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

    $stmt_main->execute();
    $contacts_on_page = $stmt_main->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error fetching contacts: " . $e->getMessage());
    $contacts_on_page = [];
    $total_contacts = 0;
    $total_pages = 0;
    echo '<div class="alert error">Error loading contacts: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Prepare array of contact IDs for bulk fetching of related data (cohorts, churned/resurrected status, aggregated metrics)
$contact_ids_on_page = array_column($contacts_on_page, 'id');
$contact_ids_placeholder = !empty($contact_ids_on_page) ? implode(',', array_fill(0, count($contact_ids_on_page), '?')) : 'NULL';

// --- Bulk Fetch Additional Contact Data ---
$cohort_memberships = [];
$churned_statuses = [];
$resurrected_statuses = [];
$total_feature_usage = [];
$total_competitor_visits = [];

// Initialize churn_metric_ids for the get_trend_data function
$churn_metric_ids = []; // Initialize to empty array to prevent undefined variable warning
try {
    $stmt = $pdo->query("SELECT id, name FROM churn_metrics");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $churn_metric_ids[$row['name']] = $row['id'];
    }
} catch (PDOException $e) {
    error_log("Database error fetching churn_metrics: " . $e->getMessage());
    echo '<div class="alert error">Error loading metric definitions: ' . htmlspecialchars($e->getMessage()) . '</div>';
    // $churn_metric_ids remains empty array if error occurs
}


if (!empty($contact_ids_on_page)) {
    try {
        // Fetch cohort memberships
        $stmt = $pdo->prepare("
            SELECT cc.contact_id, c.name AS cohort_name
            FROM contact_cohorts cc
            JOIN cohorts c ON cc.cohort_id = c.id
            WHERE cc.contact_id IN ($contact_ids_placeholder)
        ");
        $stmt->execute($contact_ids_on_page);
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cohort_memberships[$row['contact_id']][] = $row['cohort_name'];
        }

        // Check churned status
        $stmt = $pdo->prepare("
            SELECT contact_id FROM churned_users WHERE contact_id IN ($contact_ids_placeholder)
        ");
        $stmt->execute($contact_ids_on_page);
        $churned_statuses = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'contact_id');

        // Check resurrected status
        $stmt = $pdo->prepare("
            SELECT contact_id FROM resurrected_users WHERE contact_id IN ($contact_ids_placeholder)
        ");
        $stmt->execute($contact_ids_on_page);
        $resurrected_statuses = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'contact_id');

        // Fetch aggregated feature usage
        $feature_usage_metric_id = $churn_metric_ids['feature_usage'] ?? null;
        if ($feature_usage_metric_id) {
            $stmt = $pdo->prepare("
                SELECT contact_id, COUNT(id) as total_count
                FROM metric_data
                WHERE contact_id IN ($contact_ids_placeholder) AND metric_id = ?
                GROUP BY contact_id
            ");
            $stmt->execute(array_merge($contact_ids_on_page, [$feature_usage_metric_id]));
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $total_feature_usage[$row['contact_id']] = $row['total_count'];
            }
        }

        // Fetch aggregated competitor visits
        $competitor_visit_metric_id = $churn_metric_ids['competitor_visit'] ?? null;
        if ($competitor_visit_metric_id) {
            $stmt = $pdo->prepare("
                SELECT contact_id, COUNT(id) as total_count
                FROM metric_data
                WHERE contact_id IN ($contact_ids_placeholder) AND metric_id = ?
                GROUP BY contact_id
            ");
            $stmt->execute(array_merge($contact_ids_on_page, [$competitor_visit_metric_id]));
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $total_competitor_visits[$row['contact_id']] = $row['total_count'];
            }
        }

    } catch (PDOException $e) {
        error_log("Database error fetching bulk contact data: " . $e->getMessage());
        echo '<div class="alert error">Error loading additional contact data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}


// --- Prepare Data for Sparklines and additional Contact Info ---
$contact_sparkline_data = [];

// Helper to fetch and format trend data for a given metric
// churn_metric_ids passed as an argument now
$get_trend_data = function($metric_name, $contact_id, $churn_metric_ids, $pdo, $period_days = 30) {
    $trend = [];
    $metric_id = $churn_metric_ids[$metric_name] ?? null;

    try {
        if ($metric_name === 'churn_probability') {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(scored_at, '%Y-%m-%d') as date, score as value
                FROM churn_scores
                WHERE contact_id = :contact_id AND scored_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                GROUP BY date
                ORDER BY date ASC
            ");
            $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
        } else if ($metric_id) {
            $stmt = $pdo->prepare("
                SELECT DATE_FORMAT(recorded_at, '%Y-%m-%d') as date, COUNT(id) as value
                FROM metric_data
                WHERE contact_id = :contact_id AND metric_id = :metric_id AND recorded_at >= DATE_SUB(NOW(), INTERVAL :period_days DAY)
                GROUP BY date
                ORDER BY date ASC
            ");
            $stmt->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
            $stmt->bindValue(':metric_id', $metric_id, PDO::PARAM_INT);
            $stmt->bindValue(':period_days', (int)$period_days, PDO::PARAM_INT);
        } else {
            // Metric ID not found or invalid, return empty trend with correct dates
            for ($i = 0; $i < $period_days; $i++) {
                $date = (clone new DateTime())->modify("-$i days");
                $trend[] = ['x' => $date->getTimestamp() * 1000, 'y' => 0.0];
            }
            return $trend;
        }

        $stmt->execute();
        $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Populate daily data, filling zeros for missing days
        $current_date_dt = new DateTime();
        for ($i = $period_days - 1; $i >= 0; $i--) {
            $date_point = (clone $current_date_dt)->modify("-$i days");
            $date_str = $date_point->format('Y-m-d');
            $found = false;
            foreach ($raw_data as $row) {
                if ($row['date'] === $date_str) {
                    $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => (float)$row['value']];
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
            }
        }
    } catch (PDOException $e) {
        error_log("Database error fetching trend for $metric_name: " . $e->getMessage());
        // Fallback to empty/zero data on error
        for ($i = 0; $i < $period_days; $i++) {
            $date_point = (clone new DateTime())->modify("-$i days");
            $trend[] = ['x' => $date_point->getTimestamp() * 1000, 'y' => 0.0];
        }
    }
    return $trend;
};

foreach ($contacts_on_page as $contact) {
    $contact_id = $contact['id'];

    // Fetch custom fields for this contact
    $custom_fields = [];
    try {
        $stmt_custom_fields = $pdo->prepare("
            SELECT field_name, field_value
            FROM contact_custom_fields
            WHERE contact_id = :contact_id
        ");
        $stmt_custom_fields->bindValue(':contact_id', $contact_id, PDO::PARAM_INT);
        $stmt_custom_fields->execute();
        while ($row = $stmt_custom_fields->fetch(PDO::FETCH_ASSOC)) {
            $custom_fields[$row['field_name']] = $row['field_value'];
        }
    } catch (PDOException $e) {
        error_log("Database error fetching custom fields for contact ID $contact_id: " . $e->getMessage());
    }

    // Call get_trend_data with $churn_metric_ids as argument
    $churn_scores_trend = $get_trend_data('churn_probability', $contact_id, $churn_metric_ids, $pdo);
    $competitor_visits_trend = $get_trend_data('competitor_visit', $contact_id, $churn_metric_ids, $pdo);
    $feature_usage_trend = $get_trend_data('feature_usage', $contact_id, $churn_metric_ids, $pdo);


    $contact_sparkline_data[] = [
        'id' => $contact_id,
        'username' => htmlspecialchars($contact['username'] ?: 'N/A'),
        'email' => htmlspecialchars($contact['email'] ?: 'N/A'),
        'stream_name' => htmlspecialchars($contact['stream_name'] ?: 'N/A'),
        'last_activity' => $contact['last_activity'] ? (new DateTime($contact['last_activity']))->format('M d, Y H:i') : 'N/A',
        'current_churn_score' => $contact['current_churn_score'] ?? 'N/A', // Now directly from main query
        'last_churn_analysis' => $contact['last_churn_analysis_date'] ? (new DateTime($contact['last_churn_analysis_date']))->format('M d, Y') : 'N/A', // Now directly from main query
        'cohort_names' => $cohort_memberships[$contact_id] ?? [],
        'is_churned' => in_array($contact_id, $churned_statuses),
        'is_resurrected' => in_array($contact_id, $resurrected_statuses),
        'total_feature_usage' => $total_feature_usage[$contact_id] ?? 0,
        'total_competitor_visits' => $total_competitor_visits[$contact_id] ?? 0,
        'custom_data' => $custom_fields,
        'raw_custom_data_json' => !empty($contact['custom_data']) ? json_decode($contact['custom_data'], true) : [],
        'churn_scores' => $churn_scores_trend,
        'competitor_visits' => $competitor_visits_trend,
        'feature_usage' => $feature_usage_trend,
    ];
}

// PHP function to render pagination links
function render_pagination($total_pages, $current_page, $search_query, $min_churn_prob) {
    if ($total_pages <= 1) {
        return;
    }

    echo '<div class="pagination-controls">';
    $base_url = '?';
    if (!empty($search_query)) {
        $base_url .= 'search=' . urlencode($search_query) . '&';
    }
    if ($min_churn_prob > 0) {
        $base_url .= 'min_churn_prob=' . $min_churn_prob . '&';
    }

    // Previous button
    if ($current_page > 1) {
        $prev_page = $current_page - 1;
        echo "<a href='{$base_url}page=$prev_page' class='btn pagination-btn'>Previous</a>";
    }

    // Page numbers
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);

    if ($start_page > 1) {
        echo "<a href='{$base_url}page=1' class='btn pagination-btn'>1</a>";
        if ($start_page > 2) {
            echo "<span>...</span>";
        }
    }

    for ($i = $start_page; $i <= $end_page; $i++) {
        $active_class = ($i == $current_page) ? 'active' : '';
        echo "<a href='{$base_url}page=$i' class='btn pagination-btn $active_class'>$i</a>";
    }

    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
            echo "<span>...</span>";
        }
        echo "<a href='{$base_url}page=$total_pages' class='btn pagination-btn'>$total_pages</a>";
    }

    // Next button
    if ($current_page < $total_pages) {
        $next_page = $current_page + 1;
        echo "<a href='{$base_url}page=$next_page' class='btn pagination-btn'>Next</a>";
    }
    echo '</div>';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Explore Contacts</title>
    <!-- Assuming nv.d3.css and nv.d3.js are in a 'build' folder -->
    <link href="build/nv.d3.css" rel="stylesheet" type="text/css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/3.5.2/d3.min.js" charset="utf-8"></script>
    <script src="build/nv.d3.js"></script>
    <!-- jsPDF and html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        /* Base styles consistent with dashboard/experiment */
        :root {
            --primary: #3ac3b8;
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

        .explore-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 40px 20px;
            box-sizing: border-box;
        }

        .explore-header {
            margin-bottom: 30px;
            display: flex;
            flex-direction: column; /* Stack elements vertically */
            align-items: flex-start; /* Align to the left */
            gap: 15px;
        }

        .explore-header h1 {
            font-size: 2rem;
            font-weight: 600;
            color: var(--dark);
        }

        .header-controls {
            display: flex;
            align-items: center;
            width: 100%;
            gap: 20px;
            flex-wrap: wrap; /* Allow wrapping for responsiveness */
        }

        .search-container {
            flex-grow: 1; /* Allows search bar to take available space */
            max-width: 400px; /* Limit width on larger screens */
            position: relative;
        }

        .search-container input[type="text"] {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            color: var(--gray-800);
            background-color: var(--white);
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .search-container input[type="text"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1);
        }

        .churn-slider-group {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 5px 0; /* Add some padding */
        }

        .churn-slider-group label {
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--gray-700);
            white-space: nowrap; /* Prevent label from wrapping */
        }

        .churn-slider-group input[type="range"] {
            flex-grow: 1;
            width: 150px; /* Default width, will expand */
            -webkit-appearance: none; /* Remove default styling */
            appearance: none;
            height: 8px;
            background: var(--gray-300);
            border-radius: 5px;
            outline: none;
            transition: opacity .2s;
        }

        .churn-slider-group input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 18px;
            height: 18px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid var(--white);
            box-shadow: 0 0 2px rgba(0,0,0,0.2);
        }

        .churn-slider-group input[type="range"]::-moz-range-thumb {
            width: 18px;
            height: 18px;
            background: var(--primary);
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid var(--white);
            box-shadow: 0 0 2px rgba(0,0,0,0.2);
        }

        .churn-slider-value {
            min-width: 40px; /* Ensure space for 3 digits */
            text-align: right;
            font-weight: 600;
            color: var(--primary);
        }


        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .contact-card {
            background-color: #efefef; /* White background for cards */
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            position: relative;
        }
        /* Specific override for active/resurrected/churned cards */
        .contact-card.status-active-card { border: 1px solid #3ac3b8; background-color: #efefef; }
        .contact-card.status-churned-card { border: 1px solid var(--danger); background-color: #fef2f2; } /* Light red */
        .contact-card.status-resurrected-card { border: 1px solid var(--success); background-color: #f0fff4; } /* Light green */


        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 15px;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 10px;
            text-align: center;
        }

        .sparkline-section {
            margin-bottom: 15px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
        }
        .sparkline-section:last-of-type {
            margin-bottom: 0;
        }

        .sparkline-label {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 8px;
            font-weight: 500;
        }

        svg.sparkline {
            width: 100%;
            height: 70px;
            display: block;
            margin: 0 auto;
            overflow: visible;
        }

        svg.sparkline path {
            fill: none;
            stroke-width: 2;
            transition: stroke-width 0.2s;
        }
        svg.sparkline circle {
            fill: currentColor;
            opacity: 0;
            transition: opacity 0.2s;
            cursor: pointer;
        }
        svg.sparkline:hover circle {
            opacity: 1;
        }

        /* Additional Contact Info */
        .contact-details {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid var(--gray-200);
        }
        .contact-details p {
            font-size: 0.9rem;
            margin-bottom: 5px;
            color: var(--gray-700);
        }
        .contact-details strong {
            color: var(--gray-800);
        }
        .contact-details ul {
            list-style: none;
            padding-left: 10px;
            margin-top: 5px;
        }
        .contact-details ul li {
            font-size: 0.85rem;
            margin-bottom: 3px;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 8px;
            text-transform: uppercase;
        }
        .status-churned {
            background-color: var(--danger);
            color: var(--white);
        }
        .status-resurrected {
            background-color: var(--success);
            color: var(--white);
        }
        .status-active {
            background-color: var(--info);
            color: var(--white);
        }


        /* PDF Download Button */
        .btn-download-pdf {
            background-color: var(--info);
            color: var(--white);
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            transition: background-color 0.2s;
            margin-top: 20px;
            align-self: center;
        }
        .btn-download-pdf:hover {
            background-color: #3182ce;
        }
        .btn-download-pdf i {
            width: 16px;
            height: 16px;
        }

        /* Pagination Styling */
        .pagination-controls {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            margin-bottom: 40px;
            flex-wrap: wrap;
        }
        .pagination-controls .btn {
            background-color: var(--white);
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
            padding: 8px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background-color 0.2s, border-color 0.2s;
        }
        .pagination-controls .btn:hover {
            background-color: var(--gray-100);
            border-color: var(--gray-400);
        }
        .pagination-controls .btn.active {
            background-color: var(--primary);
            color: var(--white);
            border-color: var(--primary);
            font-weight: 600;
        }
        .pagination-controls span {
            color: var(--gray-600);
        }

        .no-results-message {
            text-align: center;
            padding: 50px;
            color: var(--gray-500);
            font-style: italic;
            background-color: var(--white);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--gray-200);
        }
        .no-results-message a {
            color: var(--primary);
            text-decoration: underline;
        }


        /* Tooltip for sparklines */
        .sparkline-tooltip {
            position: absolute;
            background: var(--dark);
            color: var(--white);
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
            z-index: 1000;
            white-space: nowrap;
        }

        /* Message for no data in individual sparkline */
        .sparkline-no-data {
            text-align: center;
            color: var(--gray-400);
            font-size: 0.85rem;
            padding: 20px 0;
            font-style: italic;
        }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .explore-container {
                padding: 20px 16px;
            }
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
            .explore-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .header-controls {
                flex-direction: column; /* Stack controls on small screens */
                align-items: flex-start;
                gap: 10px;
            }
            .search-container {
                max-width: 100%;
            }
            .churn-slider-group {
                width: 100%; /* Make slider group take full width */
            }
            .churn-slider-group input[type="range"] {
                width: auto; /* Allow auto-sizing within flex container */
                flex-grow: 1;
            }
        }
    </style>
</head>
<body>
    <div class="explore-container">
        <div class="explore-header">
            <h1>Explore Contacts</h1>
            <div class="header-controls">
                <div class="churn-slider-group">
                    <label for="churnProbSlider">Min Churn Prob:</label>
                    <input type="range" id="churnProbSlider" min="0" max="100" value="<?= htmlspecialchars($min_churn_prob) ?>">
                    <span id="churnProbValue" class="churn-slider-value"><?= htmlspecialchars($min_churn_prob) ?>%</span>
                </div>
                <div class="search-container">
                    <input type="text" id="contactSearch" placeholder="Search contacts by username or email..." value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
        </div>

        <?php if (!empty($contact_sparkline_data)): ?>
            <div class="dashboard-grid" id="contactsGrid">
                <?php foreach ($contact_sparkline_data as $contact): ?>
                    <?php
                        $card_status_class = 'status-active-card';
                        if ($contact['is_resurrected']) {
                            $card_status_class = 'status-resurrected-card';
                        } elseif ($contact['is_churned']) {
                            $card_status_class = 'status-churned-card';
                        }
                    ?>
                    <div class="contact-card <?= $card_status_class ?>" id="contact-card-<?= $contact['id'] ?>">
                        <div class="card-title"><?= $contact['username'] ?> <br> <small style="font-size: 0.8em; color: var(--gray-500);"><?= $contact['email'] ?></small></div>

                        <div class="sparkline-section">
                            <div class="sparkline-label">Churn Probability (Last 30 Days)</div>
                            <?php
                                $has_churn_data = !empty($contact['churn_scores']) && array_sum(array_column($contact['churn_scores'], 'y')) > 0;
                            ?>
                            <?php if ($has_churn_data): ?>
                                <svg id="churn-<?= $contact['id'] ?>" class="sparkline"></svg>
                            <?php else: ?>
                                <div class="sparkline-no-data">No churn data yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="sparkline-section">
                            <div class="sparkline-label">Competitor Visits (Last 30 Days)</div>
                            <?php
                                $has_competitor_data = !empty($contact['competitor_visits']) && array_sum(array_column($contact['competitor_visits'], 'y')) > 0;
                            ?>
                            <?php if ($has_competitor_data): ?>
                                <svg id="competitor-<?= $contact['id'] ?>" class="sparkline"></svg>
                            <?php else: ?>
                                <div class="sparkline-no-data">No competitor visit data yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="sparkline-section">
                            <div class="sparkline-label">Feature Usage (Last 30 Days)</div>
                            <?php
                                $has_feature_data = !empty($contact['feature_usage']) && array_sum(array_column($contact['feature_usage'], 'y')) > 0;
                            ?>
                            <?php if ($has_feature_data): ?>
                                <svg id="feature-<?= $contact['id'] ?>" class="sparkline"></svg>
                            <?php else: ?>
                                <div class="sparkline-no-data">No feature usage data yet.</div>
                            <?php endif; ?>
                        </div>

                        <div class="contact-details">
                            <p><strong>Stream:</strong> <?= $contact['stream_name'] ?></p>
                            <p>
                                <strong>Status:</strong>
                                <?php if ($contact['is_resurrected']): ?>
                                    <span class="status-badge status-resurrected">Resurrected</span>
                                <?php elseif ($contact['is_churned']): ?>
                                    <span class="status-badge status-churned">Churned</span>
                                <?php else: ?>
                                    <span class="status-badge status-active">Active</span>
                                <?php endif; ?>
                            </p>
                            <p><strong>Current Churn Score:</strong> <?= $contact['current_churn_score'] ?>%</p>
                            <p><strong>Last Churn Analysis:</strong> <?= $contact['last_churn_analysis'] ?></p>
                            <p><strong>Last Activity:</strong> <?= $contact['last_activity'] ?></p>
                            <p><strong>Total Feature Usage:</strong> <?= $contact['total_feature_usage'] ?></p>
                            <p><strong>Total Competitor Visits:</strong> <?= $contact['total_competitor_visits'] ?></p>
                            <?php if (!empty($contact['cohort_names'])): ?>
                                <p><strong>Cohorts:</strong> <?= implode(', ', $contact['cohort_names']) ?></p>
                            <?php endif; ?>

                            <?php if (!empty($contact['custom_data'])): ?>
                                <p><strong>Custom Fields:</strong></p>
                                <ul>
                                    <?php foreach ($contact['custom_data'] as $field_name => $field_value): ?>
                                        <li><strong><?= htmlspecialchars($field_name) ?>:</strong> <?= htmlspecialchars($field_value) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if (!empty($contact['raw_custom_data_json'])): ?>
                                <p><strong>Raw Custom Data (JSON):</strong></p>
                                <ul>
                                    <?php foreach ($contact['raw_custom_data_json'] as $key => $value): ?>
                                        <li><strong><?= htmlspecialchars($key) ?>:</strong> <?= htmlspecialchars(is_array($value) ? json_encode($value) : $value) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>

                        <button class="btn-download-pdf" data-contact-id="<?= $contact['id'] ?>">
                            <i data-feather="download"></i> Download PDF
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <?= render_pagination($total_pages, $current_page, $search_query, $min_churn_prob) ?>

        <?php else: ?>
            <div class="no-results-message">
                <p>No contacts found for your streams.
                <?php if (!empty($search_query)): ?>
                    Try a different search query or apply different filters.
                <?php else: ?>
                    Start by <a href="streams.php">creating a stream</a> and <a href="contacts.php">adding contacts</a>.
                <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Global tooltip for sparklines -->
    <div class="sparkline-tooltip" id="sparklineTooltip"></div>

    <script>
        // Initialize Feather Icons
        feather.replace();

        // Data for charts passed from PHP
        const contactSparklineData = <?= json_encode($contact_sparkline_data) ?>;
        console.log("PHP Data for Sparklines:", contactSparklineData); // Debugging: Print data to console

        // Function to initialize a sparkline chart
        function initSparkline(containerId, data, lineColor = 'var(--primary)') {
            console.log(`Initializing sparkline for ${containerId} with data:`, data);

            const hasData = data && data.length > 0 && data.some(point => point.y !== 0);

            if (!hasData) {
                console.warn(`No significant data for sparkline ${containerId}. Skipping chart render in JS.`);
                return;
            }

            try {
                nv.addGraph(function() {
                    var chart = nv.models.sparklinePlus()
                        .margin({ left: 30, right: 30 })
                        .x(function(d, i) { return d.x; })
                        .y(function(d) { return d.y; })
                        .showLastValue(true)
                        .showCurrentValue(false)
                        .showMinMax(false)
                        .xScale(d3.time.scale())
                        .xTickFormat(function(d) { return d3.time.format('%b %d')(new Date(d)); })
                        .color([lineColor]);

                    d3.select(containerId)
                        .datum([{values: data}])
                        .call(chart);

                    d3.select(containerId).selectAll('.nv-point').on('mouseover', function(d, i) {
                        const tooltip = document.getElementById('sparklineTooltip');
                        const rect = this.getBoundingClientRect();
                        tooltip.innerHTML = `Value: ${d.y.toFixed(1)} <br> Date: ${d3.time.format('%b %d, %Y')(new Date(d.x))}`;
                        tooltip.style.left = (rect.left + window.scrollX + rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
                        tooltip.style.top = (rect.top + window.scrollY - tooltip.offsetHeight - 10) + 'px';
                        tooltip.style.opacity = '1';
                    }).on('mouseout', function() {
                        document.getElementById('sparklineTooltip').style.opacity = '0';
                    });

                    d3.select(containerId).selectAll('.nv-point.nv-currentValue, .nv-point.nv-min, .nv-point.nv-max')
                        .style('fill', 'var(--dark)')
                        .style('stroke', 'var(--white)')
                        .style('stroke-width', '2px')
                        .attr('r', 5);

                    return chart;
                });
            } catch (error) {
                console.error(`Error rendering sparkline for ${containerId}:`, error);
                const containerDiv = document.getElementById(containerId).closest('.sparkline-section');
                if (containerDiv) {
                    containerDiv.innerHTML = `<div class="sparkline-no-data">Error rendering chart: ${error.message}</div>`;
                }
            }
        }

        contactSparklineData.forEach(contact => {
            initSparkline(`#churn-${contact.id}`, contact.churn_scores, 'var(--danger)');
            initSparkline(`#competitor-${contact.id}`, contact.competitor_visits, 'var(--info)');
            initSparkline(`#feature-${contact.id}`, contact.feature_usage, 'var(--success)');
        });

        // --- Search Bar Auto-search Logic ---
        const contactSearchInput = document.getElementById('contactSearch');
        let searchTimeout;

        contactSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value;
            const currentMinProb = document.getElementById('churnProbSlider').value;

            searchTimeout = setTimeout(() => {
                const urlParams = new URLSearchParams(window.location.search);
                urlParams.set('search', query);
                urlParams.set('page', 1); // Reset to first page on new search
                if (currentMinProb !== '0') {
                    urlParams.set('min_churn_prob', currentMinProb);
                } else {
                    urlParams.delete('min_churn_prob');
                }
                window.location.href = `explore.php?${urlParams.toString()}`;
            }, 500);
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                const urlParams = new URLSearchParams(window.location.search);
                const currentSearch = urlParams.get('search');
                const currentMinProb = urlParams.get('min_churn_prob');

                if (contactSearchInput.value !== (currentSearch || '')) {
                    contactSearchInput.value = currentSearch || '';
                }
                const churnProbSlider = document.getElementById('churnProbSlider');
                const churnProbValueDisplay = document.getElementById('churnProbValue');
                if (churnProbSlider && churnProbValueDisplay) {
                    churnProbSlider.value = currentMinProb || '0';
                    churnProbValueDisplay.textContent = `${churnProbSlider.value}%`;
                }
            }
        });

        // --- Churn Probability Slider Logic ---
        const churnProbSlider = document.getElementById('churnProbSlider');
        const churnProbValueDisplay = document.getElementById('churnProbValue');

        if (churnProbSlider && churnProbValueDisplay) {
            // Initial display
            churnProbValueDisplay.textContent = `${churnProbSlider.value}%`;

            churnProbSlider.addEventListener('input', function() {
                churnProbValueDisplay.textContent = `${this.value}%`;
            });

            let sliderTimeout;
            churnProbSlider.addEventListener('change', function() {
                clearTimeout(sliderTimeout);
                const selectedProb = this.value;
                const currentSearchQuery = document.getElementById('contactSearch').value;

                sliderTimeout = setTimeout(() => {
                    const urlParams = new URLSearchParams(window.location.search);
                    urlParams.set('min_churn_prob', selectedProb);
                    urlParams.set('page', 1); // Reset to first page on new filter
                    if (currentSearchQuery) {
                        urlParams.set('search', currentSearchQuery);
                    } else {
                        urlParams.delete('search');
                    }
                    window.location.href = `explore.php?${urlParams.toString()}`;
                }, 300); // Debounce slider changes
            });
        }


        // --- PDF Download Logic ---
        window.jsPDF = window.jspdf.jsPDF;

        document.querySelectorAll('.btn-download-pdf').forEach(button => {
            button.addEventListener('click', async function() {
                const contactId = this.dataset.contactId;
                const cardElement = document.getElementById(`contact-card-${contactId}`);

                if (!cardElement) {
                    console.error(`Contact card with ID contact-card-${contactId} not found.`);
                    return;
                }

                const originalDisplay = this.style.display;
                this.style.display = 'none'; // Hide the button during PDF generation

                try {
                    const canvas = await html2canvas(cardElement, {
                        scale: 2,
                        useCORS: true,
                        logging: true,
                    });

                    const imgData = canvas.toDataURL('image/png');
                    const imgWidth = 210;
                    const pageHeight = 297;
                    const imgHeight = canvas.height * imgWidth / canvas.width;
                    let heightLeft = imgHeight;

                    const doc = new jsPDF('p', 'mm', 'a4');
                    let position = 0;

                    doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;

                    while (heightLeft >= 0) {
                        position = heightLeft - imgHeight;
                        doc.addPage();
                        doc.addImage(imgData, 'PNG', 0, position, imgWidth, imgHeight);
                        heightLeft -= pageHeight;
                    }

                    doc.save(`contact_report_${contactId}.pdf`);
                    console.log('PDF generated successfully!');

                } catch (error) {
                    console.error('Error generating PDF:', error);
                    alert('Failed to generate PDF. Check console for details.');
                } finally {
                    this.style.display = originalDisplay; // Restore button
                }
            });
        });

        // Initialize Feather Icons on load
        document.addEventListener('DOMContentLoaded', (event) => {
            feather.replace();
        });

    </script>
    <?php require_once 'includes/footer.php'; ?>
</body>
</html>
