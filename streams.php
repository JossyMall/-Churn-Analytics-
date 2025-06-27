<?php
session_start(); // 1. Ensure session is started FIRST

// 2. IMPORTANT: Include DB connection here, before any form processing or HTML output
require_once 'includes/db.php'; // Make $pdo and BASE_URL available
require_once 'includes/notification_functions.php'; // Required for create_notification

// 3. Check if user is logged in and redirect if not
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// --- All PHP functions go here (before form handling) ---

/**
 * Checks if the current user is the direct owner of a stream.
 * @param int $user_id The ID of the current user.
 * @param int $stream_id The ID of the stream.
 * @return bool True if the user owns the stream, false otherwise.
 */
function user_owns_stream($user_id, $stream_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM streams WHERE id = ? AND user_id = ?");
    $stmt->execute([$stream_id, $user_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Checks if the user is an owner of a specific team.
 * @param int $user_id The ID of the current user.
 * @param int $team_id The ID of the team.
 * @return bool True if the user is an owner of the team, false otherwise.
 */
function is_team_owner($user_id, $team_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ? AND role = 'owner'");
    $stmt->execute([$team_id, $user_id]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Retrieves all teams a user belongs to, along with their role in each team.
 * @param int $user_id The ID of the current user.
 * @return array An array of team data.
 */
function get_user_teams($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT t.*, tm.role 
                            FROM teams t 
                            JOIN team_members tm ON t.id = tm.team_id 
                            WHERE tm.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Determines the effective access level of a user for a given stream.
 * Precedence: Owner > Team Editor > Team Viewer.
 * @param int $user_id The ID of the current user.
 * @param int $stream_id The ID of the stream.
 * @return string 'owner', 'editor', 'viewer', or 'none'.
 */
function get_stream_effective_access_level($user_id, $stream_id) {
    global $pdo;

    // 1. Check if user is the direct owner of the stream
    if (user_owns_stream($user_id, $stream_id)) {
        return 'owner';
    }

    // 2. Check if user has access via a team
    $stmt = $pdo->prepare("
        SELECT tm.role AS member_role, ts.access_level AS stream_access_level
        FROM team_streams ts
        JOIN team_members tm ON ts.team_id = tm.team_id
        WHERE ts.stream_id = ? AND tm.user_id = ?
        ORDER BY FIELD(tm.role, 'owner', 'editor', 'viewer') -- Prioritize higher team roles
    ");
    $stmt->execute([$stream_id, $user_id]);
    $team_accesses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $effective_team_access = 'none';

    foreach ($team_accesses as $access) {
        // A team owner in a team with 'edit' stream access means editor for stream
        if ($access['member_role'] === 'owner' && $access['stream_access_level'] === 'edit') {
            return 'editor'; // Team owner effectively has editor rights if stream is shared with edit access
        }
        // A team editor in a team with 'edit' stream access
        if ($access['member_role'] === 'editor' && $access['stream_access_level'] === 'edit') {
            return 'editor';
        }
        // If current effective access is 'none' and team provides 'view'
        if ($effective_team_access === 'none' && $access['stream_access_level'] === 'view') {
            $effective_team_access = 'viewer';
        }
    }

    return $effective_team_access;
}


// --- PHP Error Reporting for Debugging (optional, keep at top during dev) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- End PHP Error Reporting ---


// --- Handle Stream Sharing via GET parameter ---
// This must come before any HTML output, as it performs a redirect
if (isset($_GET['share'])) {
    $stream_id = (int)$_GET['id'];
    $team_id = (int)$_GET['share'];
    
    // Verify user owns the stream AND is an owner of the team
    if (user_owns_stream($_SESSION['user_id'], $stream_id) && 
        is_team_owner($_SESSION['user_id'], $team_id)) {
        
        try {
            $pdo->beginTransaction();
            
            // Check if team_streams table exists (should ideally be in a migration or db setup script)
            // Removed for brevity and assuming table exists as per provided schema.
            // If it doesn't, uncomment and ensure primary key setup is correct.
            /*
            $stmt = $pdo->query("SHOW TABLES LIKE 'team_streams'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS team_streams (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    team_id INT NOT NULL,
                    stream_id INT NOT NULL,
                    access_level VARCHAR(20) NOT NULL DEFAULT 'view',
                    added_by INT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY (team_id, stream_id)
                )");
            }
            */

            // Check if already shared
            $stmt = $pdo->prepare("SELECT 1 FROM team_streams WHERE team_id = ? AND stream_id = ?");
            $stmt->execute([$team_id, $stream_id]);
            
            if (!$stmt->fetchColumn()) {
                $stmt = $pdo->prepare("INSERT INTO team_streams (team_id, stream_id, access_level, added_by) 
                                         VALUES (?, ?, 'view', ?)"); // Default to 'view' access
                $stmt->execute([$team_id, $stream_id, $_SESSION['user_id']]);
                
                $_SESSION['success'] = "Stream shared successfully!";
            } else {
                $_SESSION['info'] = "Stream is already shared with this team.";
            }
            
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error sharing stream: " . $e->getMessage();
            error_log("Stream sharing error: " . $e->getMessage());
        }
    } else {
        $_SESSION['error'] = "Unauthorized attempt to share stream or team. You must own both the stream and the team to share.";
    }
    
    header("Location: streams.php" . ($stream_id ? "?id=$stream_id" : ""));
    exit;
}

// --- Handle form submission (POST requests) ---
// This must also be before any HTML output, as it performs redirects
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_stream'])) {
        // Get membership limits
        $stmt_limit = $pdo->prepare("SELECT ml.max_streams FROM user_subscriptions us JOIN membership_levels ml ON us.membership_id = ml.id WHERE us.user_id = ? AND us.is_active = 1");
        $stmt_limit->execute([$user_id]);
        $max_streams = $stmt_limit->fetchColumn() ?? 1;

        // Get current stream count
        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM streams WHERE user_id = ?");
        $stmt_count->execute([$user_id]);
        $current_streams = $stmt_count->fetchColumn();

        if ($current_streams >= $max_streams) {
            $_SESSION['error'] = "You've reached your maximum number of streams ($max_streams) for your membership level.";
        } else {
            $name = trim($_POST['name']);
            $website_url = isset($_POST['website_url']) ? trim($_POST['website_url']) : null; // Use null for empty string
            $is_app = isset($_POST['is_app']) ? 1 : 0;
            $description = isset($_POST['description']) ? trim($_POST['description']) : null;
            $color_code = isset($_POST['color_code']) ? $_POST['color_code'] : '#3ac3b8';
            $niche_id = isset($_POST['niche_id']) && $_POST['niche_id'] !== '' ? (int)$_POST['niche_id'] : null;
            $acquisition_cost = isset($_POST['acquisition_cost']) ? floatval($_POST['acquisition_cost']) : 0.00;
            $marketing_channel = isset($_POST['marketing_channel']) ? trim($_POST['marketing_channel']) : null;
            $revenue_per_user = isset($_POST['revenue_per_user']) ? floatval($_POST['revenue_per_user']) : 0.00;
            $currency = isset($_POST['currency']) ? $_POST['currency'] : 'USD';
            
            // Handle cover image upload
            $cover_image = null;
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'streams_cover/';
                if (!is_dir($upload_dir)) { // Ensure directory exists
                    mkdir($upload_dir, 0755, true);
                }
                $file_ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid('stream_cover_') . '.' . $file_ext;
                $upload_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_path)) {
                    $cover_image = $file_name;
                } else {
                    $_SESSION['error'] = "Failed to upload cover image.";
                    error_log("Stream cover image upload failed: " . $_FILES['cover_image']['error']);
                }
            }
            
            $tracking_code = bin2hex(random_bytes(16)); // Generate tracking code
            
            try {
                $stmt = $pdo->prepare("INSERT INTO streams 
                                         (user_id, name, website_url, is_app, description, 
                                         color_code, niche_id, tracking_code, acquisition_cost,
                                         cover_image, marketing_channel, revenue_per_user, currency)
                                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $user_id, $name, $website_url, $is_app, $description, 
                    $color_code, $niche_id, $tracking_code, $acquisition_cost,
                    $cover_image, $marketing_channel, $revenue_per_user, $currency
                ]);
                
                $_SESSION['success'] = "Stream created successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error creating stream: " . $e->getMessage();
                error_log("Stream creation error: " . $e->getMessage());
            }
        }
        header('Location: streams.php');
        exit;
    }
    elseif (isset($_POST['edit_stream'])) {
        $stream_id = (int)$_POST['stream_id'];
        $effective_access = get_stream_effective_access_level($user_id, $stream_id);

        if ($effective_access === 'owner' || $effective_access === 'editor') {
            $name = trim($_POST['name']);
            $description = isset($_POST['description']) ? trim($_POST['description']) : null;
            $niche_id = isset($_POST['niche_id']) && $_POST['niche_id'] !== '' ? (int)$_POST['niche_id'] : null;
            $acquisition_cost = isset($_POST['acquisition_cost']) ? floatval($_POST['acquisition_cost']) : 0.00;
            $revenue_per_user = isset($_POST['revenue_per_user']) ? floatval($_POST['revenue_per_user']) : 0.00;
            $marketing_channel = isset($_POST['marketing_channel']) ? trim($_POST['marketing_channel']) : null;
            $is_app = isset($_POST['is_app']) ? 1 : 0;
            $website_url = isset($_POST['website_url']) && !$is_app ? trim($_POST['website_url']) : null; // Clear URL if it's an app
            $color_code = isset($_POST['color_code']) ? $_POST['color_code'] : '#3ac3b8';
            $currency = isset($_POST['currency']) ? $_POST['currency'] : 'USD';

            // Fetch current stream details for comparison or old image path
            $stmt_current = $pdo->prepare("SELECT cover_image FROM streams WHERE id = ?");
            $stmt_current->execute([$stream_id]);
            $current_stream_data = $stmt_current->fetch(PDO::FETCH_ASSOC);
            $old_cover_image = $current_stream_data['cover_image'] ?? null;
            $new_cover_image = $old_cover_image; // Assume no change unless new file or delete action

            // Handle cover image upload/removal
            if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'streams_cover/';
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0755, true); }
                $file_ext = pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION);
                $file_name = uniqid('stream_cover_edit_') . '.' . $file_ext;
                $upload_path = $upload_dir . $file_name;
                
                if (move_uploaded_file($_FILES['cover_image']['tmp_name'], $upload_path)) {
                    $new_cover_image = $file_name;
                    // Delete old image if new one uploaded
                    if ($old_cover_image && file_exists($upload_dir . $old_cover_image)) {
                        unlink($upload_dir . $old_cover_image);
                    }
                } else {
                    $_SESSION['error'] = "Failed to upload new cover image.";
                    error_log("Stream cover image update failed: " . $_FILES['cover_image']['error']);
                    header('Location: streams.php?id=' . $stream_id);
                    exit;
                }
            } elseif (isset($_POST['remove_current_cover_image']) && $_POST['remove_current_cover_image'] == '1') {
                // User explicitly wants to remove the existing image
                if ($old_cover_image && file_exists('streams_cover/' . $old_cover_image)) {
                    unlink('streams_cover/' . $old_cover_image);
                }
                $new_cover_image = null;
            }

            try {
                $stmt = $pdo->prepare("UPDATE streams SET 
                                         name = ?, description = ?, niche_id = ?, 
                                         acquisition_cost = ?, revenue_per_user = ?, 
                                         marketing_channel = ?, is_app = ?, website_url = ?, 
                                         color_code = ?, cover_image = ?, currency = ?
                                         WHERE id = ?");
                $stmt->execute([
                    $name, $description, $niche_id,
                    $acquisition_cost, $revenue_per_user,
                    $marketing_channel, $is_app, $website_url,
                    $color_code, $new_cover_image, $currency,
                    $stream_id
                ]);
                
                $_SESSION['success'] = "Stream updated successfully!";
            } catch (PDOException $e) {
                $_SESSION['error'] = "Error updating stream: " . $e->getMessage();
                error_log("Stream update error: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "You do not have permission to edit this stream.";
        }
        header('Location: streams.php?id=' . $stream_id);
        exit;
    }
    elseif (isset($_POST['delete_stream'])) {
        $stream_id = (int)$_POST['stream_id'];
        $effective_access = get_stream_effective_access_level($user_id, $stream_id);
        
        // ONLY THE DIRECT OWNER CAN DELETE A STREAM
        if ($effective_access === 'owner') {
            try {
                $pdo->beginTransaction();

                // Fetch stream for cover image deletion
                $stmt = $pdo->prepare("SELECT cover_image FROM streams WHERE id = ?");
                $stmt->execute([$stream_id]);
                $stream_to_delete = $stmt->fetch(PDO::FETCH_ASSOC);

                // Delete cover image if exists
                if ($stream_to_delete && $stream_to_delete['cover_image']) {
                    $cover_path = 'streams_cover/' . $stream_to_delete['cover_image'];
                    if (file_exists($cover_path)) {
                        unlink($cover_path);
                    }
                }

                // Delete related data first due to foreign key constraints (ORDER IS IMPORTANT!)
                // Add any other tables linked to streams (e.g., cohorts, metric_data, churn_scores, etc.)
                // These must be deleted before streams, or set ON DELETE CASCADE in DB schema
                $pdo->prepare("DELETE FROM contacts WHERE stream_id = ?")->execute([$stream_id]);
                $pdo->prepare("DELETE FROM cohorts WHERE stream_id = ?")->execute([$stream_id]);
                $pdo->prepare("DELETE FROM team_streams WHERE stream_id = ?")->execute([$stream_id]);
                // Add more deletions here for other related tables (e.g., features, metric_data for contacts in this stream)
                // For example:
                // $pdo->prepare("DELETE FROM feature_trends WHERE stream_id = ?")->execute([$stream_id]); // If this relation exists
                // $pdo->prepare("DELETE FROM features WHERE stream_id = ?")->execute([$stream_id]);
                // Depending on your schema, you might need to delete metric_data, churn_scores, etc.
                // related to contacts belonging to this stream, or set cascading deletes on FKs.

                // Finally, delete the stream itself
                $pdo->prepare("DELETE FROM streams WHERE id = ?")->execute([$stream_id]);
                
                $pdo->commit();
                $_SESSION['success'] = "Stream and all associated data deleted successfully.";
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Error deleting stream: " . $e->getMessage();
                error_log("Stream deletion error: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "You do not have permission to delete this stream.";
        }
        header('Location: streams.php');
        exit;
    }
}


// --- Data Fetching for Display (only after all logic and potential redirects) ---

$current_viewed_stream_id = isset($_GET['id']) ? (int)$_GET['id'] : null;

// Get all streams for user (owned + shared)
$streams_query = "
    SELECT s.*, n.name as niche_name,
           'owner' AS user_effective_role -- Default for owned streams
    FROM streams s
    LEFT JOIN niches n ON s.niche_id = n.id
    WHERE s.user_id = :user_id

    UNION

    SELECT s.*, n.name as niche_name,
           (CASE
               WHEN tm.role = 'owner' AND ts.access_level = 'edit' THEN 'editor'
               WHEN tm.role = 'editor' AND ts.access_level = 'edit' THEN 'editor'
               ELSE 'viewer'
            END) AS user_effective_role -- Determine role for shared streams
    FROM team_streams ts
    JOIN streams s ON ts.stream_id = s.id
    JOIN team_members tm ON ts.team_id = tm.team_id
    LEFT JOIN niches n ON s.niche_id = n.id
    WHERE tm.user_id = :user_id_alt AND s.user_id != :user_id_owner_check -- Exclude streams already covered by direct ownership
    ORDER BY created_at DESC;
";

$stmt = $pdo->prepare($streams_query);
$stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':user_id_alt', $user_id, PDO::PARAM_INT);
$stmt->bindValue(':user_id_owner_check', $user_id, PDO::PARAM_INT); // Ensures owned streams are not duplicated
$stmt->execute();
$all_accessible_streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

$single_stream = null;
$streams_list = []; // To hold all streams to display in the grid

// Organize streams and identify the single_stream if an ID was provided
foreach ($all_accessible_streams as $s) {
    if ($s['id'] === $current_viewed_stream_id) {
        $single_stream = $s;
    }
    $streams_list[] = $s; // Add all streams to the list for the grid view
}


// If a specific stream ID was requested but not found in the accessible list
if ($current_viewed_stream_id && !$single_stream) {
    $_SESSION['error'] = "Stream not found or unauthorized.";
    header('Location: streams.php');
    exit;
}


// Get all niches
$stmt = $pdo->query("SELECT * FROM niches ORDER BY name");
$niches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Currency options
$currencies = ['USD' => 'US Dollar', 'EUR' => 'Euro', 'GBP' => 'British Pound', 'NGN' => 'Nigerian Naira'];


// --- HTML Output Starts Here ---
require_once 'includes/header.php'; // Include header now that all PHP logic is done
?>
<link rel="stylesheet" href="streams.css">
<style>
/* Add the necessary styles here if they are not in streams.css or a global CSS file */
/* Example: */
.modal-content {
    max-height: 90vh;
    overflow-y: auto;
    transform: translate3d(0,0,0);
    position: relative;
    padding-bottom: 20px; /* Add padding for scrollable content */
}

.modal-content h2 {
    cursor: grab;
    user-select: none;
    margin-bottom: 20px;
}
.modal {
    z-index: 1000;
    display: none; /* Hidden by default */
    position: fixed;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
    overflow: auto;
}

/* Ensure standard button styles are defined or loaded from a global CSS */
.submit-btn, .action-btn, .delete-btn, .new-stream-btn, .edit-stream-btn {
    padding: 10px 15px;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    transition: background-color 0.2s ease;
    border: 1px solid transparent; /* Consistent border */
    text-decoration: none; /* For anchor tags acting as buttons */
    display: inline-flex; /* For consistent alignment with icons */
    align-items: center;
    gap: 5px;
    justify-content: center;
    box-sizing: border-box; /* Include padding and border in the element's total width and height */
}

.submit-btn {
    background-color: var(--primary); /* Assuming --primary is defined */
    color: white;
    border: none;
}
.submit-btn:hover {
    background-color: var(--primary-dark);
}

.delete-btn {
    padding: 8px 15px;
    color: #ff0000; /* Directly set to red as requested */
    font-weight: 900; /* Set font weight to 900 as requested */
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s ease;
    background-color: transparent; /* Ensure background is transparent if only text color is red */
}
.delete-btn:hover {
    background-color: rgba(255, 0, 0, 0.1); /* Slight red background on hover */
}

.action-btn, .edit-stream-btn { /* Combined styles for general action and edit buttons */
    background-color: var(--gray-200); /* Lighter background for less prominent actions */
    color: var(--dark);
    border: 1px solid var(--gray-300);
}
.action-btn:hover, .edit-stream-btn:hover {
    background-color: var(--gray-300);
}

/* Styles for alerts */
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
.alert.info {
    background: #e0f2f7; /* Light blue */
    color: #2b6cb0; /* Darker blue */
    border: 1px solid #b3e0ed;
}

/* Ensure these are consistent with header.php's styles or a global CSS */
:root {
    --primary: #3ac3b8;
    --primary-dark: #2da89e; /* Added for hover effects */
    --secondary: #4299e1;
    --danger: #e53e3e;
    --danger-dark: #c53030; /* Added for hover effects */
    --warning: #f6ad55;
    --success: #68d391;
    --info: #4299e1;
    --info-dark: #3182ce; /* Adjusted for better contrast on hover */
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
    --border-color: var(--gray-300); /* Common border color */
    --text-color-light: var(--gray-500); /* Common text color for subtle elements */
    --light-bg: var(--gray-100);
    --secondary-bg: var(--gray-200);
}

/* Stream card specific styles */
.streams-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    padding: 20px 0;
}

.stream-card {
    background-color: var(--white);
    border-radius: 10px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--gray-200);
}

