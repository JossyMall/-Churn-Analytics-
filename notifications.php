<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];


// Handle notification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['mark_as_read'])) {
            // Mark single notification as read
            $notification_id = (int)$_POST['notification_id'];
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() 
                                 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notification_id, $user_id]);
            $_SESSION['success'] = "Notification marked as read";
        } 
        elseif (isset($_POST['delete_notification'])) {
            // Delete single notification
            $notification_id = (int)$_POST['notification_id'];
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?");
            $stmt->execute([$notification_id, $user_id]);
            $_SESSION['success'] = "Notification deleted";
        }
        elseif (isset($_POST['mark_all_read'])) {
            // Mark all as read
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() 
                                 WHERE user_id = ? AND is_read = 0");
            $stmt->execute([$user_id]);
            $_SESSION['success'] = "All notifications marked as read";
        }
        
        // Redirect to prevent form resubmission
        header('Location: notifications.php');
        exit;
    } catch (PDOException $e) {
        $_SESSION['error'] = "Database error: " . $e->getMessage();
    }
}

// Get user notifications
$stmt = $pdo->prepare("SELECT * FROM notifications 
                      WHERE user_id = ? 
                      ORDER BY created_at DESC 
                      LIMIT 100");
$stmt->execute([$user_id]);
$notifications = $stmt->fetchAll();

// Count unread notifications
$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications 
                      WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unread_count = $stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Notifications</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            color: #333;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            border-radius: 8px;
            padding: 20px;
        }
        
        h1 {
            color: #2c3e50;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .badge {
            background: #3ac3b8;
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert.error {
            background: #f8e6e6;
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        .alert.success {
            background: #e6f7ee;
            color: #28a745;
            border-left: 4px solid #28a745;
        }
        
        .notification-item {
            padding: 15px;
            border-left: 4px solid #3ac3b8;
            background: #f9f9f9;
            margin-bottom: 10px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
        }
        
        .notification-item.unread {
            border-left-color: #ffc107;
            background: #fff8e6;
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            margin: 0 0 5px 0;
            color: #2c3e50;
        }
        
        .notification-message {
            margin: 0 0 5px 0;
            color: #555;
        }
        
        .notification-time {
            font-size: 0.8rem;
            color: #777;
        }
        
        .notification-actions {
            display: flex;
            gap: 10px;
            align-items: flex-start;
        }
        
        .btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-mark-read {
            background: #3ac3b8;
            color: white;
        }
        
        .btn-delete {
            background: #f8e6e6;
            color: #dc3545;
        }
        
        .btn-view {
            background: #3ac3b8;
            color: white;
            text-decoration: none;
        }
        
        .btn-mark-all {
            background: #3ac3b8;
            color: white;
            padding: 8px 15px;
            text-decoration: none;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 20px;
        }
        
        .no-notifications {
            text-align: center;
            padding: 40px;
            color: #666;
            font-size: 1.1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .no-notifications i {
            font-size: 4rem;
            color: #ccc;
            margin-bottom: 20px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <i class="bi bi-bell"></i>
                Notifications
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </h1>
            <?php if ($unread_count > 0): ?>
                <form method="POST">
                    <button type="submit" name="mark_all_read" class="btn-mark-all">
                        <i class="bi bi-check-all"></i> Mark All as Read
                    </button>
                </form>
            <?php endif; ?>
        </div>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?= $_SESSION['error'] ?></div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?= $_SESSION['success'] ?></div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (empty($notifications)): ?>
            <div class="no-notifications">
                <i class="bi bi-bell-slash"></i>
                <p>You don't have any notifications yet.</p>
            </div>
        <?php else: ?>
            <div class="notifications-list">
                <?php foreach ($notifications as $notification): ?>
                    <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                        <div class="notification-content">
                            <h3 class="notification-title"><?= htmlspecialchars($notification['title']) ?></h3>
                            <p class="notification-message"><?= htmlspecialchars($notification['message']) ?></p>
                            <small class="notification-time">
                                <?= date('M j, Y g:i a', strtotime($notification['created_at'])) ?>
                                <?php if ($notification['is_read']): ?>
                                    - Read <?= date('M j', strtotime($notification['read_at'])) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="notification-actions">
                            <?php if ($notification['related_url']): ?>
                                <a href="<?= htmlspecialchars($notification['related_url']) ?>" class="btn btn-view">
                                    <i class="bi bi-eye"></i> View
                                </a>
                            <?php endif; ?>
                            <?php if (!$notification['is_read']): ?>
                                <form method="POST">
                                    <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                    <button type="submit" name="mark_as_read" class="btn btn-mark-read">
                                        <i class="bi bi-check"></i> Read
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST">
                                <input type="hidden" name="notification_id" value="<?= $notification['id'] ?>">
                                <button type="submit" name="delete_notification" class="btn btn-delete">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>