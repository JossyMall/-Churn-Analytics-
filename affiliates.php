<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1'); // Shows parse errors at startup
ini_set('log_errors', '1'); // Ensures errors are logged
ini_set('error_log', '/var/www/www-root/data/www/earndos.com/io/php_app_errors.log'); // DIRECTS ERRORS HERE
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

if (!isset($_SESSION['username'])) {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['username'] = $stmt->fetchColumn();
}

$user_id = $_SESSION['user_id'];
// Get affiliate data
$stmt = $pdo->prepare("SELECT SUM(amount) as balance FROM affiliate_earnings WHERE user_id = ? AND is_paid = 0");
$stmt->execute([$user_id]);
$balance = $stmt->fetchColumn() ?? 0;

// Get referral count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM affiliate_referrals WHERE referrer_id = ?");
$stmt->execute([$user_id]);
$referral_count = $stmt->fetchColumn();

// Get recent referrals (last 7 days)
$stmt = $pdo->prepare("SELECT COUNT(*) as count, DATE(referral_date) as day
                      FROM affiliate_referrals
                      WHERE referrer_id = ? AND referral_date >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      GROUP BY DATE(referral_date)");
$stmt->execute([$user_id]);
$recent_referrals = $stmt->fetchAll();

// Get affiliate settings
$stmt = $pdo->query("SELECT value FROM config WHERE setting = 'affiliate_threshold'");
$withdrawal_threshold = $stmt->fetchColumn() ?? 50;

// Fetch membership levels with their affiliate reward percentages
$stmt = $pdo->query("
    SELECT ml.id, ml.name, ml.promo_price, ar.percentage
    FROM membership_levels ml
    LEFT JOIN affiliate_rewards ar ON ml.id = ar.membership_id
    ORDER BY ml.promo_price DESC
");
$membership_plans_with_rewards = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Define BASE_URL (assuming it's defined elsewhere or explicitly here)
// Make sure BASE_URL is defined consistently across your application.
if (!defined('BASE_URL')) {
    // This is a placeholder; replace with your actual base URL logic if it's dynamic
    // For example, if you have a config file or similar.
    define('BASE_URL', 'https://earndos.com/io'); 
}

// Handle cashout request - NO LONGER NEEDED HERE AS BUTTON LINKS TO CASHOUT.PHP
/*
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_cashout'])) {
    $amount = floatval($_POST['amount']);
   
    if ($amount < $withdrawal_threshold) {
        $_SESSION['error'] = "Minimum withdrawal amount is $" . number_format($withdrawal_threshold, 2);
    } elseif ($amount > $balance) {
        $_SESSION['error'] = "Amount exceeds your available balance";
    } else {
        $stmt = $pdo->prepare("INSERT INTO affiliate_cashouts (user_id, amount) VALUES (?, ?)");
        $stmt->execute([$user_id, $amount]);
        $_SESSION['success'] = "Cashout request submitted successfully";
        header('Location: affiliates.php');
        exit;
    }
}
*/
?>

<div class="affiliates-container">
    <h1>Affiliate Program</h1>
   
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
   
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
   
    <div class="wallet-section">
        <h2>Your Affiliate Wallet💳</h2>
        <div class="wallet-balance">
            <span class="amount">$<?= number_format($balance, 2) ?></span>
            <span class="label">Available Balance</span>
        </div>
       
        <a href="<?= BASE_URL ?>/cashout.php" class="cashout-btn">Request Cashout</a>
        
        <div class="threshold-notice">
            Minimum withdrawal amount: $<?= number_format($withdrawal_threshold, 2) ?>
        </div>
    </div>
   
    <div class="referral-stats">
        <div class="stat-card">
            <h3>Total Referrals</h3>
            <p><?= $referral_count ?></p>
        </div>
       
        <div class="stat-card">
            <h3>Last 7 Days</h3>
            <p><?= count($recent_referrals) ?></p>
        </div>
    </div>
   
    <div class="referral-graph">
        <h2>Referral Activity</h2>
        <canvas id="referralChart" height="200"></canvas>
    </div>
   
    <div class="referral-calculator">
        <h2>Earnings Calculator</h2>
        <div class="calculator-form">
            <div class="form-group">
                <label>Membership Plan</label>
                <select id="planSelect">
                    <?php
                    // Use the fetched data with rewards
                    foreach ($membership_plans_with_rewards as $plan):
                        // Calculate the actual commission amount based on promo_price and percentage
                        $commission_amount = ($plan['promo_price'] * ($plan['percentage'] / 100)) ?? 0;
                    ?>
                        <option value="<?= $plan['id'] ?>" data-commission-per-referral="<?= number_format($commission_amount, 2, '.', '') ?>">
                            <?= htmlspecialchars($plan['name']) ?> ($<?= number_format($plan['promo_price'], 2) ?>/mo) - <?= htmlspecialchars($plan['percentage'] ?? 0) ?>% Reward
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
           
            <div class="form-group">
                <label>Number of Referrals</label>
                <input type="range" id="referralSlider" min="10" max="500" value="10" step="10">
                <div class="slider-labels">
                    <span>10</span>
                    <span>500</span>
                </div>
                <div class="slider-value" id="referralValue">10</div>
            </div>
           
            <div class="calculator-result">
                <h3>Estimated Monthly Earnings</h3>
                <p id="estimatedEarnings">$0.00</p>
            </div>
        </div>
    </div>
   
    <div class="referral-link">
        <h2>Your Referral Link</h2>
        <div class="link-options">
            <input type="radio" name="linkType" id="linkUsername" checked>
            <label for="linkUsername">Username</label>
           
            <input type="radio" name="linkType" id="linkUserId">
            <label for="linkUserId">User ID</label>
        </div>
       
        <div class="link-display">
            <input type="text" id="referralLink" value="<?= BASE_URL ?>/auth/register.php?ref=<?= isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : '' ?>" readonly>
            <button class="copy-btn" id="copyLink">Copy</button>
        </div>
    </div>
</div>

<style>
    .affiliates-container {
        border-radius: 8px;
        padding: 20px;
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
   
    .wallet-section {
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
        text-align: center;
    }
   
    .wallet-balance {
        margin: 20px 0;
    }
   
    .wallet-balance .amount {
        font-size: 2.5rem;
        font-weight: bold;
        color: #3ac3b8;
        display: block;
    }
   
    .wallet-balance .label {
        color: #666;
        font-size: 0.9rem;
    }
   
    .cashout-btn {
        background: #3ac3b8;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        font-weight: 500;
        text-decoration: none; /* Ensure it looks like a button, not a link */
        display: inline-block; /* Make it behave like a block element for padding */
        margin-bottom: 10px; /* Added margin for separation from threshold notice */
    }
    .cashout-btn:hover {
        background: #2fa89e; /* Darken on hover */
        color: white; /* Keep text white on hover */
    }
   
    .threshold-notice {
        color: #666;
        font-size: 0.9rem;
        margin-top: 10px; /* This margin is fine if button has mb */
    }
   
    .referral-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
   
    .stat-card {
        background: #f0ffdb;
        border: 2px solid #000;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
    }
   
    .stat-card h3 {
        color: #666;
        font-size: 1rem;
        margin-bottom: 10px;
    }
   
    .stat-card p {
        font-size: 1.5rem;
        font-weight: bold;
        color: #3ac3b8;
        margin: 0;
    }
   
    .referral-graph {
        margin-bottom: 30px;
        padding: 20px;
        border: 1px solid #eee;
        border-radius: 8px;
    }
   
    .referral-calculator {
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
    }
   
    .calculator-form {
        max-width: 500px;
        margin: 0 auto;
    }
   
    .form-group {
        margin-bottom: 20px;
    }
   
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-weight: 500;
    }
   
    .form-group select,
    .form-group input[type="range"],
    .form-group input[type="number"] {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
   
    .slider-labels {
        display: flex;
        justify-content: space-between;
        margin-top: 5px;
        color: #666;
        font-size: 0.8rem;
    }
   
    .slider-value {
        text-align: center;
        font-weight: bold;
        margin-top: 5px;
    }
   
    .calculator-result {
        text-align: center;
        margin-top: 20px;
        padding: 15px;
        border-radius: 8px;
    }
   
    .calculator-result h3 {
        margin-bottom: 10px;
        color: #666;
        font-size: 1rem;
    }
   
    .calculator-result p {
        font-size: 1.8rem;
        font-weight: bold;
        color: #3ac3b8;
        margin: 0;
    }
   
    .referral-link {
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #eee;
    }
   
    .link-options {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
    }
   
    .link-options input[type="radio"] {
        display: none;
    }
   
    .link-options label {
        padding: 8px 15px;
        border: 1px solid #ddd;
        border-radius: 5px;
        cursor: pointer;
    }
   
    .link-options input[type="radio"]:checked + label {
        background: #3ac3b8;
        color: white;
        border-color: #3ac3b8;
    }
   
    .link-display {
        display: flex;
        gap: 10px;
    }
   
    .link-display input {
        flex: 1;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
   
    .copy-btn {
        background: #666;
        color: white;
        border: none;
        padding: 0 15px;
        border-radius: 5px;
        cursor: pointer;
    }
   
    /* Removed Modal styles as it's no longer used inline */

</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Referral chart
    const ctx = document.getElementById('referralChart').getContext('2d');
    const chartData = {
        labels: Array(7).fill().map((_, i) => {
            const d = new Date();
            d.setDate(d.getDate() - 6 + i);
            return d.toLocaleDateString('en-US', { weekday: 'short' });
        }),
        datasets: [{
            label: 'Referrals',
            data: Array(7).fill(0),
            backgroundColor: '#3ac3b8',
            borderColor: '#2fa89e',
            borderWidth: 1
        }]
    };

    // Fill in actual data
    <?php foreach ($recent_referrals as $ref): ?>
        const refDate = new Date('<?= $ref['day'] ?>');
        const today = new Date();
        // Set both dates to start of day to ensure correct day difference calculation
        refDate.setHours(0,0,0,0);
        today.setHours(0,0,0,0);
        const diffTime = Math.abs(today - refDate);
        const dayIndex = 6 - Math.ceil(diffTime / (1000 * 60 * 60 * 24)); // Calculate index from end
        
        if (dayIndex >= 0 && dayIndex < 7) {
            chartData.datasets[0].data[dayIndex] = <?= $ref['count'] ?>;
        }
    <?php endforeach; ?>

    const referralChart = new Chart(ctx, {
        type: 'bar',
        data: chartData,
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        callback: function(value) {if (value % 1 === 0) {return value;}} // Display whole numbers only
                    }
                }
            }
        }
    });

    // Calculator logic
    const planSelect = document.getElementById('planSelect');
    const referralSlider = document.getElementById('referralSlider');
    const referralValue = document.getElementById('referralValue');
    const estimatedEarnings = document.getElementById('estimatedEarnings');

    function updateCalculation() {
        // Get commission directly from data-commission-per-referral attribute
        const commissionPerReferral = parseFloat(planSelect.selectedOptions[0].dataset.commissionPerReferral);
        const referrals = parseInt(referralSlider.value);
        const earnings = commissionPerReferral * referrals;
       
        referralValue.textContent = referrals;
        estimatedEarnings.textContent = '$' + earnings.toFixed(2);
    }

    planSelect.addEventListener('change', updateCalculation);
    referralSlider.addEventListener('input', updateCalculation);
    updateCalculation(); // Initial calculation on page load

    // Referral link toggle
    const linkUsername = document.getElementById('linkUsername');
    const linkUserId = document.getElementById('linkUserId');
    const referralLink = document.getElementById('referralLink');

    // Get username and user ID from PHP with proper escaping
    const username = '<?= isset($_SESSION['username']) ? addslashes($_SESSION['username']) : '' ?>';
    const userId = '<?= $_SESSION['user_id'] ?>';
    const baseUrl = '<?= BASE_URL ?>/auth/register.php?ref=';

    function updateReferralLink() {
        referralLink.value = baseUrl + (linkUsername.checked ? username : userId);
    }

    // Initialize with username link
    updateReferralLink();

    linkUsername.addEventListener('change', updateReferralLink);
    linkUserId.addEventListener('change', updateReferralLink);

    // Copy link
    document.getElementById('copyLink').addEventListener('click', () => {
        referralLink.select();
        document.execCommand('copy');
        alert('Referral link copied to clipboard!');
    });

</script>

<?php
// Close tags from header.php
?>
    </div></div></body>
</html>