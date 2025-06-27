<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];
// external_integrations.php
// !!! IMPORTANT DEBUGGING LINES - KEEP AT VERY TOP !!!
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0'); // Shows parse errors at startup
ini_set('log_errors', '1'); // Ensures errors are logged
ini_set('error_log', '/var/www/www-root/data/www/earndos.com/io/php_app_errors.log'); // DIRECTS ERRORS HERE
// !!! END IMPORTANT DEBUGGING LINES !!!

ob_start(); // Start output buffering at the very top
session_start(); // Start session for this page

// Check authentication using the standard header.php logic

// Core project includes - ensure these paths are correct.
require_once 'includes/functions.php'; 
require_once 'includes/api_helpers.php'; // CRUCIAL: This line was causing "undefined function" error previously if missing.
require_once 'includes/external_apis/IntegrationBase.php';
require_once 'includes/external_apis/IntegrationManager.php';


// Define all supported integrations for UI display
$api_clients = ['Mautic', 'Hubspot', 'Salesforce', 'Stripe', 'Chargebee', 'Segment', 'Zendesk', 'Freshdesk', 'Zapier'];

// Dynamically load ALL API client classes based on file existence
// This ensures that the class definitions are available for IntegrationManager.
foreach ($api_clients as $client_name) {
    $classNameSuffix = ($client_name === 'Zapier') ? '' : 'Client'; // Zapier is 'Zapier.php', others are 'XClient.php'
    $path = 'includes/external_apis/' . $client_name . $classNameSuffix . '.php';
    
    if (file_exists($path)) {
        require_once $path;
    } else {
        error_log("[FATAL - Config] Missing API client file for '{$client_name}': " . $path . ". This integration will not be available in the UI.");
        // Remove from list if the file is genuinely missing to prevent UI errors.
        $api_clients = array_diff($api_clients, [$client_name]); 
    }
}
// Re-index array after diff in case any were removed
$api_clients = array_values($api_clients); 

$integrationManager = new IntegrationManager($pdo); // Initialize the manager

// Fetch user's API key (needed for AJAX calls from client-side)
$user_api_key = null;
try {
    $stmt_api_key = $pdo->prepare("SELECT api_key FROM user_api_keys WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 1");
    $stmt_api_key->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt_api_key->execute();
    $api_key_data = $stmt_api_key->fetch(PDO::FETCH_ASSOC);

    if ($api_key_data) {
        $user_api_key = $api_key_data['api_key'];
    }
} catch (PDOException $e) {
    error_log("[Database Error] Error fetching API key for integrations page: " . $e->getMessage());
}

// Fetch user's existing profile data (for legacy keys from user_profiles to pre-fill forms)
// This calls getUserProfile() from includes/api_helpers.php
$user_profile_data = getUserProfile($pdo, $user_id); 

// Get user's external services configurations (from external_service_auth table)
$stmt = $pdo->prepare("SELECT id, service_name, auth_type, api_key, access_token, refresh_token, token_expires, base_url, metadata, last_connected_at, created_at, updated_at FROM external_service_auth WHERE user_id = ?");
$stmt->execute([$user_id]);
$services_from_db = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper function to get service data for display, consolidating from external_service_auth and user_profiles
// This function is defined inline for this file, but could be global in api_helpers.php if needed elsewhere.
function getServiceDataForDisplay(array $services_from_db_array, array $user_profile_data, string $service_name): array {
    $service_config = ['metadata' => []]; // Default empty config structure

    // Try to get config from external_service_auth first
    foreach ($services_from_db_array as $service) {
        if (isset($service['service_name']) && $service['service_name'] === $service_name) {
            $service_config = $service;
            // Decode metadata if it's a string and not empty
            if (isset($service_config['metadata']) && is_string($service_config['metadata']) && !empty($service_config['metadata'])) {
                $service_config['metadata'] = json_decode($service_config['metadata'], true) ?: [];
            } else {
                $service_config['metadata'] = [];
            }
            break;
        }
    }

    // If no config found in external_service_auth, try to populate from user_profiles (for initial display/migration)
    if (!isset($service_config['id'])) { // If 'id' is not set, it means no entry exists in external_service_auth yet for this service.
        switch ($service_name) {
            // These services store direct API keys in user_profiles and might need additional metadata fields.
            case 'stripe':
                $service_config['api_key'] = $user_profile_data['stripe_key'] ?? null;
                break;
            case 'chargebee':
                $service_config['api_key'] = $user_profile_data['chargebee_key'] ?? null;
                // 'site_name' for Chargebee isn't in user_profiles, user will have to manually fill this if empty.
                break;
            case 'segment':
                $service_config['api_key'] = $user_profile_data['segment_key'] ?? null;
                break;
            case 'zendesk':
                $service_config['api_key'] = $user_profile_data['zendesk_key'] ?? null;
                // 'subdomain' for Zendesk isn't in user_profiles, user will have to manually fill this if empty.
                break;
            case 'freshdesk':
                $service_config['api_key'] = $user_profile_data['freshdesk_key'] ?? null;
                // 'domain' for Freshdesk isn't in user_profiles, user will have to manually fill this if empty.
                break;
            case 'zapier': 
                $service_config['base_url'] = $user_profile_data['zapier_webhook'] ?? null; // Old Zapier webhook field
                break;
            // Mautic, HubSpot, Salesforce were OAuth-based and wouldn't typically have simple API keys in user_profiles
            // or are explicitly removed from backend (so no legacy data to pull here).
        }
    }
    
    return $service_config;
}


