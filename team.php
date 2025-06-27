<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1'); // Shows parse errors at startup
ini_set('log_errors', '1'); // Ensures errors are logged
ini_set('error_log', '/var/www/www-root/data/www/earndos.com/io/php_app_errors.log'); // DIRECTS ERRORS HERE
session_start(); // 1. Ensure session is started FIRST

// 2. IMPORTANT: Include DB connection here, before any form processing or HTML output
require_once 'includes/db.php'; // Make $pdo and BASE_URL available

// Check if user is logged in and redirect if not
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php'); // Use BASE_URL from db.php
    exit;
}

$user_id = $_SESSION['user_id'];

// --- Include any necessary function files here, after db.php and initial auth check ---
require_once 'team_functions.php';
require_once 'includes/notification_functions.php';

// --- PHP Error Reporting for Debugging (optional, keep at top during dev) ---
// Note: These lines are already at the very top of your file. Keeping them here for clarity.
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);
// --- End PHP Error Reporting ---


// Get the team ID from GET parameter
$team_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Verify user has access to this team BEFORE any POST handling
$user_teams = get_user_teams($user_id);
$has_access = false;
$team_role = '';
$is_owner = false;
$team_details_for_check = null; // Store team details found for consistency

foreach ($user_teams as $team_data) {
    if ($team_data['id'] == $team_id) {
        $has_access = true;
        $team_role = $team_data['role'];
        $is_owner = ($team_data['role'] == 'owner');
        $team_details_for_check = $team_data; // Capture details for potential use in messages
        break;
    }
}

if (!$has_access || $team_id === 0) { // Redirect if no access or invalid team ID
    $_SESSION['error'] = "You don't have access to this team or the team ID is invalid.";
    header('Location: teams.php');
    exit;
}

// Ensure the specific team details are fetched for the current page display
$team = get_team_details($team_id);
if (!$team) {
    $_SESSION['error'] = "Team not found.";
    header('Location: teams.php');
    exit;
}


