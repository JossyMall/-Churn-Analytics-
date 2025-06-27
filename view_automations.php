<?php
// MUST BE AT THE VERY TOP - NO WHITESPACE BEFORE THIS
session_start(); // Start session first

// Enable error reporting for debugging, but disable display in production
ini_set('display_errors', 1); // TEMPORARILY SET TO 1 TO DISPLAY ALL ERRORS
ini_set('display_startup_errors', 1); // TEMPORARILY SET TO 1 TO DISPLAY ALL ERRORS
error_reporting(E_ALL);

// Check login status BEFORE including any files that might output content
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

// Now include other files
require_once 'includes/db.php';
require_once 'includes/header.php'; // Assuming this includes necessary HTML header and potentially BASE_URL

$user_id = $_SESSION['user_id'];

// Pagination settings
$records_per_page = 20;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Fetch total number of automations for pagination
$total_automations_stmt = $pdo->prepare("SELECT COUNT(*) FROM automation_workflows WHERE user_id = ?");
$total_automations_stmt->execute([$user_id]);
$total_automations = $total_automations_stmt->fetchColumn();
$total_pages = ceil($total_automations / $records_per_page);

// Fetch automations for the current page
$stmt = $pdo->prepare("SELECT id, name, description, is_active, created_at, updated_at FROM automation_workflows WHERE user_id = ? ORDER BY created_at DESC LIMIT ?, ?");
$stmt->bindValue(1, $user_id, PDO::PARAM_INT);
$stmt->bindValue(2, $offset, PDO::PARAM_INT);
$stmt->bindValue(3, $records_per_page, PDO::PARAM_INT);
$stmt->execute();
$automations = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Automations</title>
    <link rel="stylesheet" href="assets/css/automations_builder.css"> <!-- Reusing some styles from here -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* General table styling for view_automations.php */
        .automations-list-container {
            background-color: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 900px;
            box-sizing: border-box;
            margin: 20px auto; /* Center the container */
        }

        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 15px;
        }

        .list-header h2 {
            margin: 0;
            color: var(--text-color);
            font-size: 1.8em;
        }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            background-color: var(--background-dark);
            border-radius: 8px;
            color: var(--secondary-color);
            font-size: 1.1em;
        }

        .empty-state p {
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: #fff;
            border-radius: 8px;
            overflow: hidden; /* Ensures rounded corners apply to content */
        }

        table thead tr {
            background-color: var(--primary-color);
            color: #fff;
            text-align: left;
        }

        table th, table td {
            padding: 12px 15px;
            border: 1px solid var(--border-color);
        }

        table tbody tr:nth-child(even) {
            background-color: var(--background-dark);
        }

        table tbody tr:hover {
            background-color: #f1f1f1;
        }

        .status-badge {
            padding: 5px 10px;
            border-radius: 5px;
            font-weight: 600;
            font-size: 0.9em;
            color: #fff;
        }

        .status-badge.active {
            background-color: var(--success-color);
        }

        .status-badge.inactive {
            background-color: var(--secondary-color);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .action-buttons .btn {
            padding: 8px 15px;
            font-size: 0.9em;
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 30px;
            gap: 10px;
        }

        .pagination a, .pagination span {
            padding: 10px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            text-decoration: none;
            color: var(--primary-color);
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .pagination a:hover {
            background-color: var(--primary-color);
            color: #fff;
        }

        .pagination span.current-page {
            background-color: var(--primary-color);
            color: #fff;
            font-weight: 600;
            border-color: var(--primary-color);
        }

        /* Responsive adjustments for table */
        @media (max-width: 768px) {
            .automations-list-container {
                padding: 15px;
            }
            table, thead, tbody, th, td, tr {
                display: block;
            }
            thead tr {
                position: absolute;
                top: -9999px;
                left: -9999px;
            }
            table tr {
                margin-bottom: 15px;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            }
            table td {
                border: none;
                position: relative;
                padding-left: 50%;
                text-align: right;
                border-bottom: 1px dashed var(--border-color);
            }
            table td:last-child {
                border-bottom: 0;
            }
            table td:before {
                content: attr(data-label);
                position: absolute;
                left: 15px;
                width: calc(50% - 30px);
                padding-right: 10px;
                white-space: nowrap;
                text-align: left;
                font-weight: 600;
                color: var(--secondary-color);
            }
            .action-buttons {
                justify-content: flex-end;
            }
        }
    </style>
</head>
<body>
<div class="automations-list-container">
    <div class="list-header">
        <h2>Your Automations</h2>
        <a href="automations.php?create" class="btn btn-primary new-btn">
            <i class="fas fa-plus"></i> New Automation
        </a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (empty($automations)): ?>
        <div class="empty-state">
            <p>No automations created yet.</p>
            <a href="automations.php?create" class="btn btn-primary">Create Your First Automation</a>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Created On</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($automations as $automation): ?>
                    <tr>
                        <td data-label="Name"><?= htmlspecialchars($automation['name']) ?></td>
                        <td data-label="Status">
                            <span class="status-badge <?= $automation['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $automation['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td data-label="Created On"><?= date('M j, Y', strtotime($automation['created_at'])) ?></td>
                        <td data-label="Last Updated">
                            <?= $automation['updated_at'] ? date('M j, Y H:i', strtotime($automation['updated_at'])) : 'N/A' ?>
                        </td>
                        <td data-label="Actions">
                            <div class="action-buttons">
                                <a href="automations.php?edit=<?= $automation['id'] ?>" class="btn btn-secondary">Edit</a>
                                <form method="POST" style="display:inline;" class="delete-automation-form">
                                    <input type="hidden" name="workflow_id" value="<?= $automation['id'] ?>">
                                    <button type="submit" name="delete_automation" class="btn btn-danger delete-btn-confirm">Delete</button>
                                </form>
                                <a href="automation_analytics.php?id=<?= $automation['id'] ?>" class="btn btn-info">Analytics</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($current_page > 1): ?>
                    <a href="?page=<?= $current_page - 1 ?>">Previous</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <?php if ($i == $current_page): ?>
                        <span class="current-page"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($current_page < $total_pages): ?>
                    <a href="?page=<?= $current_page + 1 ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle delete confirmation using SweetAlert2
    document.querySelectorAll('.delete-automation-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault(); // Prevent default form submission
            Swal.fire({
                title: 'Are you sure?',
                text: "You won't be able to revert this!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit(); // Submit the form if confirmed
                }
            });
        });
    });
});
</script>
</body>
</html>