// --- Handle Form Submissions (Save & Test Connection) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? ''; // Hidden field 'action_type' for save or test
    $service_name = trim($_POST['service_name']);
    
    $config_to_save = [
        'service_name' => $service_name,
        'user_id' => $user_id,
        'metadata' => [] // Initialize metadata as an empty array
    ];

    // Collect data for `config_to_save` based on service type.
    // This data goes into `external_service_auth` table.
    switch ($service_name) {
        case 'mautic':
        case 'hubspot':
        case 'salesforce': // OAuth services
            $config_to_save['auth_type'] = 'oauth';
            $config_to_save['metadata']['client_id'] = trim($_POST['client_id'] ?? '');
            $config_to_save['metadata']['client_secret'] = trim($_POST['client_secret'] ?? '');
            // base_url for Mautic/Salesforce goes into base_url column
            if (in_array($service_name, ['mautic', 'salesforce'])) {
                $config_to_save['base_url'] = trim($_POST['base_url'] ?? '');
            }
            // Access and refresh tokens are populated by the OAuth callback, not this form directly.
            // When saving from the form, ensure these are explicitly set to null/empty if not present.
            // This prevents old token values from being overwritten if the user is just updating client_id/secret.
            // The IntegrationManager's updateServiceConfig will merge with existing saved tokens.
            break;
        case 'stripe':
            $config_to_save['auth_type'] = 'api_key';
            $config_to_save['api_key'] = trim($_POST['api_key'] ?? '');
            break;
        case 'chargebee':
            $config_to_save['auth_type'] = 'api_key';
            $config_to_save['api_key'] = trim($_POST['api_key'] ?? '');
            $config_to_save['metadata']['site_name'] = trim($_POST['site_name'] ?? '');
            break;
        case 'segment':
            $config_to_save['auth_type'] = 'api_key';
            $config_to_save['api_key'] = trim($_POST['api_key'] ?? ''); // Segment Write Key
            break;
        case 'zendesk':
            $config_to_save['auth_type'] = 'api_key';
            $config_to_save['api_key'] = trim($_POST['api_key'] ?? '');
            $config_to_save['metadata']['subdomain'] = trim($_POST['subdomain'] ?? '');
            break;
        case 'freshdesk':
            $config_to_save['auth_type'] = 'api_key';
            $config_to_save['api_key'] = trim($_POST['api_key'] ?? '');
            $config_to_save['metadata']['domain'] = trim($_POST['domain'] ?? '');
            break;
        case 'zapier':
            $config_to_save['auth_type'] = 'webhook';
            $config_to_save['base_url'] = trim($_POST['base_url'] ?? ''); // Zapier webhook URL
            break;
        default:
            $_SESSION['error'] = "Attempted to save configuration for an unrecognized service: " . ucfirst($service_name);
            error_log("[Error] Attempt to save config for unrecognized service: {$service_name} by user {$user_id}.");
            ob_end_clean();
            header("Location: external_integrations.php"); // Redirect to a safe default tab
            exit;
    }

    if ($action_type === 'save_service') {
        try {
            $success = $integrationManager->updateServiceConfig($user_id, $service_name, $config_to_save);

            if ($success) {
                $_SESSION['success'] = ucfirst($service_name) . " credentials saved successfully!";
            } else {
                $_SESSION['error'] = "Failed to save " . ucfirst($service_name) . " credentials.";
            }

        } catch (Exception $e) {
            $_SESSION['error'] = "Error saving " . ucfirst($service_name) . " credentials: " . $e->getMessage();
            error_log("[Error] Failed to save {$service_name} credentials for user {$user_id}: " . $e->getMessage());
        }
    } elseif ($action_type === 'test_connection') {
        try {
            $result = $integrationManager->testConnection($service_name, $user_id);

            if ($result['success']) {
                $_SESSION['success'] = ucfirst($service_name) . " connection successful!";
            } else {
                $_SESSION['error'] = "Connection failed to " . ucfirst($service_name);
                if (isset($result['error'])) { 
                    $_SESSION['error'] .= ": " . $result['error'];
                }
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Connection error: " . $e->getMessage();
            error_log("[Error] Integration test error for {$service_name} for user {$user_id}: " . $e->getMessage());
        }
    }
    ob_end_clean();
    header("Location: external_integrations.php?service={$service_name}");
    exit;
}

// Determine which tab to open
$active_tab = $_GET['service'] ?? 'zapier'; // Default to zapier, or any service you prefer as default.


