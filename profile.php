<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];

// --- PHP Error Reporting for Debugging ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- End PHP Error Reporting ---

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $company_name = $_POST['company_name'] ?? '';
    $industry = $_POST['industry'] ?? '';
    $company_size = $_POST['company_size'] ?? '';
    $company_info = $_POST['company_info'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $phone_number = $_POST['phone_number'] ?? '';
    $alert_is_email = isset($_POST['alert_is_email']) ? 1 : 0;
    $alert_is_sms = isset($_POST['alert_is_sms']) ? 1 : 0;
    $webhook_type = $_POST['webhook_type'] ?? null;
    $webhook_url = $_POST['webhook_url'] ?? null;
    $stripe_key = $_POST['stripe_key'] ?? null;
    $chargebee_key = $_POST['chargebee_key'] ?? null;
    $segment_key = $_POST['segment_key'] ?? null;
    $zendesk_key = $_POST['zendesk_key'] ?? null;
    $freshdesk_key = $_POST['freshdesk_key'] ?? null;
    $zapier_webhook = $_POST['zapier_webhook'] ?? null;
    $webhook_slack = $_POST['webhook_slack'] ?? null;
    $webhook_teams = $_POST['webhook_teams'] ?? null;
    $webhook_discord = $_POST['webhook_discord'] ?? null;


    $profile_pic_path = null;
    // Handle profile picture upload
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = 'auth/uploads/'; // Directory for profile pictures
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_extension = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $new_file_name = uniqid('profile_') . '.' . $file_extension;
        $profile_pic_path = $upload_dir . $new_file_name;

        if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $profile_pic_path)) {
            $_SESSION['error'] = 'Failed to upload profile picture.';
            error_log('Profile picture upload failed: ' . $_FILES['profile_pic']['error']);
            $profile_pic_path = null;
        }
    }

    try {
        $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM user_profiles WHERE user_id = ?");
        $stmt_check->execute([$user_id]);
        $profile_exists = $stmt_check->fetchColumn();

        if ($profile_exists) {
            $update_sql = "UPDATE user_profiles SET
                company_name = :company_name,
                industry = :industry,
                company_size = :company_size,
                company_info = :company_info,
                full_name = :full_name,
                phone_number = :phone_number,
                alert_is_email = :alert_is_email,
                alert_is_sms = :alert_is_sms,
                webhook_type = :webhook_type,
                webhook_url = :webhook_url,
                stripe_key = :stripe_key,
                chargebee_key = :chargebee_key,
                segment_key = :segment_key,
                zendesk_key = :zendesk_key,
                freshdesk_key = :freshdesk_key,
                zapier_webhook = :zapier_webhook,
                webhook_slack = :webhook_slack,
                webhook_teams = :webhook_teams,
                webhook_discord = :webhook_discord
                " . ($profile_pic_path ? ", profile_pic = :profile_pic" : "") . "
                WHERE user_id = :user_id";

            $stmt = $pdo->prepare($update_sql);
        } else {
            $insert_sql = "INSERT INTO user_profiles (
                user_id, company_name, industry, company_size, company_info, full_name,
                phone_number, alert_is_email, alert_is_sms, webhook_type, webhook_url,
                stripe_key, chargebee_key, segment_key, zendesk_key, freshdesk_key,
                zapier_webhook, webhook_slack, webhook_teams, webhook_discord
                " . ($profile_pic_path ? ", profile_pic" : "") . "
            ) VALUES (
                :user_id, :company_name, :industry, :company_size, :company_info, :full_name,
                :phone_number, :alert_is_email, :alert_is_sms, :webhook_type, :webhook_url,
                :stripe_key, :chargebee_key, :segment_key, :zendesk_key, :freshdesk_key,
                :zapier_webhook, :webhook_slack, :webhook_teams, :webhook_discord
                " . ($profile_pic_path ? ", :profile_pic" : "") . "
            )";
            $stmt = $pdo->prepare($insert_sql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        }

        $stmt->bindValue(':company_name', $company_name);
        $stmt->bindValue(':industry', $industry);
        $stmt->bindValue(':company_size', $company_size);
        $stmt->bindValue(':company_info', $company_info);
        $stmt->bindValue(':full_name', $full_name);
        $stmt->bindValue(':phone_number', $phone_number);
        $stmt->bindValue(':alert_is_email', $alert_is_email, PDO::PARAM_INT);
        $stmt->bindValue(':alert_is_sms', $alert_is_sms, PDO::PARAM_INT);
        $stmt->bindValue(':webhook_type', $webhook_type);
        $stmt->bindValue(':webhook_url', $webhook_url);
        $stmt->bindValue(':stripe_key', $stripe_key);
        $stmt->bindValue(':chargebee_key', $chargebee_key);
        $stmt->bindValue(':segment_key', $segment_key);
        $stmt->bindValue(':zendesk_key', $zendesk_key);
        $stmt->bindValue(':freshdesk_key', $freshdesk_key);
        $stmt->bindValue(':zapier_webhook', $zapier_webhook);
        $stmt->bindValue(':webhook_slack', $webhook_slack);
        $stmt->bindValue(':webhook_teams', $webhook_teams);
        $stmt->bindValue(':webhook_discord', $webhook_discord);
        if ($profile_pic_path) {
            $stmt->bindValue(':profile_pic', $profile_pic_path);
        }

        $stmt->execute();
        $_SESSION['success'] = 'Profile updated successfully!';

    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error updating profile: ' . $e->getMessage();
        error_log('Profile update error: ' . $e->getMessage());
    }
    header('Location: profile.php');
    exit;
}

