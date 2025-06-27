<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];
error_reporting(E_ALL);
ini_set('display_errors', 0); // Keep display_errors off in production

echo '<link rel="stylesheet" href="assets/css/settings.css">';

$error = '';
$success = '';

// Debug: Show current user ID
error_log("Current User ID: $user_id");

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_profile'])) {
            // Debug: Show POST data
            error_log("Profile Update POST: " . print_r($_POST, true));
           
            // Profile update logic
            $profile_data = [
                'user_id' => $user_id,
                'company_name' => $_POST['company_name'] ?? '',
                'industry' => $_POST['industry'] ?? '',
                'company_size' => $_POST['company_size'] ?? '',
                'company_info' => $_POST['company_info'] ?? '',
                'full_name' => $_POST['full_name'] ?? '',
                'alert_is_email' => isset($_POST['alert_is_email']) ? 1 : 0,
                'alert_is_sms' => isset($_POST['alert_is_sms']) ? 1 : 0,
                'phone_number' => $_POST['phone_number'] ?? '',
                'webhook_slack' => $_POST['webhook_slack'] ?? '',
                'webhook_teams' => $_POST['webhook_teams'] ?? '',
                'webhook_discord' => $_POST['webhook_discord'] ?? ''
            ];

            // Debug: Show prepared data
            error_log("Profile Data: " . print_r($profile_data, true));

            $stmt = $pdo->prepare("
                INSERT INTO user_profiles
                (user_id, company_name, industry, company_size, company_info, full_name,
                 alert_is_email, alert_is_sms, phone_number, webhook_slack, webhook_teams, webhook_discord)
                VALUES (:user_id, :company_name, :industry, :company_size, :company_info, :full_name,
                         :alert_is_email, :alert_is_sms, :phone_number, :webhook_slack, :webhook_teams, :webhook_discord)
                ON DUPLICATE KEY UPDATE
                company_name = VALUES(company_name),
                industry = VALUES(industry),
                company_size = VALUES(company_size),
                company_info = VALUES(company_info),
                full_name = VALUES(full_name),
                alert_is_email = VALUES(alert_is_email),
                alert_is_sms = VALUES(alert_is_sms),
                phone_number = VALUES(phone_number),
                webhook_slack = VALUES(webhook_slack),
                webhook_teams = VALUES(webhook_teams),
                webhook_discord = VALUES(webhook_discord)
            ");
           
            if (!$stmt->execute($profile_data)) {
                $errorInfo = $stmt->errorInfo();
                throw new PDOException("Profile update failed: " . $errorInfo[2]);
            }
           
            $success = "Profile updated successfully!";
           
        } elseif (isset($_POST['update_password'])) {
            // Password update logic
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
           
            if ($new_password !== $confirm_password) {
                $error = "New passwords don't match!";
            } else {
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user_auth = $stmt->fetch(); // Renamed to avoid conflict with $user below
               
                if (password_verify($current_password, $user_auth['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $user_id]);
                    $success = "Password updated successfully!";
                } else {
                    $error = "Current password is incorrect!";
                }
            }
           
        } elseif (isset($_POST['generate_api_key'])) {
            // Check if user already has an API key
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_api_keys WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $count = $stmt->fetchColumn();
           
            if ($count > 0) {
                $error = "You already have an API key. Delete it first to generate a new one.";
            } else {
                $api_key = bin2hex(random_bytes(32));
                $stmt = $pdo->prepare("INSERT INTO user_api_keys (user_id, api_key) VALUES (?, ?)");
                $stmt->execute([$user_id, $api_key]);
                $success = "API key generated successfully!";
            }
           
        } elseif (isset($_POST['delete_api_key'])) {
            $stmt = $pdo->prepare("DELETE FROM user_api_keys WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $success = "API key deleted successfully!";
           
        } elseif (isset($_POST['update_tracking_method'])) {
            $tracking_method = $_POST['tracking_method'];
            $stmt = $pdo->prepare("UPDATE user_api_keys SET tracking_method = ? WHERE user_id = ?");
            $stmt->execute([$tracking_method, $user_id]);
            $success = "Tracking method updated successfully!";
           
        } elseif (isset($_POST['update_threshold'])) {
            $threshold = (float)$_POST['threshold'];
            $stream_id = !empty($_POST['stream_id']) ? (int)$_POST['stream_id'] : null;
           
            // Debug: Show threshold data
            error_log("Threshold Update - Value: $threshold, Stream ID: " . ($stream_id ?? 'null'));

            $stmt = $pdo->prepare("
                INSERT INTO user_alert_thresholds (user_id, stream_id, threshold)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE threshold = VALUES(threshold)
            ");
           
            if (!$stmt->execute([$user_id, $stream_id, $threshold])) {
                $errorInfo = $stmt->errorInfo();
                throw new PDOException("Threshold update failed: " . $errorInfo[2]);
            }
           
            $success = "Alert threshold updated successfully!";
        } elseif (isset($_POST['update_payment_methods'])) { // NEW LOGIC FOR PAYMENT METHODS
            $method = $_POST['method_type'];
            $paypal_email = null;
            $bank_name = null;
            $account_name = null;
            $account_number = null;
            $routing_number = null;
            $usdt_wallet = null;

            switch ($method) {
                case 'paypal':
                    $paypal_email = trim($_POST['paypal_email'] ?? '');
                    if (empty($paypal_email)) {
                        $error = "PayPal email cannot be empty.";
                    }
                    break;
                case 'bank':
                    $bank_name = trim($_POST['bank_name'] ?? '');
                    $account_name = trim($_POST['account_name'] ?? '');
                    $account_number = trim($_POST['account_number'] ?? '');
                    $routing_number = trim($_POST['routing_number'] ?? ''); // Added routing number
                    if (empty($bank_name) || empty($account_name) || empty($account_number)) {
                        $error = "Bank name, account name, and account number cannot be empty.";
                    }
                    break;
                case 'usdt':
                    $usdt_wallet = trim($_POST['usdt_wallet'] ?? '');
                    if (empty($usdt_wallet)) {
                        $error = "USDT wallet address cannot be empty.";
                    }
                    break;
                default:
                    $error = "Invalid payment method selected.";
            }

            if (empty($error)) {
                // Use REPLACE INTO to insert or update the record based on user_id
                // Since user_id is PRIMARY KEY, this will update if exists, insert if not.
                $stmt = $pdo->prepare("
                    REPLACE INTO user_payment_methods
                    (user_id, method, paypal_email, bank_name, account_name, account_number, routing_number, usdt_wallet)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([
                    $user_id, $method, $paypal_email, $bank_name, $account_name, $account_number, $routing_number, $usdt_wallet
                ])) {
                    $success = "Payment methods updated successfully!";
                } else {
                    $errorInfo = $stmt->errorInfo();
                    throw new PDOException("Payment method update failed: " . $errorInfo[2]);
                }
            }
        }
       
        // Handle profile picture upload
        if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'auth/uploads/';
            $file_ext = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];
           
            if (in_array($file_ext, $allowed_ext)) {
                $file_name = 'profile_' . $user_id . '.' . $file_ext;
                $file_path = $upload_dir . $file_name;
               
                if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $file_path)) {
                    $stmt = $pdo->prepare("UPDATE user_profiles SET profile_pic = ? WHERE user_id = ?");
                    $stmt->execute([$file_path, $user_id]);
                    $success = $success ? $success . " Profile picture updated!" : "Profile picture updated!";
                } else {
                    $error = $error ? $error . " Failed to upload profile picture." : "Failed to upload profile picture.";
                }
            } else {
                $error = $error ? $error . " Invalid file type for profile picture." : "Invalid file type for profile picture.";
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
        error_log("Database Error: " . $e->getMessage());
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
        error_log("General Error: " . $e->getMessage());
    }
}