// --- Handle form submissions (POST requests) ---
// This must be BEFORE any HTML output, as it performs redirects
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['invite_member']) && $is_owner) {
        $email = trim($_POST['email']);
        
        try {
            $result = send_team_invite($team_id, $email, $user_id);
            
            if ($result['status'] === 'success') {
                $_SESSION['success'] = "Invitation sent successfully!";
            } else {
                $_SESSION['error'] = $result['message'];
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Error sending invitation: " . $e->getMessage();
        }
        
        header("Location: team.php?id=$team_id");
        exit;
    }
    elseif (isset($_POST['remove_member']) && $is_owner) {
        $member_id = (int)$_POST['member_id'];
        
        // Can't remove yourself as an owner if you're the only owner
        if ($member_id == $user_id) {
            $stmt_owner_count = $pdo->prepare("SELECT COUNT(*) FROM team_members WHERE team_id = ? AND role = 'owner'");
            $stmt_owner_count->execute([$team_id]);
            if ($stmt_owner_count->fetchColumn() <= 1) { // If only one owner
                $_SESSION['error'] = "You cannot remove yourself if you are the only owner of this team.";
                header("Location: team.php?id=$team_id");
                exit;
            }
        }

        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = ? AND user_id = ?");
            $stmt->execute([$team_id, $member_id]);
            
            // Create notification for removed user
            // Use team name from $team_details_for_check or re-fetch if needed for message
            $team_name_for_notification = $team['name'] ?? 'your team'; 
            $message = "You were removed from team " . htmlspecialchars($team_name_for_notification);
            create_notification($member_id, 'team_removed', $message, $team_id);
            
            $pdo->commit();
            $_SESSION['success'] = "Member removed successfully!";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error removing member: " . $e->getMessage();
        }
        
        header("Location: team.php?id=$team_id");
        exit;
    }
    elseif (isset($_POST['update_role']) && $is_owner) {
        $member_id = (int)$_POST['member_id'];
        $new_role = $_POST['new_role'];
        
        // Can't change your own role
        if ($member_id == $user_id) {
            $_SESSION['error'] = "You can't change your own role.";
        } else {
            // Validate new role
            if (!in_array($new_role, ['owner', 'editor', 'viewer'])) {
                   $_SESSION['error'] = "Invalid role specified.";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE team_members SET role = ? WHERE team_id = ? AND user_id = ?");
                    $stmt->execute([$new_role, $team_id, $member_id]);
                    
                    // Create notification
                    $team_name_for_notification = $team['name'] ?? 'your team';
                    $message = "Your role in team " . htmlspecialchars($team_name_for_notification) . " was changed to " . htmlspecialchars($new_role);
                    create_notification($member_id, 'team_role_changed', $message, $team_id);
                    
                    $_SESSION['success'] = "Role updated successfully!";
                } catch (PDOException $e) {
                    $_SESSION['error'] = "Error updating role: " . $e->getMessage();
                }
            }
        }
        
        header("Location: team.php?id=$team_id");
        exit;
    }
    elseif (isset($_POST['update_team']) && $is_owner) {
        $team_name = trim($_POST['team_name']);
        $team_description = trim($_POST['team_description']);

        if (empty($team_name)) {
            $_SESSION['error'] = "Team name cannot be empty.";
            header("Location: team.php?id=$team_id");
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE teams SET name = ?, description = ? WHERE id = ? AND created_by = ?");
            $stmt->execute([$team_name, $team_description, $team_id, $user_id]);
            $_SESSION['success'] = "Team settings updated successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error updating team settings: " . $e->getMessage();
            error_log("Error updating team settings: " . $e->getMessage());
        }
        header("Location: team.php?id=$team_id");
        exit;
    }
    // NEW: Handle deleting pending invites
    elseif (isset($_POST['delete_invite']) && $is_owner) {
        $invite_id = (int)$_POST['invite_id'];

        try {
            $stmt = $pdo->prepare("DELETE FROM team_invites WHERE id = ? AND team_id = ?");
            $stmt->execute([$invite_id, $team_id]);
            
            // Optionally, notify the invited user that their invite was revoked.
            // This would require fetching the invite's email first.
            // For simplicity, we are skipping a notification for now, but it's a good practice.
            $_SESSION['success'] = "Invite deleted successfully!";
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error deleting invite: " . $e->getMessage();
            error_log("Error deleting invite: " . $e->getMessage());
        }
        header("Location: team.php?id=$team_id#invites"); // Redirect back to invites tab
        exit;
    }
    elseif (isset($_POST['delete_team_from_settings']) && $is_owner) { // Renamed for clarity vs. teams.php delete
        try {
            $pdo->beginTransaction();
            
            // Delete team relationships (order matters due to foreign keys)
            $tables_to_delete_from = ['team_members', 'team_invites', 'team_streams'];
            foreach ($tables_to_delete_from as $table) {
                $stmt = $pdo->prepare("DELETE FROM $table WHERE team_id = ?");
                $stmt->execute([$team_id]);
            }
            
            // Delete team
            $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ? AND created_by = ?"); // Ensure only owner can delete
            $stmt->execute([$team_id, $user_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Team deleted successfully!";
            header('Location: teams.php'); // Redirect to all teams after deletion
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error deleting team: " . $e->getMessage();
            error_log("Error deleting team: " . $e->getMessage()); // Log detailed error
        }
        header("Location: team.php?id=$team_id"); // Fallback redirect in case of error
        exit;
    }
}


// --- Data Fetching for Display (only after all logic and potential redirects) ---

// Fetch team details, members, streams, and pending invites
// $team variable is already defined and validated above.
$members = get_team_members($team_id);
$streams = get_team_streams($team_id);
$pending_invites = $is_owner ? get_pending_invites($team_id) : [];


// --- HTML Output Starts Here ---
require_once 'includes/header.php'; // Include header now that all PHP logic is done
?>
<style>
/* Add the general styles that are defined in teams.php or a global CSS file */
/* This ensures consistency. You might want to put common styles in a main.css */
:root {
    --primary: #3ac3b8;
    --primary-dark: #2da89e;
    --secondary: #4299e1;
    --danger: #e53e3e;
    --danger-dark: #c53030;
    --warning: #f6ad55;
    --success: #68d391;
    --info: #4299e1;
    --info-dark: #3182ce;
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
    --border-color: var(--gray-300);
    --text-color-light: var(--gray-500);
    --light-bg: var(--gray-100);
    --secondary-bg: var(--gray-200);
}

.team-view-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}
    
