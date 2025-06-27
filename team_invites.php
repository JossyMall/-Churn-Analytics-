<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

//require_once 'includes/header.php';
require_once 'includes/db.php';
require_once 'team_functions.php';
require_once 'includes/notification_functions.php';

$user_id = $_SESSION['user_id'];

// Handle invite actions
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token']) && isset($_GET['action'])) {
    $token = $_GET['token'];
    $action = $_GET['action'];
    
    // Validate action
    if (!in_array($action, ['accept', 'decline'])) {
        $_SESSION['error'] = "Invalid action";
        header('Location: teams.php');
        exit;
    }

    // Get invite details
    $stmt = $pdo->prepare("SELECT ti.*, t.name as team_name, u.username as inviter_name 
                          FROM team_invites ti
                          JOIN teams t ON ti.team_id = t.id
                          JOIN users u ON ti.invited_by = u.id
                          WHERE ti.token = ? AND ti.status = 'pending'");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite) {
        $_SESSION['error'] = "Invalid or expired invite";
        header('Location: teams.php');
        exit;
    }

    // Check if this is the user's email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$invite['email']]);
    $invited_user_id = $stmt->fetchColumn();

    if ($invited_user_id != $user_id) {
        $_SESSION['error'] = "This invite is not for your account";
        header('Location: teams.php');
        exit;
    }

    // Process the action
    try {
        $pdo->beginTransaction();
        
        // Update invite status
        $stmt = $pdo->prepare("UPDATE team_invites SET status = ?, updated_at = NOW() 
                              WHERE id = ?");
        $status = $action === 'accept' ? 'accepted' : 'declined';
        $stmt->execute([$status, $invite['id']]);
        
        if ($action === 'accept') {
            // Add to team members
            $stmt = $pdo->prepare("INSERT INTO team_members (team_id, user_id, role, invited_by) 
                                  VALUES (?, ?, 'viewer', ?)
                                  ON DUPLICATE KEY UPDATE role = VALUES(role)");
            $stmt->execute([$invite['team_id'], $user_id, $invite['invited_by']]);
            
            // Create notification for inviter
            $message = $_SESSION['username'] . " accepted your invite to join team " . $invite['team_name'];
            create_notification($invite['invited_by'], 'team_invite_accepted', $message, $invite['team_id']);
            
            $_SESSION['success'] = "You've joined the team successfully!";
        } else {
            // Create notification for inviter
            $message = $_SESSION['username'] . " declined your invite to join team " . $invite['team_name'];
            create_notification($invite['invited_by'], 'team_invite_declined', $message, $invite['team_id']);
            
            $_SESSION['success'] = "Invite declined";
        }
        
        $pdo->commit();
        header('Location: teams.php');
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Error processing invite: " . $e->getMessage();
        header("Location: team_invites.php?token=$token");
        exit;
    }
}

// If no action specified but has token, show invite details
if (isset($_GET['token']) && !isset($_GET['action'])) {
    $token = $_GET['token'];
    
    $stmt = $pdo->prepare("SELECT ti.*, t.name as team_name, u.username as inviter_name 
                          FROM team_invites ti
                          JOIN teams t ON ti.team_id = t.id
                          JOIN users u ON ti.invited_by = u.id
                          WHERE ti.token = ? AND ti.status = 'pending'");
    $stmt->execute([$token]);
    $invite = $stmt->fetch();

    if (!$invite) {
        $_SESSION['error'] = "Invalid or expired invite";
        header('Location: teams.php');
        exit;
    }

    // Check if this is the user's email
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$invite['email']]);
    $invited_user_id = $stmt->fetchColumn();

    if ($invited_user_id != $user_id) {
        $_SESSION['error'] = "This invite is not for your account";
        header('Location: teams.php');
        exit;
    }
} else {
    header('Location: teams.php');
    exit;
}
?>

<div class="invite-container">
    <h1>Team Invitation</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <div class="invite-card">
        <div class="invite-header">
            <h2>You've been invited to join</h2>
            <h3><?= htmlspecialchars($invite['team_name']) ?></h3>
        </div>
        
        <div class="invite-details">
            <div class="detail-item">
                <span class="detail-label">Invited by:</span>
                <span class="detail-value"><?= htmlspecialchars($invite['inviter_name']) ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Sent on:</span>
                <span class="detail-value"><?= date('F j, Y', strtotime($invite['created_at'])) ?></span>
            </div>
        </div>
        
        <div class="invite-actions">
            <a href="team_invites.php?token=<?= $token ?>&action=accept" class="btn accept-btn">
                <i class="bi bi-check-circle"></i> Accept Invite
            </a>
            <a href="team_invites.php?token=<?= $token ?>&action=decline" class="btn decline-btn">
                <i class="bi bi-x-circle"></i> Decline
            </a>
        </div>
    </div>
</div>

<style>
    .invite-container {
        max-width: 600px;
        margin: 2rem auto;
        padding: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
    
    .invite-card {
        text-align: center;
        padding: 20px;
    }
    
    .invite-header {
        margin-bottom: 2rem;
    }
    
    .invite-header h2 {
        color: #666;
        font-size: 1.2rem;
        margin-bottom: 0.5rem;
    }
    
    .invite-header h3 {
        color: #3ac3b8;
        font-size: 1.8rem;
        margin: 0;
    }
    
    .invite-details {
        margin: 2rem 0;
        text-align: left;
        max-width: 400px;
        margin-left: auto;
        margin-right: auto;
    }
    
    .detail-item {
        display: flex;
        justify-content: space-between;
        padding: 10px 0;
        border-bottom: 1px solid #eee;
    }
    
    .detail-label {
        font-weight: 500;
        color: #666;
    }
    
    .detail-value {
        color: #333;
    }
    
    .invite-actions {
        display: flex;
        gap: 15px;
        justify-content: center;
        margin-top: 2rem;
    }
    
    .btn {
        padding: 10px 25px;
        border-radius: 5px;
        text-decoration: none;
        font-weight: 500;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .accept-btn {
        background: #3ac3b8;
        color: white;
        border: none;
    }
    
    .accept-btn:hover {
        background: #2fa89e;
    }
    
    .decline-btn {
        background: #f8f9fa;
        color: #666;
        border: 1px solid #ddd;
    }
    
    .decline-btn:hover {
        background: #e9ecef;
    }
</style>

<?php
require_once 'includes/footer.php';
?>