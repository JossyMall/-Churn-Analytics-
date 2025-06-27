<?php
// MUST BE AT THE VERY TOP - NO WHITESPACE BEFORE THIS
session_start(); // Start session first

// Enable error reporting for debugging, but disable display in production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check login status BEFORE including any files that might output content
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php'); // Redirect to login page if not logged in
    exit; // IMPORTANT: Always exit after a header redirect
}

require_once 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Define BASE_URL if it's not already defined globally in db.php or header.php
if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/churn-analytics'); // Adjust this to your actual base URL
}


// --- Helper Functions for Access Control (Copied from contact_notes.php or includes) ---
// If these functions are already in a globally included file (like includes/functions.php),
// you can remove them from here and just include that file.
/**
 * Checks if the current user has view access to a given stream.
 * A user has view access if:
 * 1. They are the direct owner of the stream.
 * 2. The stream is owned by a team they are a member of.
 * 3. The stream is explicitly shared with a team they are a member of.
 * @param PDO $pdo
 * @param int $current_user_id
 * @param int $stream_id
 * @return bool
 */
function user_has_stream_view_access($pdo, $current_user_id, $stream_id) {
    // Check if current user is direct owner of the stream
    $stmt_owner = $pdo->prepare("SELECT COUNT(*) FROM streams WHERE id = ? AND user_id = ?");
    $stmt_owner->execute([$stream_id, $current_user_id]);
    if ($stmt_owner->fetchColumn() > 0) {
        return true;
    }

    // Check if stream is owned by a team the user is a member of (user is member of team_id that owns stream)
    $stmt_team_owned = $pdo->prepare("
        SELECT COUNT(s.id)
        FROM streams s
        JOIN team_members tm ON s.team_id = tm.team_id
        WHERE s.id = ? AND tm.user_id = ? AND s.team_id IS NOT NULL
    ");
    $stmt_team_owned->execute([$stream_id, $current_user_id]);
    if ($stmt_team_owned->fetchColumn() > 0) {
        return true;
    }

    // Check if stream is explicitly shared with a team the user is a member of (user is member of team_id that SHARED stream)
    $stmt_shared_team = $pdo->prepare("
        SELECT COUNT(s.id)
        FROM streams s
        JOIN team_streams ts ON s.id = ts.stream_id
        JOIN team_members tm ON ts.team_id = tm.team_id
        WHERE s.id = ? AND tm.user_id = ?
    ");
    $stmt_shared_team->execute([$stream_id, $current_user_id]);
    if ($stmt_shared_team->fetchColumn() > 0) {
        return true;
    }

    return false;
}

// BBCode parser function (copied directly from contact_notes.php)
/**
 * Basic BBCode parser to convert BBCode to HTML.
 * @param string $text The text containing BBCode.
 * @return string The HTML converted text.
 */
function parse_bbcode($text) {
    $replace = [
        '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
        '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        '/\[url=(.*?)\](.*?)\[\/url\]/is' => '<a href="$1" target="_blank" class="text-blue-600 hover:underline">$2</a>',
        '/\[url\](.*?)\[\/url\]/is' => '<a href="$1" target="_blank" class="text-blue-600 hover:underline">$1</a>',
        '/\[img\](.*?)\[\/img\]/is' => '<img src="$1" alt="Image" class="max-w-full h-auto rounded-lg my-2">',
        '/\[list\](.*?)\[\/list\]/is' => '<ul>$1</ul>',
        '/\[\*\](.*?)\n/is' => '<li>$1</li>',
        '/\[\*\](.*?)(\[\*\]|$)/is' => '<li>$1</li>', 
    ];

    $text = preg_replace(array_keys($replace), array_values($replace), $text);
    $text = preg_replace('/\[list\](.*?)\[\/list\]/is', '<ul>$1</ul>', $text);
    $text = preg_replace('/\[\*\](.*?)\n/is', '<li>$1</li>', $text);
    $text = preg_replace('/\[\*\](.*?)(\[\*\]|$)/is', '<li>$1</li>', $text);

    return $text;
}


// --- Handle Form Submissions (Team Invites, Leaving Team) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accept_invite'])) {
        $invite_id = intval($_POST['invite_id']);
        $team_id = intval($_POST['team_id']);
        try {
            $pdo->beginTransaction();
            // Update invite status
            $stmt = $pdo->prepare("UPDATE team_invites SET status = 'accepted', updated_at = NOW() WHERE id = ? AND email = (SELECT email FROM users WHERE id = ?)");
            $stmt->execute([$invite_id, $user_id]);
            // Add user to team members
            $stmt = $pdo->prepare("INSERT INTO team_members (team_id, user_id, role, invited_by) VALUES (?, ?, 'viewer', (SELECT invited_by FROM team_invites WHERE id = ?))"); // Default role viewer, capture invited_by
            $stmt->execute([$team_id, $user_id, $invite_id]); // Pass invite_id to retrieve invited_by
            $pdo->commit();
            $_SESSION['success'] = "Team invite accepted!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error accepting invite: " . $e->getMessage();
        }
    } elseif (isset($_POST['decline_invite'])) {
        $invite_id = intval($_POST['invite_id']);
        try {
            $stmt = $pdo->prepare("UPDATE team_invites SET status = 'declined', updated_at = NOW() WHERE id = ? AND email = (SELECT email FROM users WHERE id = ?)");
            $stmt->execute([$invite_id, $user_id]);
            $_SESSION['success'] = "Team invite declined.";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error declining invite: " . $e->getMessage();
        }
    } elseif (isset($_POST['leave_team'])) {
        $team_id = intval($_POST['team_id']);
        try {
            // Prevent owner from leaving without transferring ownership or deleting team
            $stmt_check_owner = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id = ? AND role = 'owner'");
            $stmt_check_owner->execute([$team_id, $user_id]);
            if ($stmt_check_owner->fetchColumn() > 0) {
                $_SESSION['error'] = "Owners cannot leave a team directly. Please transfer ownership or delete the team.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
                $stmt->execute([$team_id, $user_id]);
                $_SESSION['success'] = "You have left the team.";
            }
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error leaving team: " . $e->getMessage();
        }
    }
    // Redirect to prevent re-submission on refresh
    header("Location: " . BASE_URL . "/notes.php");
    exit;
}