.team-header {
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--gray-200); /* Using variable */
}
    
.team-header h1 {
    margin: 0 0 10px 0;
    color: var(--dark); /* Using variable */
}
    
.team-description {
    color: var(--gray-700); /* Using variable */
    line-height: 1.6;
    margin-bottom: 15px;
}
    
.team-meta {
    display: flex;
    gap: 20px;
    margin-top: 15px;
}
    
.meta-item {
    display: flex;
    align-items: center;
    gap: 5px;
    color: var(--gray-600); /* Using variable */
    font-size: 0.9rem;
}
    
.role-badge {
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}
    
.role-badge.owner {
    background: var(--warning);
    color: var(--dark);
}
    
.role-badge.editor {
    background: var(--info);
    color: white;
}
    
.role-badge.viewer {
    background: var(--gray-500);
    color: white;
}
    
.team-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 1px solid var(--gray-200); /* Using variable */
    padding-bottom: 10px;
}
    
.tab-btn {
    padding: 8px 20px;
    background: none;
    border: 1px solid transparent; /* Added border for consistency */
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    color: var(--gray-700); /* Using variable */
    transition: background 0.2s ease, color 0.2s ease;
}
    
.tab-btn:hover {
    background: var(--gray-100); /* Using variable */
    color: var(--dark);
}
    
.tab-btn.active {
    background: var(--primary);
    color: white;
    border-color: var(--primary); /* Active border */
}
    
.tab-content {
    display: none;
    padding-top: 15px; /* Space from tabs */
}
    
.tab-content.active {
    display: block;
}
    
.members-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
}
    
.member-card {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border: 1px solid var(--gray-200); /* Using variable */
    border-radius: 8px;
    background: var(--white); /* Using variable */
}
    
.member-info {
    display: flex;
    align-items: center;
    gap: 15px;
}
    
.member-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    flex-shrink: 0; /* Prevent shrinking */
}
    
.member-details {
    display: flex;
    flex-direction: column;
}
    
.member-details h4 {
    margin: 0;
    color: var(--dark);
}
    
.member-email {
    font-size: 0.8rem;
    color: var(--gray-600);
}
    
.member-joined {
    font-size: 0.7rem;
    color: var(--gray-500);
}
    
.member-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap; /* Allow wrapping on small screens */
    justify-content: flex-end; /* Align actions to the right */
}
    
.member-role {
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}
    
.member-role.owner {
    background: var(--warning);
    color: var(--dark);
}
    
.member-role.editor {
    background: var(--info);
    color: white;
}
    
.member-role.viewer {
    background: var(--gray-500);
    color: white;
}
    
.role-dropdown {
    position: relative;
}
    
.role-select {
    padding: 5px;
    border-radius: 4px;
    border: 1px solid var(--gray-300);
    background: var(--white);
    color: var(--dark);
    cursor: pointer;
}
    
.remove-btn {
    padding: 5px 10px;
    background: var(--danger);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
}
    
.remove-btn:hover {
    background: var(--danger-dark);
}
    
.invite-form-container {
    margin-top: 30px;
    padding: 20px;
    background: var(--light-bg);
    border-radius: 8px;
    border: 1px solid var(--gray-200);
}
    
