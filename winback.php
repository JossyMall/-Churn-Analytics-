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

// Get all automation workflows for the user
$stmt = $pdo->prepare("
    SELECT
        id,
        name,
        description,
        is_active,
        created_at,
        updated_at
    FROM automation_workflows
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$automations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- Fetch Automation Statistics from automation_logs ---
$stats = [];
foreach ($automations as $automation) {
    // Fetch aggregated statistics for each automation from the 'automation_logs' table
    $stats_stmt = $pdo->prepare("
        SELECT
            COUNT(id) as total_actions,
            COUNT(DISTINCT contact_id) as unique_users_reached,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_actions
        FROM automation_logs
        WHERE workflow_id = ?
    ");
    $stats_stmt->execute([$automation['id']]);
    $campaign_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $stats[$automation['id']] = [
        'actions_count' => $campaign_stats['total_actions'] ?? 0,
        'unique_users_reached' => $campaign_stats['unique_users_reached'] ?? 0,
        // For 'converted_users', we are using 'successful_actions' as a placeholder.
        // If 'converted' implies a specific action beyond 'success' (e.g., a churned user re-engaging),
        // your automation execution engine needs to log that specific 'conversion' event
        // (e.g., via a separate log entry, or a specific status in 'details_json').
        'converted_users' => $campaign_stats['successful_actions'] ?? 0 
    ];
}
?>

<div class="winback-container">
    <h1>Your Automations</h1>
    
    <div class="campaign-actions">
        <a href="automations.php?create" class="new-btn">+ Create New Automation</a>
    </div>
    
    <?php if (empty($automations)): ?>
        <div class="empty-state">
            <p>No automations created yet.</p>
            <a href="automations.php?create" class="new-btn">Create Your First Automation</a>
        </div>
    <?php else: ?>
        <div class="campaigns-grid">
            <?php foreach ($automations as $automation): ?>
                <div class="campaign-card">
                    <div class="campaign-header">
                        <h3><?= htmlspecialchars($automation['name']) ?></h3>
                        <span class="status-badge <?= $automation['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $automation['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </div>
                    
                    <div class="campaign-description">
                        <p><?= htmlspecialchars($automation['description'] ?? 'No description provided.') ?></p>
                    </div>
                    
                    <div class="campaign-stats">
                        <div class="stat-item">
                            <span class="stat-value"><?= $stats[$automation['id']]['actions_count'] ?></span>
                            <span class="stat-label">Actions Executed</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= $stats[$automation['id']]['unique_users_reached'] ?></span>
                            <span class="stat-label">Unique Users</span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-value"><?= $stats[$automation['id']]['converted_users'] ?></span>
                            <span class="stat-label">Converted</span>
                        </div>
                    </div>
                    
                    <div class="campaign-actions">
                        <a href="automations.php?edit=<?= $automation['id'] ?>" class="edit-btn">Edit</a>
                        <a href="automation_details.php?id=<?= $automation['id'] ?>" class="view-btn">View Details</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<style>
    /* Your CSS remains the same, assuming it's in a <style> block or linked CSS file */
    /* I'm including it here for completeness based on your provided file */
    .winback-container {
        border-radius: 8px;
        padding: 20px;
    }
    
    .campaign-actions {
        margin-bottom: 20px;
    }
    
    .new-btn {
        background: #3ac3b8;
        color: white;
        padding: 8px 15px;
        border-radius: 5px;
        text-decoration: none;
        display: inline-block;
    }
    
    .empty-state {
        padding: 40px;
        text-align: center;
        color: #666;
        border: 1px dashed #ddd;
        border-radius: 8px;
    }
    
    .campaigns-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .campaign-card {
        background: #fdfdfd;
        border: 1px solid #000000;
        border-radius: 8px;
        padding: 20px;
        transition: transform 0.2s;
    }
    
    .campaign-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .campaign-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
    }
    
    .campaign-header h3 {
        margin: 0;
        color: #333;
    }
    
    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
    }
    
    .status-badge.active {
        background: #e6f7ee;
        color: #28a745;
    }
    
    .status-badge.inactive {
        background: #be1313;
        color: #dcdcdc;
    }
    
    .campaign-description {
        margin-bottom: 20px;
        color: #666;
    }
    
    .campaign-stats {
        display: flex;
        justify-content: space-between;
        margin-bottom: 20px;
        padding: 15px 0;
        border-top: 1px solid #eee;
        border-bottom: 1px solid #eee;
    }
    
    .stat-item {
        text-align: center;
    }
    
    .stat-value {
        display: block;
        font-size: 1.5rem;
        font-weight: bold;
        color: #3ac3b8;
    }
    
    .stat-label {
        font-size: 0.8rem;
        color: #666;
    }
    
    .campaign-actions {
        display: flex;
        gap: 10px;
    }
    
    .edit-btn, .view-btn {
        padding: 8px 15px;
        border-radius: 4px;
        text-decoration: none;
        font-size: 0.9rem;
    }
    
    .edit-btn {
        background: #f0f0f0;
        color: #333;
    }
    
    .view-btn {
        background: #3ac3b8;
        color: white;
    }
</style>

<script>
    // Simple animation for campaign cards
    document.addEventListener('DOMContentLoaded', function() {
        const cards = document.querySelectorAll('.campaign-card');
        cards.forEach((card, index) => {
            setTimeout(() => {
                card.style.opacity = '1';
            }, index * 100);
        });
    });
</script>