<?php
// MUST BE AT THE VERY TOP - NO WHITESPACE BEFORE THIS
session_start(); // Start session first

// Enable error reporting for debugging, but disable display in production
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check login status BEFORE including any files that might output content
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Helper functions to get user data
function get_user_streams($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_cohorts($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM cohorts WHERE stream_id IN (SELECT id FROM streams WHERE user_id = ?)");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_templates($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, name FROM email_templates WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_contacts($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT c.id, c.email, c.username, s.name as stream_name
                            FROM contacts c
                            JOIN streams s ON c.stream_id = s.id
                            WHERE s.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_user_competitors($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT c.id, c.name, c.url, s.name as stream_name
                            FROM competitors c
                            JOIN streams s ON c.stream_id = s.id
                            WHERE s.user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Initialize empty automation data structure
$edit_automation = [
    'id' => null,
    'name' => '',
    'description' => '',
    'is_active' => 0,
    'source_type' => '',
    'source_config_json' => '{}',
    'condition_type' => '',
    'condition_config_json' => '{}',
    'action_type' => '',
    'action_config_json' => '{}'
];

// Handle edit request
if (isset($_GET['edit'])) {
    $workflow_id = intval($_GET['edit']);
    $stmt = $pdo->prepare("SELECT * FROM automation_workflows WHERE id = ? AND user_id = ?");
    $stmt->execute([$workflow_id, $user_id]);
    $edit_automation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$edit_automation) {
        $_SESSION['error'] = "Automation not found or you don't have permission to access it";
        header("Location: view_automations.php");
        exit;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Basic validation
    $workflow_id = isset($_POST['workflow_id']) ? intval($_POST['workflow_id']) : null;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $source_type = trim($_POST['source_type'] ?? '');
    $condition_type = trim($_POST['condition_type'] ?? '');
    $action_type = trim($_POST['action_type'] ?? '');

    // Validate required fields
    $errors = [];
    if (empty($name)) $errors[] = "Automation name is required";
    if (empty($source_type)) $errors[] = "Source type is required";
    if (empty($condition_type)) $errors[] = "Condition type is required";
    if (empty($action_type)) $errors[] = "Action type is required";

    // --- Build source_config_json in PHP ---
    $source_config = [];
    if ($source_type === 'stream') {
        $source_config['stream_id'] = $_POST['source_stream_id'] ?? null;
        if (empty($source_config['stream_id'])) $errors[] = "Stream must be selected for Stream source type";
    } elseif ($source_type === 'cohort') {
        $source_config['cohort_id'] = $_POST['source_cohort_id'] ?? null;
        if (empty($source_config['cohort_id'])) $errors[] = "Cohort must be selected for Cohort source type";
    } elseif ($source_type === 'contact') {
        $source_config['contact_id'] = $_POST['source_contact_id'] ?? [];
        if (empty($source_config['contact_id'])) $errors[] = "At least one contact must be selected for Specific Contact(s) source type";
    }
    $source_config_json = json_encode($source_config);

    // --- Build condition_config_json in PHP ---
    $condition_config = [];
    if ($condition_type === 'churn_probability') {
        $condition_config['operator'] = $_POST['condition_churn_operator'] ?? null;
        $condition_config['value'] = $_POST['condition_churn_value'] ?? null;
        if (empty($condition_config['operator']) || $condition_config['value'] === null || $condition_config['value'] < 0 || $condition_config['value'] > 100) {
            $errors[] = "Churn probability operator and value (0-100) are required";
        }
    } elseif ($condition_type === 'competitor_visit') {
        $condition_config['competitor_id'] = $_POST['condition_competitor_id'] ?? null;
        $condition_config['timeframe_days'] = $_POST['condition_competitor_timeframe'] ?? null;
        if (empty($condition_config['competitor_id']) || empty($condition_config['timeframe_days']) || $condition_config['timeframe_days'] < 1) {
            $errors[] = "Competitor and timeframe days (min 1) are required for Competitor Visit condition";
        }
    } elseif ($condition_type === 'feature_usage') {
        $condition_config['feature_name'] = trim($_POST['condition_feature_name'] ?? '');
        $condition_config['min_usage_count'] = $_POST['condition_min_usage_count'] ?? null;
        if (empty($condition_config['feature_name']) || empty($condition_config['min_usage_count']) || $condition_config['min_usage_count'] < 1) {
            $errors[] = "Feature name and minimum usage count (min 1) are required for Feature Usage condition";
        }
    } elseif ($condition_type === 'last_login') {
        $condition_config['days_since_login'] = $_POST['condition_days_since_login'] ?? null;
        if (empty($condition_config['days_since_login']) || $condition_config['days_since_login'] < 1) {
            $errors[] = "Days since last login (min 1) is required for Last Login condition";
        }
    }
    $condition_config_json = json_encode($condition_config);

    // --- Build action_config_json in PHP ---
    $action_config = [];
    if ($action_type === 'email') {
        $action_config['template_id'] = $_POST['action_email_template_id'] ?? null;
        if (empty($action_config['template_id'])) $errors[] = "Email template is required for Send Email action";
    } elseif ($action_type === 'sms') {
        $action_config['phone_field'] = trim($_POST['action_sms_phone_field'] ?? '');
        $action_config['sms_message'] = trim($_POST['action_sms_message'] ?? '');
        if (empty($action_config['phone_field']) || empty($action_config['sms_message'])) {
            $errors[] = "Phone field and SMS message are required for Send SMS action";
        }
    } elseif ($action_type === 'change_cohort') {
        $action_config['cohort_id'] = $_POST['action_change_cohort_id'] ?? null;
        if (empty($action_config['cohort_id'])) $errors[] = "New cohort is required for Change Cohort action";
    } elseif ($action_type === 'notification') {
        $action_config['notification_message'] = trim($_POST['action_notification_message'] ?? '');
        if (empty($action_config['notification_message'])) $errors[] = "Notification message is required for Notification action";
    } elseif ($action_type === 'external') {
        $action_config['service_name'] = $_POST['action_external_service'] ?? null;
        if (empty($action_config['service_name'])) {
            $errors[] = "External service name is required for External action";
        } elseif ($action_config['service_name'] === 'zapier') {
            $action_config['zapier_event_name'] = trim($_POST['action_zapier_event'] ?? '');
            $action_config['zapier_payload_template'] = trim($_POST['action_zapier_payload'] ?? '');
            if (empty($action_config['zapier_event_name']) || empty($action_config['zapier_payload_template'])) {
                $errors[] = "Zapier event name and payload template are required for Zapier service";
            }
        }
    }
    $action_config_json = json_encode($action_config);

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        $redirect_url = $workflow_id ? "automations.php?edit=$workflow_id" : "automations.php?create";
        header("Location: $redirect_url");
        exit;
    }

    try {
        if (isset($_POST['create_automation'])) {
            $stmt = $pdo->prepare("INSERT INTO automation_workflows
                (user_id, name, description, is_active, source_type, source_config_json,
                condition_type, condition_config_json, action_type, action_config_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $user_id, $name, $description, $is_active,
                $source_type, $source_config_json,
                $condition_type, $condition_config_json,
                $action_type, $action_config_json
            ]);
            $_SESSION['success'] = "Automation created successfully!";
        }
        elseif (isset($_POST['save_automation'])) {
            $stmt = $pdo->prepare("UPDATE automation_workflows SET
                name = ?, description = ?, is_active = ?,
                source_type = ?, source_config_json = ?,
                condition_type = ?, condition_config_json = ?,
                action_type = ?, action_config_json = ?,
                updated_at = NOW()
                WHERE id = ? AND user_id = ?");
            $stmt->execute([
                $name, $description, $is_active,
                $source_type, $source_config_json,
                $condition_type, $condition_config_json,
                $action_type, $action_config_json,
                $workflow_id, $user_id
            ]);
            $_SESSION['success'] = "Automation updated successfully!";
        }
        elseif (isset($_POST['delete_automation'])) {
            $stmt = $pdo->prepare("DELETE FROM automation_workflows WHERE id = ? AND user_id = ?");
            $stmt->execute([$workflow_id, $user_id]);
            $_SESSION['success'] = "Automation deleted successfully!";
        }

        header("Location: view_automations.php");
        exit;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        $redirect_url = $workflow_id ? "automations.php?edit=$workflow_id" : "automations.php?create";
        header("Location: $redirect_url");
        exit;
    }
}

// Get data for dropdowns
$streams = get_user_streams($pdo, $user_id);
$cohorts = get_user_cohorts($pdo, $user_id);
$templates = get_user_templates($pdo, $user_id);
$contacts = get_user_contacts($pdo, $user_id);
$competitors = get_user_competitors($pdo, $user_id);

// Prepare data for JavaScript
$js_data = [
    'streams' => $streams,
    'cohorts' => $cohorts,
    'templates' => $templates,
    'contacts' => $contacts,
    'competitors' => $competitors,
];

require_once 'includes/header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $edit_automation['id'] ? 'Edit' : 'Create' ?> Automation</title>
    <link rel="stylesheet" href="assets/css/automations_builder.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
<div class="automations-container">
    <h1><?= $edit_automation['id'] ? 'Edit Automation' : 'Create a New Automation' ?></h1>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <form method="POST" id="automationForm">
        <?php if ($edit_automation['id']): ?>
            <input type="hidden" name="workflow_id" value="<?= $edit_automation['id'] ?>">
        <?php endif; ?>

        <div class="form-section">
            <h2>Automation Details</h2>
            <div class="form-group">
                <label for="name">Automation Name <span class="required">*</span></label>
                <input type="text" id="name" name="name" value="<?= htmlspecialchars($edit_automation['name']) ?>" required>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description"><?= htmlspecialchars($edit_automation['description']) ?></textarea>
            </div>
            <div class="form-group checkbox">
                <input type="checkbox" name="is_active" id="is_active" <?= $edit_automation['is_active'] ? 'checked' : '' ?>>
                <label for="is_active">Active</label>
            </div>
        </div>

        <div class="automation-stages">
            <div class="stage-block" id="sourceStage">
                <div class="stage-header">
                    <div class="icon-circle source-color">
                        <i class="fas fa-folder-open"></i>
                    </div>
                    <h3>1. Source</h3>
                </div>
                <div class="stage-content">
                    <div class="form-group">
                        <label for="source_type">Source Type <span class="required">*</span></label>
                        <select id="source_type" name="source_type" required>
                            <option value="">Select source type</option>
                            <option value="stream" <?= $edit_automation['source_type'] === 'stream' ? 'selected' : '' ?>>Stream</option>
                            <option value="cohort" <?= $edit_automation['source_type'] === 'cohort' ? 'selected' : '' ?>>Cohort</option>
                            <option value="contact" <?= $edit_automation['source_type'] === 'contact' ? 'selected' : '' ?>>Specific Contact(s)</option>
                        </select>
                    </div>
                    <div id="source_dynamic_fields" class="dynamic-fields"></div>
                </div>
            </div>

            <div class="stage-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>

            <div class="stage-block" id="conditionStage">
                <div class="stage-header">
                    <div class="icon-circle condition-color">
                        <i class="fas fa-filter"></i>
                    </div>
                    <h3>2. Condition</h3>
                </div>
                <div class="stage-content">
                    <div class="form-group">
                        <label for="condition_type">Condition Type <span class="required">*</span></label>
                        <select id="condition_type" name="condition_type" required>
                            <option value="">Select condition</option>
                            <option value="churn_probability" <?= $edit_automation['condition_type'] === 'churn_probability' ? 'selected' : '' ?>>Churn Probability</option>
                            <option value="competitor_visit" <?= $edit_automation['condition_type'] === 'competitor_visit' ? 'selected' : '' ?>>Visits Competitor Site</option>
                            <option value="feature_usage" <?= $edit_automation['condition_type'] === 'feature_usage' ? 'selected' : '' ?>>Feature Usage</option>
                            <option value="last_login" <?= $edit_automation['condition_type'] === 'last_login' ? 'selected' : '' ?>>Last Login Days</option>
                        </select>
                    </div>
                    <div id="condition_dynamic_fields" class="dynamic-fields"></div>
                </div>
            </div>

            <div class="stage-arrow">
                <i class="fas fa-arrow-right"></i>
            </div>

            <div class="stage-block" id="actionStage">
                <div class="stage-header">
                    <div class="icon-circle action-color">
                        <i class="fas fa-bolt"></i>
                    </div>
                    <h3>3. Action</h3>
                </div>
                <div class="stage-content">
                    <div class="form-group">
                        <label for="action_type">Action Type <span class="required">*</span></label>
                        <select id="action_type" name="action_type" required>
                            <option value="">Select action</option>
                            <option value="email" <?= $edit_automation['action_type'] === 'email' ? 'selected' : '' ?>>Send Email</option>
                            <option value="sms" <?= $edit_automation['action_type'] === 'sms' ? 'selected' : '' ?>>Send SMS</option>
                            <option value="change_cohort" <?= $edit_automation['action_type'] === 'change_cohort' ? 'selected' : '' ?>>Change Cohort</option>
                            <option value="notification" <?= $edit_automation['action_type'] === 'notification' ? 'selected' : '' ?>>Send Notification</option>
                            <option value="external" <?= $edit_automation['action_type'] === 'external' ? 'selected' : '' ?>>Trigger External (e.g., Zapier)</option>
                        </select>
                    </div>
                    <div id="action_dynamic_fields" class="dynamic-fields"></div>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <?php if ($edit_automation['id']): ?>
                <button type="submit" name="save_automation" id="saveAutomationBtn" class="btn btn-primary">Save Automation</button>
                <button type="submit" name="delete_automation" class="btn btn-danger" id="deleteAutomationBtn">Delete Automation</button>
            <?php else: ?>
                <button type="submit" name="create_automation" id="createAutomationBtn" class="btn btn-primary">Create Automation</button>
            <?php endif; ?>
            <a href="view_automations.php" class="btn btn-secondary">View Your Automations</a>
            <?php if ($edit_automation['id']): ?>
                <a href="automations.php?create" class="btn btn-secondary">Create New</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Data passed from PHP
    const phpData = <?= json_encode($js_data) ?>;
    const editAutomation = <?= json_encode($edit_automation) ?>;

    // Form elements
    const form = document.getElementById('automationForm');
    const sourceType = document.getElementById('source_type');
    const conditionType = document.getElementById('condition_type');
    const actionType = document.getElementById('action_type');
    const sourceFields = document.getElementById('source_dynamic_fields');
    const conditionFields = document.getElementById('condition_dynamic_fields');
    const actionFields = document.getElementById('action_dynamic_fields');
    const saveBtn = document.getElementById('saveAutomationBtn');
    const createBtn = document.getElementById('createAutomationBtn');

    // Helper function to escape HTML
    function escapeHtml(str) {
        if (typeof str !== 'string') return str;
        return str.replace(/&/g, "&amp;")
                  .replace(/</g, "&lt;")
                  .replace(/>/g, "&gt;")
                  .replace(/"/g, "&quot;")
                  .replace(/'/g, "&#039;");
    }

    // Render options for select elements
    function renderOptions(dataType, selected, multiple = false) {
        const items = phpData[dataType] || [];
        let options = '';
        
        // Ensure 'selected' is an array if multiple is true, for consistent checking
        const selectedArray = multiple && !Array.isArray(selected) && selected !== null ? [String(selected)] : (Array.isArray(selected) ? selected.map(String) : [String(selected)]);

        items.forEach(item => {
            let label = item.name || 'N/A';
            if (dataType === 'contacts') {
                label = (item.username || item.email) + ' (' + (item.stream_name || 'N/A') + ')';
            } else if (dataType === 'competitors') {
                label = item.name + ' (' + (item.stream_name || 'N/A') + ')';
            }
            
            const isSelected = multiple
                ? selectedArray.includes(String(item.id))
                : String(item.id) === String(selected);
                
            options += `<option value="${item.id}" ${isSelected ? 'selected' : ''}>${escapeHtml(label)}</option>`;
        });
        
        return options;
    }

    // Render source fields based on type and config
    // Added initialConfig parameter to pass saved data
    function renderSourceFields(type, initialConfig = {}) {
        const config = initialConfig && Object.keys(initialConfig).length > 0 ? initialConfig : (editAutomation.source_config_json ? JSON.parse(editAutomation.source_config_json) : {});
        let html = '';
        
        if (type === 'stream') {
            html = `
                <label for="source_stream_id">Select Stream <span class="required">*</span></label>
                <select id="source_stream_id" name="source_stream_id" required>
                    <option value="">Select a stream</option>
                    ${renderOptions('streams', config.stream_id)}
                </select>
            `;
        } else if (type === 'cohort') {
            html = `
                <label for="source_cohort_id">Select Cohort <span class="required">*</span></label>
                <select id="source_cohort_id" name="source_cohort_id" required>
                    <option value="">Select a cohort</option>
                    ${renderOptions('cohorts', config.cohort_id)}
                </select>
            `;
        } else if (type === 'contact') {
            html = `
                <label for="source_contact_id">Select Contact(s) <span class="required">*</span></label>
                <select id="source_contact_id" name="source_contact_id[]" multiple required>
                    ${renderOptions('contacts', config.contact_id, true)}
                </select>
                <small>Hold Ctrl/Cmd to select multiple</small>
            `;
        }
        
        sourceFields.innerHTML = html;
        updateButtonState();
    }

    // Render condition fields based on type and config
    // Added initialConfig parameter to pass saved data
    function renderConditionFields(type, initialConfig = {}) {
        const config = initialConfig && Object.keys(initialConfig).length > 0 ? initialConfig : (editAutomation.condition_config_json ? JSON.parse(editAutomation.condition_config_json) : {});
        let html = '';
        
        if (type === 'churn_probability') {
            html = `
                <label for="condition_churn_operator">Operator <span class="required">*</span></label>
                <select id="condition_churn_operator" name="condition_churn_operator" required>
                    <option value=">" ${config.operator === '>' ? 'selected' : ''}>Greater than</option>
                    <option value="<" ${config.operator === '<' ? 'selected' : ''}>Less than</option>
                    <option value="=" ${config.operator === '=' ? 'selected' : ''}>Equal to</option>
                    <option value=">=" ${config.operator === '>=' ? 'selected' : ''}>Greater than or equal to</option>
                    <option value="<=" ${config.operator === '<=' ? 'selected' : ''}>Less than or equal to</option>
                </select>
                <label for="condition_churn_value">Value (0-100) <span class="required">*</span></label>
                <input type="number" id="condition_churn_value" name="condition_churn_value"
                        min="0" max="100" value="${config.value || ''}" required>
            `;
        } else if (type === 'competitor_visit') {
            html = `
                <label for="condition_competitor_id">Competitor <span class="required">*</span></label>
                <select id="condition_competitor_id" name="condition_competitor_id" required>
                    <option value="">Select a competitor</option>
                    ${renderOptions('competitors', config.competitor_id)}
                </select>
                <label for="condition_competitor_timeframe">Within Last Days <span class="required">*</span></label>
                <input type="number" id="condition_competitor_timeframe" name="condition_competitor_timeframe"
                        min="1" value="${config.timeframe_days || ''}" required>
            `;
        } else if (type === 'feature_usage') {
            html = `
                <label for="condition_feature_name">Feature Name <span class="required">*</span></label>
                <input type="text" id="condition_feature_name" name="condition_feature_name"
                        value="${escapeHtml(config.feature_name || '')}" required>
                <label for="condition_min_usage_count">Minimum Usage Count <span class="required">*</span></label>
                <input type="number" id="condition_min_usage_count" name="condition_min_usage_count"
                        min="1" value="${config.min_usage_count || ''}" required>
            `;
        } else if (type === 'last_login') {
            html = `
                <label for="condition_days_since_login">Days Since Last Login <span class="required">*</span></label>
                <input type="number" id="condition_days_since_login" name="condition_days_since_login"
                        min="1" value="${config.days_since_login || ''}" required>
            `;
        }
        
        conditionFields.innerHTML = html;
        updateButtonState();
    }

    // Render action fields based on type and config
    // Added initialConfig parameter to pass saved data
    function renderActionFields(type, initialConfig = {}) {
        const config = initialConfig && Object.keys(initialConfig).length > 0 ? initialConfig : (editAutomation.action_config_json ? JSON.parse(editAutomation.action_config_json) : {});
        let html = '';
        
        if (type === 'email') {
            html = `
                <label for="action_email_template_id">Email Template <span class="required">*</span></label>
                <select id="action_email_template_id" name="action_email_template_id" required>
                    <option value="">Select a template</option>
                    ${renderOptions('templates', config.template_id)}
                </select>
            `;
        } else if (type === 'sms') {
            html = `
                <label for="action_sms_phone_field">Phone Number Field <span class="required">*</span></label>
                <input type="text" id="action_sms_phone_field" name="action_sms_phone_field"
                        value="${escapeHtml(config.phone_field || '')}" required>
                <label for="action_sms_message">Message <span class="required">*</span></label>
                <textarea id="action_sms_message" name="action_sms_message" required>${escapeHtml(config.sms_message || '')}</textarea>
            `;
        } else if (type === 'change_cohort') {
            html = `
                <label for="action_change_cohort_id">New Cohort <span class="required">*</span></label>
                <select id="action_change_cohort_id" name="action_change_cohort_id" required>
                    <option value="">Select a cohort</option>
                    ${renderOptions('cohorts', config.cohort_id)}
                </select>
            `;
        } else if (type === 'notification') {
            html = `
                <label for="action_notification_message">Notification Message <span class="required">*</span></label>
                <textarea id="action_notification_message" name="action_notification_message" required>${escapeHtml(config.notification_message || '')}</textarea>
            `;
        } else if (type === 'external') {
            html = `
                <label for="action_external_service">Service <span class="required">*</span></label>
                <select id="action_external_service" name="action_external_service" required>
                    <option value="">Select a service</option>
                    <option value="zapier" ${config.service_name === 'zapier' ? 'selected' : ''}>Zapier</option>
                </select>
                <div id="external_service_config"></div>
            `;
        }
        
        actionFields.innerHTML = html;
        
        if (type === 'external') {
            const service = config.service_name || '';
            const serviceConfig = document.getElementById('external_service_config');
            
            if (service === 'zapier') {
                serviceConfig.innerHTML = `
                    <label for="action_zapier_event">Event Name <span class="required">*</span></label>
                    <input type="text" id="action_zapier_event" name="action_zapier_event"
                            value="${escapeHtml(config.zapier_event_name || '')}" required>
                    <label for="action_zapier_payload">Payload Template <span class="required">*</span></label>
                    <textarea id="action_zapier_payload" name="action_zapier_payload" required>${escapeHtml(config.zapier_payload_template || '')}</textarea>
                `;
            }
            
            // Event listener for external service type
            const externalServiceSelect = document.getElementById('action_external_service');
            if (externalServiceSelect) { // Check if element exists before adding listener
                externalServiceSelect.addEventListener('change', function() {
                    if (this.value === 'zapier') {
                        serviceConfig.innerHTML = `
                            <label for="action_zapier_event">Event Name <span class="required">*</span></label>
                            <input type="text" id="action_zapier_event" name="action_zapier_event" required>
                            <label for="action_zapier_payload">Payload Template <span class="required">*</span></label>
                            <textarea id="action_zapier_payload" name="action_zapier_payload" required></textarea>
                        `;
                    } else {
                        serviceConfig.innerHTML = '';
                    }
                    updateButtonState();
                });
            }
        }
        
        updateButtonState();
    }

    // Update button state based on form validity
    function updateButtonState() {
        const isValid = (
            form.querySelector('#name').value.trim() !== '' &&
            sourceType.value !== '' &&
            conditionType.value !== '' &&
            actionType.value !== '' &&
            // Check dynamic fields for source stage
            [...sourceFields.querySelectorAll('[required]')].every(el => {
                if (el.tagName === 'SELECT' && el.multiple) {
                    return el.selectedOptions.length > 0;
                }
                return el.value.trim() !== '';
            }) &&
            // Check dynamic fields for condition stage
            [...conditionFields.querySelectorAll('[required]')].every(el => el.value.trim() !== '') &&
            // Check dynamic fields for action stage
            [...actionFields.querySelectorAll('[required]')].every(el => el.value.trim() !== '')
        );
        
        if (saveBtn) saveBtn.disabled = !isValid;
        if (createBtn) createBtn.disabled = !isValid;
    }

    // Event listeners for type changes
    sourceType.addEventListener('change', function() {
        // When changing type, don't pass initialConfig, let it be empty
        renderSourceFields(this.value); 
    });
    
    conditionType.addEventListener('change', function() {
        // When changing type, don't pass initialConfig, let it be empty
        renderConditionFields(this.value);
    });
    
    actionType.addEventListener('change', function() {
        // When changing type, don't pass initialConfig, let it be empty
        renderActionFields(this.value);
    });

    // Initialize form if editing - this runs once on page load
    if (editAutomation.id) {
        if (editAutomation.source_type) {
            sourceType.value = editAutomation.source_type; // Explicitly set the value from PHP data
            // Pass the parsed JSON config for initial rendering
            renderSourceFields(editAutomation.source_type, JSON.parse(editAutomation.source_config_json));
        }
        if (editAutomation.condition_type) {
            conditionType.value = editAutomation.condition_type; // Explicitly set the value from PHP data
            renderConditionFields(editAutomation.condition_type, JSON.parse(editAutomation.condition_config_json));
        }
        if (editAutomation.action_type) {
            actionType.value = editAutomation.action_type; // Explicitly set the value from PHP data
            renderActionFields(editAutomation.action_type, JSON.parse(editAutomation.action_config_json));
        }
    }

    // Delete confirmation
    const deleteBtn = document.getElementById('deleteAutomationBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function(e) {
            if (!confirm('Are you sure you want to delete this automation?')) {
                e.preventDefault();
            }
        });
    }

    // General form validation listeners
    form.addEventListener('input', updateButtonState);
    form.addEventListener('change', updateButtonState);
    updateButtonState(); // Initial state check
});
</script>
</body>
</html>