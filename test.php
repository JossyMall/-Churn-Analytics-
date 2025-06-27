<?php
require_once 'includes/header.php';
require_once 'includes/db.php';

// Authentication check
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['is_admin'] ?? 0) == 1;

// Initialize variables
$error = '';
$success = '';
$edit_gift = null;
$gifts = [];
$earned_gifts = [];
$consecutive_months = 0;
$membership_levels = [];
$stats = [
    'total_gifts' => 0,
    'global_gifts' => 0,
    'specific_gifts' => 0,
    'users_earned' => 0
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_admin) {
        if (isset($_POST['save_gift'])) {
            // Process gift creation/update
            $gift_id = isset($_POST['gift_id']) ? intval($_POST['gift_id']) : 0;
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $duration = intval($_POST['duration'] ?? 0);
            $is_global = isset($_POST['is_global']) ? 1 : 0;
            $membership_id = $is_global ? null : (isset($_POST['membership_id']) ? intval($_POST['membership_id']) : null);
            $icon = trim($_POST['icon'] ?? 'fa-gift');

            if (empty($name) || empty($description) || $duration <= 0) {
                $error = "Please fill all required fields correctly";
            } else {
                try {
                    if ($gift_id > 0) {
                        // Update existing gift
                        $stmt = $pdo->prepare("UPDATE membership_gifts SET 
                            name = ?, description = ?, duration_months = ?, 
                            membership_id = ?, is_global = ?, icon = ?
                            WHERE id = ?");
                        $stmt->execute([$name, $description, $duration, $membership_id, $is_global, $icon, $gift_id]);
                        $success = "Gift updated successfully";
                    } else {
                        // Create new gift
                        $stmt = $pdo->prepare("INSERT INTO membership_gifts 
                            (name, description, duration_months, membership_id, is_global, icon) 
                            VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$name, $description, $duration, $membership_id, $is_global, $icon]);
                        $success = "Gift created successfully";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            }
        } 
        elseif (isset($_POST['delete_gift'])) {
            // Process gift deletion
            $gift_id = intval($_POST['gift_id'] ?? 0);
            try {
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
        elseif (isset($_POST['bulk_action'])) {
            // Process bulk actions
            $action = $_POST['bulk_action'] ?? '';
            $gift_ids = $_POST['gift_ids'] ?? [];
            
            if (!empty($gift_ids) && in_array($action, ['delete', 'activate', 'deactivate'])) {
                try {
                    $placeholders = implode(',', array_fill(0, count($gift_ids), '?'));
                    
                    if ($action === 'delete') {
                        // Check if any selected gifts have been earned
                        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_gifts WHERE gift_id IN ($placeholders)");
                        $stmt->execute($gift_ids);
                        $count = $stmt->fetchColumn();
                        
                        if ($count > 0) {
                            $error = "Cannot delete - some gifts have been earned by users";
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM membership_gifts WHERE id IN ($placeholders)");
                            $stmt->execute($gift_ids);
                            $success = "Selected gifts deleted successfully";
                        }
                    } else {
                        $status = ($action === 'activate') ? 1 : 0;
                        $stmt = $pdo->prepare("UPDATE membership_gifts SET is_active = ? WHERE id IN ($placeholders)");
                        array_unshift($gift_ids, $status);
                        $stmt->execute($gift_ids);
                        $success = "Selected gifts updated successfully";
                    }
                } catch (PDOException $e) {
                    $error = "Database error: " . $e->getMessage();
                }
            } else {
                $error = "Invalid bulk action or no gifts selected";
            }
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
    
    // Fetch all gifts with statistics
    try {
        $gifts = $pdo->query("
            SELECT g.*, m.name as membership_name,
            (SELECT COUNT(*) FROM user_gifts WHERE gift_id = g.id) as earned_count
            FROM membership_gifts g
            LEFT JOIN membership_levels m ON g.membership_id = m.id
            ORDER BY g.duration_months ASC
        ")->fetchAll();
        
        // Fetch membership levels for dropdown
        $membership_levels = $pdo->query("SELECT id, name FROM membership_levels WHERE is_active = 1")->fetchAll();
        
        // Get statistics
        $stats['total_gifts'] = $pdo->query("SELECT COUNT(*) FROM membership_gifts")->fetchColumn();
        $stats['global_gifts'] = $pdo->query("SELECT COUNT(*) FROM membership_gifts WHERE is_global = 1")->fetchColumn();
        $stats['specific_gifts'] = $stats['total_gifts'] - $stats['global_gifts'];
        $stats['users_earned'] = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM user_gifts")->fetchColumn();
    } catch (PDOException $e) {
        $error = "Failed to load data: " . $e->getMessage();
    }
} 
// Regular user interface data remains the same...
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $is_admin ? 'Gift Management' : 'My Membership Rewards' ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .gift-card {
            transition: transform 0.3s ease;
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
        .stats-card {
            transition: all 0.3s ease;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .icon-preview {
            font-size: 2rem;
            margin-right: 10px;
        }
        .select2-container {
            width: 100% !important;
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
            <!-- Enhanced Admin Interface -->
            <h1 class="mb-4"><i class="fas fa-gift me-2"></i>Gift Management</h1>
            
            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card stats-card bg-primary text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-gift icon-preview"></i>
                                <div>
                                    <h5 class="card-title mb-0">Total Gifts</h5>
                                    <p class="card-text display-6 mb-0"><?= $stats['total_gifts'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-success text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-globe icon-preview"></i>
                                <div>
                                    <h5 class="card-title mb-0">Global Gifts</h5>
                                    <p class="card-text display-6 mb-0"><?= $stats['global_gifts'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-info text-white">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-star icon-preview"></i>
                                <div>
                                    <h5 class="card-title mb-0">Specific Gifts</h5>
                                    <p class="card-text display-6 mb-0"><?= $stats['specific_gifts'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card stats-card bg-warning text-dark">
                        <div class="card-body">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-users icon-preview"></i>
                                <div>
                                    <h5 class="card-title mb-0">Users Earned</h5>
                                    <p class="card-text display-6 mb-0"><?= $stats['users_earned'] ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Gift Form -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="mb-0"><?= $edit_gift ? 'Edit Gift' : 'Create New Gift' ?></h2>
                    <?php if ($edit_gift): ?>
                        <a href="gift_membership.php" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times me-1"></i> Cancel
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php if ($edit_gift): ?>
                            <input type="hidden" name="gift_id" value="<?= $edit_gift['id'] ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Gift Name</label>
                                    <input type="text" name="name" class="form-control" 
                                           value="<?= htmlspecialchars($edit_gift['name'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Icon Class (Font Awesome)</label>
                                    <div class="input-group">
                                        <span class="input-group-text"><i id="icon-preview" class="<?= htmlspecialchars($edit_gift['icon'] ?? 'fa-gift') ?>"></i></span>
                                        <input type="text" name="icon" class="form-control" 
                                               value="<?= htmlspecialchars($edit_gift['icon'] ?? 'fa-gift') ?>" 
                                               placeholder="e.g. fa-gift, fa-trophy">
                                    </div>
                                    <small class="text-muted">Use <a href="https://fontawesome.com/icons" target="_blank">Font Awesome</a> icon classes</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="3" required><?= htmlspecialchars($edit_gift['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Duration (months)</label>
                                    <input type="number" name="duration" min="1" class="form-control" 
                                           value="<?= $edit_gift['duration_months'] ?? '1' ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3 form-check form-switch pt-3">
                                    <input type="checkbox" class="form-check-input" name="is_global" id="is_global" 
                                           <?= ($edit_gift['is_global'] ?? 0) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="is_global">Available to all premium members</label>
                                </div>
                            </div>
                            <div class="col-md-4" id="membership-group" style="<?= ($edit_gift['is_global'] ?? 0) ? 'display:none;' : '' ?>">
                                <div class="mb-3">
                                    <label class="form-label">Specific Membership Level</label>
                                    <select name="membership_id" class="form-select select2">
                                        <option value="">-- Select Membership Level --</option>
                                        <?php foreach ($membership_levels as $level): ?>
                                            <option value="<?= $level['id'] ?>" 
                                                <?= (($edit_gift['membership_id'] ?? '') == $level['id']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($level['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <button type="submit" name="save_gift" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i> <?= $edit_gift ? 'Update' : 'Create' ?> Gift
                            </button>
                            
                            <?php if ($edit_gift): ?>
                                <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">
                                    <i class="fas fa-trash me-1"></i> Delete Gift
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Gifts Table with Bulk Actions -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="mb-0">Manage Gifts</h2>
                    <div class="d-flex">
                        <form method="POST" class="me-2" id="bulkForm">
                            <input type="hidden" name="bulk_action" id="bulkAction">
                            <div class="input-group">
                                <select class="form-select" id="bulkSelect">
                                    <option value="">Bulk Actions</option>
                                    <option value="delete">Delete Selected</option>
                                    <option value="activate">Activate Selected</option>
                                    <option value="deactivate">Deactivate Selected</option>
                                </select>
                                <button class="btn btn-outline-secondary" type="button" id="applyBulk">Apply</button>
                            </div>
                        </form>
                        <a href="gift_membership.php" class="btn btn-primary">
                            <i class="fas fa-plus me-1"></i> New Gift
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="giftsTable">
                            <thead>
                                <tr>
                                    <th width="50"><input type="checkbox" id="selectAll"></th>
                                    <th>Name</th>
                                    <th>Icon</th>
                                    <th>Description</th>
                                    <th>Duration</th>
                                    <th>Availability</th>
                                    <th>Earned By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gifts as $gift): ?>
                                    <tr>
                                        <td><input type="checkbox" name="gift_ids[]" value="<?= $gift['id'] ?>" class="gift-checkbox"></td>
                                        <td><?= htmlspecialchars($gift['name']) ?></td>
                                        <td><i class="<?= htmlspecialchars($gift['icon'] ?? 'fa-gift') ?>"></i></td>
                                        <td><?= htmlspecialchars($gift['description']) ?></td>
                                        <td><?= $gift['duration_months'] ?> months</td>
                                        <td>
                                            <?= $gift['is_global'] ? 'All Premium' : htmlspecialchars($gift['membership_name'] ?? 'Specific Level') ?>
                                        </td>
                                        <td>
                                            <?= $gift['earned_count'] ?> user(s)
                                            <?php if ($gift['earned_count'] > 0): ?>
                                                <a href="#" class="ms-2" data-bs-toggle="modal" data-bs-target="#earnedModal" 
                                                   data-gift-id="<?= $gift['id'] ?>" data-gift-name="<?= htmlspecialchars($gift['name']) ?>">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="gift_membership.php?edit=<?= $gift['id'] ?>" class="btn btn-primary">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <?php if ($gift['earned_count'] == 0): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="gift_id" value="<?= $gift['id'] ?>">
                                                        <button type="submit" name="delete_gift" class="btn btn-danger" 
                                                                onclick="return confirm('Are you sure you want to delete this gift?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <button class="btn btn-danger" disabled title="Cannot delete - users have earned this gift">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Delete Confirmation Modal -->
            <?php if ($edit_gift): ?>
                <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="deleteModalLabel">Confirm Deletion</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p>Are you sure you want to delete the gift "<?= htmlspecialchars($edit_gift['name']) ?>"?</p>
                                <?php
                                $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_gifts WHERE gift_id = ?");
                                $stmt->execute([$edit_gift['id']]);
                                $earned_count = $stmt->fetchColumn();
                                ?>
                                <?php if ($earned_count > 0): ?>
                                    <div class="alert alert-warning">
                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                        This gift has been earned by <?= $earned_count ?> user(s) and cannot be deleted.
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <?php if ($earned_count == 0): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="gift_id" value="<?= $edit_gift['id'] ?>">
                                        <button type="submit" name="delete_gift" class="btn btn-danger">
                                            <i class="fas fa-trash me-1"></i> Delete Gift
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Earned Users Modal -->
            <div class="modal fade" id="earnedModal" tabindex="-1" aria-labelledby="earnedModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="earnedModalLabel">Users Who Earned This Gift</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="earnedUsersContent">
                                Loading...
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Toggle membership selection based on global checkbox
                const globalCheckbox = document.getElementById('is_global');
                const membershipGroup = document.getElementById('membership-group');
                
                if (globalCheckbox && membershipGroup) {
                    globalCheckbox.addEventListener('change', function() {
                        membershipGroup.style.display = this.checked ? 'none' : 'block';
                    });
                }
                
                // Preview icon
                const iconInput = document.querySelector('input[name="icon"]');
                const iconPreview = document.getElementById('icon-preview');
                
                if (iconInput && iconPreview) {
                    iconInput.addEventListener('input', function() {
                        iconPreview.className = this.value;
                    });
                }
                
                // Initialize Select2
                $('.select2').select2();
                
                // Bulk actions
                const selectAll = document.getElementById('selectAll');
                const giftCheckboxes = document.querySelectorAll('.gift-checkbox');
                const bulkForm = document.getElementById('bulkForm');
                const bulkSelect = document.getElementById('bulkSelect');
                const applyBulk = document.getElementById('applyBulk');
                
                if (selectAll) {
                    selectAll.addEventListener('change', function() {
                        giftCheckboxes.forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });
                    });
                }
                
                if (applyBulk) {
                    applyBulk.addEventListener('click', function() {
                        const selectedAction = bulkSelect.value;
                        if (!selectedAction) return;
                        
                        const selectedGifts = Array.from(giftCheckboxes)
                            .filter(checkbox => checkbox.checked)
                            .map(checkbox => checkbox.value);
                        
                        if (selectedGifts.length === 0) {
                            alert('Please select at least one gift');
                            return;
                        }
                        
                        if (selectedAction === 'delete' && !confirm('Are you sure you want to delete the selected gifts?')) {
                            return;
                        }
                        
                        // Create hidden inputs for selected gift IDs
                        bulkForm.innerHTML = '';
                        const inputAction = document.createElement('input');
                        inputAction.type = 'hidden';
                        inputAction.name = 'bulk_action';
                        inputAction.value = selectedAction;
                        bulkForm.appendChild(inputAction);
                        
                        selectedGifts.forEach(giftId => {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = 'gift_ids[]';
                            input.value = giftId;
                            bulkForm.appendChild(input);
                        });
                        
                        bulkForm.submit();
                    });
                }
                
                // Earned users modal
                const earnedModal = document.getElementById('earnedModal');
                if (earnedModal) {
                    earnedModal.addEventListener('show.bs.modal', function(event) {
                        const button = event.relatedTarget;
                        const giftId = button.getAttribute('data-gift-id');
                        const giftName = button.getAttribute('data-gift-name');
                        
                        const modalTitle = earnedModal.querySelector('.modal-title');
                        modalTitle.textContent = `Users Who Earned "${giftName}"`;
                        
                        // Fetch earned users via AJAX
                        fetch(`ajax/get_earned_users.php?gift_id=${giftId}`)
                            .then(response => response.text())
                            .then(data => {
                                document.getElementById('earnedUsersContent').innerHTML = data;
                            })
                            .catch(error => {
                                document.getElementById('earnedUsersContent').innerHTML = 
                                    `<div class="alert alert-danger">Error loading data: ${error}</div>`;
                            });
                    });
                }
            });
            </script>

        <?php else: ?>
            <!-- Regular user interface remains the same -->
            <!-- ... -->
        <?php endif; ?>
    </div>
</body>
</html>