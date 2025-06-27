<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];

// Check for payment success message
$payment_success = false;
if (isset($_SESSION['payment_success'])) {
    $payment_success = true;
    unset($_SESSION['payment_success']);
}

// Get current subscription
$stmt = $pdo->prepare("SELECT us.*, ml.name as level_name, ml.features as level_features 
                      FROM user_subscriptions us
                      JOIN membership_levels ml ON us.membership_id = ml.id
                      WHERE us.user_id = ? AND us.is_active = 1");
$stmt->execute([$user_id]);
$subscription = $stmt->fetch();

// Get subscription history
$stmt = $pdo->prepare("SELECT us.*, ml.name as level_name 
                      FROM user_subscriptions us
                      JOIN membership_levels ml ON us.membership_id = ml.id
                      WHERE us.user_id = ?
                      ORDER BY us.start_date DESC");
$stmt->execute([$user_id]);
$history = $stmt->fetchAll();

// Get available gifts
$stmt = $pdo->prepare("SELECT mg.* FROM membership_gifts mg
                      JOIN membership_levels ml ON mg.membership_id = ml.id
                      JOIN user_subscriptions us ON ml.id = us.membership_id
                      WHERE us.user_id = ? AND us.is_active = 1");
$stmt->execute([$user_id]);
$available_gifts = $stmt->fetchAll();

// Calculate progress toward next gift
$gift_progress = 0;
$next_gift = null;
if ($subscription && count($available_gifts) {
    $next_gift = $available_gifts[0];
    $months_active = (new DateTime($subscription['start_date']))->diff(new DateTime())->m;
    $gift_progress = min(100, ($months_active / $next_gift['duration_months']) * 100);
}
?>

<div class="membership-plus-container">
    <?php if ($payment_success): ?>
        <div class="alert success">
            Payment successful! Thank you for your subscription.
        </div>
    <?php endif; ?>

    <?php if ($subscription): ?>
        <div class="current-membership">
            <h2>Your Membership</h2>
            <div class="membership-card">
                <h3><?= htmlspecialchars($subscription['level_name']) ?></h3>
                <div class="membership-details">
                    <p><strong>Started:</strong> <?= date('M j, Y', strtotime($subscription['start_date'])) ?></p>
                    <p><strong>Expires:</strong> <?= date('M j, Y', strtotime($subscription['end_date'])) ?></p>
                    <p><strong>Billing:</strong> <?= $subscription['is_yearly'] ? 'Yearly' : 'Monthly' ?></p>
                    <p><strong>Amount:</strong> $<?= number_format($subscription['amount'], 2) ?></p>
                </div>
                
                <div class="membership-features">
                    <h4>Plan Features:</h4>
                    <ul>
                        <?php foreach (explode("\n", $subscription['level_features']) as $feature): ?>
                            <li><?= htmlspecialchars(trim($feature)) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <?php if ($next_gift): ?>
            <div class="gift-section">
                <h2>Your Gift Progress</h2>
                <div class="gift-card">
                    <div class="gift-image">
                        <?php if ($next_gift['icon']): ?>
                            <img src="../assets/images/gifts/<?= htmlspecialchars($next_gift['icon']) ?>" alt="Gift Icon">
                        <?php else: ?>
                            <div class="gift-icon-placeholder">🎁</div>
                        <?php endif; ?>
                    </div>
                    <div class="gift-info">
                        <h3><?= htmlspecialchars($next_gift['name']) ?></h3>
                        <p><?= htmlspecialchars($next_gift['description']) ?></p>
                        <p><small>Earn after <?= $next_gift['duration_months'] ?> months of continuous membership</small></p>
                        
                        <div class="progress-container">
                            <div class="progress-bar" style="width: <?= $gift_progress ?>%"></div>
                            <span class="progress-text"><?= round($gift_progress) ?>%</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="no-membership">
            <h2>No Active Membership</h2>
            <p>You don't have an active membership plan. Subscribe to unlock all features.</p>
            <a href="membership.php" class="subscribe-btn">View Plans</a>
        </div>
    <?php endif; ?>

    <div class="history-section">
        <h2>Membership History</h2>
        <?php if (count($history)): ?>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['level_name']) ?></td>
                            <td><?= date('M j, Y', strtotime($item['start_date'])) ?></td>
                            <td><?= date('M j, Y', strtotime($item['end_date'])) ?></td>
                            <td>$<?= number_format($item['amount'], 2) ?></td>
                            <td>
                                <span class="status-badge <?= $item['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $item['is_active'] ? 'Active' : 'Expired' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No membership history found.</p>
        <?php endif; ?>
    </div>
</div>

<style>
    .membership-plus-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }
    
    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border-radius: 8px;
    }
    
    .alert.success {
        background: #e6f7ee;
        color: #28a745;
    }
    
    .current-membership {
        margin-bottom: 30px;
    }
    
    .membership-card {
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 20px;
        background: #f9f9f9;
    }
    
    .membership-details {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin: 15px 0;
    }
    
    .membership-features ul {
        padding-left: 20px;
    }
    
    .membership-features li {
        margin-bottom: 8px;
    }
    
    .gift-section {
        margin: 30px 0;
        padding: 20px 0;
        border-top: 1px solid #eee;
        border-bottom: 1px solid #eee;
    }
    
    .gift-card {
        display: flex;
        gap: 20px;
        align-items: center;
        background: #f5f9ff;
        padding: 20px;
        border-radius: 8px;
    }
    
    .gift-image {
        width: 80px;
        height: 80px;
        background: #e6f0ff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
    }
    
    .gift-image img {
        width: 50px;
        height: 50px;
        object-fit: contain;
    }
    
    .progress-container {
        margin-top: 15px;
        height: 20px;
        background: #eee;
        border-radius: 10px;
        position: relative;
        overflow: hidden;
    }
    
    .progress-bar {
        height: 100%;
        background: #3ac3b8;
        border-radius: 10px;
    }
    
    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: white;
        font-size: 0.8rem;
        font-weight: bold;
    }
    
    .no-membership {
        text-align: center;
        padding: 40px 20px;
        background: #f9f9f9;
        border-radius: 8px;
        margin-bottom: 30px;
    }
    
    .subscribe-btn {
        display: inline-block;
        padding: 10px 20px;
        background: #3ac3b8;
        color: white;
        border-radius: 5px;
        text-decoration: none;
        margin-top: 15px;
    }
    
    .history-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    
    .history-table th, .history-table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    .history-table th {
        font-weight: 500;
        color: #666;
    }
    
    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.8rem;
        font-weight: 500;
    }
    
    .status-badge.active {
        background: #e6f7ee;
        color: #28a745;
    }
    
    .status-badge.inactive {
        background: #f8e6e6;
        color: #dc3545;
    }
</style>

<?php
// Close tags from header.php
?>
    </div><!-- main-content -->
</div><!-- content-container -->
</body>
</html>