.invite-form {
    display: flex;
    gap: 10px;
    margin-top: 10px;
    flex-wrap: wrap;
}
    
.invite-form input[type="email"] {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    min-width: 200px;
}
    
.invite-btn {
    padding: 8px 20px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.invite-btn:hover {
    background: var(--primary-dark);
}
    
.streams-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 15px;
}
    
.stream-card {
    padding: 15px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: var(--white);
}
    
.stream-card h4 {
    margin: 0 0 10px 0;
    color: var(--dark);
}
    
.access-badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}
    
.access-badge.edit {
    background: var(--success);
    color: white;
}
    
.access-badge.view {
    background: var(--gray-400);
    color: var(--dark);
}
    
.view-stream-btn {
    display: inline-block;
    margin-top: 10px;
    padding: 5px 10px;
    background: var(--gray-200);
    color: var(--dark);
    border-radius: 4px;
    text-decoration: none;
    font-size: 0.9rem;
    transition: background 0.2s ease;
}
.view-stream-btn:hover {
    background: var(--gray-300);
}
    
.no-streams, .no-invites {
    text-align: center;
    padding: 40px 20px;
    background: var(--light-bg);
    border-radius: 8px;
    color: var(--gray-700);
    border: 1px solid var(--gray-200);
}
    
.add-stream-btn {
    display: inline-block;
    margin-top: 15px;
    padding: 8px 20px;
    background: var(--primary);
    color: white;
    border-radius: 4px;
    text-decoration: none;
    transition: background 0.2s ease;
}
.add-stream-btn:hover {
    background: var(--primary-dark);
}
    
.invites-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
    
.invite-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    background: var(--white);
}
    
.invite-details {
    display: flex;
    flex-direction: column;
}
    
.invite-email {
    font-weight: 500;
    color: var(--dark);
}
    
.invite-date {
    font-size: 0.8rem;
    color: var(--gray-600);
}
    
.invite-status {
    padding: 3px 10px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
    flex-shrink: 0; /* Prevent shrinking */
}
    
.invite-status.pending {
    background: var(--warning);
    color: var(--dark);
}
/* New style for invite actions */
.invite-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}
/* New style for delete invite button */
.delete-invite-btn {
    padding: 5px 10px;
    background: var(--danger);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
    font-size: 0.8rem;
}
.delete-invite-btn:hover {
    background: var(--danger-dark);
}

.team-settings-form {
    max-width: 600px;
    margin: 0 auto;
    padding: 20px;
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: 8px;
}
    
.form-group {
    margin-bottom: 20px;
}
    
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--gray-700);
}
    
.form-group input[type="text"],
.form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 1rem;
    color: var(--dark);
}
    
.form-group textarea {
    min-height: 100px;
    resize: vertical;
}
    
.form-actions {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    flex-wrap: wrap;
    gap: 15px;
}
    
