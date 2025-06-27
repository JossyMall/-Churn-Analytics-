<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php';

$user_id = $_SESSION['user_id'];

// Check if membership ID is provided
if (!isset($_POST['membership_id'])) {
    $_SESSION['error'] = 'No membership plan selected';
    header('Location: membership.php');
    exit;
}

$membership_id = (int)$_POST['membership_id'];
$is_yearly = isset($_POST['is_yearly']) ? (int)$_POST['is_yearly'] : 0;

// Get membership details
$stmt = $pdo->prepare("SELECT * FROM membership_levels WHERE id = ?");
$stmt->execute([$membership_id]);
$membership = $stmt->fetch();

if (!$membership) {
    $_SESSION['error'] = 'Invalid membership plan';
    header('Location: membership.php');
    exit;
}

// Calculate price
if ($is_yearly) {
    $amount = $membership['promo_price'] * 12 * (1 - ($membership['yearly_discount']/100));
    $duration = '1 Year';
} else {
    $amount = $membership['promo_price'];
    $duration = '1 Month';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method'])) {
    $payment_method = $_POST['payment_method'];
    $transaction_id = bin2hex(random_bytes(8));
    
    try {
        $pdo->beginTransaction();
        
        // Calculate end date
        $start_date = date('Y-m-d H:i:s');
        $end_date = $is_yearly ? date('Y-m-d H:i:s', strtotime('+1 year')) : date('Y-m-d H:i:s', strtotime('+1 month'));
        
        // Deactivate any current subscription
        $pdo->prepare("UPDATE user_subscriptions SET is_active = 0 WHERE user_id = ?")->execute([$user_id]);
        
        // Create new subscription
        $stmt = $pdo->prepare("INSERT INTO user_subscriptions 
                              (user_id, membership_id, start_date, end_date, is_yearly, payment_method, amount, transaction_id, is_active)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)");
        $stmt->execute([
            $user_id,
            $membership_id,
            $start_date,
            $end_date,
            $is_yearly,
            $payment_method,
            $amount,
            $transaction_id
        ]);
        
        // Process affiliate earnings if this is a referred user
        $stmt = $pdo->prepare("SELECT referrer_id FROM affiliate_referrals 
                              WHERE referred_id = ? AND has_converted = 0");
        $stmt->execute([$user_id]);
        $referral = $stmt->fetch();
        
        if ($referral) {
            // Get affiliate percentage for this membership level
            $stmt = $pdo->prepare("SELECT percentage FROM affiliate_rewards WHERE membership_id = ?");
            $stmt->execute([$membership_id]);
            $percentage = $stmt->fetchColumn() ?? 0.10; // Default to 10% if not set
            
            // Calculate commission
            $commission = $amount * $percentage;
            
            // Record affiliate earnings
            $stmt = $pdo->prepare("INSERT INTO affiliate_earnings 
                                  (user_id, amount, source, source_id, earned_date)
                                  VALUES (?, ?, 'membership', ?, NOW())");
            $stmt->execute([$referral['referrer_id'], $commission, $membership_id]);
            
            // Mark referral as converted
            $pdo->prepare("UPDATE affiliate_referrals SET has_converted = 1 
                          WHERE referrer_id = ? AND referred_id = ?")
               ->execute([$referral['referrer_id'], $user_id]);
            
            // Create notification for referrer
            $message = "You earned $".number_format($commission,2)." from ".$_SESSION['username']."'s membership purchase";
            $pdo->prepare("INSERT INTO notifications 
                          (user_id, title, message, type, related_id, created_at)
                          VALUES (?, 'New Affiliate Earnings', ?, 'affiliate', ?, NOW())")
               ->execute([$referral['referrer_id'], $message, $user_id]);
        }
        
        $pdo->commit();
        
        // Redirect to thank you page
        $_SESSION['payment_success'] = true;
        header('Location: membership_plus.php');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = 'Payment processing failed. Please try again.';
        header('Location: payment.php');
        exit;
    }
}
?>

<div class="payment-container">
    <h1>Complete Your Payment</h1>
    
    <div class="payment-summary">
        <h2><?= htmlspecialchars($membership['name']) ?> Plan</h2>
        <p>Duration: <?= $duration ?></p>
        <p class="payment-amount">$<?= number_format($amount, 2) ?></p>
    </div>
    
    <form method="POST" class="payment-form">
        <input type="hidden" name="membership_id" value="<?= $membership_id ?>">
        <input type="hidden" name="is_yearly" value="<?= $is_yearly ?>">
        
        <div class="payment-methods">
            <div class="payment-method">
                <input type="radio" name="payment_method" id="paypal" value="paypal" checked>
                <label for="paypal">
                    <img src="../io/assets/images/paypal.png" alt="PayPal">
                    PayPal
                </label>
            </div>
            
            <div class="payment-method">
                <input type="radio" name="payment_method" id="card" value="card">
                <label for="card">
                    <img src="../io/assets/images/credit-card.png" alt="Credit Card">
                    Credit/Debit Card
                </label>
            </div>
            
            <div class="payment-method">
                <input type="radio" name="payment_method" id="bank" value="bank">
                <label for="bank">
                    <img src="../io/assets/images/bank.png" alt="Bank Transfer">
                    Bank Transfer
                </label>
            </div>
            
            <div class="payment-method">
                <input type="radio" name="payment_method" id="crypto" value="crypto">
                <label for="crypto">
                    <img src="../io/assets/images/crypto.png" alt="Crypto">
                    Cryptocurrency
                </label>
            </div>
        </div>
        
        <button type="submit" class="pay-now-btn">Pay Now</button>
    </form>
</div>

<style>
    .payment-container {
        border-radius: 8px;
        padding: 20px;
        max-width: 600px;
        margin: 0 auto;
    }
    
    .payment-summary {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    
    .payment-summary h2 {
        color: #333;
        margin-bottom: 10px;
    }
    
    .payment-amount {
        font-size: 2rem;
        font-weight: bold;
        color: #3ac3b8;
        margin: 10px 0;
    }
    
    .payment-methods {
        margin-bottom: 30px;
    }
    
    .payment-method {
        margin-bottom: 10px;
    }
    
    .payment-method input {
        display: none;
    }
    
    .payment-method label {
        display: flex;
        align-items: center;
        padding: 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .payment-method label:hover {
        border-color: #3ac3b8;
    }
    
    .payment-method input:checked + label {
        border-color: #3ac3b8;
        background-color: #f0f9f8;
    }
    
    .payment-method img {
        width: 30px;
        margin-right: 15px;
    }
    
    .pay-now-btn {
        width: 100%;
        padding: 12px;
        background: #3ac3b8;
        color: white;
        border: none;
        border-radius: 8px;
        font-size: 1rem;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.3s ease;
    }
    
    .pay-now-btn:hover {
        background: #2fa89e;
    }
</style>

<?php
require_once 'includes/footer.php';
?>