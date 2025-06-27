<?php
// includes/team_functions.php

function get_user_teams($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT t.*, tm.role, 
                          (SELECT COUNT(*) FROM team_members WHERE team_id = t.id) as member_count,
                          (SELECT COUNT(*) FROM team_streams WHERE team_id = t.id) as stream_count
                          FROM teams t
                          JOIN team_members tm ON t.id = tm.team_id
                          WHERE tm.user_id = ?
                          ORDER BY tm.role DESC, t.name ASC");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

function generate_team_color($team_id) {
    $colors = ['#3ac3b8', '#5a8dee', '#fdac41', '#f16d75', '#a36bf6', '#46c7a8'];
    return $colors[$team_id % count($colors)];
}

function is_team_owner($user_id, $team_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT 1 FROM team_members 
                          WHERE team_id = ? AND user_id = ? AND role = 'owner'");
    $stmt->execute([$team_id, $user_id]);
    return $stmt->fetchColumn();
}

function get_team_members($team_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT u.id, u.username, u.email, tm.role, tm.joined_at
                          FROM team_members tm
                          JOIN users u ON tm.user_id = u.id
                          WHERE tm.team_id = ?
                          ORDER BY 
                              CASE tm.role
                                  WHEN 'owner' THEN 1
                                  WHEN 'editor' THEN 2
                                  WHEN 'viewer' THEN 3
                              END, tm.joined_at");
    $stmt->execute([$team_id]);
    return $stmt->fetchAll();
}

function get_team_streams($team_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT s.*, ts.access_level
                          FROM team_streams ts
                          JOIN streams s ON ts.stream_id = s.id
                          WHERE ts.team_id = ?");
    $stmt->execute([$team_id]);
    return $stmt->fetchAll();
}

function get_team_details($team_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT t.*, u.username as owner_name
                          FROM teams t
                          JOIN users u ON t.created_by = u.id
                          WHERE t.id = ?");
    $stmt->execute([$team_id]);
    return $stmt->fetch();
}

function get_pending_invites($team_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT ti.*, u.username as invited_by_name
                          FROM team_invites ti
                          JOIN users u ON ti.invited_by = u.id
                          WHERE ti.team_id = ? AND ti.status = 'pending'");
    $stmt->execute([$team_id]);
    return $stmt->fetchAll();
}

/**
 * Get user details by ID
 */
function get_user_by_id($user_id) {
    global $pdo;
    
    $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

/**
 * Send an invitation to join a team
 */
function send_team_invite($team_id, $email, $invited_by) {
    global $pdo;
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user_id = $stmt->fetchColumn();
    
    // Check if already member
    if ($user_id) {
        $stmt = $pdo->prepare("SELECT 1 FROM team_members WHERE team_id = ? AND user_id = ?");
        $stmt->execute([$team_id, $user_id]);
        if ($stmt->fetchColumn()) {
            return ['status' => 'error', 'message' => 'User is already a team member'];
        }
    }
    
    // Check if pending invite exists
    $stmt = $pdo->prepare("SELECT 1 FROM team_invites WHERE team_id = ? AND email = ? AND status = 'pending'");
    $stmt->execute([$team_id, $email]);
    if ($stmt->fetchColumn()) {
        return ['status' => 'error', 'message' => 'Invite already pending for this email'];
    }
    
    try {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO team_invites (team_id, email, token, invited_by, status) 
                              VALUES (?, ?, ?, ?, 'pending')");
        $stmt->execute([$team_id, $email, $token, $invited_by]);
        
        // Get team details for email
        $team = get_team_details($team_id);
        $inviter = get_user_by_id($invited_by);
        
        // Send email
        require_once __DIR__ . '/includes/email_functions.php';
        $sent = send_team_invite_email(
            $email,
            $team['name'],
            $inviter['username'],
            $token
        );
        
        if (!$sent) {
            throw new Exception("Failed to send invitation email");
        }
        
        return ['status' => 'success', 'token' => $token];
    } catch (Exception $e) {
        // Log the error
        error_log("Team invite error: " . $e->getMessage());
        
        // Clean up if the insert succeeded but email failed
        if (isset($token)) {
            $stmt = $pdo->prepare("DELETE FROM team_invites WHERE token = ?");
            $stmt->execute([$token]);
        }
        
        return ['status' => 'error', 'message' => 'Failed to send invitation: ' . $e->getMessage()];
    }
}