// Get user and profile data
$stmt = $pdo->prepare("SELECT u.*, up.* FROM users u LEFT JOIN user_profiles up ON u.id = up.user_id WHERE u.id = ?");
$stmt->execute([$user_id]);
$user_profile_data = $stmt->fetch(PDO::FETCH_ASSOC);

// --- Fetch Churn Metrics for the Logged-in User's Contacts ---
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
    error_log("Database error fetching churn metrics summary: " . $e->getMessage());
    echo '<div class="alert error">Error loading churn metrics summary: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// --- Fetch Additional User Stats (Total Streams, Total Cohorts) ---
$total_user_streams = 0;
$total_user_cohorts = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(id) FROM streams WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_user_streams = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(id) FROM cohorts WHERE created_by = :user_id");
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $total_user_cohorts = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Database error fetching additional user stats: " . $e->getMessage());
    echo '<div class="alert error">Error loading user stats: ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// --- Historical Data for Monthly Contact Acquisition Chart ---
$monthly_contacts_labels = [];
$monthly_contacts_data = [];

// Get selected date range from GET parameters
$start_date_param = $_GET['start_date'] ?? null;
$end_date_param = $_GET['end_date'] ?? null;

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
    echo '<div class="alert error">Error loading monthly contacts chart data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $monthly_contacts_labels = [];
    $monthly_contacts_data = [];
}


