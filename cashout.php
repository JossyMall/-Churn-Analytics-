<?php
// cashout.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // This is where the 'No membership plan selected' warning might originate

// Fetch user's available balance
$stmt = $pdo->prepare("SELECT SUM(amount) FROM affiliate_earnings WHERE user_id = ? AND is_paid = 0");
$stmt->execute([$_SESSION['user_id']]);
$available_balance = $stmt->fetchColumn() ?? 0;

// Get withdrawal threshold
$stmt = $pdo->query("SELECT value FROM config WHERE setting = 'affiliate_threshold'");
$withdrawal_threshold = $stmt->fetchColumn() ?? 50;

// Fetch user's payment methods
$stmt = $pdo->prepare("SELECT * FROM user_payment_methods WHERE user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user's cashout history
$stmt = $pdo->prepare("SELECT * FROM affiliate_cashouts WHERE user_id = ? ORDER BY request_date DESC");
$stmt->execute([$_SESSION['user_id']]);
$cashout_history = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Process withdrawal request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_withdrawal'])) {
    $amount = floatval($_POST['amount']);
    $selected_method = $_POST['payment_method'] ?? ''; // Added for selected method

    $paypal_email = null;
    $bank_name = null;
    $account_name = null;
    $account_number = null;
    $usdt_wallet = null;

    // Based on the selected method, populate the correct variable
    if ($selected_method === 'paypal') {
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        if (empty($paypal_email)) {
            $_SESSION['error'] = "PayPal email is required for PayPal withdrawal.";
        }
    } elseif ($selected_method === 'bank') {
        $bank_name = trim($_POST['bank_name'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');
        if (empty($bank_name) || empty($account_name) || empty($account_number)) {
            $_SESSION['error'] = "All bank details are required for bank withdrawal.";
        }
    } elseif ($selected_method === 'usdt') {
        $usdt_wallet = trim($_POST['usdt_wallet'] ?? '');
        if (empty($usdt_wallet)) {
            $_SESSION['error'] = "USDT wallet address is required for USDT withdrawal.";
        }
    } else {
        $_SESSION['error'] = "Please select a valid payment method.";
    }

    // Check for other errors
    if (!isset($_SESSION['error'])) { // Only proceed if no payment method error
        if ($amount < $withdrawal_threshold) {
            $_SESSION['error'] = "Minimum withdrawal amount is $" . number_format($withdrawal_threshold, 2);
        } elseif ($amount > $available_balance) {
            $_SESSION['error'] = "Amount exceeds your available balance";
        } else {
            // Insert into affiliate_cashouts, including the method and details
            $stmt = $pdo->prepare("INSERT INTO affiliate_cashouts (user_id, amount, method, paypal_email, bank_name, account_name, account_number, usdt_wallet) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $amount, $selected_method, $paypal_email, $bank_name, $account_name, $account_number, $usdt_wallet])) {
                $_SESSION['success'] = "Withdrawal request submitted successfully";
                header('Location: cashout.php');
                exit;
            } else {
                $_SESSION['error'] = "Failed to process withdrawal request";
            }
        }
    }
}
?>

<div class="container">
    <h1>Withdraw Earnings</h1>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="balance-card">
        <div class="balance-header">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 1L3 5V11C3 16.55 6.84 21.74 12 23C17.16 21.74 21 16.55 21 11V5L12 1Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 11C13.6569 11 15 9.65685 15 8C15 6.34315 13.6569 5 12 5C10.3431 5 9 6.34315 9 8C9 9.65685 10.3431 11 12 11Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M12 11V15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 15H15" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <h2>Available Balance</h2>
        </div>
        <div class="balance-amount">$<?= number_format($available_balance, 2) ?></div>
        <div class="balance-threshold">Minimum withdrawal: $<?= number_format($withdrawal_threshold, 2) ?></div>
    </div>

    <form method="POST" class="withdrawal-form">
        <div class="form-group">
            <label for="payment_method">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 8H22" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M6 16H8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M10 16H12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <rect x="2" y="3" width="20" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                </svg>
                Select Payment Method
            </label>
            <select name="payment_method" id="payment_method" required onchange="showPaymentFields()">
                <option value="">-- Select --</option>
                <?php foreach ($payment_methods as $method): ?>
                    <option value="<?= htmlspecialchars($method['method']) ?>"
                            data-paypal-email="<?= htmlspecialchars($method['paypal_email'] ?? '') ?>"
                            data-bank-name="<?= htmlspecialchars($method['bank_name'] ?? '') ?>"
                            data-account-name="<?= htmlspecialchars($method['account_name'] ?? '') ?>"
                            data-account-number="<?= htmlspecialchars($method['account_number'] ?? '') ?>"
                            data-usdt-wallet="<?= htmlspecialchars($method['usdt_wallet'] ?? '') ?>">
                        <?= ucfirst(htmlspecialchars($method['method'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($payment_methods)): ?>
                <p class="text-danger mt-2">You haven't added any payment methods yet. Please add one in your <a href="settings.php">settings</a> to withdraw earnings.</p>
            <?php endif; ?>
        </div>

        <div id="paypal_fields" class="form-group" style="display: none;">
            <label for="paypal_email">
                <i class="fab fa-paypal"></i> PayPal Email
            </label>
            <input type="email" id="paypal_email" name="paypal_email" placeholder="Your PayPal email">
        </div>

        <div id="bank_fields" class="form-group" style="display: none;">
            <label for="bank_name">
                <i class="fas fa-bank"></i> Bank Name
            </label>
            <input type="text" id="bank_name" name="bank_name" placeholder="Bank Name">
            <label for="account_name">Account Name</label>
            <input type="text" id="account_name" name="account_name" placeholder="Account Name">
            <label for="account_number">Account Number</label>
            <input type="text" id="account_number" name="account_number" placeholder="Account Number">
        </div>

        <div id="usdt_fields" class="form-group" style="display: none;">
            <label for="usdt_wallet_input">
                <i class="fas fa-wallet"></i> USDT Wallet Address
            </label>
            <input type="text" id="usdt_wallet_input" name="usdt_wallet" placeholder="Your USDT wallet address">
        </div>

        <div class="form-group">
            <label>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 1V23" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M17 5H9.5C8.57174 5 7.6815 5.36875 7.02513 6.02513C6.36875 6.6815 6 7.57174 6 8.5C6 9.42826 6.36875 10.3185 7.02513 10.9749C7.6815 11.6313 8.57174 12 9.5 12H14.5C15.4283 12 16.3185 12.3687 16.9749 13.0251C17.6313 13.6815 18 14.5717 18 15.5C18 16.4283 17.6313 17.3185 16.9749 17.9749C16.3185 18.6313 15.4283 19 14.5 19H6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                Amount to Withdraw ($)
            </label>
            <input type="number" name="amount" min="<?= $withdrawal_threshold ?>" max="<?= $available_balance ?>" step="0.01" value="<?= min($available_balance, max($withdrawal_threshold, 50)) ?>" required>
        </div>

        <button type="submit" name="request_withdrawal" class="submit-btn" <?= empty($payment_methods) ? 'disabled' : '' ?>>
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M22 2L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Request Withdrawal
        </button>
    </form>

    <hr style="margin: 40px 0;">

    <h2>Your Cashout History</h2>
    <?php if (empty($cashout_history)): ?>
        <p>You have no past cashout requests.</p>
    <?php else: ?>
        <div class="table-container">
            <table class="cashout-history-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Requested Date</th>
                        <th>Processed Date</th>
                        <th>Comments</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cashout_history as $cashout): ?>
                        <tr>
                            <td><?= htmlspecialchars($cashout['id']) ?></td>
                            <td>$<?= number_format($cashout['amount'], 2) ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($cashout['status']) ?>">
                                    <?= ucfirst(htmlspecialchars($cashout['status'])) ?>
                                </span>
                            </td>
                            <td><?= date('M j, Y H:i', strtotime($cashout['request_date'])) ?></td>
                            <td><?= $cashout['processed_date'] ? date('M j, Y H:i', strtotime($cashout['processed_date'])) : 'N/A' ?></td>
                            <td><?= !empty($cashout['comments']) ? htmlspecialchars($cashout['comments']) : 'No comments' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<style>
/* Existing styles remain the same */
.container {
    max-width: 600px;
    margin: 0 auto;
    padding: 20px;
}

.alert {
    padding: 12px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.alert.error {
    background-color: #ffebee;
    color: #c62828;
}

.alert.success {
    background-color: #e8f5e9;
    color: #2e7d32;
}

.balance-card {
    margin-bottom: 30px;
    text-align: center;
}

.balance-header {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 10px;
}

.balance-amount {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.balance-threshold {
    color: #666;
    font-size: 0.9rem;
}

.withdrawal-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.form-group input, .form-group select { /* Added select to apply styles */
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.submit-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 12px;
    background-color: #3ac3b8;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-weight: 500;
    transition: background-color 0.2s ease;
}
.submit-btn:hover:not(:disabled) {
    background-color: #2da89e;
}
.submit-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}


/* New styles for cashout history table */
.table-container {
    overflow-x: auto;
    margin-top: 20px;
}

.cashout-history-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.cashout-history-table th,
.cashout-history-table td {
    border: 1px solid #e0e0e0;
    padding: 10px;
    text-align: left;
    vertical-align: top;
}

.cashout-history-table th {
    background-color: #f2f2f2;
    font-weight: bold;
}

.cashout-history-table tbody tr:nth-child(even) {
    background-color: #f9f9f9;
}

.cashout-history-table .badge {
    padding: 5px 8px;
    border-radius: 12px;
    font-size: 0.85em;
    color: white;
    display: inline-block;
}

.badge-pending { background-color: #ffc107; color: #333; } /* Warning yellow */
.badge-processed { background-color: #17a2b8; } /* Info blue */
.badge-paid { background-color: #28a745; } /* Success green */
.badge-revoked { background-color: #dc3545; } /* Danger red */

</style>

<script>
function showPaymentFields() {
    const methodSelect = document.getElementById('payment_method');
    const paypalFields = document.getElementById('paypal_fields');
    const bankFields = document.getElementById('bank_fields');
    const usdtFields = document.getElementById('usdt_fields');

    // Get input elements within each field group
    const paypalEmailInput = document.getElementById('paypal_email');
    const bankNameInput = document.getElementById('bank_name');
    const accountNameInput = document.getElementById('account_name');
    const accountNumberInput = document.getElementById('account_number');
    const usdtWalletInput = document.getElementById('usdt_wallet_input');

    // Hide all fields and remove 'required'
    paypalFields.style.display = 'none';
    bankFields.style.display = 'none';
    usdtFields.style.display = 'none';

    paypalEmailInput.removeAttribute('required');
    bankNameInput.removeAttribute('required');
    accountNameInput.removeAttribute('required');
    accountNumberInput.removeAttribute('required');
    usdtWalletInput.removeAttribute('required');

    const selectedOption = methodSelect.options[methodSelect.selectedIndex];
    const selectedMethod = selectedOption.value;

    // Show relevant fields and set 'required' based on selection
    if (selectedMethod === 'paypal') {
        paypalFields.style.display = 'flex';
        paypalEmailInput.setAttribute('required', 'required');
        paypalEmailInput.value = selectedOption.getAttribute('data-paypal-email');
    } else if (selectedMethod === 'bank') {
        bankFields.style.display = 'flex';
        bankNameInput.setAttribute('required', 'required');
        accountNameInput.setAttribute('required', 'required');
        accountNumberInput.setAttribute('required', 'required');
        bankNameInput.value = selectedOption.getAttribute('data-bank-name');
        accountNameInput.value = selectedOption.getAttribute('data-account-name');
        accountNumberInput.value = selectedOption.getAttribute('data-account-number');
    } else if (selectedMethod === 'usdt') {
        usdtFields.style.display = 'flex';
        usdtWalletInput.setAttribute('required', 'required');
        usdtWalletInput.value = selectedOption.getAttribute('data-usdt-wallet');
    }
}

// Call on page load to set initial state if a method is pre-selected (though not in this current example)
document.addEventListener('DOMContentLoaded', showPaymentFields);
</script>

<?php
require_once 'includes/footer.php';
?>