// Get current user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
if (!$stmt->execute([$user_id])) {
    $errorInfo = $stmt->errorInfo();
    throw new PDOException("User data fetch failed: " . $errorInfo[2]);
}
$user = $stmt->fetch();

// Get user profile data
$stmt = $pdo->prepare("SELECT * FROM user_profiles WHERE user_id = ?");
if (!$stmt->execute([$user_id])) {
    $errorInfo = $stmt->errorInfo();
    throw new PDOException("Profile data fetch failed: " . $errorInfo[2]);
}
$profile = $stmt->fetch() ?: [];

// Get API key if exists
$stmt = $pdo->prepare("SELECT * FROM user_api_keys WHERE user_id = ?");
if (!$stmt->execute([$user_id])) {
    $errorInfo = $stmt->errorInfo();
    throw new PDOException("API key fetch failed: " . $errorInfo[2]);
}
$api_key_data = $stmt->fetch();

// Get API usage data (last 7 days)
$api_usage = [];
if ($api_key_data) {
    $stmt = $pdo->prepare("
        SELECT DATE(used_at) as date, COUNT(*) as count
        FROM api_usage_log
        WHERE api_key = ? AND used_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(used_at)
        ORDER BY date ASC
    ");
    if (!$stmt->execute([$api_key_data['api_key']])) {
        $errorInfo = $stmt->errorInfo();
        throw new PDOException("API usage fetch failed: " . $errorInfo[2]);
    }
    $api_usage = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Get threshold data
$global_threshold = ['threshold' => 50];
$streams = []; // Initialize $streams to prevent undefined variable error
$threshold_history = [];

try {
    // Get global threshold
    $stmt = $pdo->prepare("
        SELECT threshold
        FROM user_alert_thresholds
        WHERE user_id = ? AND stream_id IS NULL
        ORDER BY created_at DESC LIMIT 1
    ");
    if ($stmt->execute([$user_id])) {
        $global_threshold = $stmt->fetch() ?: ['threshold' => 50];
    }

    // Get streams
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE user_id = ?");
    if ($stmt->execute([$user_id])) {
        $streams = $stmt->fetchAll();
    }

    // Get threshold history
    $stmt = $pdo->prepare("
        SELECT t.created_at, s.name as stream_name, t.threshold
        FROM user_alert_thresholds t
        LEFT JOIN streams s ON t.stream_id = s.id
        WHERE t.user_id = ?
        ORDER BY t.created_at DESC
        LIMIT 10
    ");
    if ($stmt->execute([$user_id])) {
        $threshold_history = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log("Threshold data error: " . $e->getMessage());
    $error = "Error loading threshold data: " . $e->getMessage();
}

// Fetch user's existing payment methods for the form
$stmt = $pdo->prepare("SELECT * FROM user_payment_methods WHERE user_id = ?");
$stmt->execute([$user_id]);
$current_payment_methods = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

?>

<div class="settings-container">
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php unset($error); ?>
    <?php endif; ?>
   
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php unset($success); ?>
    <?php endif; ?>
   
    <div class="settings-tabs">
        <button class="tab-btn active" data-tab="profile">Profile</button>
        <button class="tab-btn" data-tab="security">Security</button>
        <button class="tab-btn" data-tab="api">API</button>
        <button class="tab-btn" data-tab="usage">API Usage</button>
        <button class="tab-btn" data-tab="threshold">Alert Threshold</button>
        <button class="tab-btn" data-tab="payment">Payment Methods</button> </div>
   

    <div class="tab-content active" id="profile-tab">
        <form method="POST" enctype="multipart/form-data">
            <div class="profile-pic-container">
                <img src="<?= htmlspecialchars($profile['profile_pic'] ?? 'assets/images/default-profile.png') ?>"
                     class="profile-pic" id="profile-pic-preview">
                <div class="profile-pic-upload">
                    <input type="file" name="profile_pic" id="profile-pic-input" accept="image/*">
                    <small class="notice">Max 2MB. JPG, PNG or GIF.</small>
                </div>
            </div>
           
            <div class="form-row">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" value="<?= htmlspecialchars($user['username']) ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>">
                </div>
            </div>
           
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="full_name" value="<?= htmlspecialchars($profile['full_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text" name="phone_number" value="<?= htmlspecialchars($profile['phone_number'] ?? '') ?>">
                </div>
            </div>
           
            <div class="form-row">
                <div class="form-group">
                    <label>Company Name</label>
                    <input type="text" name="company_name" value="<?= htmlspecialchars($profile['company_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Industry</label>
                    <input type="text" name="industry" value="<?= htmlspecialchars($profile['industry'] ?? '') ?>">
                </div>
            </div>
           
            <div class="form-group">
                <label>Company Size</label>
                <select name="company_size">
                    <option value="">Select...</option>
                    <option value="1-10" <?= ($profile['company_size'] ?? '') === '1-10' ? 'selected' : '' ?>>1-10 employees</option>
                    <option value="11-50" <?= ($profile['company_size'] ?? '') === '11-50' ? 'selected' : '' ?>>11-50 employees</option>
                    <option value="51-200" <?= ($profile['company_size'] ?? '') === '51-200' ? 'selected' : '' ?>>51-200 employees</option>
                    <option value="201-500" <?= ($profile['company_size'] ?? '') === '201-500' ? 'selected' : '' ?>>201-500 employees</option>
                    <option value="501+" <?= ($profile['company_size'] ?? '') === '501+' ? 'selected' : '' ?>>501+ employees</option>
                </select>
            </div>
           
            <div class="form-group">
                <label>Company Info/About</label>
                <textarea name="company_info" rows="4"><?= htmlspecialchars($profile['company_info'] ?? '') ?></textarea>
            </div>
           
            <div class="form-group">
                <label>Notification Preferences</label>
                <div class="notification-preferences">
                    <label class="notification-option">
                        <input type="checkbox" name="alert_is_email" <?= ($profile['alert_is_email'] ?? 1) ? 'checked' : '' ?>>
                        <span>Email Alerts</span>
                    </label>
                    <label class="notification-option">
                        <input type="checkbox" name="alert_is_sms" <?= ($profile['alert_is_sms'] ?? 0) ? 'checked' : '' ?>>
                        <span>SMS Alerts</span>
                    </label>
                </div>
            </div>
           
            <div class="form-group">
                <label>Webhook Integrations</label>
                <div class="webhook-integrations">
                    <div class="webhook-integration <?= !empty($profile['webhook_slack']) ? 'active' : '' ?>">
                        <h4>Slack</h4>
                        <div class="form-group">
                            <input type="text" name="webhook_slack"
                                   value="<?= htmlspecialchars($profile['webhook_slack'] ?? '') ?>"
                                   placeholder="https://hooks.slack.com/services/...">
                        </div>
                    </div>
                   
                    <div class="webhook-integration <?= !empty($profile['webhook_teams']) ? 'active' : '' ?>">
                        <h4>Microsoft Teams</h4>
                        <div class="form-group">
                            <input type="text" name="webhook_teams"
                                   value="<?= htmlspecialchars($profile['webhook_teams'] ?? '') ?>"
                                   placeholder="https://outlook.office.com/webhook/...">
                        </div>
                    </div>
                   
                    <div class="webhook-integration <?= !empty($profile['webhook_discord']) ? 'active' : '' ?>">
                        <h4>Discord</h4>
                        <div class="form-group">
                            <input type="text" name="webhook_discord"
                                   value="<?= htmlspecialchars($profile['webhook_discord'] ?? '') ?>"
                                   placeholder="https://discord.com/api/webhooks/...">
                        </div>
                    </div>
                </div>
            </div>
           
            <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
        </form>
    </div>

    <div class="tab-content" id="security-tab">
        <form method="POST">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" required>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" required>
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" required>
            </div>
            <button type="submit" name="update_password" class="btn btn-primary">Update Password</button>
        </form>
    </div>

    <div class="tab-content" id="api-tab">
        <div class="api-key-container">
            <?php if ($api_key_data): ?>
                <div class="form-group">
                    <label>Tracking Method</label>
                    <form method="POST" class="tracking-method-selector">
                        <label class="tracking-method-option <?= $api_key_data['tracking_method'] === 'gdpr' ? 'selected' : '' ?>">
                            <input type="radio" name="tracking_method" value="gdpr" <?= $api_key_data['tracking_method'] === 'gdpr' ? 'checked' : '' ?>>
                            GDPR/CCPA Tracking
                            <small class="notice">Requires cookie consent</small>
                        </label>
                        <label class="tracking-method-option <?= $api_key_data['tracking_method'] === 'non_gdpr' ? 'selected' : '' ?>">
                            <input type="radio" name="tracking_method" value="non_gdpr" <?= $api_key_data['tracking_method'] === 'non_gdpr' ? 'checked' : '' ?>>
                            Non-GDPR Tracking
                            <small class="notice">No cookie consent needed</small>
                        </label>
                        <label class="tracking-method-option <?= $api_key_data['tracking_method'] === 'hybrid' ? 'selected' : '' ?>">
                            <input type="radio" name="tracking_method" value="hybrid" <?= $api_key_data['tracking_method'] === 'hybrid' ? 'checked' : '' ?>>
                            Hybrid Tracking
                            <small class="notice">Fallback to non-GDPR if no consent</small>
                        </label>
                        <button type="submit" name="update_tracking_method" class="btn btn-primary">Update Method</button>
                    </form>
                </div>
               
                <div class="form-group">
                    <label>Your API Key</label>
                    <div class="api-key-display">
                        <div class="api-key-value">
                            <span class="api-key-masked">••••••••••••••••••••••••••••••••</span>
                            <span class="api-key-full" style="display:none"><?= htmlspecialchars($api_key_data['api_key']) ?></span>
                        </div>
                        <div class="api-key-actions">
                            <button type="button" class="btn btn-secondary" id="toggle-api-key">Show</button>
                            <button type="button" class="btn btn-secondary" id="copy-api-key">Copy</button>
                        </div>
                    </div>
                    <small class="notice">Keep this key secret. It provides full access to your account.</small>
                </div>
               
                <form method="POST">
                    <button type="submit" name="delete_api_key" class="btn btn-danger">Delete API Key</button>
                </form>
            <?php else: ?>
                <div class="form-group">
                    <p>You don't have an API key yet. Generate one to start using our API.</p>
                    <form method="POST">
                        <button type="submit" name="generate_api_key" class="btn btn-primary">Generate API Key</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="tab-content" id="usage-tab">
        <div class="form-group">
            <label>API Usage Statistics</label>
            <div class="date-range-selector">
                <input type="date" id="start-date" value="<?= date('Y-m-d', strtotime('-7 days')) ?>">
                <span>to</span>
                <input type="date" id="end-date" value="<?= date('Y-m-d') ?>">
                <button class="btn btn-secondary" id="update-chart">Update</button>
            </div>
            <div class="api-usage-chart" id="api-usage-chart">
                <?php if (empty($api_usage)): ?>
                    <p>No API usage data available.</p>
                <?php else: ?>
                    <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-content" id="threshold-tab">
        <div class="form-group">
            <h3>Global Alert Threshold</h3>
            <form method="POST">
                <div class="threshold-control">
                    <input type="range" name="threshold" min="0" max="100"
                           value="<?= htmlspecialchars($global_threshold['threshold']) ?>"
                           class="threshold-slider" id="globalThreshold">
                    <span class="threshold-value"><?= htmlspecialchars($global_threshold['threshold']) ?>%</span>
                </div>
                <input type="hidden" name="stream_id" value="">
                <button type="submit" name="update_threshold" class="btn btn-primary">Save Global Threshold</button>
            </form>
        </div>

        <?php if (!empty($streams)): ?>
            <div class="form-group">
                <h3>Stream-Specific Thresholds</h3>
                <?php foreach ($streams as $stream):
                    $stmt = $pdo->prepare("
                        SELECT threshold
                        FROM user_alert_thresholds
                        WHERE user_id = ? AND stream_id = ?
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $stmt->execute([$user_id, $stream['id']]);
                    $stream_threshold = $stmt->fetch();
                ?>
                    <form method="POST" class="stream-threshold-form">
                        <h4><?= htmlspecialchars($stream['name']) ?></h4>
                        <div class="threshold-control">
                            <input type="range" name="threshold" min="0" max="100"
                                   value="<?= htmlspecialchars($stream_threshold['threshold'] ?? 50) ?>"
                                   class="threshold-slider"
                                   id="streamThreshold<?= $stream['id'] ?>">
                            <span class="threshold-value"><?= htmlspecialchars($stream_threshold['threshold'] ?? 50) ?>%</span>
                        </div>
                        <input type="hidden" name="stream_id" value="<?= $stream['id'] ?>">
                        <button type="submit" name="update_threshold" class="btn btn-secondary">Save</button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="threshold-history">
            <h3>Threshold History</h3>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Stream</th>
                        <th>Threshold</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($threshold_history as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars(date('M j, Y', strtotime($record['created_at']))) ?></td>
                            <td><?= $record['stream_name'] ? htmlspecialchars($record['stream_name']) : 'Global' ?></td>
                            <td><?= htmlspecialchars($record['threshold']) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="tab-content" id="payment-tab">
        <h3>Affiliate Payment Methods</h3>
        <form method="POST">
            <div class="form-group">
                <label for="method_type">Select Payment Method</label>
                <select name="method_type" id="method_type" onchange="togglePaymentFields()">
                    <option value="">-- Select --</option>
                    <option value="paypal" <?= ($current_payment_methods['method'] ?? '') === 'paypal' ? 'selected' : '' ?>>PayPal</option>
                    <option value="bank" <?= ($current_payment_methods['method'] ?? '') === 'bank' ? 'selected' : '' ?>>Bank Transfer</option>
                    <option value="usdt" <?= ($current_payment_methods['method'] ?? '') === 'usdt' ? 'selected' : '' ?>>USDT Wallet</option>
                </select>
            </div>

            <div id="paypal_fields_settings" class="payment-field-group" style="display: none;">
                <div class="form-group">
                    <label for="paypal_email">PayPal Email</label>
                    <input type="email" id="paypal_email" name="paypal_email"
                           value="<?= htmlspecialchars($current_payment_methods['paypal_email'] ?? '') ?>"
                           placeholder="your.email@example.com">
                </div>
            </div>

            <div id="bank_fields_settings" class="payment-field-group" style="display: none;">
                <div class="form-group">
                    <label for="bank_name">Bank Name</label>
                    <input type="text" id="bank_name" name="bank_name"
                           value="<?= htmlspecialchars($current_payment_methods['bank_name'] ?? '') ?>"
                           placeholder="e.g., First Bank, Chase Bank">
                </div>
                <div class="form-group">
                    <label for="account_name">Account Name</label>
                    <input type="text" id="account_name" name="account_name"
                           value="<?= htmlspecialchars($current_payment_methods['account_name'] ?? '') ?>"
                           placeholder="Your Name / Company Name">
                </div>
                <div class="form-group">
                    <label for="account_number">Account Number</label>
                    <input type="text" id="account_number" name="account_number"
                           value="<?= htmlspecialchars($current_payment_methods['account_number'] ?? '') ?>"
                           placeholder="Account Number">
                </div>
                 <div class="form-group">
                    <label for="routing_number">Routing Number (Optional)</label>
                    <input type="text" id="routing_number" name="routing_number"
                           value="<?= htmlspecialchars($current_payment_methods['routing_number'] ?? '') ?>"
                           placeholder="Routing Number (e.g., ABA/SWIFT)">
                </div>
            </div>

            <div id="usdt_fields_settings" class="payment-field-group" style="display: none;">
                <div class="form-group">
                    <label for="usdt_wallet">USDT Wallet Address (TRC20 preferred)</label>
                    <input type="text" id="usdt_wallet" name="usdt_wallet"
                           value="<?= htmlspecialchars($current_payment_methods['usdt_wallet'] ?? '') ?>"
                           placeholder="Your USDT TRC20 wallet address">
                </div>
            </div>

            <button type="submit" name="update_payment_methods" class="btn btn-primary">Save Payment Methods</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
           
            btn.classList.add('active');
            const tabId = btn.getAttribute('data-tab');
            document.getElementById(`${tabId}-tab`).classList.add('active');

            // Special handling for payment tab to toggle fields on tab switch
            if (tabId === 'payment') {
                togglePaymentFields();
            }
        });
    });

    // Profile picture preview
    document.getElementById('profile-pic-input')?.addEventListener('change', function(e) {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('profile-pic-preview').src = e.target.result;
            }
            reader.readAsDataURL(this.files[0]);
        }
    });

    // API key toggle visibility
    document.getElementById('toggle-api-key')?.addEventListener('click', function() {
        const masked = document.querySelector('.api-key-masked');
        const full = document.querySelector('.api-key-full');
        const btn = document.getElementById('toggle-api-key');
       
        if (masked.style.display === 'none') {
            masked.style.display = 'inline';
            full.style.display = 'none';
            btn.textContent = 'Show';
        } else {
            masked.style.display = 'none';
            full.style.display = 'inline';
            btn.textContent = 'Hide';
        }
    });

    // API key copy
    document.getElementById('copy-api-key')?.addEventListener('click', function() {
        const apiKey = document.querySelector('.api-key-full').textContent;
        navigator.clipboard.writeText(apiKey).then(() => {
            alert('API key copied to clipboard!');
        });
    });

    // API usage chart
    function renderChart(data) {
        const ctx = document.createElement('canvas');
        document.getElementById('api-usage-chart').innerHTML = '';
        document.getElementById('api-usage-chart').appendChild(ctx);
       
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: Object.keys(data),
                datasets: [{
                    label: 'API Calls',
                    data: Object.values(data),
                    borderColor: '#3ac3b8',
                    backgroundColor: 'rgba(58, 195, 184, 0.1)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });
    }

    // Initial chart render if data exists
    <?php if (!empty($api_usage)): ?>
        renderChart(<?= json_encode($api_usage) ?>);
    <?php endif; ?>

    // Update chart on date range change
    document.getElementById('update-chart')?.addEventListener('click', function() {
        const startDate = document.getElementById('start-date').value;
        const endDate = document.getElementById('end-date').value;
       
        // In a real implementation, you would fetch new data from the server here
        // For now, we'll just show a message
        alert('In a real implementation, this would fetch data for ' + startDate + ' to ' + endDate);
    });

    // Threshold sliders
    document.querySelectorAll('.threshold-slider').forEach(slider => {
        slider.addEventListener('input', function() {
            this.closest('.threshold-control').querySelector('.threshold-value').textContent = this.value + '%';
        });
    });

    // NEW JavaScript for Payment Methods Tab
    function togglePaymentFields() {
        const methodType = document.getElementById('method_type').value;
        const paypalFields = document.getElementById('paypal_fields_settings');
        const bankFields = document.getElementById('bank_fields_settings');
        const usdtFields = document.getElementById('usdt_fields_settings');

        // Hide all fields first
        paypalFields.style.display = 'none';
        bankFields.style.display = 'none';
        usdtFields.style.display = 'none';

        // Remove 'required' from all input fields within these groups
        paypalFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
        bankFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));
        usdtFields.querySelectorAll('input').forEach(input => input.removeAttribute('required'));

        // Show relevant fields and add 'required'
        if (methodType === 'paypal') {
            paypalFields.style.display = 'block'; // Use block for form-group styling
            paypalFields.querySelector('#paypal_email').setAttribute('required', 'required');
        } else if (methodType === 'bank') {
            bankFields.style.display = 'block';
            bankFields.querySelector('#bank_name').setAttribute('required', 'required');
            bankFields.querySelector('#account_name').setAttribute('required', 'required');
            bankFields.querySelector('#account_number').setAttribute('required', 'required');
            // routing_number is optional, so no 'required'
        } else if (methodType === 'usdt') {
            usdtFields.style.display = 'block';
            usdtFields.querySelector('#usdt_wallet').setAttribute('required', 'required');
        }
    }

    // Call togglePaymentFields on page load to set initial state based on fetched data
    document.addEventListener('DOMContentLoaded', togglePaymentFields);

    // This handles keeping the correct tab active on page load if a form was submitted
    // and there were errors/successes, redirecting back to the correct tab.
    document.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab');
        if (tab) {
            const activeBtn = document.querySelector(`.tab-btn[data-tab="${tab}"]`);
            if (activeBtn) {
                activeBtn.click(); // Simulate click to activate tab
            }
        }
    });

</script>

<?php
require_once 'includes/footer.php';
?>