.save-btn {
    padding: 10px 25px;
    background: var(--primary);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.save-btn:hover {
    background: var(--primary-dark);
}
    
.delete-team-btn {
    padding: 10px 25px;
    background: var(--danger);
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.delete-team-btn:hover {
    background: var(--danger-dark);
}
    
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
}
    
.alert.error {
    background: #f8e6e6;
    color: #dc3545;
}
    
.alert.success {
    background: #e6f7ee;
    color: #28a745;
}

/* Modal styles (from streams.php, ensuring consistency) */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    overflow: auto;
    align-items: center;
    justify-content: center;
}
.modal-content {
    background: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    max-width: 600px;
    position: relative;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    transform: translate3d(0,0,0); /* For draggable modals */
    max-height: 90vh; /* Allow scrolling if content is too long */
    overflow-y: auto;
}
.modal-content h2 {
    cursor: grab;
    user-select: none;
    margin-bottom: 20px;
}
.close {
    position: absolute;
    right: 20px;
    top: 15px;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray-500);
    transition: color 0.2s ease;
}
.close:hover {
    color: var(--dark);
}
/* Editor styles (if not already global or in a dedicated CSS) */
.editor-toolbar {
    display: flex;
    gap: 5px;
    margin-bottom: 5px;
}
.editor-btn {
    padding: 5px 10px;
    background: var(--gray-200);
    border: 1px solid var(--gray-300);
    border-radius: 3px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.editor-btn:hover {
    background: var(--gray-300);
}
.editor-container {
    position: relative;
}
#teamDescription {
    width: 100%;
    min-height: 100px;
    resize: vertical;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    padding: 8px 12px;
    font-family: inherit;
}
.editor-preview {
    display: none;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    min-height: 100px;
    background: var(--white);
    margin-top: 10px;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .team-meta {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .member-card {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    .member-actions {
        width: 100%;
        justify-content: flex-start;
        gap: 10px;
    }
    .invite-form input[type="email"] {
        width: 100%;
        min-width: unset;
    }
    .invite-form-container, .team-settings-form {
        padding: 15px;
    }
    .form-actions {
        flex-direction: column;
        gap: 10px;
    }
    .save-btn, .delete-team-btn {
        width: 100%;
        text-align: center;
    }
    /* Responsive adjustment for invite item */
    .invite-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    .invite-actions {
        width: 100%;
        justify-content: flex-start;
    }
}
</style>

<div class="team-view-container">
    <div class="team-header">
        <h1><?= htmlspecialchars($team['name']) ?></h1>
        <div class="team-description"><?= nl2br(htmlspecialchars($team['description'])) ?></div>
        
        <div class="team-meta">
            <span class="meta-item">
                <i class="bi bi-people-fill"></i> <?= count($members) ?> members
            </span>
            <span class="meta-item">
                <i class="bi bi-collection-play-fill"></i> <?= count($streams) ?> streams
            </span>
            <span class="meta-item role-badge <?= $team_role ?>">
                <?= ucfirst($team_role) ?>
            </span>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="team-tabs">
        <button class="tab-btn active" data-tab="members">Members</button>
        <button class="tab-btn" data-tab="streams">Streams</button>
        <?php if ($is_owner): ?>
            <button class="tab-btn" data-tab="invites">Pending Invites</button>
            <button class="tab-btn" data-tab="settings">Team Settings</button>
        <?php endif; ?>
    </div>

    <div class="tab-content active" id="members-tab">
        <div class="members-list">
            <?php foreach ($members as $member): ?>
                <div class="member-card">
                    <div class="member-info">
                        <div class="member-avatar">
                            <?= strtoupper(substr($member['username'], 0, 1)) ?>
                        </div>
                        <div class="member-details">
                            <h4><?= htmlspecialchars($member['username']) ?></h4>
                            <span class="member-email"><?= htmlspecialchars($member['email']) ?></span>
                            <span class="member-joined">Joined <?= date('M j, Y', strtotime($member['joined_at'])) ?></span>
                        </div>
                    </div>
                    
                    <div class="member-actions">
                        <span class="member-role <?= $member['role'] ?>">
                            <?= ucfirst($member['role']) ?>
                        </span>
                        
                        <?php if ($is_owner && $member['id'] != $user_id): // Owners can change others' roles, but not their own ?>
                            <div class="role-dropdown">
                                <form method="POST" class="role-form">
                                    <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                    <select name="new_role" class="role-select" onchange="this.form.submit()">
                                        <option value="owner" <?= $member['role'] == 'owner' ? 'selected' : '' ?>>Owner</option>
                                        <option value="editor" <?= $member['role'] == 'editor' ? 'selected' : '' ?>>Editor</option>
                                        <option value="viewer" <?= $member['role'] == 'viewer' ? 'selected' : '' ?>>Viewer</option>
                                    </select>
                                    <input type="hidden" name="update_role">
                                </form>
                            </div>
                            
                            <form method="POST" class="remove-form">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <button type="submit" name="remove_member" class="remove-btn" 
                                        onclick="return confirm('Remove <?= htmlspecialchars($member['username']) ?> from team?');">
                                    Remove
                                </button>
                            </form>
                        <?php elseif ($member['id'] == $user_id): // Display current user's role without action if not owner ?>
                            <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if ($is_owner): ?>
            <div class="invite-form-container">
                <h3>Invite New Member</h3>
                <form method="POST" class="invite-form">
                    <input type="email" name="email" placeholder="Enter email address" required>
                    <button type="submit" name="invite_member" class="invite-btn">
                        Send Invite
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="tab-content" id="streams-tab">
        <?php if (count($streams)): ?>
            <div class="streams-grid">
                <?php foreach ($streams as $stream): ?>
                    <div class="stream-card">
                        <h4><?= htmlspecialchars($stream['name'] ?? 'N/A') ?></h4>
                        <div class="stream-meta">
                            <span class="access-badge <?= htmlspecialchars($stream['access_level'] ?? '') ?>">
                                <?= ucfirst(htmlspecialchars($stream['access_level'] ?? '')) ?> access
                            </span>
                        </div>
                        <a href="streams.php?id=<?= htmlspecialchars($stream['id'] ?? '') ?>" class="view-stream-btn">
                            View Stream
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="no-streams">
                <p>No streams have been shared with this team yet.</p>
                <?php if ($is_owner): ?>
                    <a href="streams.php" class="add-stream-btn">
                        Share a Stream
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($is_owner): ?>
        <div class="tab-content" id="invites-tab">
            <?php if (count($pending_invites)): ?>
                <div class="invites-list">
                    <?php foreach ($pending_invites as $invite): ?>
                        <div class="invite-item">
                            <div class="invite-details">
                                <span class="invite-email"><?= htmlspecialchars($invite['email']) ?></span>
                                <span class="invite-date">Invited <?= date('M j, Y', strtotime($invite['created_at'])) ?> by <?= htmlspecialchars($invite['invited_by_name']) ?></span>
                            </div>
                            <div class="invite-actions">
                                <span class="invite-status pending">
                                    Pending
                                </span>
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this invite for <?= htmlspecialchars($invite['email']) ?>?');">
                                    <input type="hidden" name="invite_id" value="<?= $invite['id'] ?>">
                                    <button type="submit" name="delete_invite" class="delete-invite-btn">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="no-invites">
                    <p>No pending invites for this team.</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="tab-content" id="settings-tab">
            <form method="POST" class="team-settings-form">
                <div class="form-group">
                    <label>Team Name</label>
                    <input type="text" name="team_name" value="<?= htmlspecialchars($team['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="team_description" rows="3"><?= htmlspecialchars($team['description']) ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="update_team" class="save-btn" onclick="return confirm('Are you sure you want to save these changes?');">
                        Save Changes
                    </button>
                    
                    <button type="submit" name="delete_team_from_settings" class="delete-team-btn" onclick="return confirm('Are you absolutely sure you want to DELETE this team? This action is irreversible and will remove all members and shared streams!');">
                        Delete Team
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabContents = document.querySelectorAll('.tab-content');
        
        // Function to activate a tab
        function activateTab(tabId) {
            tabBtns.forEach(b => b.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            
            const selectedBtn = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
            const selectedContent = document.getElementById(`${tabId}-tab`);

            if (selectedBtn) selectedBtn.classList.add('active');
            if (selectedContent) selectedContent.classList.add('active');
        }

        // Add event listeners for tab buttons
        tabBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const tabId = this.getAttribute('data-tab');
                activateTab(tabId);
            });
        });

        // Activate tab based on URL hash if present
        const hash = window.location.hash.substring(1); // Get hash without '#'
        if (hash && document.getElementById(`${hash}-tab`)) {
            activateTab(hash);
        } else {
            // Default to 'members' tab if no hash or invalid hash
            activateTab('members');
        }
    });

    // Draggable Modal Logic (copied from contacts.php)
    // This assumes the modal (`newTeamModal`) is present on this page
    const newTeamModal = document.getElementById('newTeamModal');
    if (newTeamModal) {
        const modalContent = newTeamModal.querySelector('.modal-content');
        if (modalContent) {
            let xOffset = 0;
            let yOffset = 0;
            let isDragging = false;
            let initialX, initialY;

            const handle = modalContent.querySelector('h2'); // Assumes h2 is the draggable handle

            if (handle) {
                handle.addEventListener('mousedown', dragStart);
            }

            function dragStart(e) {
                initialX = e.clientX - xOffset;
                initialY = e.clientY - yOffset;
                if (e.button === 0) { // Only drag with left mouse button
                    isDragging = true;
                    handle.style.cursor = 'grabbing';
                    document.addEventListener('mousemove', drag);
                    document.addEventListener('mouseup', dragEnd);
                }
            }

            function drag(e) {
                if (isDragging) {
                    e.preventDefault(); // Prevent text selection etc.
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

            // Close modal when clicking outside (re-added for this specific modal)
            window.addEventListener('click', (e) => {
                if (e.target === newTeamModal) {
                    newTeamModal.style.display = 'none';
                    // Reset modal position when closed
                    modalContent.style.transform = "translate3d(0px, 0px, 0)";
                    modalContent.scrollTop = 0; // Reset scroll position
                }
            });

            // Close button functionality (re-added for this specific modal)
            const closeBtn = newTeamModal.querySelector('.close');
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    newTeamModal.style.display = 'none';
                    modalContent.style.transform = "translate3d(0px, 0px, 0)";
                    modalContent.scrollTop = 0; // Reset scroll position
                });
            }
        }
    }

    // Simple text editor functionality (copied from teams.php)
    document.addEventListener('DOMContentLoaded', function() {
        const descriptionField = document.getElementById('teamDescription');
        // const previewArea = document.getElementById('descriptionPreview'); // Not needed for plain text output
        const editorButtons = document.querySelectorAll('.editor-btn');
        
        editorButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const command = this.dataset.command;
                
                const start = descriptionField.selectionStart;
                const end = descriptionField.selectionEnd;
                const selectedText = descriptionField.value.substring(start, end);
                let newText = '';
                let cursorOffset = 0;

                switch (command) {
                    case 'bold':
                        newText = `**${selectedText}**`;
                        cursorOffset = 2;
                        break;
                    case 'italic':
                        newText = `*${selectedText}*`;
                        cursorOffset = 1;
                        break;
                    case 'underline':
                        newText = `_${selectedText}_`;
                        cursorOffset = 1;
                        break;
                    case 'insertUnorderedList':
                        if (selectedText.length === 0) {
                            newText = `* `;
                            cursorOffset = 2;
                        } else {
                            const lines = selectedText.split('\n');
                            newText = lines.map(line => `* ${line}`).join('\n');
                            cursorOffset = 2;
                        }
                        break;
                    case 'insertLink':
                        const url = prompt('Enter the URL:');
                        if (url) {
                            const linkText = prompt('Enter the link text (optional):', selectedText || url);
                            if (linkText !== null) {
                                newText = `[${linkText}](${url})`; // Corrected markdown for link
                                cursorOffset = linkText.length + 1;
                            } else {
                                return;
                            }
                        } else {
                            return;
                        }
                        break;
                    default:
                        return;
                }

                const originalValue = descriptionField.value;
                descriptionField.value = originalValue.substring(0, start) + newText + originalValue.substring(end);

                if (selectedText.length === 0) {
                    descriptionField.selectionStart = descriptionField.selectionEnd = start + cursorOffset;
                } else {
                    descriptionField.selectionStart = start;
                    descriptionField.selectionEnd = start + newText.length;
                }
                
                descriptionField.focus();
            });
        });
    });
</script>

<?php
require_once 'includes/footer.php';
?>