ob_end_flush();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Integrations | Churn Analytics</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        /* Define your color variables (consistent with project theme) */
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

        /* Body and main layout styling */
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
        
        body {
            background-color: #f1f1f1;
        }
        .site-wrapper { /* Main wrapper for the entire page content (including header and footer) */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        /* The header.php output will be fixed at the top,
           and the content-container will have margin-top to push below it. */
        .content-area { /* This will wrap the entire main content of this page */
            flex-grow: 1; /* Allows content area to expand and push footer down */
            padding-top: 70px; /* Space for the fixed header. Adjust if header height changes. */
        }

        /* Integrations-specific styles */
        .integrations-container {
            max-width: 900px;
            margin: 40px auto; /* Centered with top/bottom margin */
            padding: 30px;
            border-radius: 12px;
        }
        .integrations-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 15px;
        }
        .integrations-header h1 {
            font-size: 2em;
            color: var(--dark);
            margin: 0;
        }
        .tabs {
            display: flex;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--gray-200);
            flex-wrap: wrap; /* Allow tabs to wrap on smaller screens */
        }
        .tab-btn {
            background-color: transparent;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray-600);
            border-bottom: 3px solid transparent;
            transition: all 0.2s ease;
            display: flex; /* For icons */
            align-items: center;
            gap: 8px;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-btn:hover {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-content-wrapper { /* Wrapper to contain all tab panes */
            /* No direct styling needed, just a container */
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .service-card {
            background-color: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .service-card.connected {
            border-left: 5px solid var(--success);
        }
        .service-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 10px;
            margin-bottom: 10px;
        }
        .service-header h3 {
            margin: 0;
            font-size: 1.4em;
            color: var(--dark);
        }
        .connection-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9em;
            color: var(--gray-500);
        }
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--gray-400);
        }
        .status-indicator.connected {
            background-color: var(--success);
        }
        .status-indicator.disconnected {
            background-color: var(--danger);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--gray-700);
        }
        .form-group input[type="text"],
        .form-group input[type="password"],
        .form-group input[type="url"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-size: 0.95em;
        }
        /* Style for password toggle eye icon */
        .input-group {
            position: relative;
        }
        .input-group .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--gray-500);
        }
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 15px;
        }
        .save-btn, .test-btn, .authorize-btn, .fetch-contacts-btn {
            background-color: var(--primary);
            color: var(--white);
            border: none;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 0.9em;
        }
        .save-btn:hover, .test-btn:hover, .authorize-btn:hover, .fetch-contacts-btn:hover {
            background-color: #2da89e;
        }
        .test-btn {
            background-color: var(--info);
        }
        .test-btn:hover {
            background-color: #3182ce;
        }
        .authorize-btn {
            background-color: var(--secondary);
        }
        .authorize-btn:hover {
            background-color: #367dca;
        }

        .service-stats {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px dashed var(--gray-300);
        }
        .stat {
            text-align: center;
        }
        .stat-value {
            font-size: 1.5em;
            font-weight: 600;
            color: var(--dark);
        }
        .stat-label {
            font-size: 0.8em;
            color: var(--gray-600);
        }

        /* Log tables */
        .logs-table table, .actions-list table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            background-color: var(--white);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
        }
        .logs-table th, .logs-table td, .actions-list th, .actions-list td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--gray-200);
            text-align: left;
        }
        .logs-table th, .actions-list th {
            background-color: var(--gray-100);
            font-weight: 600;
            color: var(--gray-800);
        }
        .logs-table tbody tr:hover, .actions-list tbody tr:hover {
            background-color: var(--gray-50);
        }
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 5px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-badge.success { background-color: var(--success); color: var(--white); }
        .status-badge.failed, .status-badge.error { background-color: var(--danger); color: var(--white); }
        .status-badge.pending { background-color: var(--warning); color: var(--dark); }
        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--gray-500);
            font-style: italic;
        }

        /* Modal for Log Details & External Contacts */
        .modal {
            display: none;
            position: fixed;
            z-index: 1001; /* Above other content */
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: var(--white);
            margin: 10% auto; /* 10% from the top and centered */
            padding: 20px;
            border: 1px solid var(--gray-400);
            width: 80%;
            max-width: 700px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            position: relative;
        }
        .modal-content h3 {
            margin-top: 0;
            color: var(--dark);
            border-bottom: 1px solid var(--gray-200);
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        .close {
            color: var(--gray-600);
            position: absolute;
            top: 10px;
            right: 20px;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close:hover, .close:focus {
            color: var(--dark);
            text-decoration: none;
        }
        .log-tabs button {
            background-color: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-bottom: none;
            padding: 8px 15px;
            cursor: pointer;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            margin-right: 5px;
        }
        .log-tabs button.active {
            background-color: var(--primary);
            color: var(--white);
        }
        .log-data {
            background-color: var(--gray-800);
            color: var(--white);
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.85em;
            max-height: 300px;
            overflow: auto;
            white-space: pre-wrap; /* Wrap text */
        }
        .external-contacts-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            padding: 10px;
        }
        .external-contacts-list ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .external-contacts-list li {
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-100);
            font-size: 0.9em;
            word-wrap: break-word; /* Ensure long emails/names wrap */
        }
        .external-contacts-list li:last-child {
            border-bottom: none;
        }
        .loading-spinner {
            text-align: center;
            padding: 20px;
            font-size: 1.1em;
            color: var(--gray-500);
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .col-md-9.ms-sm-auto.col-lg-10.px-md-4 {
                margin-left: 0 !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            .admin-sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
                padding: 15px;
            }
            .service-list {
                grid-template-columns: 1fr;
            }
            .modal-content {
                width: 95%;
                margin: 5% auto;
            }
        }
    </style>
