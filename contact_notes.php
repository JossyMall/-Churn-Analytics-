<?php
// MUST BE AT THE VERY TOP - NO WHITESPACE BEFORE THIS
session_start(); // Start session first

// Enable error reporting for debugging, but disable display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Check login status BEFORE including any files that might output content
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php'); // Redirect to login page if not logged in
    exit; // IMPORTANT: Always exit after a header redirect
}

require_once 'includes/db.php';
require_once 'includes/notification_functions.php'; // For create_notification

// Define BASE_URL if it's not already defined globally in db.php
// This check makes the file more portable, but ideally BASE_URL is set centrally.
if (!defined('BASE_URL')) {
    // Attempt to get from config table if not defined
    global $pdo; // Ensure $pdo is available for this
    try {
        $stmt_base_url = $pdo->query("SELECT value FROM config WHERE setting = 'base_url'");
        $configured_base_url = $stmt_base_url->fetchColumn();
        if ($configured_base_url) {
            define('BASE_URL', $configured_base_url);
        } else {
            // Fallback if config table doesn't have it (should be configured)
            define('BASE_URL', 'http://localhost/churn-analytics/'); // Fallback for local testing
            error_log("BASE_URL not defined and not found in config. Using fallback: " . BASE_URL);
        }
    } catch (PDOException $e) {
        define('BASE_URL', 'http://localhost/churn-analytics/'); // Fallback if DB connection fails for config
        error_log("Failed to get BASE_URL from config table: " . $e->getMessage());
    }
}


$user_id = $_SESSION['user_id'];
$contact_id = isset($_GET['contact_id']) ? intval($_GET['contact_id']) : 0;

if ($contact_id === 0) {
    $_SESSION['error'] = "No contact selected for notes.";
    header('Location: contacts.php'); // Redirect back to contacts list
    exit;
}