// --- Fetch User's Team Memberships and Invites ---
$user_teams = [];
$pending_invites = [];

try {
    $stmt = $pdo->prepare("
        SELECT tm.team_id, t.name AS team_name, tm.role
        FROM team_members tm
        JOIN teams t ON tm.team_id = t.id
        WHERE tm.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $user_teams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT ti.id AS invite_id, ti.team_id, t.name AS team_name, u.username AS invited_by_username
        FROM team_invites ti
        JOIN teams t ON ti.team_id = t.id
        JOIN users u ON ti.invited_by = u.id
        WHERE ti.email = (SELECT email FROM users WHERE id = ?) AND ti.status = 'pending'
    ");
    $stmt->execute([$user_id]);
    $pending_invites = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log("Database error fetching team info: " . $e->getMessage());
    $_SESSION['error'] = "Error fetching team information.";
}


// --- Determine Accessible Streams and Contacts for Notes ---
$accessible_stream_ids = [];
$owned_stream_ids = []; // For "Notes for My Streams"
$team_stream_ids = []; // For "Notes for Team Streams" (shared or team-owned)

try {
    // Get streams directly owned by the user
    $stmt = $pdo->prepare("SELECT id FROM streams WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $owned_streams = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $owned_stream_ids = array_map('intval', $owned_streams); // Ensure int type

    // Get streams from teams the user belongs to (either team-owned or shared with team)
    if (!empty($user_teams)) {
        $team_ids_user_is_member_of = array_column($user_teams, 'team_id');
        $placeholders = implode(',', array_fill(0, count($team_ids_user_is_member_of), '?'));

        // Streams owned by teams user is member of
        $stmt = $pdo->prepare("SELECT id FROM streams WHERE team_id IN ($placeholders) AND team_id IS NOT NULL");
        $stmt->execute($team_ids_user_is_member_of);
        $team_owned_streams = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Streams explicitly shared with teams user is member of
        $stmt = $pdo->prepare("SELECT stream_id FROM team_streams WHERE team_id IN ($placeholders)");
        $stmt->execute($team_ids_user_is_member_of);
        $shared_streams = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $team_stream_ids = array_map('intval', array_unique(array_merge($team_owned_streams, $shared_streams)));
    }

    // Combine all unique accessible stream IDs
    $accessible_stream_ids = array_unique(array_merge($owned_stream_ids, $team_stream_ids));
    $accessible_stream_ids = array_filter($accessible_stream_ids); // Remove any null/empty values
    
} catch (PDOException $e) {
    error_log("Database error fetching accessible streams: " . $e->getMessage());
    $_SESSION['error'] = "Error fetching stream access information.";
    $accessible_stream_ids = []; // Clear to prevent further errors
}


// --- Fetch All Notes relevant to accessible streams ---
$all_notes_raw = [];
$notes_for_my_streams = [];
$notes_for_team_streams = []; // For streams team owns or are shared with team
$my_private_notes = [];
$notes_from_my_team_members = []; // Public notes created by team members on accessible streams

if (!empty($accessible_stream_ids)) {
    $placeholders = implode(',', array_fill(0, count($accessible_stream_ids), '?'));
    
    try {
        $stmt_notes = $pdo->prepare("
            SELECT
                cn.id,
                cn.contact_id,
                cn.user_id AS note_creator_user_id,
                cn.note,
                cn.tags,
                cn.is_private,
                cn.created_at,
                cn.updated_at,
                u.username AS creator_username,
                c.username AS contact_username,
                c.email AS contact_email,
                s.id AS stream_id,
                s.name AS stream_name,
                s.user_id AS stream_owner_user_id,
                s.team_id AS stream_owner_team_id,
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS creator_team_names
            FROM contact_notes cn
            JOIN contacts c ON cn.contact_id = c.id
            JOIN streams s ON c.stream_id = s.id
            JOIN users u ON cn.user_id = u.id
            LEFT JOIN team_members tm ON cn.user_id = tm.user_id
            LEFT JOIN teams t ON tm.team_id = t.id
            WHERE s.id IN ($placeholders)
            GROUP BY cn.id
            ORDER BY cn.created_at DESC
        ");
        $stmt_notes->execute($accessible_stream_ids);
        $all_notes_raw = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);

        // Classify notes after fetching
        foreach ($all_notes_raw as $note) {
            $note_creator_user_id = $note['note_creator_user_id'];
            $note_stream_id = $note['stream_id'];
            $note_is_private = (bool)$note['is_private'];

            // 1. My Private Notes (only if created by current user and marked private)
            if ($note_is_private && $note_creator_user_id === $user_id) {
                $my_private_notes[] = $note;
                continue; // Skip further classification for private notes
            }

            // 2. Notes for My Streams (public notes on streams I own)
            if (in_array($note_stream_id, $owned_stream_ids)) {
                if (!$note_is_private) { // Only public notes
                    $notes_for_my_streams[] = $note;
                }
            }
            
            // 3. Notes for Team Streams (public notes on streams owned by team or shared with team)
            // This needs to be distinct from "My Streams" if I own the stream.
            // A note on a stream I own can also be a "Team Stream" note if the stream is team-owned.
            if (in_array($note_stream_id, $team_stream_ids)) {
                if (!$note_is_private) { // Only public notes
                    // Avoid duplicating notes from "My Streams" if they are also team streams
                    $is_already_in_my_streams = false;
                    foreach($notes_for_my_streams as $my_note) {
                        if ($my_note['id'] === $note['id']) {
                            $is_already_in_my_streams = true;
                            break;
                        }
                    }
                    if (!$is_already_in_my_streams) {
                        $notes_for_team_streams[] = $note;
                    }
                }
            }

            // 4. Notes from My Team Members (public notes by any team member on accessible streams)
            // This captures all public notes from team members that are not already captured as "My Streams" or "Team Streams" notes
            // Need to determine if the note creator is a member of any of the user's teams
            $is_creator_a_team_member = false;
            foreach ($user_teams as $team_member_info) {
                $stmt_is_member = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND user_id = ?");
                $stmt_is_member->execute([$team_member_info['team_id'], $note_creator_user_id]);
                if ($stmt_is_member->fetchColumn() > 0) {
                    $is_creator_a_team_member = true;
                    break;
                }
            }

            if ($is_creator_a_team_member && !$note_is_private && $note_creator_user_id !== $user_id) {
                 // Ensure it's not a duplicate if already in other categories
                 $is_already_in_other_categories = false;
                 foreach($notes_for_my_streams as $n) { if ($n['id'] === $note['id']) $is_already_in_other_categories = true; break; }
                 if (!$is_already_in_other_categories) {
                    foreach($notes_for_team_streams as $n) { if ($n['id'] === $note['id']) $is_already_in_other_categories = true; break; }
                 }
                 if (!$is_already_in_other_categories) {
                    $notes_from_my_team_members[] = $note;
                 }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Database error fetching contact notes: " . $e->getMessage());
        $_SESSION['error'] = "Error fetching contact notes.";
    }
} else {
    $_SESSION['info'] = "You currently have no accessible streams or team memberships to view notes.";
}

// --- Aggregate Notes Data for Chart ---
$chart_notes_data = [];
$current_month = new DateTime('first day of this month');
$start_month = (clone $current_month)->modify('-11 months'); // Last 12 months for chart

// Initialize months with zero notes for the last 12 months
for ($i = 0; $i < 12; $i++) {
    $month_label = (clone $start_month)->modify("+$i months")->format('M Y');
    $chart_notes_data[$month_label] = 0;
}

// Aggregate counts from accessible notes
foreach ($all_notes_raw as $note) {
    // Count all notes that are either public, or private but created by the current user
    // This is aligned with how notes are classified in the main view.
    if (!$note['is_private'] || ($note['is_private'] && $note['note_creator_user_id'] === $user_id)) {
        $note_date = new DateTime($note['created_at']);
        $note_month_year = $note_date->format('M Y');
        
        // Only count if the note falls within our 12-month chart range
        if ($note_date >= $start_month && $note_date <= $current_month->modify('last day of this month')) {
            if (isset($chart_notes_data[$note_month_year])) {
                $chart_notes_data[$note_month_year]++;
            }
        }
    }
}

// Convert to array of objects for JS chart: [{ label: 'Jan 2023', value: 10 }, ...]
$notes_chart_data_for_js = [];
foreach ($chart_notes_data as $label => $value) {
    $notes_chart_data_for_js[] = ['label' => $label, 'value' => $value];
}


// Now it's safe to include the header and output HTML
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team & Contact Notes | Churn Analytics Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <style>
        body { 
            font-family: 'Inter', system-ui, -apple-system, sans-serif; 
            color: #1e293b; 
            /* Removed background-color: #f8fafc; as requested */
        }
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .glass-card {
            /* Removed backdrop-filter, border, and box-shadow as requested */
            background-color: #ffffff; /* Explicitly set a white background */
            border-radius: 0.75rem; /* rounded-xl */
        }
        .header-section {
            background-color: #3ac3b8; /* Primary brand color */
            color: white;
            padding: 2rem;
            border-radius: 0.75rem; /* rounded-xl */
            margin-bottom: 2rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-section h1 {
            font-size: 2.25rem; /* text-3xl */
            font-weight: 700; /* font-bold */
        }
        .btn-primary {
            background-color: #3b82f6; /* Blue for primary actions */
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-primary:hover {
            background-color: #2563eb;
        }
        .btn-secondary {
            background-color: #e2e8f0;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 500;
            transition: background-color 0.2s ease-in-out;
        }
        .btn-secondary:hover {
            background-color: #cbd5e0;
        }
        .section-title {
            font-size: 1.5rem; /* text-2xl */
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 1.5rem;
        }
        .note-card {
            background-color: #f8fafc;
            border: 1px solid #cbd5e0;
            border-radius: 0.5rem;
            padding: 1.5rem;
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        }
        .note-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.08);
        }
        .note-card .tags-container span {
            background-color: #bfdbfe;
            color: #1e40af;
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px; /* full rounded */
        }
        .note-card .note-meta {
            border-top: 1px solid #e2e8f0;
            padding-top: 1rem;
            margin-top: 1rem;
            font-size: 0.875rem;
            color: #64748b;
        }
        .note-card .note-content-text {
            white-space: pre-wrap; /* Preserve whitespace and line breaks from textarea input */
            word-break: break-word; /* Prevent long words from overflowing */
        }
        .note-card .note-content-text strong { font-weight: 700; }
        .note-card .note-content-text em { font-style: italic; }
        .note-card .note-content-text u { text-decoration: underline; }
        .note-card .note-content-text a { color: #2563eb; text-decoration: underline; }
        .note-card .note-content-text img { max-width: 100%; height: auto; border-radius: 0.5rem; margin-top: 0.5rem; margin-bottom: 0.5rem; }
        .note-card .note-content-text ul { list-style-type: disc; margin-left: 1.25rem; }
        .note-card .note-content-text li { margin-bottom: 0.25rem; }

        .modal {
            background-color: rgba(0, 0, 0, 0.5);
            opacity: 0;
            visibility: hidden;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background-color: white;
            border-radius: 0.75rem;
            padding: 2rem;
            width: 100%;
            max-width: 32rem; /* max-w-lg */
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            transform: scale(0.95);
        }
        .modal.show .modal-content {
            transform: scale(1);
        }
        textarea.form-textarea {
            min-height: 8rem; /* h-32 */
            resize: vertical;
        }

        /* Tabs styling */
        .tab-buttons {
            display: flex;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap; /* Allow tabs to wrap */
            justify-content: center; /* Center tabs when they wrap */
            gap: 0.5rem; /* Add some space between wrapped buttons */
        }
        .tab-button {
            padding: 0.75rem 1.25rem;
            font-weight: 500;
            color: #64748b;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease-in-out;
            white-space: nowrap; 
        }
        .tab-button.active {
            color: #3b82f6;
            border-color: #3b82f6;
        }
        .tab-button:hover:not(.active) {
            color: #1e293b;
            background-color: #f1f5f9;
            border-radius: 0.5rem 0.5rem 0 0;
        }
        .tab-content {
            padding-top: 1rem;
        }

        /* Chart specific styles */
        .chart-container {
            position: relative;
            background-color: #ffffff;
            border: 1px solid #e0e7ff;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            max-width: 100%; /* Make it responsive within its parent */
            height: 400px; 
            display: flex;
            justify-content: center;
            align-items: center;
            border-radius: 1rem;
            margin-bottom: 2rem; /* Spacing below chart */
        }

        canvas {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: 1rem;
        }

        /* Tooltip styling */
        .chart-tooltip { /* Renamed to avoid conflict with other tooltips */
            position: absolute;
            background-color: rgba(30, 41, 59, 0.9); /* slate-800 with transparency */
            color: #ffffff;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            pointer-events: none; 
            opacity: 0;
            transition: opacity 0.2s ease-in-out;
            z-index: 100;
            white-space: nowrap;
            font-size: 0.875rem; 
            line-height: 1.25rem; 
            transform: translate(-50%, -110%); 
        }

        .chart-tooltip.visible {
            opacity: 1;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .container {
                margin-top: 20px;
                padding: 0 15px;
            }
            .header-section {
                flex-direction: column;
                align-items: flex-start;
                padding: 1.5rem;
            }
            .header-section h1 {
                font-size: 1.75rem;
                margin-bottom: 1rem;
            }
            .tab-buttons {
                flex-wrap: wrap;
                justify-content: center;
            }
            .tab-button {
                flex-grow: 1;
                text-align: center;
                padding: 0.5rem 0.75rem;
            }
            .chart-container {
                height: 300px; /* Adjust height for smaller screens */
            }
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="container">
        <!-- Header Section -->
        <div class="header-section">
            <div>
                <h1>Team & Contact Notes</h1>
                <p class="text-white text-opacity-80 mt-1">Manage your team memberships and view collaborative notes.</p>
            </div>
            <a href="dashboard.php" class="btn-primary text-sm">Back to Dashboard</a>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['info'])): ?>
            <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                <?= htmlspecialchars($_SESSION['info']) ?>
            </div>
            <?php unset($_SESSION['info']); ?>
        <?php endif; ?>

        <!-- Team Membership Section -->
        <div class="glass-card p-8 mb-8">
            <h2 class="section-title">Your Team Memberships</h2>
            <?php if (empty($user_teams) && empty($pending_invites)): ?>
                <div class="text-center py-8 text-slate-500">
                    <p>You are not currently part of any team and have no pending invites.</p>
                    <p class="mt-2">Reach out to a team owner to get invited!</p>
                </div>
            <?php else: ?>
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-slate-700 mb-4">Your Teams</h3>
                    <?php if (empty($user_teams)): ?>
                        <p class="text-slate-500">Not yet a member of any team.</p>
                    <?php else: ?>
                        <ul class="space-y-3">
                            <?php foreach ($user_teams as $team): ?>
                                <li class="flex justify-between items-center bg-slate-50 p-4 rounded-lg border border-slate-200">
                                    <span class="font-medium text-slate-800"><?= htmlspecialchars($team['team_name']) ?></span>
                                    <span class="text-slate-600 text-sm capitalize">Role: <span class="font-bold"><?= htmlspecialchars($team['role']) ?></span></span>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to leave <?= htmlspecialchars($team['team_name']) ?>? If you are an owner, you must transfer ownership first.');">
                                        <input type="hidden" name="team_id" value="<?= $team['team_id'] ?>">
                                        <button type="submit" name="leave_team" class="btn-secondary text-xs px-3 py-1">Leave Team</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-slate-700 mb-4">Pending Invites</h3>
                    <?php if (empty($pending_invites)): ?>
                        <p class="text-slate-500">No pending team invites.</p>
                    <?php else: ?>
                        <ul class="space-y-3">
                            <?php foreach ($pending_invites as $invite): ?>
                                <li class="flex justify-between items-center bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                                    <span class="font-medium text-yellow-800">Invite to <?= htmlspecialchars($invite['team_name']) ?></span>
                                    <span class="text-yellow-700 text-sm">Invited by: <?= htmlspecialchars($invite['invited_by_username']) ?></span>
                                    <div class="flex gap-2">
                                        <form method="POST">
                                            <input type="hidden" name="invite_id" value="<?= $invite['invite_id'] ?>">
                                            <input type="hidden" name="team_id" value="<?= $invite['team_id'] ?>">
                                            <button type="submit" name="accept_invite" class="btn bg-green-600 text-white text-xs px-3 py-1 rounded-md hover:bg-green-700">Accept</button>
                                        </form>
                                        <form method="POST">
                                            <input type="hidden" name="invite_id" value="<?= $invite['invite_id'] ?>">
                                            <button type="submit" name="decline_invite" class="btn bg-red-600 text-white text-xs px-3 py-1 rounded-md hover:bg-red-700">Decline</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notes Chart Section -->
        <div class="glass-card p-8 mb-8">
            <h2 class="section-title">Monthly Notes Overview</h2>
            <div class="chart-container">
                <canvas id="notesLineChart"></canvas>
                <div id="chartTooltip" class="chart-tooltip"></div>
            </div>
        </div>

        <!-- Contact Notes Section -->
        <div class="glass-card p-8">
            <h2 class="section-title">Contact Notes</h2>
            
            <div class="tab-buttons">
                <button class="tab-button active" data-tab="all_notes">All Notes (<?= count($all_notes_raw) ?>)</button>
                <button class="tab-button" data-tab="my_streams_notes">My Streams' Notes (<?= count($notes_for_my_streams) ?>)</button>
                <button class="tab-button" data-tab="team_streams_notes">Team Streams' Notes (<?= count($notes_for_team_streams) ?>)</button>
                <button class="tab-button" data-tab="my_team_members_notes">My Team Members' Notes (<?= count($notes_from_my_team_members) ?>)</button>
                <button class="tab-button" data-tab="my_private_notes">My Private Notes (<?= count($my_private_notes) ?>)</button>
            </div>

            <div id="tab_all_notes" class="tab-content space-y-6">
                <?php if (empty($all_notes_raw)): ?>
                    <div class="text-center py-12 text-slate-500">
                        <div class="text-4xl mb-4">📝</div>
                        <p>No notes found for your accessible contacts.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($all_notes_raw as $note): ?>
                        <div class="note-card">
                            <div class="text-slate-700 leading-relaxed mb-4 note-content-text">
                                <?= parse_bbcode(htmlspecialchars($note['note'])) ?>
                            </div>
                            <?php if (!empty($note['tags'])): ?>
                                <div class="flex flex-wrap gap-2 mb-4 tags-container">
                                    <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                        <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                            <?= htmlspecialchars(trim($tag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-slate-500 note-meta">
                                For <a href="contact_notes.php?contact_id=<?= $note['contact_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= htmlspecialchars($note['contact_username'] ?: $note['contact_email']) ?>
                                </a> in <span class="font-medium"><?= htmlspecialchars($note['stream_name']) ?></span>
                                <br>
                                By <strong><?= htmlspecialchars($note['creator_username']) ?></strong> 
                                <?php if (!empty($note['creator_team_names'])): ?>
                                    (Team: <?= htmlspecialchars($note['creator_team_names']) ?>)
                                <?php endif; ?>
                                • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                                <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                    • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                                <?php endif; ?>
                                <?php if ($note['is_private'] && $note['note_creator_user_id'] === $user_id): /* Only show Private tag if it's THEIR private note */ ?>
                                    <span class="bg-slate-100 text-slate-600 text-xs px-2 py-1 rounded ml-2">🔒 Private</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab_my_streams_notes" class="tab-content space-y-6 hidden">
                <?php if (empty($notes_for_my_streams)): ?>
                    <div class="text-center py-12 text-slate-500">
                        <div class="text-4xl mb-4">📝</div>
                        <p>No notes for your directly owned streams.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notes_for_my_streams as $note): ?>
                        <div class="note-card">
                            <div class="text-slate-700 leading-relaxed mb-4 note-content-text">
                                <?= parse_bbcode(htmlspecialchars($note['note'])) ?>
                            </div>
                            <?php if (!empty($note['tags'])): ?>
                                <div class="flex flex-wrap gap-2 mb-4 tags-container">
                                    <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                        <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                            <?= htmlspecialchars(trim($tag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-slate-500 note-meta">
                                For <a href="contact_notes.php?contact_id=<?= $note['contact_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= htmlspecialchars($note['contact_username'] ?: $note['contact_email']) ?>
                                </a> in <span class="font-medium"><?= htmlspecialchars($note['stream_name']) ?></span>
                                <br>
                                By <strong><?= htmlspecialchars($note['creator_username']) ?></strong> 
                                <?php if (!empty($note['creator_team_names'])): ?>
                                    (Team: <?= htmlspecialchars($note['creator_team_names']) ?>)
                                <?php endif; ?>
                                • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                                <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                    • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab_team_streams_notes" class="tab-content space-y-6 hidden">
                <?php if (empty($notes_for_team_streams)): ?>
                    <div class="text-center py-12 text-slate-500">
                        <div class="text-4xl mb-4">📝</div>
                        <p>No notes for streams owned by your teams or shared with your teams.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notes_for_team_streams as $note): ?>
                        <div class="note-card">
                            <div class="text-slate-700 leading-relaxed mb-4 note-content-text">
                                <?= parse_bbcode(htmlspecialchars($note['note'])) ?>
                            </div>
                            <?php if (!empty($note['tags'])): ?>
                                <div class="flex flex-wrap gap-2 mb-4 tags-container">
                                    <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                        <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                            <?= htmlspecialchars(trim($tag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-slate-500 note-meta">
                                For <a href="contact_notes.php?contact_id=<?= $note['contact_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= htmlspecialchars($note['contact_username'] ?: $note['contact_email']) ?>
                                </a> in <span class="font-medium"><?= htmlspecialchars($note['stream_name']) ?></span>
                                <br>
                                By <strong><?= htmlspecialchars($note['creator_username']) ?></strong> 
                                <?php if (!empty($note['creator_team_names'])): ?>
                                    (Team: <?= htmlspecialchars($note['creator_team_names']) ?>)
                                <?php endif; ?>
                                • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                                <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                    • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab_my_team_members_notes" class="tab-content space-y-6 hidden">
                <?php if (empty($notes_from_my_team_members)): ?>
                    <div class="text-center py-12 text-slate-500">
                        <div class="text-4xl mb-4">📝</div>
                        <p>No public notes from your team members on accessible streams.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($notes_from_my_team_members as $note): ?>
                        <div class="note-card">
                            <div class="text-slate-700 leading-relaxed mb-4 note-content-text">
                                <?= parse_bbcode(htmlspecialchars($note['note'])) ?>
                            </div>
                            <?php if (!empty($note['tags'])): ?>
                                <div class="flex flex-wrap gap-2 mb-4 tags-container">
                                    <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                        <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                            <?= htmlspecialchars(trim($tag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-slate-500 note-meta">
                                For <a href="contact_notes.php?contact_id=<?= $note['contact_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= htmlspecialchars($note['contact_username'] ?: $note['contact_email']) ?>
                                </a> in <span class="font-medium"><?= htmlspecialchars($note['stream_name']) ?></span>
                                <br>
                                By <strong><?= htmlspecialchars($note['creator_username']) ?></strong> 
                                <?php if (!empty($note['creator_team_names'])): ?>
                                    (Team: <?= htmlspecialchars($note['creator_team_names']) ?>)
                                <?php endif; ?>
                                • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                                <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                    • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div id="tab_my_private_notes" class="tab-content space-y-6 hidden">
                <?php if (empty($my_private_notes)): ?>
                    <div class="text-center py-12 text-slate-500">
                        <div class="text-4xl mb-4">📝</div>
                        <p>You have no private notes.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($my_private_notes as $note): ?>
                        <div class="note-card border-l-4 border-slate-400">
                            <div class="text-slate-700 leading-relaxed mb-4 note-content-text">
                                <?= parse_bbcode(htmlspecialchars($note['note'])) ?>
                            </div>
                            <?php if (!empty($note['tags'])): ?>
                                <div class="flex flex-wrap gap-2 mb-4 tags-container">
                                    <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                        <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                            <?= htmlspecialchars(trim($tag)) ?>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <div class="text-sm text-slate-500 note-meta">
                                For <a href="contact_notes.php?contact_id=<?= $note['contact_id'] ?>" class="text-blue-600 hover:underline font-medium">
                                    <?= htmlspecialchars($note['contact_username'] ?: $note['contact_email']) ?>
                                </a> in <span class="font-medium"><?= htmlspecialchars($note['stream_name']) ?></span>
                                <br>
                                By <strong><?= htmlspecialchars($note['creator_username']) ?></strong> 
                                • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                                <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                    • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                                <?php endif; ?>
                                <span class="bg-slate-100 text-slate-600 text-xs px-2 py-1 rounded ml-2">🔒 Private</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Tab functionality
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabContents = document.querySelectorAll('.tab-content');

            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    const tab = button.dataset.tab;

                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    button.classList.add('active');

                    tabContents.forEach(content => {
                        if (content.id === `tab_${tab}`) {
                            content.classList.remove('hidden');
                        } else {
                            content.classList.add('hidden');
                        }
                    });
                });
            });

            // Chart functionality
            const canvas = document.getElementById('notesLineChart');
            if (canvas) { // Ensure canvas element exists
                const ctx = canvas.getContext('2d');
                const tooltip = document.getElementById('chartTooltip');

                // Data passed from PHP
                const chartData = <?= json_encode($notes_chart_data_for_js) ?>;

                const config = {
                    padding: 40,
                    pointRadius: 6,
                    gridColor: '#e0e7ff',
                    axisLabelColor: '#6b7280',
                    lineColor: '#3b82f6', // Use Tailwind blue-600 for consistency
                    gradientStartColor: 'rgba(59, 130, 246, 0.2)', // blue-600 with opacity
                    gradientEndColor: 'rgba(59, 130, 246, 0)', // Transparent
                };

                function drawChart() {
                    const devicePixelRatio = window.devicePixelRatio || 1;
                    canvas.width = canvas.offsetWidth * devicePixelRatio;
                    canvas.height = canvas.offsetHeight * devicePixelRatio;
                    ctx.scale(devicePixelRatio, devicePixelRatio);

                    ctx.clearRect(0, 0, canvas.offsetWidth, canvas.offsetHeight);

                    const width = canvas.offsetWidth;
                    const height = canvas.offsetHeight;
                    const { padding, pointRadius, gridColor, axisLabelColor, lineColor, gradientStartColor, gradientEndColor } = config;

                    const chartWidth = width - 2 * padding;
                    const chartHeight = height - 2 * padding;

                    if (chartData.length === 0) {
                        ctx.fillStyle = axisLabelColor;
                        ctx.font = '16px Inter';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';
                        ctx.fillText("No notes data available for chart.", width / 2, height / 2);
                        tooltip.classList.remove('visible');
                        return;
                    }

                    const values = chartData.map(d => d.value);
                    const maxValue = Math.max(...values);
                    const minValue = Math.min(...values);
                    
                    const effectiveMinValue = minValue > 0 ? 0 : minValue; 
                    const effectiveMaxValue = maxValue * 1.1; // Add 10% buffer at the top
                    const effectiveValueRange = effectiveMaxValue - effectiveMinValue;
                    if (effectiveValueRange === 0) { // Prevent division by zero if all values are the same
                        effectiveMaxValue = maxValue + 1; // Give it some range
                        effectiveValueRange = effectiveMaxValue - effectiveMinValue;
                    }


                    const getX = (index) => padding + (index / (chartData.length - 1)) * chartWidth;

                    const getY = (value) => {
                        const normalizedValue = (value - effectiveMinValue) / effectiveValueRange;
                        return height - padding - (normalizedValue * chartHeight);
                    };

                    // Draw Grid Lines and Labels
                    const numYLabels = 5;
                    for (let i = 0; i <= numYLabels; i++) {
                        const value = effectiveMinValue + (effectiveValueRange / numYLabels) * i;
                        const y = getY(value);

                        ctx.beginPath();
                        ctx.moveTo(padding, y);
                        ctx.lineTo(width - padding, y);
                        ctx.strokeStyle = gridColor;
                        ctx.lineWidth = 1;
                        ctx.globalAlpha = 0.5;
                        ctx.stroke();
                        ctx.globalAlpha = 1;

                        ctx.fillStyle = axisLabelColor;
                        ctx.font = '12px Inter';
                        ctx.textAlign = 'right';
                        ctx.textBaseline = 'middle';
                        ctx.fillText(Math.round(value), padding - 10, y);
                    }

                    // X-axis labels
                    ctx.fillStyle = axisLabelColor;
                    ctx.font = '12px Inter';
                    ctx.textBaseline = 'top';
                    chartData.forEach((d, i) => {
                        const x = getX(i);
                        ctx.textAlign = (i === 0) ? 'left' : (i === chartData.length - 1) ? 'right' : 'center';
                        ctx.fillText(d.label, x, height - padding + 15);
                    });

                    // Draw the Line and Gradient Fill
                    ctx.beginPath();
                    ctx.moveTo(getX(0), getY(effectiveMinValue));
                    chartData.forEach((d, i) => {
                        const x = getX(i);
                        const y = getY(d.value);
                        if (i === 0) {
                            ctx.lineTo(x, y);
                        } else {
                            const prevX = getX(i - 1);
                            const prevY = getY(chartData[i - 1].value);
                            const cp1x = (prevX + x) / 2;
                            const cp1y = prevY;
                            const cp2x = (prevX + x) / 2;
                            const cp2y = y;
                            ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                        }
                    });
                    ctx.lineTo(getX(chartData.length - 1), getY(effectiveMinValue));
                    ctx.closePath();

                    const gradient = ctx.createLinearGradient(0, padding, 0, height - padding);
                    gradient.addColorStop(0, gradientStartColor);
                    gradient.addColorStop(1, gradientEndColor);
                    ctx.fillStyle = gradient;
                    ctx.fill();

                    // Draw the line
                    ctx.beginPath();
                    chartData.forEach((d, i) => {
                        const x = getX(i);
                        const y = getY(d.value);
                        if (i === 0) {
                            ctx.moveTo(x, y);
                        } else {
                            const prevX = getX(i - 1);
                            const prevY = getY(chartData[i - 1].value);
                            const cp1x = (prevX + x) / 2;
                            const cp1y = prevY;
                            const cp2x = (prevX + x) / 2;
                            const cp2y = y;
                            ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                        }
                    });
                    ctx.strokeStyle = lineColor;
                    ctx.lineWidth = 3;
                    ctx.stroke();

                    // Draw Data Points
                    chartData.forEach((d, i) => {
                        const x = getX(i);
                        const y = getY(d.value);

                        ctx.beginPath();
                        ctx.arc(x, y, pointRadius, 0, Math.PI * 2);
                        ctx.fillStyle = lineColor;
                        ctx.fill();
                        ctx.strokeStyle = '#ffffff';
                        ctx.lineWidth = 2;
                        ctx.stroke();
                    });
                }

                // --- Interactive Tooltip Logic ---
                let animationFrameId = null;

                canvas.addEventListener('mousemove', (e) => {
                    cancelAnimationFrame(animationFrameId);

                    animationFrameId = requestAnimationFrame(() => {
                        const rect = canvas.getBoundingClientRect();
                        const mouseX = e.clientX - rect.left;
                        const mouseY = e.clientY - rect.top;

                        let hoveredPoint = null;
                        const tolerance = config.pointRadius * 1.5;

                        for (let i = 0; i < chartData.length; i++) {
                            const x = config.padding + (i / (chartData.length - 1)) * (canvas.offsetWidth - 2 * config.padding);
                            const y = getY(chartData[i].value);

                            const distance = Math.sqrt(Math.pow(mouseX - x, 2) + Math.pow(mouseY - y, 2));

                            if (distance < tolerance) {
                                hoveredPoint = {
                                    data: chartData[i],
                                    x: x,
                                    y: y
                                };
                                break;
                            }
                        }

                        if (hoveredPoint) {
                            tooltip.innerHTML = `<strong>${hoveredPoint.data.label}</strong>: ${hoveredPoint.data.value}`;
                            // Adjust tooltip position to be relative to the chart container, not canvas
                            tooltip.style.left = `${hoveredPoint.x + canvas.offsetLeft}px`;
                            tooltip.style.top = `${hoveredPoint.y + canvas.offsetTop}px`;
                            tooltip.classList.add('visible');
                        } else {
                            tooltip.classList.remove('visible');
                        }
                    });
                });

                canvas.addEventListener('mouseleave', () => {
                    cancelAnimationFrame(animationFrameId);
                    tooltip.classList.remove('visible');
                });

                // --- Responsiveness ---
                let resizeTimeout;
                window.addEventListener('resize', () => {
                    clearTimeout(resizeTimeout);
                    resizeTimeout = setTimeout(() => {
                        drawChart();
                    }, 100); 
                });

                // Initial draw when window is fully loaded
                drawChart();
            } // End of if (canvas) check
        });
    </script>
</body>
</html>