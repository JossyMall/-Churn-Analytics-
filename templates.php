<?php
// templates.php
// Ensure ob_start() is the VERY FIRST thing in the file, before any whitespace or BOM.
ob_start();
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_template'])) {
        $template_id = isset($_POST['template_id']) ? intval($_POST['template_id']) : 0;
        $name = trim($_POST['name']);
        $subject = trim($_POST['subject']);
        $content = trim($_POST['content']); // Content will be stored as is, ensure sanitization if needed
        $category = $_POST['category'];
        $sender_name = trim($_POST['sender_name']);
        
        if (!empty($name) && !empty($subject) && !empty($content) && !empty($sender_name)) {
            try {
                if ($template_id > 0) {
                    $stmt = $pdo->prepare("UPDATE email_templates SET
                        name = ?, subject = ?, content = ?, category = ?, sender_name = ?, updated_at = NOW()
                        WHERE id = ? AND user_id = ?");
                    $stmt->execute([$name, $subject, $content, $category, $sender_name, $template_id, $user_id]);
                    $_SESSION['success'] = "Template updated successfully";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO email_templates
                        (user_id, name, subject, content, category, sender_name)
                        VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $name, $subject, $content, $category, $sender_name]);
                    $_SESSION['success'] = "Template created successfully";
                }
                header('Location: templates.php');
                exit;
            } catch (PDOException $e) {
                $_SESSION['error'] = "Database error: " . $e->getMessage();
                // This redirect will now work due to ob_start()
                header('Location: templates.php');
                exit;
            }
        } else {
            $_SESSION['error'] = "Please fill all required fields";
            // This redirect will now work due to ob_start()
            header('Location: templates.php');
            exit;
        }
    }
    elseif (isset($_POST['delete_template'])) {
        $template_id = intval($_POST['template_id']);
        try {
            $stmt = $pdo->prepare("DELETE FROM email_templates WHERE id = ? AND user_id = ?");
            $stmt->execute([$template_id, $user_id]);
            $_SESSION['success'] = "Template deleted successfully";
            header('Location: templates.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Database error: " . $e->getMessage();
            header('Location: templates.php');
            exit;
        }
    }
    elseif (isset($_POST['preview_template'])) {
        // IMPORTANT SECURITY NOTE:
        // Directly outputting user-supplied HTML via srcdoc (or any other direct HTML injection)
        // without robust sanitization (e.g., using HTML Purifier library) is an XSS VULNERABILITY.
        // For production, you MUST sanitize `$_POST['content']` before putting it into srcdoc.
        // For demonstration, `htmlspecialchars` is removed and `urlencode` is added for correct rendering,
        // but this approach is INSECURE without a proper sanitization layer.
        $_SESSION['preview_content'] = $_POST['content']; // Storing raw HTML
        $_SESSION['preview_subject'] = $_POST['subject'];
        $_SESSION['preview_sender'] = $_POST['sender_name'];
        header('Location: templates.php?preview=true');
        exit;
    }
}

// Get all templates for the user
$templates = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $templates = $stmt->fetchAll();
} catch (PDOException $e) {
    $_SESSION['error'] = "Failed to load templates: " . $e->getMessage();
    header('Location: templates.php');
    exit;
}