.stream-card-header {
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid var(--gray-200);
    flex-wrap: wrap; /* Allow wrapping */
}

.stream-card-header h3 {
    margin: 0;
    font-size: 1.2rem;
    color: var(--dark);
    flex-grow: 1;
}

.stream-niche {
    padding: 4px 8px;
    background-color: var(--gray-200);
    color: var(--gray-700);
    border-radius: 5px;
    font-size: 0.8em;
    font-weight: 600;
}

.stream-cover-image {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    overflow: hidden;
    background-color: var(--gray-100);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.stream-cover-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.stream-card-body {
    padding: 15px;
    flex-grow: 1;
}
.stream-card-body p {
    font-size: 0.9rem;
    color: var(--gray-600);
    margin-bottom: 10px;
}
.stream-card-body a {
    color: var(--info);
    text-decoration: none;
}
.stream-card-body a:hover {
    text-decoration: underline;
}

.stream-stats {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 20px;
    margin-top: 15px;
    padding-top: 10px;
    border-top: 1px dashed var(--gray-200);
}
.stat {
    flex: 1 1 auto;
    min-width: 120px;
}
.stat-label {
    display: block;
    font-size: 0.75rem;
    color: var(--gray-500);
    margin-bottom: 2px;
    font-weight: 500;
}
.stat-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--dark);
}