// --- New Chart: Churn Rate Over Time (e.g., last 12 months) ---
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
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT); // Fix: Named parameter for user_id
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
    echo '<div class="alert error">Error loading monthly churn chart data: ' . htmlspecialchars($e->getMessage()) . '</div>';
    $monthly_churn_labels = [];
    $monthly_churn_values = [];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Settings</title>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script> <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Base styles from global setup, ensure consistency */
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

        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--gray-700);
            line-height: 1.6;
            display: flex;
            flex-direction: column; /* For sticky footer */
        }

        .site-wrapper { /* Main flex container for sticky footer */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .profile-wrapper { /* Main content area */
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            box-sizing: border-box;
            flex-grow: 1; /* Allows content area to expand and push footer down */
        }


        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            font-weight: 500;
        }

        .alert.success {
            background: #e6f7ee;
            color: #28a745;
            border: 1px solid #c3e6cb;
        }
        .alert.error {
            background: #f8e6e6;
            color: #dc3545;
            border: 1px solid #f5c6cb;
        }

        .form-section {
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }
        .form-section:last-of-type {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .form-section h2 {
            margin-bottom: 20px;
            color: var(--dark);
            font-size: 1.5rem;
            font-weight: 600;
        }

        /* Multi-part form layout improvement */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); /* At least 2 columns */
            gap: 20px; /* Space between form groups */
        }

        .form-group {
            margin-bottom: 0; /* Remove default margin as gap handles it */
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--gray-700);
            font-size: 0.95rem;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group input[type="url"],
        .form-group input[type="date"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            color: var(--gray-800);
            background-color: var(--white);
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box; /* Crucial for width: 100% with padding */
        }
        .form-group input[type="file"] {
            padding: 8px 0;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1);
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        .checkbox-group input[type="checkbox"] {
            width: auto;
            height: 18px;
            width: 18px;
            margin-right: 5px;
            cursor: pointer;
        }
        .checkbox-group label {
            margin-bottom: 0;
            cursor: pointer;
        }

        .save-btn {
            background: var(--primary);
            color: var(--white);
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: background 0.2s;
            display: block;
            width: fit-content;
            margin: 0 auto;
            margin-top: 20px; /* Space above save button */
        }
        .save-btn:hover {
            background: #2da89e;
        }

        .profile-picture-container {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--gray-200);
        }
        .profile-picture-container img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--primary);
        }
        .profile-picture-container label {
            margin-bottom: 0;
        }
        .profile-picture-container input[type="file"] {
            padding-top: 0;
            padding-bottom: 0;
        }


        /* Churn Metrics Bar */
        .churn-metrics-summary {
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
        }

        .churn-metrics-summary h2 {
            text-align: center;
            margin-bottom: 25px;
            color: var(--dark);
            font-size: 1.8rem;
            font-weight: 700;
        }

        .metric-bar-container {
            width: 100%;
            height: 30px;
            background-color: var(--gray-200);
            border-radius: 15px;
            overflow: hidden;
            display: flex;
            margin-bottom: 20px;
        }

        .metric-segment {
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: 0.85rem;
            font-weight: 600;
            text-shadow: 0 0 2px rgba(0,0,0,0.3);
            white-space: nowrap;
            box-sizing: border-box;
            padding: 0 5px;
        }

        .segment-high-risk { background-color: var(--danger); }
        .segment-medium-risk { background-color: var(--warning); }
        .segment-low-risk { background-color: var(--success); }

        .metric-legend {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 15px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            color: var(--gray-700);
        }

        .legend-color-box {
            width: 18px;
            height: 18px;
            border-radius: 4px;
        }

        .summary-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-top: 30px;
        }

        .summary-stat-card {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #000000;
        }

        .summary-stat-card h3 {
            font-size: 0.9rem;
            color: var(--gray-600);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .summary-stat-card p {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--dark);
        }
        .summary-stat-card.churned-stat p { color: var(--danger); }
        .summary-stat-card.resurrected-stat p { color: var(--success); }
        .summary-stat-card.total-stat p { color: var(--primary); }
        .summary-stat-card.high-stat p { color: var(--danger); }
        .summary-stat-card.medium-stat p { color: var(--warning); }
        .summary-stat-card.low-stat p { color: var(--success); }

        /* User Info Summary Card */
        .user-info-summary {
           
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
            text-align: center;
        }
        .user-info-summary h2 {
            font-size: 2rem;
            color: var(--dark);
            margin-bottom: 10px;
        }
        .user-info-summary p {
            font-size: 1rem;
            color: var(--gray-600);
            margin-bottom: 5px;
        }
        .user-info-stats {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 20px;
        }
        .user-info-stat-item {
            text-align: center;
            padding: 10px 15px;
            background-color: var(--gray-100);
            border-radius: 8px;
            border: 2px solid #000000;
        }
        .user-info-stat-item strong {
            display: block;
            font-size: 1.5rem;
            color: var(--primary);
            font-weight: 700;
        }
        .user-info-stat-item span {
            font-size: 0.85rem;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Chart Section */
        .chart-section {
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 40px;
        }
        .chart-section h2 {
            text-align: center;
            margin-bottom: 25px;
            color: var(--dark);
            font-size: 1.8rem;
            font-weight: 700;
        }
        .chart-container {
            position: relative;
            height: 350px;
            width: 100%;
        }
        .chart-container canvas {
            max-height: 100%;
            max-width: 100%;
        }
        .chart-container .no-data-message {
            text-align: center;
            color: var(--gray-500);
            font-style: italic;
            padding: 50px 0;
        }

        /* Date Range Selector for Charts */
        .date-range-selector {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .date-range-selector label {
            font-weight: 500;
            color: var(--gray-700);
        }
        .date-range-selector input[type="date"] {
            width: 150px;
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.95rem;
        }
        
       .profile-container2 {
            width: auto;
            height: auto;
    /* border-radius: 50%; */
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
}
        .date-range-selector button {
            background-color: var(--primary);
            color: var(--white);
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            transition: background-color 0.2s;
        }
        .date-range-selector button:hover {
            background-color: #2da89e;
        }


        /* Footer styles (from footer.php) */
        .footer {
            padding: 15px 0;
            margin-top: auto; /* Pushes the footer to the bottom */
            border-top: 1px solid var(--gray-200);
        }
        
        .footer-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 15px;
            color: var(--gray-600);
        }
        
        .copyright {
            color: var(--gray-600);
            font-size: 0.9rem;
        }
        
        .logout-link {
            color: var(--danger);
            text-decoration: none;
            transition: color 0.3s;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .logout-link:hover {
            color: #c82333;
        }
        .logout-link strong {
            font-weight: 600;
        }
        .logout-link i {
            font-size: 1em;
        }
        /* End Footer styles */

        @media (max-width: 768px) {
            .profile-wrapper {
                margin: 20px auto;
                padding: 15px;
            }
            .form-section h2 {
                font-size: 1.3rem;
            }
            .profile-picture-container {
                flex-direction: column;
                align-items: flex-start;
            }
            .summary-stats-grid, .user-info-stats, .form-grid { /* Added .form-grid here */
                grid-template-columns: 1fr;
            }
            .footer-content {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            .date-range-selector {
                flex-direction: column;
                align-items: center;
            }
            .date-range-selector input[type="date"] {
                width: 100%;
                max-width: 250px;
            }
        }
    </style>
</head>
<body>
    <div class="site-wrapper">
        <div class="profile-wrapper">
            <div class="user-info-summary">
                <h2>Hello, <?= htmlspecialchars($user_profile_data['username'] ?? 'User') ?>!</h2>
                <p><?= htmlspecialchars($user_profile_data['email'] ?? 'No email available') ?></p>
                <?php if (!empty($user_profile_data['company_name'])): ?>
                    <p>Company: <strong><?= htmlspecialchars($user_profile_data['company_name']) ?></strong> (Industry: <?= htmlspecialchars($user_profile_data['industry'] ?? 'N/A') ?>)</p>
                <?php endif; ?>
                <div class="user-info-stats">
                    <div class="user-info-stat-item">
                        <strong><?= $total_user_streams ?></strong>
                        <span>Streams</span>
                    </div>
                    <div class="user-info-stat-item">
                        <strong><?= $total_user_cohorts ?></strong>
                        <span>Cohorts</span>
                    </div>
                    <div class="user-info-stat-item">
                        <strong><?= $churn_metrics_summary['total_contacts'] ?></strong>
                        <span>Total Contacts</span>
                    </div>
                </div>
            </div>

            <div class="churn-metrics-summary">
                <h2>Your Contacts' Risk Distribution</h2>
                <?php if ($churn_metrics_summary['total_contacts'] > 0): ?>
                    <div class="metric-bar-container">
                        <div class="metric-segment segment-low-risk" style="width: <?= $churn_metrics_summary['risk_distribution']['low'] ?>%;">
                            <?php if ($churn_metrics_summary['risk_distribution']['low'] > 10): ?>
                                <?= $churn_metrics_summary['risk_distribution']['low'] ?>% Low
                            <?php endif; ?>
                        </div>
                        <div class="metric-segment segment-medium-risk" style="width: <?= $churn_metrics_summary['risk_distribution']['medium'] ?>%;">
                            <?php if ($churn_metrics_summary['risk_distribution']['medium'] > 10): ?>
                                <?= $churn_metrics_summary['risk_distribution']['medium'] ?>% Medium
                            <?php endif; ?>
                        </div>
                        <div class="metric-segment segment-high-risk" style="width: <?= $churn_metrics_summary['risk_distribution']['high'] ?>%;">
                            <?php if ($churn_metrics_summary['risk_distribution']['high'] > 10): ?>
                                <?= $churn_metrics_summary['risk_distribution']['high'] ?>% High
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="metric-legend">
                        <div class="legend-item"><span class="legend-color-box segment-low-risk"></span> Low Risk</div>
                        <div class="legend-item"><span class="legend-color-box segment-medium-risk"></span> Medium Risk</div>
                        <div class="legend-item"><span class="legend-color-box segment-high-risk"></span> High Risk</div>
                    </div>

                    <div class="summary-stats-grid">
                        <div class="summary-stat-card total-stat">
                            <h3>Total Contacts</h3>
                            <p><?= $churn_metrics_summary['total_contacts'] ?></p>
                        </div>
                        <div class="summary-stat-card high-stat">
                            <h3>High Risk</h3>
                            <p><?= $churn_metrics_summary['high_risk_count'] ?></p>
                        </div>
                        <div class="summary-stat-card medium-stat">
                            <h3>Medium Risk</h3>
                            <p><?= $churn_metrics_summary['medium_risk_count'] ?></p>
                        </div>
                        <div class="summary-stat-card low-stat">
                            <h3>Low Risk</h3>
                            <p><?= $churn_metrics_summary['low_risk_count'] ?></p>
                        </div>
                        <div class="summary-stat-card churned-stat">
                            <h3>Churned Contacts</h3>
                            <p><?= $churn_metrics_summary['churned_count'] ?></p>
                        </div>
                        <div class="summary-stat-card resurrected-stat">
                            <h3>Resurrected</h3>
                            <p><?= $churn_metrics_summary['resurrected_count'] ?></p>
                        </div>
                    </div>

                <?php else: ?>
                    <div class="no-data-message" style="text-align: center; color: var(--gray-500);">
                        No contact data found for your streams to generate churn metrics.
                        <br>Start by <a href="streams.php" style="color: var(--primary); text-decoration: underline;">creating a stream</a> and <a href="contacts.php" style="color: var(--primary); text-decoration: underline;">adding contacts</a>.
                    </div>
                <?php endif; ?>
            </div>

            <div class="chart-section">
                <h2>Monthly Contact Acquisition</h2>
                <div class="date-range-selector">
                    <label for="acquisition_start_date">From:</label>
                    <input type="date" id="acquisition_start_date" value="<?= htmlspecialchars($selected_start_date->format('Y-m-d')) ?>">
                    <label for="acquisition_end_date">To:</label>
                    <input type="date" id="acquisition_end_date" value="<?= htmlspecialchars($selected_end_date->format('Y-m-d')) ?>">
                    <button id="applyAcquisitionDateRange">Apply</button>
                </div>
                <?php if ($churn_metrics_summary['total_contacts'] > 0 || !empty($monthly_contacts_data)): ?>
                    <div class="chart-container">
                        <canvas id="monthlyContactsChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="no-data-message">
                        No contact data available for this chart.
                    </div>
                <?php endif; ?>
            </div>

            <div class="chart-section">
                <h2>Monthly Churn Rate Trend</h2>
                <?php if ($churn_metrics_summary['total_contacts'] > 0 || !empty($monthly_churn_values)): ?>
                    <div class="chart-container">
                        <canvas id="monthlyChurnRateChart"></canvas>
                    </div>
                <?php else: ?>
                    <div class="no-data-message">
                        No churn data available for this chart.
                    </div>
                <?php endif; ?>
            </div>

            <div class="profile-container2">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert success"><?= $_SESSION['success'] ?></div>
                    <?php unset($_SESSION['success']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert error"><?= $_SESSION['error'] ?></div>
                    <?php unset($_SESSION['error']); ?>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-section">
                        <h2>Company Information</h2>
                        <div class="form-grid"> <div class="form-group">
                                <label>Company Name</label>
                                <input type="text" name="company_name" value="<?= htmlspecialchars($user_profile_data['company_name'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label>Industry</label>
                                <select name="industry">
                                    <option value="">Select Industry</option>
                                    <option value="Technology" <?= ($user_profile_data['industry'] ?? '') === 'Technology' ? 'selected' : '' ?>>Technology</option>
                                    <option value="Finance" <?= ($user_profile_data['industry'] ?? '') === 'Finance' ? 'selected' : '' ?>>Finance</option>
                                    <option value="Healthcare" <?= ($user_profile_data['industry'] ?? '') === 'Healthcare' ? 'selected' : '' ?>>Healthcare</option>
                                    <option value="Retail" <?= ($user_profile_data['industry'] ?? '') === 'Retail' ? 'selected' : '' ?>>Retail</option>
                                    <option value="Education" <?= ($user_profile_data['industry'] ?? '') === 'Education' ? 'selected' : '' ?>>Education</option>
                                    <option value="Manufacturing" <?= ($user_profile_data['industry'] ?? '') === 'Manufacturing' ? 'selected' : '' ?>>Manufacturing</option>
                                    <option value="E-commerce" <?= ($user_profile_data['industry'] ?? '') === 'E-commerce' ? 'selected' : '' ?>>E-commerce</option>
                                    <option value="Marketing" <?= ($user_profile_data['industry'] ?? '') === 'Marketing' ? 'selected' : '' ?>>Marketing</option>
                                    <option value="Other" <?= ($user_profile_data['industry'] ?? '') === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Company Size</label>
                                <select name="company_size">
                                    <option value="">Select Size</option>
                                    <option value="1-10" <?= ($user_profile_data['company_size'] ?? '') === '1-10' ? 'selected' : '' ?>>1-10 employees</option>
                                    <option value="11-50" <?= ($user_profile_data['company_size'] ?? '') === '11-50' ? 'selected' : '' ?>>11-50 employees</option>
                                    <option value="51-200" <?= ($user_profile_data['company_size'] ?? '') === '51-200' ? 'selected' : '' ?>>51-200 employees</option>
                                    <option value="201-500" <?= ($user_profile_data['company_size'] ?? '') === '201-500' ? 'selected' : '' ?>>201-500 employees</option>
                                    <option value="501+" <?= ($user_profile_data['company_size'] ?? '') === '501+' ? 'selected' : '' ?>>501+ employees</option>
                                </select>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;"> <label>About Company</label>
                                <textarea name="company_info"><?= htmlspecialchars($user_profile_data['company_info'] ?? '') ?></textarea>
                            </div>
                        </div> </div>

                    <div class="form-section">
                        <h2>Personal Information</h2>
                        <div class="form-grid"> <div class="form-group">
                                <label>Full Name</label>
                                <input type="text" name="full_name" value="<?= htmlspecialchars($user_profile_data['full_name'] ?? '') ?>">
                            </div>
                            <div class="profile-picture-container" style="grid-column: 1 / -1;"> <?php if (!empty($user_profile_data['profile_pic'])): ?>
                                    <img src="<?= htmlspecialchars($user_profile_data['profile_pic']) ?>" alt="Profile Picture">
                                <?php else: ?>
                                    <img src="https://placehold.co/80x80/aabbcc/ffffff?text=N/A" alt="No Image">
                                <?php endif; ?>
                                <div class="form-group" style="flex-grow: 1; margin-bottom: 0;">
                                    <label for="profile_pic">Upload New Profile Picture</label>
                                    <input type="file" id="profile_pic" name="profile_pic" accept="image/*">
                                </div>
                            </div>
                        </div> </div>

                    <div class="form-section">
                        <h2>Notification Preferences</h2>
                        <div class="form-grid"> <div class="form-group checkbox-group">
                                <input type="checkbox" id="alert_is_email" name="alert_is_email" <?= ($user_profile_data['alert_is_email'] ?? 1) ? 'checked' : '' ?>>
                                <label for="alert_is_email">Email Alerts (Enabled by default)</label>
                            </div>
                            <div class="form-group checkbox-group">
                                <input type="checkbox" id="alert_is_sms" name="alert_is_sms" <?= ($user_profile_data['alert_is_sms'] ?? 0) ? 'checked' : '' ?>>
                                <label for="alert_is_sms">SMS Alerts</label>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;"> <label>Phone Number (for SMS, with country code)</label>
                                <input type="tel" name="phone_number" value="<?= htmlspecialchars($user_profile_data['phone_number'] ?? '') ?>" placeholder="+1234567890">
                            </div>
                        </div> </div>

                    <div class="form-section">
                        <h2>External Integrations</h2>
                        <div class="form-grid"> <div class="form-group">
                                <label>Webhook Service (for Churn Alerts)</label>
                                <select name="webhook_type">
                                    <option value="">Select Service</option>
                                    <option value="slack" <?= ($user_profile_data['webhook_type'] ?? '') === 'slack' ? 'selected' : '' ?>>Slack</option>
                                    <option value="microsoft_teams" <?= ($user_profile_data['webhook_type'] ?? '') === 'microsoft_teams' ? 'selected' : '' ?>>Microsoft Teams</option>
                                    <option value="discord" <?= ($user_profile_data['webhook_type'] ?? '') === 'discord' ? 'selected' : '' ?>>Discord</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Webhook URL (e.g., for selected service)</label>
                                <input type="url" name="webhook_url" value="<?= htmlspecialchars($user_profile_data['webhook_url'] ?? '') ?>" placeholder="https://hooks.slack.com/services/...">
                            </div>
                            <div class="form-group">
                                <label>Slack Webhook URL</label>
                                <input type="url" name="webhook_slack" value="<?= htmlspecialchars($user_profile_data['webhook_slack'] ?? '') ?>" placeholder="https://hooks.slack.com/services/...">
                            </div>
                            <div class="form-group">
                                <label>Microsoft Teams Webhook URL</label>
                                <input type="url" name="webhook_teams" value="<?= htmlspecialchars($user_profile_data['webhook_teams'] ?? '') ?>" placeholder="https://outlook.office.com/webhook/...">
                            </div>
                            <div class="form-group">
                                <label>Discord Webhook URL</label>
                                <input type="url" name="webhook_discord" value="<?= htmlspecialchars($user_profile_data['webhook_discord'] ?? '') ?>" placeholder="https://discord.com/api/webhooks/...">
                            </div>
                            <div class="form-group">
                                <label>Zapier Webhook URL</label>
                                <input type="url" name="zapier_webhook" value="<?= htmlspecialchars($user_profile_data['zapier_webhook'] ?? '') ?>" placeholder="https://hooks.zapier.com/hooks/catch/...">
                            </div>
                            <div class="form-group">
                                <label>Stripe API Key</label>
                                <input type="text" name="stripe_key" value="<?= htmlspecialchars($user_profile_data['stripe_key'] ?? '') ?>" placeholder="sk_live_...">
                            </div>
                            <div class="form-group">
                                <label>Chargebee API Key</label>
                                <input type="text" name="chargebee_key" value="<?= htmlspecialchars($user_profile_data['chargebee_key'] ?? '') ?>" placeholder="your_site_key">
                            </div>
                            <div class="form-group">
                                <label>Segment.com Write Key</label>
                                <input type="text" name="segment_key" value="<?= htmlspecialchars($user_profile_data['segment_key'] ?? '') ?>" placeholder="YOUR_WRITE_KEY">
                            </div>
                            <div class="form-group">
                                <label>Zendesk API Key</label>
                                <input type="text" name="zendesk_key" value="<?= htmlspecialchars($user_profile_data['zendesk_key'] ?? '') ?>" placeholder="your_zendesk_api_key">
                            </div>
                            <div class="form-group">
                                <label>Freshdesk API Key</label>
                                <input type="text" name="freshdesk_key" value="<?= htmlspecialchars($user_profile_data['freshdesk_key'] ?? '') ?>" placeholder="your_freshdesk_api_key">
                            </div>
                        </div> </div>

                    <button type="submit" class="save-btn">Save Profile</button>
                </form>
            </div>
        </div>
        <?php require_once 'includes/footer.php'; ?>
    </div> <script>
        // Initialize Feather Icons
        feather.replace();

        // Basic phone number validation example (client-side)
        const phoneNumberInput = document.querySelector('input[name="phone_number"]');
        if (phoneNumberInput) {
            phoneNumberInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9+]/g, '');
            });
        }

        // Initialize Flatpickr for date inputs
        flatpickr("#acquisition_start_date", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });
        flatpickr("#acquisition_end_date", {
            dateFormat: "Y-m-d",
            maxDate: "today"
        });

        // Handle date range application for Monthly Contacts Chart
        document.getElementById('applyAcquisitionDateRange').addEventListener('click', function() {
            const startDate = document.getElementById('acquisition_start_date').value;
            const endDate = document.getElementById('acquisition_end_date').value;

            // Redirect with new date parameters
            window.location.href = `profile.php?start_date=${startDate}&end_date=${endDate}`;
        });


        // Initialize Monthly Contacts Chart
        const monthlyContactsCtx = document.getElementById('monthlyContactsChart');
        if (monthlyContactsCtx) {
            new Chart(monthlyContactsCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthly_contacts_labels) ?>,
                    datasets: [{
                        label: 'Total Contacts',
                        data: <?= json_encode($monthly_contacts_data) ?>,
                        borderColor: 'var(--primary)',
                        backgroundColor: 'rgba(58, 195, 184, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: 'var(--primary)',
                        pointBorderColor: 'var(--white)',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: 'var(--gray-800)',
                            bodyColor: 'var(--gray-700)',
                            borderColor: 'var(--gray-200)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return `Contacts: ${context.parsed.y}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: 'var(--gray-600)' }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'var(--gray-200)' },
                            ticks: { color: 'var(--gray-600)',
                                callback: function(value) { if (value % 1 === 0) return value; }
                            }
                        }
                    }
                }
            });
        }

        // Initialize Monthly Churn Rate Chart
        const monthlyChurnRateCtx = document.getElementById('monthlyChurnRateChart');
        if (monthlyChurnRateCtx) {
            new Chart(monthlyChurnRateCtx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($monthly_churn_labels) ?>,
                    datasets: [{
                        label: 'Average Churn Score (%)',
                        data: <?= json_encode($monthly_churn_values) ?>,
                        borderColor: 'var(--danger)',
                        backgroundColor: 'rgba(229, 62, 62, 0.1)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 5,
                        pointBackgroundColor: 'var(--danger)',
                        pointBorderColor: 'var(--white)',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(255, 255, 255, 0.95)',
                            titleColor: 'var(--gray-800)',
                            bodyColor: 'var(--gray-700)',
                            borderColor: 'var(--gray-200)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return `Churn Score: ${context.parsed.y}%`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: 'var(--gray-600)' }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            grid: { color: 'var(--gray-200)' },
                            ticks: { color: 'var(--gray-600)',
                                callback: function(value) { return value + '%'; }
                            }
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>
