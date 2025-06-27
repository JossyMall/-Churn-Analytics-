<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];

// Initialize variables
$error = '';
$success = '';
$edit_gift = null;
$gifts = [];
$earned_gifts = [];
$consecutive_months = 0;
$membership_levels = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_admin && isset($_POST['save_gift'])) {
        $gift_id = isset($_POST['gift_id']) ? intval($_POST['gift_id']) : 0;
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $duration = intval($_POST['duration'] ?? 0);
        $is_global = isset($_POST['is_global']) ? 1 : 0;
        $membership_id = $is_global ? null : (isset($_POST['membership_id']) ? intval($_POST['membership_id']) : null);

        if (empty($name) || empty($description) || $duration <= 0) {
            $error = "Please fill all required fields correctly";
        } else {
            try {
                if ($gift_id > 0) {
                    // Update existing gift
                    $stmt = $pdo->prepare("UPDATE membership_gifts SET 
                        name = ?, description = ?, duration_months = ?, 
                        membership_id = ?, is_global = ?
                        WHERE id = ?");
                    $stmt->execute([$name, $description, $duration, $membership_id, $is_global, $gift_id]);
                    $success = "Gift updated successfully";
                } else {
                    // Create new gift
                    $stmt = $pdo->prepare("INSERT INTO membership_gifts 
                        (name, description, duration_months, membership_id, is_global) 
                        VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $description, $duration, $membership_id, $is_global]);
                    $success = "Gift created successfully";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    } elseif ($is_admin && isset($_POST['delete_gift'])) {
        $gift_id = intval($_POST['gift_id'] ?? 0);
        try {
            // Check if any user has earned this gift
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_gifts WHERE gift_id = ?");
            $stmt->execute([$gift_id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                $error = "Cannot delete gift - users have already earned it";
            } else {
                $stmt = $pdo->prepare("DELETE FROM membership_gifts WHERE id = ?");
                $stmt->execute([$gift_id]);
                $success = "Gift deleted successfully";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Admin interface data
if ($is_admin) {
    // Check for edit request
    if (isset($_GET['edit'])) {
        $gift_id = intval($_GET['edit']);
        try {
            $stmt = $pdo->prepare("SELECT * FROM membership_gifts WHERE id = ?");
            $stmt->execute([$gift_id]);
            $edit_gift = $stmt->fetch();
        } catch (PDOException $e) {
            $error = "Failed to load gift: " . $e->getMessage();
        }
    }
    
    // Fetch all gifts
    try {
        $gifts = $pdo->query("
            SELECT g.*, m.name as membership_name 
            FROM membership_gifts g
            LEFT JOIN membership_levels m ON g.membership_id = m.id
            ORDER BY g.duration_months ASC
        ")->fetchAll();
        
        // Fetch membership levels for dropdown
        $membership_levels = $pdo->query("SELECT id, name FROM membership_levels WHERE is_active = 1")->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load data: " . $e->getMessage();
    }
} 
// Regular user interface data
else {
    try {
        // Get user's current membership
        $stmt = $pdo->prepare("SELECT membership_id FROM user_subscriptions WHERE user_id = ? AND is_active = 1");
        $stmt->execute([$user_id]);
        $current_membership = $stmt->fetch();
        $membership_id = $current_membership['membership_id'] ?? null;
        
        // Calculate consecutive membership duration
        $stmt = $pdo->prepare("
            SELECT TIMESTAMPDIFF(MONTH, MIN(start_date), NOW()) as months 
            FROM user_subscriptions 
            WHERE user_id = ? 
            AND is_active = 1
            AND cancelled_at IS NULL
        ");
        $stmt->execute([$user_id]);
        $consecutive_months = $stmt->fetchColumn() ?: 0;
        
        // Get gifts available to user (global or for their membership level)
        $stmt = $pdo->prepare("
            SELECT * FROM membership_gifts 
            WHERE is_global = 1 OR membership_id = ?
            ORDER BY duration_months ASC
        ");
        $stmt->execute([$membership_id]);
        $gifts = $stmt->fetchAll();
        
        // Get gifts user has already earned
        $stmt = $pdo->prepare("
            SELECT g.*, ug.earned_date 
            FROM user_gifts ug
            JOIN membership_gifts g ON ug.gift_id = g.id
            WHERE ug.user_id = ?
        ");
        $stmt->execute([$user_id]);
        $earned_gifts = $stmt->fetchAll();
    } catch (PDOException $e) {
        $error = "Failed to load your gift data: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_admin ? 'Gift Management' : 'My Membership Rewards' ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .gift-card {
            transition: transform 0.3s ease;
        }
        
        body {
            background-color: #f1f1f1 !important;
        }
        
        
        }
        .gift-card:hover {
            transform: translateY(-5px);
        }
        .progress {
            height: 20px;
        }
        .earned-badge {
            position: absolute;
            top: 10px;
            right: 10px;
        }
        .gift-icon {
            font-size: 2.5rem;
            color: #3AC3B8;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($is_admin): ?>
            <!-- Admin Interface -->
            <h1 class="mb-4"><i class="fas fa-gift me-2"></i>Gift Management</h1>
            
            <div class="card mb-4">
                <div class="card-header">
                    <h2><?= $edit_gift ? 'Edit Gift' : 'Create New Gift' ?></h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php if ($edit_gift): ?>
                            <input type="hidden" name="gift_id" value="<?= $edit_gift['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label">Gift Name</label>
                            <input type="text" name="name" class="form-control" 
                                   value="<?= htmlspecialchars($edit_gift['name'] ?? '') ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($edit_gift['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Duration (months)</label>
                            <input type="number" name="duration" min="1" class="form-control" 
                                   value="<?= $edit_gift['duration_months'] ?? '1' ?>" required>
                        </div>
                        
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" name="is_global" id="is_global" 
                                   <?= ($edit_gift['is_global'] ?? 0) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="is_global">Available to all premium members</label>
                        </div>
                        
                        <div class="mb-3" id="membership-group" style="<?= ($edit_gift['is_global'] ?? 0) ? 'display:none;' : '' ?>">
                            <label class="form-label">Specific Membership Level</label>
                            <select name="membership_id" class="form-select">
                                <option value="">-- Select Membership Level --</option>
                                <?php foreach ($membership_levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" 
                                        <?= (($edit_gift['membership_id'] ?? '') == $level['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <button type="submit" name="save_gift" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> <?= $edit_gift ? 'Update' : 'Create' ?> Gift
                        </button>
                        
                        <?php if ($edit_gift): ?>
                            <a href="gift_membership.php" class="btn btn-secondary">
                                <i class="fas fa-times me-1"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    <h2>Existing Gifts</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Duration</th>
                                    <th>Availability</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gifts as $gift): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($gift['name']) ?></td>
                                        <td><?= htmlspecialchars($gift['description']) ?></td>
                                        <td><?= $gift['duration_months'] ?> months</td>
                                        <td>
                                            <?= $gift['is_global'] ? 'All Premium' : (htmlspecialchars($gift['membership_name'] ?? 'Specific Level')) ?>
                                        </td>
                                        <td>
                                            <a href="gift_membership.php?edit=<?= $gift['id'] ?>" class="btn btn-sm btn-primary me-1">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="gift_id" value="<?= $gift['id'] ?>">
                                                <button type="submit" name="delete_gift" class="btn btn-sm btn-danger" 
                                                        onclick="return confirm('Are you sure you want to delete this gift?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const globalCheckbox = document.getElementById('is_global');
                const membershipGroup = document.getElementById('membership-group');
                
                if (globalCheckbox && membershipGroup) {
                    globalCheckbox.addEventListener('change', function() {
                        membershipGroup.style.display = this.checked ? 'none' : 'block';
                    });
                }
            });
            </script>

        <?php else: ?>
            <!-- User Interface -->
            <h1 class="mb-4"><i class="fas fa-gift me-2"></i>My Membership Rewards</h1>
            
            <div class="card mb-4">
                <div class="card-body text-center">
                    <h2 class="card-title">Your Membership Status</h2>
                    <div class="display-4 my-3"><?= $consecutive_months ?></div>
                    <p class="lead">Consecutive months of active membership</p>
                </div>
            </div>
            
            <?php if (empty($gifts)): ?>
                <div class="alert alert-info">
                    No rewards available for your current membership level
                </div>
            <?php else: ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h2>Available Rewards</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($gifts as $gift): 
                                $earned = false;
                                $earned_date = null;
                                foreach ($earned_gifts as $eg) {
                                    if ($eg['id'] == $gift['id']) {
                                        $earned = true;
                                        $earned_date = $eg['earned_date'];
                                        break;
                                    }
                                }
                                $progress = min(100, ($consecutive_months / $gift['duration_months']) * 100);
                            ?>
                                <div class="col-md-6 mb-4">
                                    <div class="card h-100 <?= $earned ? 'border-success' : '' ?>">
                                        <div class="card-body position-relative">
                                            <?php if ($earned): ?>
                                                <span class="badge bg-success earned-badge">Earned</span>
                                            <?php endif; ?>
                                            
                                            <div class="d-flex align-items-start">
                                                <div class="gift-icon me-3">
                                                    <i class="fas fa-gift"></i>
                                                </div>
                                                <div class="flex-grow-1">
                                                    <h3 class="card-title"><?= htmlspecialchars($gift['name']) ?></h3>
                                                    <p class="card-text"><?= htmlspecialchars($gift['description']) ?></p>
                                                    
                                                    <div class="progress mb-2">
                                                        <div class="progress-bar <?= $earned ? 'bg-success' : '' ?>" 
                                                             style="width: <?= $progress ?>%">
                                                        </div>
                                                    </div>
                                                    
                                                    <p class="text-muted mb-0">
                                                        <?php if ($earned): ?>
                                                            Earned on <?= date('M j, Y', strtotime($earned_date)) ?>
                                                        <?php else: ?>
                                                            <?= $consecutive_months ?> of <?= $gift['duration_months'] ?> months
                                                        <?php endif; ?>
                                                    </p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($earned_gifts)): ?>
                <div class="card">
                    <div class="card-header">
                        <h2>Your Earned Rewards</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($earned_gifts as $gift): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 border-success">
                                        <div class="card-body text-center">
                                            <div class="gift-icon mb-3">
                                                <i class="fas fa-gift"></i>
                                            </div>
                                            <h4><?= htmlspecialchars($gift['name']) ?></h4>
                                            <p class="text-muted">
                                                Earned on <?= date('M j, Y', strtotime($gift['earned_date'])) ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>