.stream-card-footer {
    padding: 15px;
    border-top: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

/* Single stream view specific styles */
.single-stream-view .stream-card {
    max-width: 800px;
    margin: 20px auto;
    border-width: 2px; /* Thicker border for single view */
}
.single-stream-view .stream-card-header {
    flex-direction: column;
    align-items: flex-start;
}
.single-stream-view .stream-cover-image {
    width: 80px;
    height: 80px;
    margin-bottom: 10px;
}
.single-stream-view .stream-card-footer {
    justify-content: flex-start;
    gap: 15px;
}

/* No streams message */
.no-streams {
    text-align: center;
    padding: 50px 20px;
    background-color: var(--light-bg);
    border: 1px dashed var(--gray-300);
    border-radius: 8px;
    color: var(--gray-700);
    font-size: 1.1rem;
    margin-top: 30px;
}
.no-streams p {
    margin: 0;
}

/* Modal form adjustments */
.modal-content .form-group {
    margin-bottom: 15px;
}
.modal-content label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--gray-700);
}
.modal-content input[type="text"],
.modal-content input[type="url"],
.modal-content input[type="number"],
.modal-content select,
.modal-content textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--gray-300);
    border-radius: 5px;
    box-sizing: border-box; /* Include padding and border in width */
    font-size: 1rem;
}
.modal-content input[type="color"] {
    width: 60px; /* Smaller for color picker */
    height: 35px;
    padding: 0;
    border: none;
    cursor: pointer;
}
.modal-content textarea {
    resize: vertical;
    min-height: 80px;
}
.modal-content small {
    font-size: 0.8em;
    color: var(--gray-500);
    display: block;
    margin-top: 5px;
}
.modal-content .input-group {
    display: flex;
    gap: 10px;
}
.modal-content .input-group input {
    flex-grow: 1;
}
.modal-content .checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 15px;
    margin-bottom: 15px;
}
.modal-content .checkbox-group input {
    width: auto; /* Override 100% width */
}
.modal-content .checkbox-group label {
    margin: 0; /* Override margin-bottom */
}

