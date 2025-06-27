<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];

// Get membership levels
$stmt = $pdo->query("SELECT * FROM membership_levels WHERE is_active = 1");
$membership_levels = $stmt->fetchAll();

// Get current user subscription
$stmt = $pdo->prepare("SELECT m.*, ml.name as level_name 
                      FROM user_subscriptions m
                      JOIN membership_levels ml ON m.membership_id = ml.id
                      WHERE m.user_id = ? AND m.is_active = 1");
$stmt->execute([$user_id]);
$current_subscription = $stmt->fetch();
?>

<div class="membership-container">
    <h1>Membership Plans</h1>
    
    <?php if ($current_subscription): ?>
        <div class="current-membership">
            <h2>Your Current Plan</h2>
            <div class="current-plan">
                <h3><?= htmlspecialchars($current_subscription['level_name']) ?></h3>
                <p>Expires on: <?= date('M j, Y', strtotime($current_subscription['end_date'])) ?></p>
                <p>Payment Method: <?= ucfirst($current_subscription['payment_method']) ?></p>
            </div>
        </div>
    <?php endif; ?>

    <div class="plans-grid">
        <?php foreach ($membership_levels as $plan): ?>
            <div class="plan-card">
                <h3><?= htmlspecialchars($plan['name']) ?></h3>
                
                <div class="price-toggle">
                    <span class="toggle-label">Monthly</span>
                    <label class="switch">
                        <input type="checkbox" class="plan-toggle" data-plan="<?= $plan['id'] ?>">
                        <span class="slider round"></span>
                    </label>
                    <span class="toggle-label">Yearly</span>
                </div>
                
                <div class="price monthly">
                    <span class="amount">$<?= number_format($plan['promo_price'], 2) ?></span>
                    <span class="period">per month</span>
                </div>
                
                <div class="price yearly" style="display:none;">
                    <span class="amount">$<?= number_format(($plan['promo_price'] * 12) * (1 - ($plan['yearly_discount']/100)), 2) ?></span>
                    <span class="period">per year</span>
                    <span class="discount">Save <?= $plan['yearly_discount'] ?>%</span>
                </div>
                
                <ul class="features">
                    <?php foreach (explode("\n", $plan['features']) as $feature): ?>
                        <li><?= htmlspecialchars(trim($feature)) ?></li>
                    <?php endforeach; ?>
                </ul>
                
                <form action="payment.php" method="POST">
                    <input type="hidden" name="membership_id" value="<?= $plan['id'] ?>">
                    <input type="hidden" name="is_yearly" value="0" class="yearly-field">
                    <button type="submit" class="subscribe-btn">Subscribe</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<style>
    .membership-container {
        background: #ffffff00;
        border-radius: 8px;
        padding: 20px;
    }
    
    .current-membership {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    
    .current-plan {
        background: #b8ffa0;
        padding: 15px;
        border-radius: 8px;
        margin-top: 10px;
        border-left: 4px solid #3ac3b8;
    }
    
    .plans-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }
    
    .plan-card {
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 20px;
        transition: all 0.3s ease;
    }
    
    .plan-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .price-toggle {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin: 15px 0;
    }
    
    .toggle-label {
        font-size: 0.9rem;
        color: #666;
    }
    
    .switch {
        position: relative;
        display: inline-block;
        width: 50px;
        height: 24px;
    }
    
    .switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #ccc;
        transition: .4s;
    }
    
    .slider:before {
        position: absolute;
        content: "";
        height: 16px;
        width: 16px;
        left: 4px;
        bottom: 4px;
        background-color: white;
        transition: .4s;
    }
    
    input:checked + .slider {
        background-color: #3ac3b8;
    }
    
    input:checked + .slider:before {
        transform: translateX(26px);
    }
    
    .slider.round {
        border-radius: 24px;
    }
    
    .slider.round:before {
        border-radius: 50%;
    }
    
    .price {
        text-align: center;
        margin: 20px 0;
    }
    
    .price .amount {
        font-size: 2rem;
        font-weight: bold;
        color: #3ac3b8;
    }
    
    .price .period {
        display: block;
        color: #666;
        font-size: 0.9rem;
    }
    
    .price .discount {
        display: block;
        color: #28a745;
        font-weight: bold;
        margin-top: 5px;
    }
    
    .features {
        margin: 20px 0;
        padding-left: 20px;
    }
    
    .features li {
        margin-bottom: 8px;
    }
    
    .subscribe-btn {
        width: 100%;
        padding: 10px;
        background: #3ac3b8;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 500;
        transition: background 0.3s ease;
    }
    
    .subscribe-btn:hover {
        background: #2fa89e;
    }
</style>

<script>
    // Handle price toggle
    document.querySelectorAll('.plan-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const planCard = this.closest('.plan-card');
            const monthlyPrice = planCard.querySelector('.price.monthly');
            const yearlyPrice = planCard.querySelector('.price.yearly');
            const yearlyField = planCard.querySelector('.yearly-field');
            
            if (this.checked) {
                monthlyPrice.style.display = 'none';
                yearlyPrice.style.display = 'block';
                yearlyField.value = '1';
            } else {
                monthlyPrice.style.display = 'block';
                yearlyPrice.style.display = 'none';
                yearlyField.value = '0';
            }
        });
    });
</script>

<?php
// Close tags from header.php
?>
    </div><!-- main-content -->
</div><!-- content-container -->
</body>
</html>