// --- Fetch Contact and Stream Information ---
$stmt_contact_info = $pdo->prepare("
    SELECT
        c.id AS contact_id,
        c.username,
        c.email,
        s.id AS stream_id,
        s.name AS stream_name,
        s.user_id AS stream_owner_user_id,
        s.team_id AS stream_owning_team_id -- Changed column name for clarity
    FROM contacts c
    JOIN streams s ON c.stream_id = s.id
    WHERE c.id = ?
");
$stmt_contact_info->execute([$contact_id]);
$contact_info = $stmt_contact_info->fetch(PDO::FETCH_ASSOC);

if (!$contact_info) {
    $_SESSION['error'] = "Contact not found.";
    header('Location: contacts.php');
    exit;
}

$stream_id = $contact_info['stream_id'];
$stream_owner_user_id = $contact_info['stream_owner_user_id'];
$stream_owning_team_id = $contact_info['stream_owning_team_id'];


// --- Helper Functions for Access Control (adapted from streams.php for consistency) ---

/**
 * Determines the effective access level of a user for a given stream.
 * Precedence: Owner > Team Editor > Team Viewer.
 * @param PDO $pdo
 * @param int $current_user_id The ID of the current user.
 * @param int $stream_id The ID of the stream.
 * @return string 'owner', 'editor', 'viewer', or 'none'.
 */
function get_stream_effective_access_level($pdo, $current_user_id, $stream_id) {
    // 1. Check if user is the direct owner of the stream
    $stmt_owner_check = $pdo->prepare("SELECT 1 FROM streams WHERE id = ? AND user_id = ?");
    $stmt_owner_check->execute([$stream_id, $current_user_id]);
    if ($stmt_owner_check->fetchColumn()) {
        return 'owner';
    }

    // 2. Check if user has access via a team (explicitly shared or team-owned)
    $stmt_team_access = $pdo->prepare("
        SELECT tm.role AS member_role, ts.access_level AS stream_access_level, 
               ts.stream_id AS shared_stream_id,        -- Aliased for clarity
               s_owned_by_team.id AS owned_stream_id     -- Aliased for clarity
        FROM team_members tm
        LEFT JOIN team_streams ts ON tm.team_id = ts.team_id AND ts.stream_id = ?
        LEFT JOIN streams s_owned_by_team ON tm.team_id = s_owned_by_team.team_id AND s_owned_by_team.id = ? AND s_owned_by_team.team_id IS NOT NULL -- Check if stream is owned by this team
        WHERE tm.user_id = ? 
          AND (ts.stream_id IS NOT NULL OR s_owned_by_team.id IS NOT NULL) -- Ensure stream access exists
        ORDER BY FIELD(tm.role, 'owner', 'editor', 'viewer') ASC, -- Higher team role first (e.g., owner = 1, editor = 2)
                 FIELD(COALESCE(ts.access_level, 'view'), 'edit', 'view') DESC -- 'edit' is higher than 'view'
        LIMIT 1
    ");
    $stmt_team_access->execute([$stream_id, $stream_id, $current_user_id]);
    $access = $stmt_team_access->fetch(PDO::FETCH_ASSOC);

    if ($access) {
        // A. Check for 'editor' access based on explicit share or team ownership + role
        // If team member is an 'owner' or 'editor' AND (stream is explicitly shared with 'edit' access
        // OR stream is owned by this team AND user has owner/editor role in that team)
        if (in_array($access['member_role'], ['owner', 'editor'])) {
            if ($access['stream_access_level'] === 'edit') {
                return 'editor'; // Explicit edit access through team_streams
            }
            if ($access['owned_stream_id'] !== null) {
                return 'editor'; // Stream is owned by this team, and user has owner/editor role
            }
        }
        
        // B. If not 'owner' (checked first) and not 'editor' (checked above), then it's 'viewer'
        // This covers explicit 'view' access, or being a 'viewer' in an owning/shared team,
        // or an 'owner'/'editor' in a team with only 'view' access for the stream.
        return 'viewer';
    }

    return 'none'; // No direct or team-based access found
}


// Verify user has at least view access to this contact's stream
$user_effective_stream_access = get_stream_effective_access_level($pdo, $user_id, $stream_id);
if ($user_effective_stream_access === 'none') {
    $_SESSION['error'] = "Unauthorized access to this contact's notes. You do not have view access to this stream.";
    header('Location: contacts.php');
    exit;
}

// Determine permissions based on effective stream access level
$can_add_note = in_array($user_effective_stream_access, ['owner', 'editor', 'viewer']); // All roles can add notes
$can_edit_note = in_array($user_effective_stream_access, ['owner', 'editor']); // Only owner and editor can edit
$can_delete_note = ($user_effective_stream_access === 'owner'); // Only owner can delete


// --- Fetch Team Members for Note Recipient Selection ---
$team_members_for_notes = [];

// Get all unique users who are members of any team that has access to this stream
// This includes:
// 1. Members of the stream's owning team (if any)
// 2. Members of any team the stream is explicitly shared with
// Exclude the current user from this list, as they are creating the note.
$stmt_team_members_for_notes = $pdo->prepare("
    SELECT DISTINCT u.id AS user_id, u.username
    FROM users u
    JOIN team_members tm ON u.id = tm.user_id
    WHERE u.id != :current_user_id
    AND (
        -- User is a member of the stream's owning team
        tm.team_id = :stream_owning_team_id
        OR
        -- User is a member of a team this stream is explicitly shared with
        tm.team_id IN (SELECT team_id FROM team_streams WHERE stream_id = :stream_id)
    )
    ORDER BY u.username ASC
");

// This query handles stream_owning_team_id possibly being NULL by simply not matching that condition.
$stmt_team_members_for_notes->bindValue(':current_user_id', $user_id, PDO::PARAM_INT);
$stmt_team_members_for_notes->bindValue(':stream_id', $stream_id, PDO::PARAM_INT);
if ($stream_owning_team_id === null) {
    $stmt_team_members_for_notes->bindValue(':stream_owning_team_id', null, PDO::PARAM_NULL);
} else {
    $stmt_team_members_for_notes->bindValue(':stream_owning_team_id', $stream_owning_team_id, PDO::PARAM_INT);
}

$stmt_team_members_for_notes->execute();
$team_members_for_notes = $stmt_team_members_for_notes->fetchAll(PDO::FETCH_ASSOC);


// --- Handle Form Submissions (Add, Edit, Delete) ---
// This section MUST be executed BEFORE any HTML output.

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_note'])) {
        if (!$can_add_note) { // Check if user has permission to add notes
            $_SESSION['error'] = "Unauthorized to add notes for this contact.";
            header("Location: " . BASE_URL . "/contact_notes.php?contact_id={$contact_id}");
            exit;
        }

        $note_content = trim($_POST['note_content'] ?? '');
        $tags = trim($_POST['tags'] ?? '');
        $is_private = isset($_POST['is_private']) ? 1 : 0;
        // Selected recipients (an array of user IDs)
        $selected_recipient_ids = isset($_POST['note_recipients']) ? (array)$_POST['note_recipients'] : [];
        // Ensure all selected recipients are integers
        $selected_recipient_ids = array_map('intval', $selected_recipient_ids);
        // Filter out current user if they accidentally select themselves or are default
        $selected_recipient_ids = array_filter($selected_recipient_ids, function($id) use ($user_id) { return $id !== $user_id; });

        if (empty($note_content)) {
            $_SESSION['error'] = "Note content cannot be empty.";
        } else {
            try {
                $pdo->beginTransaction(); // Start transaction for atomicity

                $stmt = $pdo->prepare("INSERT INTO contact_notes (contact_id, user_id, note, tags, is_private) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$contact_id, $user_id, $note_content, empty($tags) ? null : $tags, $is_private]);
                $new_note_id = $pdo->lastInsertId(); // Get the ID of the newly inserted note
                $_SESSION['success'] = "Note added successfully!";

                // --- NEW NOTIFICATION LOGIC ---
                $final_recipients_for_notification = [];
                $notification_type = 'system'; // Default type if specific note types are not desired in ENUM

                if ($is_private) {
                    // For private notes, only notify explicitly selected recipients (if any)
                    $final_recipients_for_notification = $selected_recipient_ids;
                    $notification_type = 'private_note_added'; // Specific type for private notes
                } else {
                    // For public notes
                    if (!empty($selected_recipient_ids)) {
                        // If specific recipients chosen, notify only them
                        $final_recipients_for_notification = $selected_recipient_ids;
                    } else {
                        // If no specific recipients chosen for a public note, fall back to broad notification
                        // Notify Stream Owner (if not the creator of the note)
                        if ($stream_owner_user_id !== $user_id) {
                            $final_recipients_for_notification[] = $stream_owner_user_id;
                        }

                        // Notify members of the stream's owning team (if applicable and not the creator)
                        if ($stream_owning_team_id !== null) {
                            $stmt_team_members = $pdo->prepare("SELECT user_id FROM team_members WHERE team_id = ? AND user_id != ?");
                            $stmt_team_members->execute([$stream_owning_team_id, $user_id]);
                            $team_members = $stmt_team_members->fetchAll(PDO::FETCH_COLUMN);
                            $final_recipients_for_notification = array_merge($final_recipients_for_notification, $team_members);
                        }

                        // Notify members of teams explicitly shared with this stream (if not the creator)
                        $stmt_shared_teams = $pdo->prepare("
                            SELECT tm.user_id
                            FROM team_streams ts
                            JOIN team_members tm ON ts.team_id = tm.team_id
                            WHERE ts.stream_id = ? AND tm.user_id != ?
                        ");
                        $stmt_shared_teams->execute([$stream_id, $user_id]);
                        $shared_team_members = $stmt_shared_teams->fetchAll(PDO::FETCH_COLUMN);
                        $final_recipients_for_notification = array_merge($final_recipients_for_notification, $shared_team_members);
                    }
                    $notification_type = 'note_added'; // Specific type for public notes
                }

                $final_recipients_for_notification = array_unique($final_recipients_for_notification); // Ensure unique recipients
                // Final filter to ensure current user is never notified by this logic
                $final_recipients_for_notification = array_filter($final_recipients_for_notification, function($id) use ($user_id) { return $id !== $user_id; });


                if (!empty($final_recipients_for_notification)) {
                    $contact_display_name = htmlspecialchars($contact_info['username'] ?: $contact_info['email']);
                    $notification_title = ($is_private ? "Private Note Added to " : "New Note Added to ") . $contact_display_name;
                    $notification_message = "A new " . ($is_private ? "private " : "") . "note was created for {$contact_display_name} in stream " . htmlspecialchars($contact_info['stream_name']) . ".";
                    // Ensure BASE_URL always ends with a single slash before concatenation
                    $normalized_base_url = rtrim(BASE_URL, '/') . '/';
                    $notification_related_url = $normalized_base_url . "contact_notes.php?contact_id={$contact_id}";


                    $stmt_notify = $pdo->prepare("INSERT INTO notifications (user_id, title, message, is_read, created_at, type, related_id, related_url) VALUES (?, ?, ?, 0, NOW(), ?, ?, ?)");
                    $stmt_note_recipient = $pdo->prepare("INSERT INTO note_recipients (note_id, user_id) VALUES (?, ?)");

                    foreach ($final_recipients_for_notification as $notify_user_id) {
                        try {
                            // Insert into notifications table
                            $stmt_notify->execute([
                                $notify_user_id,
                                $notification_title,
                                $notification_message,
                                $notification_type, // Use the determined type
                                $contact_id, // related_id can be contact_id
                                $notification_related_url
                            ]);
                            // Insert into note_recipients table
                            $stmt_note_recipient->execute([$new_note_id, $notify_user_id]);

                            error_log("Note notification: Successfully notified user {$notify_user_id} for contact {$contact_id}. Note ID: {$new_note_id}, Type: {$notification_type}");
                        } catch (PDOException $e) {
                            error_log("Note notification: Failed to insert notification/recipient for user {$notify_user_id}: " . $e->getMessage());
                        }
                    }
                } else {
                    error_log("Note notification: No unique recipients identified for notification for contact {$contact_id} (private: {$is_private}).");
                }
                // --- END NEW NOTIFICATION LOGIC ---

                $pdo->commit(); // Commit transaction if all is successful
            } catch (PDOException $e) {
                $pdo->rollBack(); // Rollback on error
                $_SESSION['error'] = "Error adding note: " . $e->getMessage();
                error_log("Error in add_note transaction for contact {$contact_id}: " . $e->getMessage());
            }
        }
        header("Location: " . BASE_URL . "/contact_notes.php?contact_id={$contact_id}");
        exit;

    } elseif (isset($_POST['edit_note'])) {
        // User must have 'owner' or 'editor' access to edit notes
        if (!$can_edit_note) {
            $_SESSION['error'] = "Unauthorized to edit this note.";
        } else {
            $note_id = intval($_POST['note_id'] ?? 0);
            $edited_note_content = trim($_POST['edited_note_content'] ?? '');
            $edited_tags = trim($_POST['edited_tags'] ?? '');
            $edited_is_private = isset($_POST['edited_is_private']) ? 1 : 0;

            if ($note_id === 0 || empty($edited_note_content)) {
                $_SESSION['error'] = "Invalid note ID or empty content for edit.";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE contact_notes SET note = ?, tags = ?, is_private = ?, updated_at = NOW() WHERE id = ? AND contact_id = ?");
                    $stmt->execute([$edited_note_content, empty($edited_tags) ? null : $edited_tags, $edited_is_private, $note_id, $contact_id]);
                    $_SESSION['success'] = "Note updated successfully!";
                }
                 catch (PDOException $e) {
                    $_SESSION['error'] = "Error updating note: " . $e->getMessage();
                    error_log("Error updating note for contact {$contact_id}, note {$note_id}: " . $e->getMessage());
                }
            }
        }
        header("Location: " . BASE_URL . "/contact_notes.php?contact_id={$contact_id}");
        exit;

    } elseif (isset($_POST['delete_note'])) {
        // Only 'owner' can delete notes
        if (!$can_delete_note) {
            $_SESSION['error'] = "Unauthorized to delete this note.";
        } else {
            $note_id = intval($_POST['note_id'] ?? 0);

            if ($note_id === 0) {
                $_SESSION['error'] = "Invalid note ID for deletion.";
            } else {
                try {
                    $pdo->beginTransaction();
                    // Delete from note_recipients first due to foreign key constraints (ON DELETE CASCADE is set, but explicit is safer or for specific logic)
                    $pdo->prepare("DELETE FROM note_recipients WHERE note_id = ?")->execute([$note_id]);
                    // Then delete the note itself
                    $stmt = $pdo->prepare("DELETE FROM contact_notes WHERE id = ? AND contact_id = ?");
                    $stmt->execute([$note_id, $contact_id]);
                    $pdo->commit();
                    $_SESSION['success'] = "Note deleted successfully!";
                } catch (PDOException $e) {
                    $pdo->rollBack();
                    $_SESSION['error'] = "Error deleting note: " . $e->getMessage();
                    error_log("Error deleting note for contact {$contact_id}, note {$note_id}: " . $e->getMessage());
                }
            }
        }
        header("Location: " . BASE_URL . "/contact_notes.php?contact_id={$contact_id}");
        exit;
    }
}

/**
 * Basic BBCode parser to convert BBCode to HTML.
 * For security, ensure the output HTML is properly sanitized if it's rendered directly in a browser without further processing.
 * This is a simplified parser and might not cover all edge cases or nested tags perfectly.
 * For production, consider a more robust, battle-tested BBCode parser library.
 * @param string $text The text containing BBCode.
 * @return string The HTML converted text.
 */
function parse_bbcode($text) {
    // Escape HTML entities to prevent XSS from raw HTML in input
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

    $replace = [
        '/\[b\](.*?)\[\/b\]/is' => '<strong>$1</strong>',
        '/\[i\](.*?)\[\/i\]/is' => '<em>$1</em>',
        '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
        // Ensure URLs are properly escaped and validated before rendering in real app
        // Use a callback to htmlspecialchars the captured group ($1) for URLs
        '/\[url=(.*?)\](.*?)\[\/url\]/is' => '<a href="' . htmlspecialchars('$1', ENT_QUOTES, 'UTF-8') . '" target="_blank" class="text-blue-600 hover:underline">$2</a>',
        '/\[url\](.*?)\[\/url\]/is' => '<a href="' . htmlspecialchars('$1', ENT_QUOTES, 'UTF-8') . '" target="_blank" class="text-blue-600 hover:underline">$1</a>',
        // Images: Ensure image URLs are validated and only from trusted sources
        '/\[img\](.*?)\[\/img\]/is' => '<img src="' . htmlspecialchars('$1', ENT_QUOTES, 'UTF-8') . '" alt="Image" class="max-w-full h-auto rounded-lg my-2">',
        // List items
        '/\[list\](.*?)\[\/list\]/is' => '<ul>$1</ul>',
        '/\[\*\](.*?)\n/is' => '<li>$1</li>',
        // Fallback for [*] at end of string or before another [*]
        '/\[\*\](.*?)(\[\*\]|$)/is' => '<li>$1</li>', 
    ];

    $text = preg_replace(array_keys($replace), array_values($replace), $text);
    
    // Convert newlines to <br> for display after BBCode parsing
    $text = nl2br($text);

    return $text;
}


// --- Fetch all notes for the contact ---
// Join with users to get username of the note creator
// Left Join with team_members and teams to get team name(s) for the note creator (if any)
$stmt_notes = $pdo->prepare("
    SELECT
        cn.id,
        cn.contact_id,
        cn.user_id AS note_creator_user_id, -- Alias for clarity
        cn.note,
        cn.tags,
        cn.is_private,
        cn.created_at,
        cn.updated_at,
        u.username AS creator_username,
        GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS creator_team_names
    FROM contact_notes cn
    JOIN users u ON cn.user_id = u.id
    LEFT JOIN team_members tm ON cn.user_id = tm.user_id
    LEFT JOIN teams t ON tm.team_id = t.id
    WHERE cn.contact_id = ?
    GROUP BY cn.id 
    ORDER BY cn.created_at DESC
");
$stmt_notes->execute([$contact_id]);
$notes = $stmt_notes->fetchAll(PDO::FETCH_ASSOC);

// Now it's safe to include the header and output HTML
require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notes for <?= htmlspecialchars($contact_info['username'] ?: $contact_info['email']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* General styles already provided */
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .glass-card {
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            background-color: #e5e7eb;
        }
        .note-card {
            backdrop-filter: blur(8px);
            border: 1px solid rgba(148, 163, 184, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .note-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .modal {
            backdrop-filter: blur(8px);
            transition: all 0.3s ease;
        }
        
        .text-slate-700 {
            font-weight: 500 !important;
        }
        
        .modal.show { opacity: 1; visibility: visible; display: flex; /* Ensure it uses flex to center */ }
        .modal-content {
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transform: scale(0.95);
            transition: transform 0.3s ease;
        }
        .modal.show .modal-content { transform: scale(1); }
        .btn { transition: all 0.2s ease; }
        .btn:hover { transform: translateY(-1px); }

        /* Styles for multi-select (Select2) */
        /* These styles override Tailwind defaults to make Select2 look better */
        .select2-container--default .select2-selection--multiple {
            background-color: white !important;
            border: 1px solid #e2e8f0 !important; /* slate-200 */
            border-radius: 0.5rem !important; /* rounded-lg */
            min-height: 44px;
            padding: 0.25rem 0.5rem;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            cursor: text; /* Indicate it's an input area */
        }
        .select2-container--default.select2-container--focus .select2-selection--multiple {
            border-color: #3b82f6 !important; /* blue-500 */
            box-shadow: 0 0 0 1px #3b82f6 !important; /* ring-2 equivalent */
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #e0f2f7 !important; /* Light blue */
            border: 1px solid #b3e0ed !important;
            border-radius: 0.25rem !important;
            color: #2b6cb0 !important;
            padding: 0.2rem 0.5rem;
            margin-top: 0.25rem; /* Adjust margin for proper wrapping */
            margin-right: 0.25rem;
            display: flex;
            align-items: center;
            font-size: 0.875rem; /* text-sm */
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #2b6cb0 !important;
            float: none; /* Override default float */
            margin-left: 0.3rem;
            font-weight: bold;
            cursor: pointer;
            line-height: 1; /* Adjust vertical alignment */
        }
        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
            color: #ef4444 !important; /* Red on hover */
        }
        .select2-container--default .select2-results__option--highlighted.select2-results__option--selectable {
            background-color: #bfdbfe !important; /* blue-200 */
            color: #1e40af !important; /* blue-800 */
        }
        .select2-container .select2-search--inline .select2-search__field {
            font-size: 1rem; /* Adjust search input font size */
            margin-top: 0.25rem;
            min-width: 100px; /* Ensure search field has a minimum width */
        }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
</head>
<body>
    <div class="max-w-4xl mx-auto p-6">
        <div class="glass-card rounded-2xl p-8 mb-8 shadow-xl">
            <div class="flex justify-between items-start">
                <div>
                    <h1 class="text-3xl font-bold text-slate-800 mb-2">
                        Notes for <span class="text-blue-600"><?= htmlspecialchars($contact_info['username'] ?: $contact_info['email']) ?></span>
                    </h1>
                    <p class="text-slate-600">Stream: <span class="font-medium"><?= htmlspecialchars($contact_info['stream_name']) ?></span></p>
                </div>
                <a href="contacts.php?stream_id=<?= htmlspecialchars($stream_id) ?>" class="btn inline-flex items-center px-4 py-2 bg-slate-100 text-slate-700 rounded-lg hover:bg-slate-200 font-medium">
                    ← Back to Contacts
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="glass-card rounded-2xl p-8 mb-8 shadow-lg">
            <h2 class="text-xl font-semibold text-slate-800 mb-6">Add New Note</h2>
            <?php if ($can_add_note): ?>
            <form method="POST" class="space-y-4">
                <div class="flex flex-wrap gap-2 mb-2">
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'b')"><b>B</b></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'i')"><i>I</i></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'u')"><u>U</u></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'url')">Link</button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'img')">Image</button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('note_content', 'list')">List</button>
                </div>
                <div>
                    <textarea name="note_content" id="note_content" rows="4" placeholder="Write your note..."
                        class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none" required></textarea>
                </div>
                <div class="flex gap-4 items-center"> <input type="text" name="tags" placeholder="Tags (comma-separated)"
                        class="flex-1 px-4 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <label class="flex items-center gap-2 text-slate-700">
                        <input type="checkbox" name="is_private" id="is_private_note" class="rounded text-blue-600">
                        Private
                    </label>
                </div>
                <div class="form-group">
                    <label for="note_recipients" class="block text-slate-700 text-sm font-bold mb-2">Notify Team Members (optional)</label>
                    <select class="js-example-basic-multiple w-full" name="note_recipients[]" multiple="multiple" id="note_recipients" style="width: 100%;">
                        <?php foreach ($team_members_for_notes as $member): ?>
                            <option value="<?= htmlspecialchars($member['user_id']) ?>"><?= htmlspecialchars($member['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-slate-500 text-xs mt-1 block">Select specific members to notify. If no one selected for a **public note**, all relevant team members (stream owner, owning team, shared teams) will be notified. For **private notes**, only selected members are notified.</small>
                </div>
                <button type="submit" name="add_note" class="btn bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700 font-medium">
                    Add Note
                </button>
            </form>
            <?php else: ?>
                <p class="text-slate-600 text-center py-4">You do not have permission to add notes for this contact.</p>
            <?php endif; ?>
        </div>

        <div class="space-y-6">
            <h2 class="text-xl font-semibold text-slate-800">Notes (<?= count($notes) ?>)</h2>

            <?php if (empty($notes)): ?>
                <div class="text-center py-12 text-slate-500">
                    <div class="text-4xl mb-4">📝</div>
                    <p>No notes yet. Add the first one above!</p>
                </div>
            <?php else: ?>
                <?php foreach ($notes as $note): ?>
                    <div class="note-card rounded-xl p-6 relative shadow-lg">
                        <div class="text-slate-700 leading-relaxed mb-4 whitespace-pre-wrap">
                            <?= parse_bbcode($note['note']) ?>
                        </div>

                        <?php if (!empty($note['tags'])): ?>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <?php foreach (explode(',', $note['tags']) as $tag): ?>
                                    <span class="bg-blue-100 text-blue-800 text-sm px-3 py-1 rounded-full">
                                        <?= htmlspecialchars(trim($tag)) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="text-sm text-slate-500 border-t border-slate-100 pt-4">
                            <strong><?= htmlspecialchars($note['creator_username']) ?></strong>
                            <?php if (!empty($note['creator_team_names'])): ?>
                                (<?= htmlspecialchars($note['creator_team_names']) ?>)
                            <?php endif; ?>
                            • <?= (new DateTime($note['created_at']))->format('M j, Y g:i A') ?>
                            <?php if ($note['updated_at'] && $note['created_at'] !== $note['updated_at']): ?>
                                • Updated <?= (new DateTime($note['updated_at']))->format('M j, Y g:i A') ?>
                            <?php endif; ?>
                            <?php if ($note['is_private']): ?>
                                <span class="bg-slate-100 text-slate-600 text-xs px-2 py-1 rounded ml-2">🔒 Private</span>
                            <?php endif; ?>
                        </div>

                        <?php
                            // Determine if current user can edit/delete this specific note
                            // Based strictly on effective stream access level.
                            // If you want the note creator to *always* be able to edit/delete their own notes
                            // regardless of stream access, you would add an OR condition here.
                            $can_edit_this_specific_note = $can_edit_note;
                            $can_delete_this_specific_note = $can_delete_note;
                        ?>
                        <?php if ($can_edit_this_specific_note || $can_delete_this_specific_note): /* Show buttons if any action is allowed */ ?>
                            <div class="absolute top-4 right-4 flex gap-2">
                                <?php if ($can_edit_this_specific_note): ?>
                                    <button type="button" class="p-2 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-blue-50 transition-colors edit-btn"
                                        data-note-id="<?= htmlspecialchars($note['id']) ?>"
                                        data-note-content="<?= htmlspecialchars($note['note']) ?>"
                                        data-note-tags="<?= htmlspecialchars($note['tags'] ?? '') ?>"
                                        data-is-private="<?= htmlspecialchars($note['is_private']) ?>">
                                        ✏️
                                    </button>
                                <?php endif; ?>
                                <?php if ($can_delete_this_specific_note): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Delete this note?')">
                                        <input type="hidden" name="note_id" value="<?= htmlspecialchars($note['id']) ?>">
                                        <button type="submit" name="delete_note" class="p-2 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                            🗑️
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fixed inset-0 flex items-center justify-center z-50 opacity-0 invisible transition-all" id="editModal">
        <div class="modal-content w-full max-w-lg mx-4 rounded-2xl p-8 shadow-2xl">
            <div class="flex justify-between items-center mb-6">
                <h3 class="text-lg font-semibold text-slate-800">Edit Note</h3>
                <button type="button" class="text-slate-400 hover:text-slate-600 text-xl close-modal">×</button>
            </div>
            <form method="POST" id="editForm" class="space-y-4">
                <input type="hidden" name="note_id" id="editNoteId">
                <div class="flex flex-wrap gap-2 mb-2">
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'b')"><b>B</b></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'i')"><i>I</i></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'u')"><u>U</u></button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'url')">Link</button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'img')">Image</button>
                    <button type="button" class="px-3 py-1 bg-slate-200 rounded-md text-sm font-medium hover:bg-slate-300 transition-colors" onclick="addBBCode('editContent', 'list')">List</button>
                </div>
                <textarea name="edited_note_content" id="editContent" rows="4"
                    class="w-full px-4 py-3 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 resize-none" required></textarea>
                <div class="flex gap-4">
                    <input type="text" name="edited_tags" id="editTags" placeholder="Tags"
                        class="flex-1 px-4 py-2 border border-slate-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <label class="flex items-center gap-2 text-slate-700">
                        <input type="checkbox" name="edited_is_private" id="editPrivate" class="rounded text-blue-600">
                        Private
                    </label>
                </div>
                <div class="flex gap-3 pt-2">
                    <button type="button" class="btn flex-1 bg-slate-100 text-slate-700 py-2 rounded-lg hover:bg-slate-200 close-modal">Cancel</button>
                    <button type="submit" name="edit_note" class="btn flex-1 bg-blue-600 text-white py-2 rounded-lg hover:bg-blue-700">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        // Initialize Select2
        $(document).ready(function() {
            $('.js-example-basic-multiple').select2({
                placeholder: "Select team members to notify",
                allowClear: true // Option to clear all selections
            });

            // The original logic for disabling/enabling Select2 based on private checkbox
            // is commented out as the backend logic now handles the broad/specific notification
            // based on selection state regardless of 'private' status.
            // If private, only selected are notified. If public, selected are notified, else broad.
        });


        const modal = document.getElementById('editModal');
        const editBtns = document.querySelectorAll('.edit-btn');
        const closeBtns = document.querySelectorAll('.close-modal');

        editBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('editNoteId').value = btn.dataset.noteId;
                document.getElementById('editContent').value = btn.dataset.noteContent;
                document.getElementById('editTags').value = btn.dataset.noteTags;
                document.getElementById('editPrivate').checked = btn.dataset.isPrivate === '1';
                modal.classList.add('show');
            });
        });

        closeBtns.forEach(btn => {
            btn.addEventListener('click', () => modal.classList.remove('show'));
        });

        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('show');
        });

        // BBCode Functionality
        function addBBCode(textareaId, tag) {
            const textarea = document.getElementById(textareaId);
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const selectedText = textarea.value.substring(start, end);
            let newText = '';

            switch (tag) {
                case 'b':
                    newText = `[b]${selectedText}[/b]`;
                    break;
                case 'i':
                    newText = `[i]${selectedText}[/i]`;
                    break;
                case 'u':
                    newText = `[u]${selectedText}[/u]`;
                    break;
                case 'url':
                    const url = prompt("Enter the URL:");
                    if (url) {
                        newText = `[url=${url}]${selectedText || 'Link Text'}[/url]`;
                    } else {
                        newText = `[url]${selectedText}[/url]`;
                    }
                    break;
                case 'img':
                    const imageUrl = prompt("Enter the image URL:");
                    if (imageUrl) {
                        newText = `[img]${imageUrl}[/img]`;
                    } else {
                        newText = `[img]${selectedText}[/img]`;
                    }
                    break;
                case 'list':
                    newText = `[list]\n[*]Item 1\n[*]Item 2\n[/list]`;
                    break;
                default:
                    newText = selectedText;
            }

            textarea.value = textarea.value.substring(0, start) + newText + textarea.value.substring(end, textarea.value.length);

            // Set cursor position after adding BBCode
            if (tag === 'url' && !selectedText) {
                textarea.selectionStart = start + `[url=${url}]`.length;
                textarea.selectionEnd = start + `[url=${url}]Link Text`.length;
            } else if (tag === 'list') {
                textarea.selectionStart = start + `[list]\n`.length;
                textarea.selectionEnd = start + `[list]\n[*]Item 1`.length;
            } else {
                textarea.selectionStart = start + newText.length;
                textarea.selectionEnd = start + newText.length;
            }
            textarea.focus();
        }
    </script>
</body>
</html>