/* Modal close button */
.close {
    position: absolute;
    right: 15px;
    top: 10px;
    font-size: 2rem;
    cursor: pointer;
    color: var(--gray-500);
    transition: color 0.2s ease;
}
.close:hover {
    color: var(--dark);
}

/* Share with Teams section */
.share-stream-section {
    padding: 15px;
    margin-top: 20px;
    border-top: 1px solid var(--gray-200);
    background-color: var(--light-bg);
    border-radius: 0 0 8px 8px; /* Rounded bottom corners */
}
.share-stream-section h3 {
    margin-top: 0;
    font-size: 1.1rem;
    color: var(--dark);
    margin-bottom: 10px;
}
.share-form {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed var(--gray-200);
}
.share-form:last-child {
    border-bottom: none;
}
.share-form button {
    padding: 5px 12px;
    background-color: var(--info);
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 0.9em;
    transition: background-color 0.2s ease;
}
.share-form button:hover {
    background-color: var(--info-dark);
}
/* Responsive adjustments */
@media (max-width: 768px) {
    .streams-grid {
        grid-template-columns: 1fr;
    }
    .stream-card-footer {
        flex-direction: column;
        align-items: stretch;
    }
    .stream-card-footer .action-btn,
    .stream-card-footer .edit-stream-btn,
    .stream-card-footer .delete-form button {
        width: 100%;
        margin-bottom: 5px;
    }
    .stream-card-footer .delete-form {
        width: 100%; /* Ensure form takes full width */
    }
}


