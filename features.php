<?php
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
    if (isset($_POST['add_feature'])) {
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $tags = trim($_POST['tags']);
        $stream_id = intval($_POST['stream_id']);

        if (!empty($name) && !empty($tags) && $stream_id > 0) {
            $stmt = $pdo->prepare("INSERT INTO features (stream_id, name, url, tags) VALUES (?, ?, ?, ?)");
            $stmt->execute([$stream_id, $name, $url, $tags]);
            $_SESSION['success'] = "Feature added successfully";
            header('Location: features.php');
            exit;
        } else {
            $_SESSION['error'] = "Please fill all required fields";
        }
    } elseif (isset($_POST['delete_feature'])) {
        $feature_id = intval($_POST['feature_id']);
        $stmt = $pdo->prepare("DELETE FROM features WHERE id = ? AND stream_id IN (SELECT id FROM streams WHERE user_id = ?)");
        $stmt->execute([$feature_id, $user_id]);
        $_SESSION['success'] = "Feature deleted successfully";
        header('Location: features.php');
        exit;
    }
}

// Get user's streams
$stmt = $pdo->prepare("SELECT id, name FROM streams WHERE user_id = ?");
$stmt->execute([$user_id]);
$streams = $stmt->fetchAll();

// Get features for selected stream
$selected_stream = isset($_GET['stream_id']) ? intval($_GET['stream_id']) : ($streams[0]['id'] ?? 0);
$features = [];
if ($selected_stream > 0) {
    $stmt = $pdo->prepare("SELECT * FROM features WHERE stream_id = ?");
    $stmt->execute([$selected_stream]);
    $features = $stmt->fetchAll();
}
?>

<div class="features-container">
    <h1>Feature Tracking</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <div class="stream-selector">
        <h2>Select Stream</h2>
        <select id="streamSelect">
            <?php foreach ($streams as $stream): ?>
                <option value="<?= $stream['id'] ?>" <?= $stream['id'] == $selected_stream ? 'selected' : '' ?>>
                    <?= htmlspecialchars($stream['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="features-list">
        <h2>Tracked Features</h2>
        
        <?php if (empty($features)): ?>
            <div class="empty-state">
                <p>No features added yet for this stream</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>URL</th>
                        <th>Tags</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($features as $feature): ?>
                        <tr>
                            <td><?= htmlspecialchars($feature['name']) ?></td>
                            <td>
                                <?php if (!empty($feature['url'])): ?>
                                    <a href="<?= htmlspecialchars($feature['url']) ?>" target="_blank"><?= htmlspecialchars($feature['url']) ?></a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($feature['tags']) ?></td>
                            <td>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="feature_id" value="<?= $feature['id'] ?>">
                                    <button type="submit" name="delete_feature" class="delete-btn">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <div class="add-feature">
        <h2>Add New Feature</h2>
        <form method="POST">
            <div class="form-group">
                <label>Stream</label>
                <select name="stream_id" required>
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?= $stream['id'] ?>" <?= $stream['id'] == $selected_stream ? 'selected' : '' ?>>
                            <?= htmlspecialchars($stream['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Feature Name</label>
                <input type="text" name="name" required>
            </div>
            
            <div class="form-group">
                <label>URL (optional)</label>
                <input type="url" name="url" placeholder="https://example.com/feature">
                <small>For web projects only</small>
            </div>
            
            <div class="form-group">
                <label>Tags (comma separated)</label>
                <input type="text" name="tags" required placeholder="e.g., map,dashboard,analytics">
            </div>
            
            <button type="submit" name="add_feature" class="submit-btn">Add Feature</button>
        </form>
    </div>
</div>

<style>
    .features-container {
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
    
    .alert.error {
        background: #f8e6e6;
        color: #dc3545;
    }
    
    .alert.success {
        background: #e6f7ee;
        color: #28a745;
    }
    
    .stream-selector {
        margin-bottom: 30px;
    }
    
    .stream-selector select {
        width: 100%;
        max-width: 400px;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
    
    .features-list {
        margin-bottom: 40px;
    }
    
    .empty-state {
        padding: 20px;
        text-align: center;
        color: #666;
        border: 1px dashed #ddd;
        border-radius: 8px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    table th, table td {
        padding: 12px 15px;
        text-align: left;
        border-bottom: 1px solid #eee;
    }
    
    table th {
        background: #f5f5f5;
        font-weight: 500;
    }
    
    .delete-btn {
        background: #dc3545;
        color: white;
        border: none;
        padding: 5px 10px;
        border-radius: 4px;
        cursor: pointer;
    }
    
    .add-feature {
        background: #f9f9f9;
        padding: 20px;
        border-radius: 8px;
    }
    
    .form-group {
        margin-bottom: 15px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: 500;
    }
    
    .form-group input[type="text"],
    .form-group input[type="url"],
    .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
    }
    
    .form-group small {
        color: #666;
        font-size: 0.8rem;
    }
    
    .submit-btn {
        background: #3ac3b8;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        margin-top: 10px;
    }
</style>

<script>
    // Stream selector change
    document.getElementById('streamSelect').addEventListener('change', function() {
        window.location.href = 'features.php?stream_id=' + this.value;
    });
</script>