// Get template for editing if specified
$edit_template = null;
if (isset($_GET['edit'])) {
    $template_id = intval($_GET['edit']);
    try {
        $stmt = $pdo->prepare("SELECT * FROM email_templates WHERE id = ? AND user_id = ?");
        $stmt->execute([$template_id, $user_id]);
        $edit_template = $stmt->fetch();
        if (!$edit_template) {
            $_SESSION['error'] = "Template not found";
            header('Location: templates.php');
            exit;
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Failed to load template: " . $e->getMessage();
        header('Location: templates.php');
        exit;
    }
}

// Available placeholders with sample values
$placeholders = [
    '{username}' => "User's username (e.g., johndoe)",
    '{email}' => "User's email address (e.g., user@example.com)",
    '{stream_name}' => "Name of the stream/project (e.g., My SaaS App)",
    '{cohort_name}' => "Name of the cohort/segment (e.g., Premium Users)",
    '{competitor_name}' => "Name of competitor (e.g., Competitor Inc)",
    '{feature_name}' => "Name of feature (e.g., Dashboard Analytics)",
    '{unsubscribe_link}' => "Unsubscribe URL (auto-generated)",
    '{current_date}' => "Current date (e.g., ".date('F j, Y').")",
    '{user_first_name}' => "User's first name (e.g., John)",
    '{user_last_name}' => "User's last name (e.g., Doe)",
    '{company_name}' => "User's company name",
    '{trial_end_date}' => "Trial end date (e.g., ".date('F j, Y', strtotime('+7 days')).")"
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Templates</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #3AC3B8;
            --primary-dark: #2FB3A8;
            --secondary: #6C757D;
            --danger: #E53E3E;
            --light: #F8F9FA;
            --dark: #343A40;
            --border: #E2E8F0;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
       
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
       
        body {
            color: #2D3748;
            line-height: 1.6;
        }
       
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
       
        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
       
        .page-title {
            font-size: 2rem;
            font-weight: 600;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
       
        /* Alerts */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 8px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
       
        .alert.error {
            background: #FFF0F0;
            color: #E74C3C;
            border-left: 4px solid #E74C3C;
        }
       
        .alert.success {
            background: #F0FFF4;
            color: #2ECC71;
            border-left: 4px solid #2ECC71;
        }
       
        /* Buttons */
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            border: none;
            font-size: 0.95rem;
        }
       
        .btn-primary {
            background: var(--primary);
            color: white;
        }
       
        .btn-primary:hover {
            background: var(--primary-dark);
        }
       
        .btn-secondary {
            background: #EDF2F7;
            color: #4A5568;
        }
       
        .btn-secondary:hover {
            background: #E2E8F0;
        }
       
        .btn-danger {
            background: #FFF5F5;
            color: var(--danger);
            border: 1px solid #FED7D7;
        }
       
        .btn-danger:hover {
            background: #FEEBEB;
        }
       
        .btn-sm {
            padding: 0.5rem 1rem;
            font-size: 0.85rem;
        }
       
        /* Template List */
        .templates-list {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }
       
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: #718096;
        }
       
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #CBD5E0;
        }
       
        .empty-state h3 {
            margin-bottom: 0.5rem;
            color: #4A5568;
        }
       
        /* Table */
        .template-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
       
        .template-table th {
            background: #F7FAFC;
            color: #4A5568;
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
       
        .template-table td {
            padding: 1rem;
            border-bottom: 1px solid #EDF2F7;
            vertical-align: middle;
        }
       
        .template-table tr:hover td {
            background: #F8FAFC;
        }
       
        /* Category Badges */
        .category-badge {
            display: inline-block;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
       
        .category-badge.discount {
            background: #EBF8FF;
            color: #3182CE;
        }
       
        .category-badge.feature {
            background: #EBF8F2;
            color: #38A169;
        }
       
        .category-badge.survey {
            background: #FFF5F5;
            color: var(--danger);
        }
       
        .category-badge.support {
            background: #FAF5FF;
            color: #805AD5;
        }
       
        .category-badge.marketing {
            background: #FFF6E5;
            color: #DD6B20;
        }
       
        .category-badge.general {
            background: #EDF2F7;
            color: #4A5568;
        }
       
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
       
        /* Search Box */
        .search-box {
            position: relative;
            width: 300px;
        }
       
        .search-box input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
        }
       
        .search-box input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1);
        }
       
        .search-box i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #A0AEC0;
        }
       
        /* Template Editor */
        .template-editor {
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow);
            padding: 2rem;
            margin-top: 1.5rem;
        }
       
        .template-editor h2 {
            margin-bottom: 1.5rem;
            color: #2D3748;
            font-size: 1.5rem;
        }
       
        .form-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }
       
        .form-group {
            flex: 1;
            margin-bottom: 1rem;
        }
       
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4A5568;
            font-weight: 500;
        }
       
        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
            transition: all 0.2s ease;
        }
       
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1);
        }
       
        /* Editor Toolbar */
        .editor-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 0.75rem;
            padding: 0.75rem;
            background: #F7FAFC;
            border-radius: 8px;
            border: 1px solid var(--border);
        }
       
        .tool-btn {
            background: white;
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            color: #4A5568;
        }
       
        .tool-btn:hover {
            background: #EDF2F7;
        }
       
        .tool-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
       
        /* Dropdown */
        .dropdown {
            position: relative;
            display: inline-block;
        }
       
        .dropdown-toggle {
            background: white;
            border: 1px solid var(--border);
            padding: 0.5rem 0.75rem;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.9rem;
            color: #4A5568;
        }
       
        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            z-index: 1000;
            display: none;
            min-width: 250px;
            padding: 0.5rem 0;
            margin: 0.125rem 0 0;
            font-size: 0.9rem;
            color: #2D3748;
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
       
        .dropdown:hover .dropdown-menu {
            display: block;
        }
       
        .dropdown-item {
            display: block;
            padding: 0.5rem 1rem;
            color: #4A5568;
            text-decoration: none;
            transition: all 0.2s ease;
        }
       
        .dropdown-item:hover {
            background: #F7FAFC;
            color: #2D3748;
        }
       
        .dropdown-item strong {
            display: block;
            margin-bottom: 0.25rem;
        }
       
        .dropdown-item small {
            font-size: 0.8rem;
            color: #718096;
        }
       
        /* Editor Content */
        .editor-content {
            width: 100%;
            min-height: 400px;
            padding: 1rem;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: 'Menlo', 'Monaco', 'Consolas', monospace;
            line-height: 1.6;
            resize: vertical;
        }
       
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #EDF2F7;
        }
       
        /* Preview Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            display: flex;
            align-items: center;
            justify-content: center;
        }
       
        .modal-content {
            background: white;
            width: 80%;
            max-width: 900px;
            max-height: 90vh;
            border-radius: 12px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
       
        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
       
        .modal-header h3 {
            font-size: 1.25rem;
            color: #2D3748;
        }
       
        .close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #718096;
        }
       
        .modal-body {
            padding: 0;
            flex: 1;
            overflow: auto;
        }
       
        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
       
        .preview-container {
            display: flex;
            flex-direction: column;
            height: 100%;
        }
       
        .preview-toolbar {
            padding: 1rem;
            background: #F7FAFC;
            border-bottom: 1px solid var(--border);
        }
       
        .preview-info {
            font-size: 0.9rem;
            color: #4A5568;
        }
       
        .preview-info strong {
            color: #2D3748;
        }
       
        .preview-frame {
            flex: 1;
            border: none;
            background: white;
        }
       
        /* Responsive */
        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }
           
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
           
            .search-box {
                width: 100%;
            }
           
            .form-row {
                flex-direction: column;
                gap: 1rem;
            }
           
            .modal-content {
                width: 95%;
                height: 95%;
            }
           
            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-envelope"></i> Email Templates</h1>
            <div class="search-box">
                <input type="text" id="template-search" placeholder="Search templates...">
                <i class="fas fa-search"></i>
            </div>
        </div>
       
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
       
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($_SESSION['success']) ?>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
       
        <div class="templates-actions">
            <a href="templates.php?new" class="btn btn-primary">
                <i class="fas fa-plus"></i> New Template
            </a>
        </div>
       
        <?php if (isset($_GET['new']) || isset($edit_template)): ?>
            <div class="template-editor">
                <h2><?= isset($edit_template) ? 'Edit Template' : 'Create New Template' ?></h2>
               
                <form method="POST" id="template-form">
                    <?php if (isset($edit_template)): ?>
                        <input type="hidden" name="template_id" value="<?= $edit_template['id'] ?>">
                    <?php endif; ?>
                   
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-heading"></i> Template Name</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= isset($edit_template) ? htmlspecialchars($edit_template['name']) : '' ?>"
                                   required>
                        </div>
                       
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Category</label>
                            <select name="category" class="form-control" required>
                                <?php
                                $categories = [
                                    'discount' => 'Discount Offer',
                                    'feature' => 'Feature Walkthrough',
                                    'survey' => 'Survey',
                                    'support' => 'Support and Help',
                                    'marketing' => 'Marketing',
                                    'general' => 'General'
                                ];
                                foreach ($categories as $key => $label): ?>
                                    <option value="<?= $key ?>"
                                        <?= isset($edit_template) && $edit_template['category'] == $key ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                   
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-pencil-alt"></i> Subject</label>
                            <input type="text" name="subject" class="form-control"
                                   value="<?= isset($edit_template) ? htmlspecialchars($edit_template['subject']) : '' ?>"
                                   required>
                        </div>
                       
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Sender Name</label>
                            <input type="text" name="sender_name" class="form-control"
                                   value="<?= isset($edit_template) ? htmlspecialchars($edit_template['sender_name']) : '' ?>"
                                   required>
                        </div>
                    </div>
                   
                    <div class="form-group">
                        <label><i class="fas fa-envelope-open-text"></i> Email Content</label>
                        <div class="editor-toolbar">
                            <button type="button" class="tool-btn" data-tag="b" title="Bold"><i class="fas fa-bold"></i></button>
                            <button type="button" class="tool-btn" data-tag="i" title="Italic"><i class="fas fa-italic"></i></button>
                            <button type="button" class="tool-btn" data-tag="h2" title="Heading"><i class="fas fa-heading"></i></button>
                            <button type="button" class="tool-btn" data-tag="p" title="Paragraph"><i class="fas fa-paragraph"></i></button>
                            <button type="button" class="tool-btn" data-tag="ul" title="Bullet List"><i class="fas fa-list-ul"></i></button>
                            <button type="button" class="tool-btn" data-tag="ol" title="Numbered List"><i class="fas fa-list-ol"></i></button>
                            <button type="button" class="tool-btn" data-tag="a" title="Link"><i class="fas fa-link"></i></button>
                            <button type="button" class="tool-btn" data-tag="img" title="Image"><i class="fas fa-image"></i></button>
                            <button type="button" class="tool-btn" data-tag="youtube" title="YouTube Video"><i class="fab fa-youtube"></i></button>
                            <button type="button" class="tool-btn" data-tag="hr" title="Divider"><i class="fas fa-minus"></i></button>
                            
                            <div class="dropdown">
                                <button type="button" class="tool-btn dropdown-toggle">
                                    <i class="fas fa-tags"></i> Placeholders
                                </button>
                                <div class="dropdown-menu">
                                    <?php foreach ($placeholders as $placeholder => $description): ?>
                                        <a href="#" class="dropdown-item" data-placeholder="<?= $placeholder ?>">
                                            <strong><?= $placeholder ?></strong>
                                            <small><?= $description ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <textarea id="emailContent" name="content" class="editor-content" required><?=
                            isset($edit_template) ? htmlspecialchars($edit_template['content']) : ''
                        ?></textarea>
                    </div>
                   
                    <div class="form-actions">
                        <button type="submit" name="save_template" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Template
                        </button>
                        <button type="submit" name="preview_template" class="btn btn-secondary">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <a href="templates.php" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                        <?php if (isset($edit_template)): ?>
                            <button type="submit" name="delete_template" class="btn btn-danger"
                                    onclick="return confirm('Are you sure you want to delete this template?')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        <?php elseif (isset($_GET['preview'])): ?>
            <div class="modal" id="preview-modal" style="display: block;">
                <div class="modal-content">
                    <div class="modal-header">
                        <h3>Email Preview</h3>
                        <button type="button" class="close-btn" onclick="window.location.href='templates.php'">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="modal-body">
                        <div class="preview-container">
                            <div class="preview-toolbar">
                                <div class="preview-info">
                                    <strong>Subject:</strong> <?= htmlspecialchars($_SESSION['preview_subject']) ?><br>
                                    <strong>From:</strong> <?= htmlspecialchars($_SESSION['preview_sender']) ?>
                                </div>
                            </div>
                            <iframe class="preview-frame" srcdoc="<?= urlencode($_SESSION['preview_content']) ?>"></iframe>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="window.location.href='templates.php'">
                            <i class="fas fa-arrow-left"></i> Back to Templates
                        </button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['preview_content']); ?>
            <?php unset($_SESSION['preview_subject']); ?>
            <?php unset($_SESSION['preview_sender']); ?>
        <?php else: ?>
            <div class="templates-list">
                <?php if (empty($templates)): ?>
                    <div class="empty-state">
                        <i class="fas fa-envelope-open-text fa-3x"></i>
                        <h3>No templates created yet</h3>
                        <p>Get started by creating your first email template</p>
                        <a href="templates.php?new" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Create Template
                        </a>
                    </div>
                <?php else: ?>
                    <table class="template-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Category</th>
                                <th>Subject</th>
                                <th>Last Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-envelope"></i>
                                        <?= htmlspecialchars($template['name']) ?>
                                    </td>
                                    <td>
                                        <span class="category-badge <?= $template['category'] ?>">
                                            <?= ucfirst($template['category']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($template['subject']) ?></td>
                                    <td><?= date('M j, Y', strtotime($template['updated_at'] ?: $template['created_at'])) ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="templates.php?edit=<?= $template['id'] ?>" class="btn btn-sm" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('Are you sure?')">
                                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                                <button type="submit" name="delete_template" class="btn btn-sm btn-danger" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
                                                <input type="hidden" name="content" value="<?= htmlspecialchars($template['content']) ?>">
                                                <input type="hidden" name="subject" value="<?= htmlspecialchars($template['subject']) ?>">
                                                <input type="hidden" name="sender_name" value="<?= htmlspecialchars($template['sender_name']) ?>">
                                                <button type="submit" name="preview_template" class="btn btn-sm" title="Preview">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const textarea = document.getElementById('emailContent');
       
        // Formatting buttons
        document.querySelectorAll('.tool-btn[data-tag]').forEach(btn => {
            btn.addEventListener('click', function() {
                const tag = this.dataset.tag;
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd;
                const selectedText = textarea.value.substring(start, end);
                let newText = '';
               
                switch(tag) {
                    case 'b':
                        newText = `<strong>${selectedText}</strong>`;
                        break;
                    case 'i':
                        newText = `<em>${selectedText}</em>`;
                        break;
                    case 'h2':
                        newText = `<h2 style="color: #2D3748; font-size: 1.5rem; margin-bottom: 1rem;">${selectedText}</h2>`;
                        break;
                    case 'p':
                        newText = `<p style="margin-bottom: 1rem;">${selectedText}</p>`;
                        break;
                    case 'ul':
                        newText = `<ul style="margin-bottom: 1rem; padding-left: 2rem;">\n<li>${selectedText}</li>\n</ul>`;
                        break;
                    case 'ol':
                        newText = `<ol style="margin-bottom: 1rem; padding-left: 2rem;">\n<li>${selectedText}</li>\n</ol>`;
                        break;
                    case 'a':
                        // Prompt for URL if 'a' tag is clicked
                        const url = prompt('Enter the URL:', 'https://example.com');
                        if (url) {
                            newText = `<a href="${url}" style="color: #3AC3B8; text-decoration: underline;">${selectedText || 'Link Text'}</a>`;
                        } else {
                            newText = ''; // Don't insert anything if URL is cancelled
                        }
                        break;
                    case 'img':
                        // Prompt for Image URL if 'img' tag is clicked
                        const imageUrl = prompt('Enter the Image URL:', 'https://via.placeholder.com/600x300');
                        if (imageUrl) {
                            newText = `<img src="${imageUrl}" alt="${selectedText || 'Image'}" style="max-width: 100%; height: auto; margin: 1rem 0;">`;
                        } else {
                            newText = ''; // Don't insert anything if URL is cancelled
                        }
                        break;
                    case 'youtube':
                        // Prompt for YouTube Video ID
                        const videoId = prompt('Enter YouTube Video ID (e.g., dQw4w9WgXcQ):');
                        if (videoId) {
                             // FIX: Corrected YouTube embed URL
                             newText = `<div style="margin: 1rem 0; position: relative; padding-bottom: 56.25%; height: 0; overflow: hidden;">
                                <iframe width="100%" height="100%" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;"
                                src="https://www.youtube.com/embed/${videoId}" frameborder="0" allowfullscreen></iframe>
                            </div>`;
                        } else {
                            newText = ''; // Don't insert anything if video ID is cancelled
                        }
                        break;
                    case 'hr':
                        newText = `<hr style="border: none; border-top: 1px solid #E2E8F0; margin: 1.5rem 0;">`;
                        break;
                }
               
                if (newText) { // Only update if newText is not empty (e.g., if prompts were cancelled)
                    textarea.value = textarea.value.substring(0, start) + newText + textarea.value.substring(end);
                    // Adjust cursor position if text was selected
                    if (start !== end) {
                        textarea.setSelectionRange(start, start + newText.length);
                    } else {
                        textarea.setSelectionRange(start + newText.length, start + newText.length);
                    }
                }
                textarea.focus(); // Keep focus on textarea
            });
        });
       
        // Placeholder insertion
        document.querySelectorAll('.dropdown-item[data-placeholder]').forEach(item => {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                const placeholder = this.dataset.placeholder;
                const start = textarea.selectionStart;
                const end = textarea.selectionEnd; // Get current end to preserve text after cursor
                
                textarea.value = textarea.value.substring(0, start) + placeholder + textarea.value.substring(end);
                // Move cursor after the inserted placeholder
                textarea.setSelectionRange(start + placeholder.length, start + placeholder.length);
                textarea.focus();
            });
        });
       
        // Template search
        const searchInput = document.getElementById('template-search');
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                document.querySelectorAll('.template-table tbody tr').forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        }
    });
    </script>
</body>
</html>