</style>

<div class="streams-container">
    <div class="streams-header">
        <h1>Your Streams</h1>
        <button class="new-stream-btn" id="newStreamBtn" aria-label="Create a new stream">+ New Stream</button>
    </div>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error" role="alert"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success" role="alert"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert info" role="alert"><?= $_SESSION['info'] ?></div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>
    
    <?php if ($single_stream): ?>
        <div class="single-stream-view">
            <div class="stream-card" style="border-left: 4px solid <?= htmlspecialchars($single_stream['color_code']) ?>">
                <div class="stream-card-header">
                    <?php if ($single_stream['cover_image']): ?>
                        <div class="stream-cover-image">
                            <img src="streams_cover/<?= htmlspecialchars($single_stream['cover_image']) ?>" alt="Stream Cover">
                        </div>
                    <?php endif; ?>
                    <h3><?= htmlspecialchars($single_stream['name']) ?></h3>
                    <span class="stream-niche"><?= htmlspecialchars($single_stream['niche_name'] ?? 'No Niche') ?></span>
                </div>
                
                <div class="stream-card-body">
                    <p><?= nl2br(htmlspecialchars($single_stream['description'] ?: 'No description')) ?></p>
                    
                    <?php if ($single_stream['website_url']): ?>
                        <p><a href="<?= htmlspecialchars($single_stream['website_url']) ?>" target="_blank"><?= htmlspecialchars($single_stream['website_url']) ?></a></p>
                    <?php endif; ?>
                    
                    <div class="stream-stats">
                        <div class="stat">
                            <span class="stat-label">Tracking Code</span>
                            <span class="stat-value"><?= htmlspecialchars($single_stream['tracking_code']) ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Acquisition Cost</span>
                            <span class="stat-value"><?= htmlspecialchars($single_stream['currency']) ?> <?= number_format($single_stream['acquisition_cost'], 2) ?></span>
                        </div>
                        <div class="stat">
                            <span class="stat-label">Revenue per User</span>
                            <span class="stat-value"><?= htmlspecialchars($single_stream['currency']) ?> <?= number_format($single_stream['revenue_per_user'], 2) ?></span>
                        </div>
                        <?php if ($single_stream['marketing_channel']): ?>
                        <div class="stat">
                            <span class="stat-label">Marketing Channel</span>
                            <span class="stat-value"><?= htmlspecialchars($single_stream['marketing_channel']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php 
                $user_teams = get_user_teams($user_id);
                $is_stream_owner = user_owns_stream($user_id, $single_stream['id']);
                $effective_access_level = get_stream_effective_access_level($user_id, $single_stream['id']); // Get access for THIS stream

                if ($is_stream_owner && !empty($user_teams)): // Only stream owner can share
                ?>
                    <div class="share-stream-section">
                        <h3>Share with Teams</h3>
                        <?php foreach ($user_teams as $team): 
                            // Only show teams where the current user is an owner
                            if (isset($team['role']) && $team['role'] === 'owner'): 
                        ?>
                            <form method="GET" class="share-form">
                                <span>Share with <?= htmlspecialchars($team['name']) ?> (Your role: <?= htmlspecialchars(ucfirst($team['role'])) ?>)</span>
                                <input type="hidden" name="id" value="<?= htmlspecialchars($single_stream['id']) ?>">
                                <input type="hidden" name="share" value="<?= htmlspecialchars($team['id']) ?>">
                                <button type="submit">Share</button>
                            </form>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                <?php 
                endif; 
                ?>
                
                <div class="stream-card-footer">
                    <a href="contacts.php?stream_id=<?= htmlspecialchars($single_stream['id']) ?>" class="action-btn">View Contacts</a>
                    <a href="features.php?stream_id=<?= htmlspecialchars($single_stream['id']) ?>" class="action-btn">Manage Features</a>
                    <a href="cohorts.php?stream_id=<?= htmlspecialchars($single_stream['id']) ?>" class="action-btn">Manage Cohorts</a>

                    <?php if ($effective_access_level === 'owner' || $effective_access_level === 'editor'): ?>
                        <button class="edit-stream-btn" data-stream-id="<?= htmlspecialchars($single_stream['id']) ?>"
                                data-stream-name="<?= htmlspecialchars($single_stream['name']) ?>"
                                data-stream-desc="<?= htmlspecialchars($single_stream['description']) ?>"
                                data-stream-niche="<?= htmlspecialchars($single_stream['niche_id']) ?>"
                                data-stream-acq-cost="<?= htmlspecialchars($single_stream['acquisition_cost']) ?>"
                                data-stream-rev-per-user="<?= htmlspecialchars($single_stream['revenue_per_user']) ?>"
                                data-stream-marketing-channel="<?= htmlspecialchars($single_stream['marketing_channel']) ?>"
                                data-stream-is-app="<?= htmlspecialchars($single_stream['is_app']) ?>"
                                data-stream-website-url="<?= htmlspecialchars($single_stream['website_url']) ?>"
                                data-stream-color="<?= htmlspecialchars($single_stream['color_code']) ?>"
                                data-stream-currency="<?= htmlspecialchars($single_stream['currency']) ?>"
                                >
                            <i class="bi bi-pencil-square"></i> Edit Stream
                        </button>
                    <?php endif; ?>

                    <?php if ($effective_access_level === 'owner'): ?>
                        <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to DELETE this stream and ALL its associated data (contacts, cohorts, features, etc.)? This action is irreversible!');">
                            <input type="hidden" name="stream_id" value="<?= htmlspecialchars($single_stream['id']) ?>">
                            <button type="submit" name="delete_stream" class="delete-btn">Delete Stream</button>
                        </form>
                    <?php endif; ?>
                    <a href="streams.php" class="action-btn">Back to All Streams</a>
                </div>
            </div>
        </div>
    <?php elseif (count($streams_list)): // Display all accessible streams if no single stream is selected ?>
        <div class="streams-grid">
            <?php foreach ($streams_list as $stream): 
                $effective_access_level = get_stream_effective_access_level($user_id, $stream['id']); // Get access for EACH stream in the list
            ?>
                <div class="stream-card" style="border-left: 4px solid <?= htmlspecialchars($stream['color_code']) ?>">
                    <div class="stream-card-header">
                        <?php if ($stream['cover_image']): ?>
                            <div class="stream-cover-image">
                                <img src="streams_cover/<?= htmlspecialchars($stream['cover_image']) ?>" alt="Stream Cover">
                            </div>
                        <?php endif; ?>
                        <h3><?= htmlspecialchars($stream['name']) ?></h3>
                        <span class="stream-niche"><?= htmlspecialchars($stream['niche_name'] ?? 'No Niche') ?></span>
                    </div>
                    
                    <div class="stream-card-body">
                        <p><?= nl2br(htmlspecialchars($stream['description'] ?: 'No description')) ?></p>
                        
                        <?php if ($stream['website_url']): ?>
                            <p><a href="<?= htmlspecialchars($stream['website_url']) ?>" target="_blank"><?= htmlspecialchars($stream['website_url']) ?></a></p>
                        <?php endif; ?>
                        
                        <div class="stream-stats">
                            <div class="stat">
                                <span class="stat-label">Access Level</span>
                                <span class="stat-value"><?= htmlspecialchars(ucfirst($effective_access_level)) ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Acquisition Cost</span>
                                <span class="stat-value"><?= htmlspecialchars($stream['currency']) ?> <?= number_format($stream['acquisition_cost'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="stream-card-footer">
                        <a href="contacts.php?stream_id=<?= htmlspecialchars($stream['id']) ?>" class="action-btn">View Contacts</a>
                        <a href="streams.php?id=<?= htmlspecialchars($stream['id']) ?>" class="action-btn">View Details</a>
                        
                        <?php if ($effective_access_level === 'owner' || $effective_access_level === 'editor'): ?>
                            <button class="edit-stream-btn" data-stream-id="<?= htmlspecialchars($stream['id']) ?>"
                                    data-stream-name="<?= htmlspecialchars($stream['name']) ?>"
                                    data-stream-desc="<?= htmlspecialchars($stream['description']) ?>"
                                    data-stream-niche="<?= htmlspecialchars($stream['niche_id']) ?>"
                                    data-stream-acq-cost="<?= htmlspecialchars($stream['acquisition_cost']) ?>"
                                    data-stream-rev-per-user="<?= htmlspecialchars($stream['revenue_per_user']) ?>"
                                    data-stream-marketing-channel="<?= htmlspecialchars($stream['marketing_channel']) ?>"
                                    data-stream-is-app="<?= htmlspecialchars($stream['is_app']) ?>"
                                    data-stream-website-url="<?= htmlspecialchars($stream['website_url']) ?>"
                                    data-stream-color="<?= htmlspecialchars($stream['color_code']) ?>"
                                    data-stream-currency="<?= htmlspecialchars($stream['currency']) ?>"
                                    >
                                <i class="bi bi-pencil-square"></i> Edit
                            </button>
                        <?php endif; ?>

                        <?php if ($effective_access_level === 'owner'): ?>
                            <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to DELETE this stream and ALL its associated data (contacts, cohorts, features, etc.)? This action is irreversible!');">
                                <input type="hidden" name="stream_id" value="<?= htmlspecialchars($stream['id']) ?>">
                                <button type="submit" name="delete_stream" class="delete-btn">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="no-streams">
            <p>👋You don't have any streams yet. Create your first stream to start tracking churn.</p>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="newStreamModal" role="dialog" aria-labelledby="newStreamModalTitle" aria-modal="true" aria-hidden="true">
    <div class="modal-content">
        <span class="close" aria-label="Close" tabindex="0" role="button">&times;</span>
        <h2 id="newStreamModalTitle">Create New Stream</h2>
        
        <form method="POST" enctype="multipart/form-data" class="scrollable-form">
            <div class="form-group">
                <label for="create-stream-name">Stream Name</label>
                <input type="text" name="name" id="create-stream-name" required>
            </div>
            
            <div class="form-group">
                <label for="create-niche-select">Niche</label>
                <select name="niche_id" id="create-niche-select" required>
                    <option value="">Select Niche</option>
                    <?php foreach ($niches as $niche): ?>
                        <option value="<?= htmlspecialchars($niche['id']) ?>"><?= htmlspecialchars($niche['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="create-stream-description">Description</label>
                <textarea name="description" id="create-stream-description" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label for="create-cover-image">Cover Image</label>
                <input type="file" name="cover_image" id="create-cover-image" accept="image/*">
                <small>Recommended size: 300x300 pixels</small>
            </div>
            
            <div class="form-group">
                <label for="create-color-code">Color</label>
                <input type="color" name="color_code" id="create-color-code" value="#3ac3b8">
            </div>
            
            <div class="form-group">
                <label for="create-acquisition-cost">Acquisition Cost per Contact</label>
                <div class="input-group">
                    <select name="currency" id="create-acquisition-currency" style="width: 80px;">
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= ($code == 'USD' ? 'selected' : '') ?>><?= htmlspecialchars($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="acquisition_cost" id="create-acquisition-cost" min="0" step="0.01" value="0.00">
                </div>
                <small>The average cost to acquire a new user for this stream</small>
            </div>
            
            <div class="form-group">
                <label for="create-revenue-per-user">Revenue per User per Month</label>
                <div class="input-group">
                    <select name="currency" id="create-revenue-currency" style="width: 80px;">
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= ($code == 'USD' ? 'selected' : '') ?>><?= htmlspecialchars($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="revenue_per_user" id="create-revenue-per-user" min="0" step="0.01" value="0.00">
                </div>
                <small>The average revenue generated per user each month</small>
            </div>
            
            <div class="form-group">
                <label for="create-marketing-channel">Primary Marketing Channel</label>
                <input type="text" name="marketing_channel" id="create-marketing-channel" placeholder="e.g., TikTok, Facebook Ads, SEO">
                <small>Where most of your users come from</small>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" id="create-is_app" name="is_app">
                <label for="create-is_app">This is a mobile app (not a website)</label>
            </div>
            
            <div class="form-group" id="createWebsiteUrlGroup">
                <label for="create-website-url">Website URL</label>
                <input type="url" name="website_url" id="create-website-url" placeholder="https://example.com">
            </div>
            
            <button type="submit" name="create_stream" class="submit-btn">Create Stream</button>
        </form>
    </div>
</div>

<div class="modal" id="editStreamModal" role="dialog" aria-labelledby="editStreamModalTitle" aria-modal="true" aria-hidden="true">
    <div class="modal-content">
        <span class="close" aria-label="Close" tabindex="0" role="button">&times;</span>
        <h2 id="editStreamModalTitle">Edit Stream</h2>
        
        <form method="POST" enctype="multipart/form-data" class="scrollable-form">
            <input type="hidden" name="stream_id" id="edit-stream-id">
            
            <div class="form-group">
                <label for="edit-stream-name">Stream Name</label>
                <input type="text" name="name" id="edit-stream-name" required>
            </div>
            
            <div class="form-group">
                <label for="edit-niche-select">Niche</label>
                <select name="niche_id" id="edit-niche-select" required>
                    <option value="">Select Niche</option>
                    <?php foreach ($niches as $niche): ?>
                        <option value="<?= htmlspecialchars($niche['id']) ?>"><?= htmlspecialchars($niche['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="edit-stream-description">Description</label>
                <textarea name="description" id="edit-stream-description" rows="3"></textarea>
            </div>
            
            <div class="form-group">
                <label for="edit-cover-image">Cover Image</label>
                <input type="file" name="cover_image" id="edit-cover-image" accept="image/*">
                <small>Recommended size: 300x300 pixels</small>
                <div id="currentCoverImageContainer" style="margin-top: 10px; display: none;">
                    <strong>Current Image:</strong> <img id="currentCoverImagePreview" src="" alt="Current Cover" style="max-width: 100px; max-height: 100px; vertical-align: middle;">
                    <input type="checkbox" id="removeCurrentCoverImage" name="remove_current_cover_image" value="1">
                    <label for="removeCurrentCoverImage">Remove current image</label>
                </div>
            </div>
            
            <div class="form-group">
                <label for="edit-color-code">Color</label>
                <input type="color" name="color_code" id="edit-color-code" value="#3ac3b8">
            </div>
            
            <div class="form-group">
                <label for="edit-acquisition-cost">Acquisition Cost per Contact</label>
                <div class="input-group">
                    <select name="currency" id="edit-acquisition-currency" style="width: 80px;">
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="acquisition_cost" id="edit-acquisition-cost" min="0" step="0.01" value="0.00">
                </div>
                <small>The average cost to acquire a new user for this stream</small>
            </div>
            
            <div class="form-group">
                <label for="edit-revenue-per-user">Revenue per User per Month</label>
                <div class="input-group">
                    <select name="currency" id="edit-revenue-currency" style="width: 80px;">
                        <?php foreach ($currencies as $code => $name): ?>
                            <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($code) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="number" name="revenue_per_user" id="edit-revenue-per-user" min="0" step="0.01" value="0.00">
                </div>
                <small>The average revenue generated per user each month</small>
            </div>
            
            <div class="form-group">
                <label for="edit-marketing-channel">Primary Marketing Channel</label>
                <input type="text" name="marketing_channel" id="edit-marketing-channel" placeholder="e.g., TikTok, Facebook Ads, SEO">
                <small>Where most of your users come from</small>
            </div>
            
            <div class="form-group checkbox-group">
                <input type="checkbox" id="edit-is_app" name="is_app">
                <label for="edit-is_app">This is a mobile app (not a website)</label>
            </div>
            
            <div class="form-group" id="editWebsiteUrlGroup">
                <label for="edit-website-url">Website URL</label>
                <input type="url" name="website_url" id="edit-website-url" placeholder="https://example.com">
            </div>
            
            <button type="submit" name="edit_stream" class="submit-btn">Save Changes</button>
        </form>
    </div>
</div>


<script>
    // Helper function to setup modal draggable functionality
    function setupModalDraggable(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) return;

        const modalContent = modal.querySelector('.modal-content');
        const handle = modalContent.querySelector('h2'); // Assumes h2 is the draggable handle

        if (!handle) return;

        let xOffset = 0;
        let yOffset = 0;
        let isDragging = false;
        let initialX, initialY;

        handle.addEventListener('mousedown', dragStart);

        function dragStart(e) {
            initialX = e.clientX - xOffset;
            initialY = e.clientY - yOffset;
            if (e.button === 0) {
                isDragging = true;
                handle.style.cursor = 'grabbing';
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', dragEnd);
            }
        }

        function drag(e) {
            if (isDragging) {
                e.preventDefault();
                xOffset = e.clientX - initialX;
                yOffset = e.clientY - initialY;
                setTranslate(xOffset, yOffset, modalContent);
            }
        }

        function dragEnd() {
            isDragging = false;
            handle.style.cursor = 'grab';
            document.removeEventListener('mousemove', drag);
            document.removeEventListener('mouseup', dragEnd);
        }

        function setTranslate(xPos, yPos, el) {
            el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0)`;
        }
    }

    // New Stream Modal Logic
    const newStreamModal = document.getElementById('newStreamModal');
    const newStreamBtn = document.getElementById('newStreamBtn');
    const newStreamCloseBtn = newStreamModal.querySelector('.close');
    const newStreamFocusableElements = newStreamModal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    const newStreamFirstFocusableElement = newStreamFocusableElements[0];
    const newStreamLastFocusableElement = newStreamFocusableElements[newStreamFocusableElements.length - 1];

    function openNewStreamModal() {
        newStreamModal.style.display = 'flex'; // Use flex for centering
        newStreamModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => { document.getElementById('create-stream-name').focus(); }, 100);
    }

    function closeNewStreamModal() {
        newStreamModal.style.display = 'none';
        newStreamModal.setAttribute('aria-hidden', 'true');
        newStreamModal.querySelector('.modal-content').style.transform = "translate3d(0px, 0px, 0)"; // Reset position
        newStreamBtn.focus();
    }

    newStreamBtn.addEventListener('click', openNewStreamModal);
    newStreamCloseBtn.addEventListener('click', closeNewStreamModal);
    newStreamCloseBtn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { closeNewStreamModal(); } });
    window.addEventListener('click', (e) => { if (e.target === newStreamModal) { closeNewStreamModal(); } });
    newStreamModal.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeModal(); }
        if (e.key === 'Tab') {
            if (e.shiftKey) { if (document.activeElement === newStreamFirstFocusableElement) { e.preventDefault(); newStreamLastFocusableElement.focus(); } } 
            else { if (document.activeElement === newStreamLastFocusableElement) { e.preventDefault(); newStreamFirstFocusableElement.focus(); } }
        }
    });

    const createIsAppCheckbox = document.getElementById('create-is_app');
    const createWebsiteUrlGroup = document.getElementById('createWebsiteUrlGroup');
    createIsAppCheckbox.addEventListener('change', () => {
        createWebsiteUrlGroup.style.display = createIsAppCheckbox.checked ? 'none' : 'block';
        createWebsiteUrlGroup.setAttribute('aria-hidden', createIsAppCheckbox.checked ? 'true' : 'false');
    });
    // Initial state
    createWebsiteUrlGroup.style.display = createIsAppCheckbox.checked ? 'none' : 'block';


    // Edit Stream Modal Logic
    const editStreamModal = document.getElementById('editStreamModal');
    const editStreamCloseBtn = editStreamModal.querySelector('.close');
    const editStreamBtns = document.querySelectorAll('.edit-stream-btn'); // Select all edit buttons

    const editStreamIdField = document.getElementById('edit-stream-id');
    const editStreamNameField = document.getElementById('edit-stream-name');
    const editStreamDescField = document.getElementById('edit-stream-description');
    const editNicheSelect = document.getElementById('edit-niche-select');
    const editAcquisitionCostField = document.getElementById('edit-acquisition-cost');
    const editRevenuePerUserField = document.getElementById('edit-revenue-per-user');
    const editMarketingChannelField = document.getElementById('edit-marketing-channel');
    const editIsAppCheckbox = document.getElementById('edit-is_app');
    const editWebsiteUrlGroup = document.getElementById('editWebsiteUrlGroup');
    const editWebsiteUrlField = document.getElementById('edit-website-url');
    const editColorCodeField = document.getElementById('edit-color-code');
    const editAcquisitionCurrency = document.getElementById('edit-acquisition-currency');
    const editRevenueCurrency = document.getElementById('edit-revenue-currency');
    const currentCoverImageContainer = document.getElementById('currentCoverImageContainer');
    const currentCoverImagePreview = document.getElementById('currentCoverImagePreview');
    const removeCurrentCoverImageCheckbox = document.getElementById('removeCurrentCoverImage');

    function openEditStreamModal(streamData) {
        editStreamIdField.value = streamData.id;
        editStreamNameField.value = streamData.name;
        editStreamDescField.value = streamData.desc;
        editNicheSelect.value = streamData.niche; // Set selected option
        editAcquisitionCostField.value = streamData.acqCost;
        editRevenuePerUserField.value = streamData.revPerUser;
        editMarketingChannelField.value = streamData.marketingChannel;
        editIsAppCheckbox.checked = streamData.isApp == 1; // Convert to boolean
        editWebsiteUrlField.value = streamData.websiteUrl;
        editColorCodeField.value = streamData.color;
        
        // Set currency selects
        if (editAcquisitionCurrency) editAcquisitionCurrency.value = streamData.currency;
        if (editRevenueCurrency) editRevenueCurrency.value = streamData.currency;


        // Handle cover image preview
        const streamsCoverPath = 'streams_cover/'; // Adjust if your server serves from a different base
        if (streamData.coverImage) {
            currentCoverImagePreview.src = streamsCoverPath + streamData.coverImage;
            currentCoverImageContainer.style.display = 'block';
        } else {
            currentCoverImageContainer.style.display = 'none';
        }
        removeCurrentCoverImageCheckbox.checked = false; // Reset checkbox on open


        // Toggle website URL field visibility
        editWebsiteUrlGroup.style.display = editIsAppCheckbox.checked ? 'none' : 'block';
        editWebsiteUrlGroup.setAttribute('aria-hidden', editIsAppCheckbox.checked ? 'true' : 'false');
        
        editStreamModal.style.display = 'flex'; // Use flex for centering
        editStreamModal.setAttribute('aria-hidden', 'false');
        setTimeout(() => { editStreamNameField.focus(); }, 100);
    }

    function closeEditStreamModal() {
        editStreamModal.style.display = 'none';
        editStreamModal.setAttribute('aria-hidden', 'true');
        editStreamModal.querySelector('.modal-content').style.transform = "translate3d(0px, 0px, 0)"; // Reset position
        // You might want to return focus to the specific edit button that was clicked
        // For simplicity, we're not doing that here, but it's good for accessibility.
    }

    editStreamCloseBtn.addEventListener('click', closeEditStreamModal);
    editStreamCloseBtn.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { closeEditStreamModal(); } });
    window.addEventListener('click', (e) => { if (e.target === editStreamModal) { closeEditStreamModal(); } });
    editStreamModal.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeEditStreamModal(); }
        // Trap focus similar to newStreamModal (exercise for the user)
    });

    // Event listeners for all 'Edit Stream' buttons
    editStreamBtns.forEach(button => {
        button.addEventListener('click', () => {
            const streamData = {
                id: button.dataset.streamId,
                name: button.dataset.streamName,
                desc: button.dataset.streamDesc,
                niche: button.dataset.streamNiche,
                acqCost: button.dataset.streamAcqCost,
                revPerUser: button.dataset.streamRevPerUser,
                marketingChannel: button.dataset.streamMarketingChannel,
                isApp: button.dataset.streamIsApp,
                websiteUrl: button.dataset.streamWebsiteUrl,
                color: button.dataset.streamColor,
                currency: button.dataset.streamCurrency,
                coverImage: button.dataset.streamCoverImage || null // Pass cover image if available
            };
            openEditStreamModal(streamData);
        });
    });

    // Toggle website URL field based on app checkbox in EDIT modal
    editIsAppCheckbox.addEventListener('change', () => {
        editWebsiteUrlGroup.style.display = editIsAppCheckbox.checked ? 'none' : 'block';
        editWebsiteUrlGroup.setAttribute('aria-hidden', editIsAppCheckbox.checked ? 'true' : 'false');
    });

    // Setup draggable for both modals
    setupModalDraggable('newStreamModal');
    setupModalDraggable('editStreamModal');

</script>

<?php
require_once 'includes/footer.php';
?>