</head>
<body>
    <div class="site-wrapper">
        <?php
        // The header.php will output the fixed header and start the <body> and .content-container div
        // So we need to correctly close the main content container and the site-wrapper here.
        // Assuming header.php opened: <body> <div class="header-container"> ... </div> <div class="content-container"> ...
        // We will close the .content-container and then the site-wrapper here.
        ?>
        <div class="content-container"> <div class="main-content"> <div class="integrations-container">
                    <div class="integrations-header">
                        <h1>External Integrations</h1>
                    </div>
                    
                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= $_SESSION['error'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error']); ?>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['success'])): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= $_SESSION['success'] ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success']); ?>
                    <?php endif; ?>
                    
                    <div class="tabs">
                        <?php foreach ($api_clients as $service_name_tab): ?>
                            <button class="tab-btn <?= (strtolower($service_name_tab) === $active_tab) ? 'active' : '' ?>" data-tab="<?= strtolower($service_name_tab) ?>">
                                <i data-feather="<?= 
                                    strtolower($service_name_tab) === 'zapier' ? 'zap' : (
                                    strtolower($service_name_tab) === 'stripe' ? 'credit-card' : (
                                    strtolower($service_name_tab) === 'chargebee' ? 'dollar-sign' : (
                                    strtolower($service_name_tab) === 'segment' ? 'activity' : (
                                    strtolower($service_name_tab) === 'zendesk' || strtolower($service_name_tab) === 'freshdesk' ? 'life-buoy' : (
                                    strtolower($service_name_tab) === 'mautic' ? 'shuffle' : (
                                    strtolower($service_name_tab) === 'hubspot' ? 'heart' : (
                                    strtolower($service_name_tab) === 'salesforce' ? 'cloud' : 'plug' ))))))) // Removed trailing comment
                                ?>"></i> <?= ucfirst($service_name_tab) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="tab-content-wrapper">
                        <?php foreach ($api_clients as $service): ?>
                            <?php $service_data = getServiceDataForDisplay($services_from_db, $user_profile_data, strtolower($service)); ?>
                            <div class="tab-content <?= (strtolower($service) === $active_tab) ? 'active' : '' ?>" id="<?= strtolower($service) ?>">
                                <div class="service-card <?= $service_data && (!empty($service_data['last_connected_at']) || !empty($service_data['api_key']) || !empty($service_data['access_token']) || !empty($service_data['base_url']) || (!empty($service_data['metadata']['client_id']) && !empty($service_data['metadata']['client_secret']))) ? 'connected' : '' ?>">
                                    <div class="service-header">
                                        <h3><?= ucfirst($service) ?></h3>
                                        <div class="connection-status">
                                            <span class="status-indicator <?= $service_data && (!empty($service_data['last_connected_at']) || !empty($service_data['api_key']) || !empty($service_data['access_token']) || !empty($service_data['base_url']) || (!empty($service_data['metadata']['client_id']) && !empty($service_data['metadata']['client_secret']))) ? 'connected' : 'disconnected' ?>"></span>
                                            <?php 
                                            // FIX for "Undefined array key updated_at"
                                            // Ensure 'updated_at' key exists AND is not empty before attempting to format the date.
                                            if (isset($service_data['updated_at']) && !empty($service_data['updated_at'])): 
                                            ?>
                                                <span class="last-connected">
                                                    Last Connected: <?= date('M j, Y H:i', strtotime($service_data['updated_at'])) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="last-connected">Last Connected: Never</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <form method="POST" class="service-config-form" data-service="<?= strtolower($service) ?>">
                                        <input type="hidden" name="service_name" value="<?= strtolower($service) ?>">
                                        <input type="hidden" name="action_type" value="save_service"> <?php if (strtolower($service) === 'stripe'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_api_key">Secret API Key</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_api_key" name="api_key" 
                                                           value="<?= htmlspecialchars($service_data['api_key'] ?? '') ?>" 
                                                           placeholder="sk_live_XXXXXXXXXXXXXXXXXXXXXXX" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_api_key"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                        <?php elseif (strtolower($service) === 'chargebee'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_api_key">API Key</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_api_key" name="api_key" 
                                                           value="<?= htmlspecialchars($service_data['api_key'] ?? '') ?>" 
                                                           placeholder="Your Chargebee API Key" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_api_key"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_site_name">Site Name</label>
                                                <input type="text" id="<?= strtolower($service) ?>_site_name" name="site_name" 
                                                       value="<?= htmlspecialchars($service_data['metadata']['site_name'] ?? '') ?>" 
                                                       placeholder="e.g., yourcompany-test" required>
                                            </div>
                                        <?php elseif (strtolower($service) === 'segment'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_api_key">Write Key</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_api_key" name="api_key" 
                                                           value="<?= htmlspecialchars($service_data['api_key'] ?? '') ?>" 
                                                           placeholder="XXXXXXXXXXXXXXXXXXXX" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_api_key"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                        <?php elseif (strtolower($service) === 'zendesk'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_api_key">API Token</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_api_key" name="api_key" 
                                                           value="<?= htmlspecialchars($service_data['api_key'] ?? '') ?>" 
                                                           placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_api_key"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_subdomain">Subdomain</label>
                                                <input type="text" id="<?= strtolower($service) ?>_subdomain" name="subdomain" 
                                                       value="<?= htmlspecialchars($service_data['metadata']['subdomain'] ?? '') ?>" 
                                                       placeholder="e.g., yourcompany" required>
                                            </div>
                                        <?php elseif (strtolower($service) === 'freshdesk'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_api_key">API Key</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_api_key" name="api_key" 
                                                           value="<?= htmlspecialchars($service_data['api_key'] ?? '') ?>" 
                                                           placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_api_key"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_domain">Domain</label>
                                                <input type="text" id="<?= strtolower($service) ?>_domain" name="domain" 
                                                       value="<?= htmlspecialchars($service_data['metadata']['domain'] ?? '') ?>" 
                                                       placeholder="e.g., yourcompany.freshdesk.com" required>
                                            </div>
                                        <?php elseif (strtolower($service) === 'mautic' || strtolower($service) === 'hubspot' || strtolower($service) === 'salesforce'): // OAuth services ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_client_id">Client ID</label>
                                                <input type="text" id="<?= strtolower($service) ?>_client_id" name="client_id" 
                                                       value="<?= htmlspecialchars($service_data['metadata']['client_id'] ?? '') ?>" 
                                                       placeholder="Your App Client ID" required>
                                            </div>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_client_secret">Client Secret</label>
                                                <div class="input-group">
                                                    <input type="password" id="<?= strtolower($service) ?>_client_secret" name="client_secret" 
                                                           value="<?= htmlspecialchars($service_data['metadata']['client_secret'] ?? '') ?>" 
                                                           placeholder="Your App Client Secret" required>
                                                    <span class="password-toggle" data-target="<?= strtolower($service) ?>_client_secret"><i class="bi bi-eye"></i></span>
                                                </div>
                                            </div>
                                            <?php if (strtolower($service) === 'mautic' || strtolower($service) === 'salesforce'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_base_url"><?= ucfirst($service) ?> Base URL</label>
                                                <input type="url" id="<?= strtolower($service) ?>_base_url" name="base_url" 
                                                       value="<?= htmlspecialchars($service_data['base_url'] ?? '') ?>" 
                                                       placeholder="https://your-instance.com" required>
                                            </div>
                                            <?php endif; ?>
                                            <div class="form-group">
                                                <label>OAuth Tokens</label>
                                                <input type="text" id="<?= strtolower($service) ?>_access_token" value="<?= htmlspecialchars($service_data['access_token'] ? 'Access Token Saved' : 'No Access Token') ?>" readonly>
                                                <input type="text" id="<?= strtolower($service) ?>_refresh_token" value="<?= htmlspecialchars($service_data['refresh_token'] ? 'Refresh Token Saved' : 'No Refresh Token') ?>" readonly>
                                                <?php if (!empty($service_data['token_expires'])): ?>
                                                    <small class="text-muted">Expires: <?= date('M j, Y H:i', strtotime($service_data['token_expires'])) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <a href="#" class="authorize-btn" data-service="<?= strtolower($service) ?>" style="margin-top: 10px;">
                                                <i data-feather="lock"></i> Authorize with <?= ucfirst($service) ?> (OAuth)
                                            </a>
                                        <?php elseif (strtolower($service) === 'zapier'): ?>
                                            <div class="form-group">
                                                <label for="<?= strtolower($service) ?>_webhook_url">Zapier Webhook URL</label>
                                                <input type="url" id="<?= strtolower($service) ?>_webhook_url" name="base_url" 
                                                       value="<?= htmlspecialchars($service_data['base_url'] ?? '') ?>"
                                                       placeholder="https://hooks.zapier.com/hooks/catch/..." required>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="form-actions">
                                            <button type="submit" name="save_service" class="save-btn">Save Credentials</button>
                                            <?php 
                                            // Determine if a test button should be shown for the current service.
                                            // A test is possible if relevant credentials exist.
                                            $has_credentials_for_test = false;
                                            if (in_array(strtolower($service), ['stripe', 'chargebee', 'segment', 'zendesk', 'freshdesk'])) {
                                                $has_credentials_for_test = !empty($service_data['api_key']);
                                                // For Chargebee, Zendesk, Freshdesk, also need site_name/subdomain/domain from metadata
                                                if (strtolower($service) === 'chargebee') {
                                                    $has_credentials_for_test = $has_credentials_for_test && !empty($service_data['metadata']['site_name']);
                                                } elseif (strtolower($service) === 'zendesk') {
                                                    $has_credentials_for_test = $has_credentials_for_test && !empty($service_data['metadata']['subdomain']);
                                                } elseif (strtolower($service) === 'freshdesk') {
                                                    $has_credentials_for_test = $has_credentials_for_test && !empty($service_data['metadata']['domain']);
                                                }
                                            } elseif (in_array(strtolower($service), ['mautic', 'hubspot', 'salesforce'])) {
                                                // For OAuth services, test button is enabled if access token exists.
                                                // If no access token, but client ID/Secret are filled, the "Authorize" button is used for the *first* test.
                                                $has_credentials_for_test = !empty($service_data['access_token']) || (!empty($service_data['metadata']['client_id']) && !empty($service_data['metadata']['client_secret']));
                                            } elseif (strtolower($service) === 'zapier') {
                                                $has_credentials_for_test = !empty($service_data['base_url']);
                                            }

                                            if ($has_credentials_for_test): 
                                            ?>
                                                <button type="submit" name="test_connection" class="test-btn">Test Connection</button>
                                            <?php endif; ?>
                                        </div>
                                    </form>

                                    <?php 
                                    // Show contact fetch section if the service supports it and has credentials.
                                    $supports_contact_fetch = in_array(strtolower($service), ['stripe', 'chargebee', 'zendesk', 'freshdesk', 'mautic', 'hubspot', 'salesforce']);
                                    if ($supports_contact_fetch && $has_credentials_for_test): 
                                    ?>
                                        <div class="contact-fetch-section mt-4 pt-3 border-top">
                                            <h4>Fetch External Contacts</h4>
                                            <p class="text-muted">Retrieve a list of contacts from your integrated <?= ucfirst($service) ?> account.</p>
                                            <button type="button" class="fetch-contacts-btn" data-service="<?= strtolower($service) ?>">
                                                <i data-feather="refresh-cw"></i> Fetch Contacts
                                            </button>
                                            <div class="external-contacts-list mt-3" id="<?= strtolower($service) ?>-contacts-list">
                                                <div class="loading-spinner" style="display:none;">Loading contacts...</div>
                                                <ul>
                                                    </ul>
                                                <div class="no-contacts-message" style="display:none;">No contacts found or error fetching.</div>
                                            </div>
                                        </div>
                                    <?php elseif (strtolower($service) === 'zapier' && $has_credentials_for_test): ?>
                                        <div class="contact-fetch-section mt-4 pt-3 border-top"> 
                                            <h4>Information</h4>
                                            <p class="text-info">Zapier is a webhook service for *sending* data and does not support fetching contacts directly.</p>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($service_data): ?>
                                        <div class="service-stats">
                                            <div class="stat">
                                                <span class="stat-value">0</span><span class="stat-label">Configured Actions</span>
                                            </div>
                                            <div class="stat">
                                                <span class="stat-value"><?= 
                                                    (isset($service_data['updated_at']) && !empty($service_data['updated_at'])) 
                                                    ? date('M j, Y H:i', strtotime($service_data['updated_at'])) 
                                                    : 'Never' 
                                                ?></span>
                                                <span class="stat-label">Last Saved</span>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="tab-content" id="actions" style="display:none;">
                        <h3>Configured External Actions</h3>
                        <p class="empty-state">No external actions configured yet. This section is where you would list specific actions you've set up, like "Add contact to HubSpot list".</p>
                    </div>
                    
                    <div class="tab-content" id="logs" style="display:none;">
                          <h3>External Action Logs</h3>
                        <form method="GET" class="log-filter">
                            <input type="hidden" name="tab" value="logs">
                            <div class="form-group">
                                <label>From</label>
                                <input type="date" name="date_from" value="<?= htmlspecialchars($_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'))) ?>">
                            </div>
                            <div class="form-group">
                                <label>To</label>
                                <input type="date" name="date_to" value="<?= htmlspecialchars($_GET['date_to'] ?? date('Y-m-d')) ?>">
                            </div>
                            <button type="submit" class="filter-btn">Filter Logs</button>
                        </form>
                        
                        <div class="logs-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Action Type</th>
                                        <th>Status</th>
                                        <th>Details</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($logs)): ?>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td><?= date('M j, H:i', strtotime($log['execution_time'])) ?></td>
                                                <td><?= htmlspecialchars($log['action_type']) ?></td>
                                                <td>
                                                    <span class="status-badge <?= $log['status'] ?>">
                                                        <?= ucfirst($log['status']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="view-log-btn" data-log-payload="<?= htmlspecialchars($log['payload'] ?? '{}') ?>" data-log-response="<?= htmlspecialchars($log['response'] ?? '{}') ?>">View Details</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="4" class="empty-state">No logs found for this period.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div> <div class="modal" id="logModal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h3>Log Details</h3>
                <div class="log-tabs">
                    <button class="log-tab-btn active" data-tab="payload">Payload</button>
                    <button class="log-tab-btn" data-tab="response">Response</button>
                </div>
                <pre class="log-data" id="payloadData"></pre>
                <pre class="log-data" id="responseData" style="display:none;"></pre>
            </div>
        </div>

        <?php require_once 'includes/footer.php'; ?> </div> <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Initialize Feather Icons
        feather.replace();

        // --- Tab Switching Logic ---
        document.addEventListener('DOMContentLoaded', () => {
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            const urlParams = new URLSearchParams(window.location.search);
            const serviceParam = urlParams.get('service');
            const tabParam = urlParams.get('tab');
            // Default to 'mautic' or the first available client if no specific service is in URL
            let activeTab = 'mautic'; 
            if (tabButtons.length > 0 && !(serviceParam || tabParam)) {
                activeTab = tabButtons[0].dataset.tab; // Default to the first button's data-tab
            } else if (serviceParam) {
                activeTab = serviceParam;
            } else if (tabParam) {
                activeTab = tabParam;
            }
            

            function activateTab(tabId) {
                tabButtons.forEach(btn => {
                    btn.classList.remove('active');
                });
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    content.style.display = 'none'; // Hide content by default
                });

                const targetButton = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
                const targetContent = document.getElementById(tabId);

                if (targetButton && targetContent) {
                    targetButton.classList.add('active');
                    targetContent.classList.add('active');
                    targetContent.style.display = 'block'; // Show active content
                } else {
                    // Fallback to the first available tab if URL param is invalid or not found
                    const firstAvailableTabButton = document.querySelector('.tab-btn');
                    if (firstAvailableTabButton) {
                        const firstTabId = firstAvailableTabButton.dataset.tab;
                        const firstTabContent = document.getElementById(firstTabId);
                        if (firstTabButton && firstTabContent) {
                            firstTabButton.classList.add('active');
                            firstTabContent.classList.add('active');
                            firstTabContent.style.display = 'block';
                        }
                    }
                }
            }

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    activateTab(this.dataset.tab);
                });
            });

            // Activate tab on page load based on URL or default
            activateTab(activeTab);
        });

        // --- Password Visibility Toggle ---
        document.querySelectorAll('.password-toggle').forEach(toggle => {
            toggle.addEventListener('click', function() {
                const targetId = this.getAttribute('data-target');
                const input = document.getElementById(targetId);
                const icon = this.querySelector('i');
                
                if (input.type === 'password') {
                    input.type = 'text';
                    icon.classList.remove('bi-eye');
                    icon.classList.add('bi-eye-slash');
                } else {
                    input.type = 'password';
                    icon.classList.remove('bi-eye-slash');
                    icon.classList.add('bi-eye');
                }
            });
        });

        // --- Log Modal Functionality ---
        const logModal = document.getElementById('logModal');
        const closeModalBtn = logModal.querySelector('.close');
        const payloadDataPre = document.getElementById('payloadData');
        const responseDataPre = document.getElementById('responseData');
        const logTabButtons = logModal.querySelectorAll('.log-tab-btn');

        document.querySelectorAll('.view-log-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const payload = JSON.parse(this.dataset.logPayload);
                const response = JSON.parse(this.dataset.logResponse);
                
                payloadDataPre.textContent = JSON.stringify(payload, null, 2);
                responseDataPre.textContent = JSON.stringify(response, null, 2);

                // Reset modal tabs to Payload view
                logTabButtons.forEach(tabBtn => tabBtn.classList.remove('active'));
                logModal.querySelector('.log-tab-btn[data-tab="payload"]').classList.add('active');
                payloadDataPre.style.display = 'block';
                responseDataPre.style.display = 'none';

                logModal.style.display = 'block';
            });
        });
        
        closeModalBtn.addEventListener('click', () => {
            logModal.style.display = 'none';
        });

        window.addEventListener('click', (event) => {
            if (event.target === logModal) {
                logModal.style.display = 'none';
            }
        });

        logTabButtons.forEach(btn => {
            btn.addEventListener('click', function() {
                logTabButtons.forEach(tabBtn => tabBtn.classList.remove('active'));
                this.classList.add('active');

                payloadDataPre.style.display = 'none';
                responseDataPre.style.display = 'none';

                if (this.dataset.tab === 'payload') {
                    payloadDataPre.style.display = 'block';
                } else if (this.dataset.tab === 'response') {
                    responseDataPre.style.display = 'block';
                }
            });
        });

        // --- OAuth Authorization Links ---
        // This JS section handles the client-side initiation of OAuth flows.
        document.querySelectorAll('.authorize-btn').forEach(button => {
            button.addEventListener('click', async function(e) {
                e.preventDefault();
                const service = this.dataset.service;
                const form = this.closest('form');
                
                let clientId = null;
                let clientSecret = null;
                let baseUrl = null;

                // Dynamically get relevant fields based on service (for POST to /api/oauth/authorize)
                if (service === 'mautic' || service === 'hubspot' || service === 'salesforce') {
                    clientId = form.querySelector(`#${service}_client_id`)?.value;
                    clientSecret = form.querySelector(`#${service}_client_secret`)?.value;
                    // Mautic and Salesforce need base_url for auth URL construction
                    if (service === 'mautic' || service === 'salesforce') {
                        baseUrl = form.querySelector(`#${service}_base_url`)?.value;
                    }
                }

                if (!clientId || !clientSecret || (service === 'mautic' && !baseUrl)) {
                    alert('Please fill in all required OAuth credentials (Client ID, Client Secret, and Base URL for Mautic/Salesforce) before authorizing.');
                    return;
                }

                // Call the API endpoint to get the authorization URL
                try {
                    const response = await fetch('api/index.php/oauth/authorize', { // Path from root
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-API-KEY': <?= json_encode($user_api_key ?? '') ?> // CORRECTED: Use json_encode
                        },
                        body: JSON.stringify({
                            service: service,
                            client_id: clientId,
                            client_secret: clientSecret,
                            base_url: baseUrl, // Passed to backend for client's getAuthorizationUrl
                            redirect_uri: '<?= BASE_URL ?>/external_integrations.php' // Our platform's redirect URI
                        })
                    });

                    const result = await response.json();
                    if (result.success && result.authorization_url) {
                        window.location.href = result.authorization_url; // Redirect user to OAuth provider
                    } else {
                        alert('Failed to get authorization URL: ' + (result.error || 'Unknown error'));
                        console.error('OAuth Auth URL Error:', result);
                    }
                } catch (error) {
                    alert('Error initiating OAuth authorization. Check console for details.');
                    console.error('OAuth initiation fetch error:', error);
                }
            });
        });

        // --- Handle OAuth Callback (on page load if redirected back from OAuth provider) ---
        // This JS section runs on page load after the OAuth provider redirects back.
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const authCode = urlParams.get('code');
            const authState = urlParams.get('state'); // State for CSRF protection
            const authService = urlParams.get('service'); // Custom param to know which service is redirecting

            // Only proceed if an auth code and service are present
            if (authCode && authService) {
                // Remove the code and state from URL to clean it up and prevent re-processing on refresh
                urlParams.delete('code');
                urlParams.delete('state');
                urlParams.delete('service');
                history.replaceState(null, '', '?' + urlParams.toString());

                // Display a loading message while tokens are exchanged
                const currentTabContent = document.getElementById(authService);
                if (currentTabContent) {
                    currentTabContent.innerHTML = '<div class="loading-spinner">Processing authorization... Please wait.</div>';
                    currentTabContent.style.display = 'block';
                }

                // Exchange code for tokens via AJAX
                fetch('api/index.php/oauth/token', { // Path to our backend token exchange endpoint
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-API-KEY': <?= json_encode($user_api_key ?? '') ?> // CORRECTED: Use json_encode
                    },
                    body: JSON.stringify({
                        service: authService,
                        code: authCode,
                        state: authState, // Pass state for verification on server
                        redirect_uri: '<?= BASE_URL ?>/external_integrations.php' // Must match the URI used in authorize step
                    })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert(`Successfully authorized with ${ucfirst(authService)}! Tokens saved.`);
                        // Redirect to the service's tab with success message for clean URL and state
                        window.location.href = `external_integrations.php?service=${authService}&success=1`;
                    } else {
                        alert(`Failed to exchange token with ${ucfirst(authService)}: ` + (result.error || 'Unknown error'));
                        console.error('OAuth Token Exchange Error:', result);
                        window.location.href = `external_integrations.php?service=${authService}&error=1`; // Redirect with error
                    }
                })
                .catch(error => {
                    alert(`Network error during OAuth token exchange with ${ucfirst(authService)}.`);
                    console.error('OAuth token exchange fetch error:', error);
                    window.location.href = `external_integrations.php?service=${authService}&error=1`;
                });
            } else if (urlParams.has('success') || urlParams.has('error')) {
                // Clean up success/error parameters from URL after display
                urlParams.delete('success');
                urlParams.delete('error');
                history.replaceState(null, '', '?' + urlParams.toString());
            }
        });

        // Helper for ucfirst
        function ucfirst(string) {
            return string.charAt(0).toUpperCase() + string.slice(1);
        }

        // --- Fetch External Contacts Button ---
        // This JS section handles fetching contacts from external services via AJAX.
        document.querySelectorAll('.fetch-contacts-btn').forEach(button => {
            button.addEventListener('click', async function() {
                const service = this.dataset.service;
                const contactsListDiv = document.getElementById(`${service}-contacts-list`);
                const loadingSpinner = contactsListDiv.querySelector('.loading-spinner');
                const contactsUl = contactsListDiv.querySelector('ul');
                const noContactsMessage = contactsListDiv.querySelector('.no-contacts-message');

                contactsUl.innerHTML = ''; // Clear previous list
                noContactsMessage.style.display = 'none';
                loadingSpinner.style.display = 'block'; // Show spinner

                try {
                    const response = await fetch(`api/index.php/integrations/contacts?service=${service}`, { // Call our backend API
                        method: 'GET',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-API-KEY': <?= json_encode($user_api_key ?? '') ?> // CORRECTED: Use json_encode
                        }
                    });

                    const result = await response.json();

                    if (response.ok && result.success && result.contacts) {
                        if (result.contacts.length > 0) {
                            result.contacts.forEach(contact => {
                                const li = document.createElement('li');
                                // Display basic contact info. Adjust as needed for each service's contact structure
                                li.textContent = `${contact.name || contact.email || 'N/A'} (ID: ${contact.id || 'N/A'}) - ${contact.email || ''}`;
                                contactsUl.appendChild(li);
                            });
                        } else {
                            noContactsMessage.textContent = `No contacts found in your ${ucfirst(service)} account.`;
                            noContactsMessage.style.display = 'block';
                        }
                    } else {
                        noContactsMessage.textContent = `Error fetching contacts: ${result.error || 'Unknown error'}`;
                        noContactsMessage.style.display = 'block';
                        console.error('Fetch Contacts Error:', result);
                    }
                } catch (error) {
                    noContactsMessage.textContent = `Network error: ${error.message}. Check console.`;
                    noContactsMessage.style.display = 'block';
                    console.error('Fetch contacts network error:', error);
                } finally {
                    loadingSpinner.style.display = 'none'; // Hide spinner
                }
            });
        });

        // Keep correct tab active on page load via URL parameter `?service=` or `?tab=`
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-btn');
            const tabContents = document.querySelectorAll('.tab-content');
            const urlParams = new URLSearchParams(window.location.search);
            const serviceParam = urlParams.get('service');
            const tabParam = urlParams.get('tab');
            let activeTab = 'zapier'; // Default to 'zapier' or the first client in the list

            // Find the first client in the `api_clients` array defined in PHP and make it default if no param is set
            // or if the parameter is invalid.
            const phpApiClients = <?= json_encode(array_map('strtolower', $api_clients)) ?>; // Get the actual PHP list as JS array
            if (!phpApiClients.includes(activeTab) && phpApiClients.length > 0) {
                activeTab = phpApiClients[0]; // Set default to the first *available* client
            }
            if (serviceParam && phpApiClients.includes(serviceParam)) {
                activeTab = serviceParam;
            } else if (tabParam && phpApiClients.includes(tabParam)) {
                activeTab = tabParam;
            }
            
            function activateTab(tabId) {
                tabButtons.forEach(btn => {
                    btn.classList.remove('active');
                });
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    content.style.display = 'none'; // Hide content by default
                });

                const targetButton = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
                const targetContent = document.getElementById(tabId);

                if (targetButton && targetContent) {
                    targetButton.classList.add('active');
                    targetContent.classList.add('active');
                    targetContent.style.display = 'block'; // Show active content
                } else {
                    // Fallback to the first available tab if provided tabId is invalid
                    if (phpApiClients.length > 0) {
                        const firstValidTabId = phpApiClients[0];
                        const firstValidTabButton = document.querySelector(`.tab-btn[data-tab="${firstValidTabId}"]`);
                        const firstValidTabContent = document.getElementById(firstValidTabId);
                        if (firstValidTabButton && firstValidTabContent) {
                            firstValidTabButton.classList.add('active');
                            firstValidTabContent.classList.add('active');
                            firstValidTabContent.style.display = 'block';
                        }
                    }
                }
            }

            tabButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    activateTab(this.dataset.tab);
                });
            });

            // Activate tab on page load based on URL or determined default
            activateTab(activeTab);
        });

    </script>
</body>
</html>