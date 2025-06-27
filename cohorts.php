<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];
echo '<link rel="stylesheet" href="assets/css/cohorts.css">';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['create_cohort'])) {
            $name = $_POST['name'];
            $description = $_POST['description'] ?? '';
            $cost_per_user = $_POST['cost_per_user'] ?? 0;
            $revenue_per_user = $_POST['revenue_per_user'] ?? 0;
            $stream_id = $_POST['stream_id'];

            $valid_stream = $pdo->prepare("SELECT id FROM streams WHERE id = ? AND (user_id = ? OR team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))");
            $valid_stream->execute([$stream_id, $user_id, $user_id]);
            
            if (!$valid_stream->fetch()) {
                $error = "Invalid stream selected";
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO cohorts 
                    (stream_id, name, description, cost_per_user, revenue_per_user, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $stream_id, $name, $description, $cost_per_user, $revenue_per_user, $user_id
                ]);
                
                $cohort_id = $pdo->lastInsertId();
                $success = "Cohort created successfully!";
            }

        } elseif (isset($_POST['update_cohort'])) {
            $cohort_id = $_POST['cohort_id'];
            $name = $_POST['name'];
            $description = $_POST['description'] ?? '';
            $cost_per_user = $_POST['cost_per_user'] ?? 0;
            $revenue_per_user = $_POST['revenue_per_user'] ?? 0;

            $stmt = $pdo->prepare("
                UPDATE cohorts SET
                name = ?,
                description = ?,
                cost_per_user = ?,
                revenue_per_user = ?
                WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([
                $name, $description, $cost_per_user, $revenue_per_user, 
                $cohort_id, $user_id
            ]);
            
            $success = "Cohort updated successfully!";

        } elseif (isset($_POST['delete_cohort'])) {
            $cohort_id = $_POST['cohort_id'];
            
            $stmt = $pdo->prepare("DELETE FROM cohorts WHERE id = ? AND created_by = ?");
            $stmt->execute([$cohort_id, $user_id]);
            
            $stmt = $pdo->prepare("DELETE FROM contact_cohorts WHERE cohort_id = ?");
            $stmt->execute([$cohort_id]);
            
            $success = "Cohort deleted successfully!";

        } elseif (isset($_POST['add_contacts_to_cohort'])) {
            $cohort_id = $_POST['cohort_id'];
            $contact_ids = $_POST['contact_ids'] ?? [];
            
            $values = [];
            foreach ($contact_ids as $contact_id) {
                $values[] = "($contact_id, $cohort_id, NOW())";
            }
            
            if (!empty($values)) {
                $sql = "INSERT IGNORE INTO contact_cohorts (contact_id, cohort_id, joined_at) 
                        VALUES " . implode(',', $values);
                $pdo->exec($sql);
                $success = count($contact_ids) . " contacts added to cohort!";
            }

        } elseif (isset($_POST['remove_contacts_from_cohort'])) {
            $cohort_id = $_POST['cohort_id'];
            $contact_ids = $_POST['contact_ids'] ?? [];
            
            if (!empty($contact_ids)) {
                $placeholders = implode(',', array_fill(0, count($contact_ids), '?'));
                $stmt = $pdo->prepare("
                    DELETE FROM contact_cohorts 
                    WHERE cohort_id = ? AND contact_id IN ($placeholders)
                ");
                $stmt->execute(array_merge([$cohort_id], $contact_ids));
                $success = count($contact_ids) . " contacts removed from cohort!";
            }
        }
    } catch (PDOException $e) {
        $error = "Database error: " . $e->getMessage();
    }
}

$streams = $pdo->prepare("
    SELECT * FROM streams 
    WHERE user_id = ? OR team_id IN (SELECT team_id FROM team_members WHERE user_id = ?)
    ORDER BY name
");
$streams->execute([$user_id, $user_id]);
$streams = $streams->fetchAll();

$selected_stream_id = $_GET['stream_id'] ?? ($streams[0]['id'] ?? null);

$cohorts = [];
if ($selected_stream_id) {
    $cohorts = $pdo->prepare("
        SELECT c.*, 
               COUNT(cc.contact_id) as member_count,
               s.name as stream_name
        FROM cohorts c
        LEFT JOIN contact_cohorts cc ON c.id = cc.cohort_id
        LEFT JOIN streams s ON c.stream_id = s.id
        WHERE c.stream_id = ? AND c.created_by = ?
        GROUP BY c.id
        ORDER BY c.name
    ");
    $cohorts->execute([$selected_stream_id, $user_id]);
    $cohorts = $cohorts->fetchAll();
}

$contacts = [];
if ($selected_stream_id) {
    $contacts = $pdo->prepare("
        SELECT con.id, con.email, con.username, 
               GROUP_CONCAT(coh.name SEPARATOR ', ') as cohorts
        FROM contacts con
        LEFT JOIN contact_cohorts cc ON con.id = cc.contact_id
        LEFT JOIN cohorts coh ON cc.cohort_id = coh.id
        WHERE con.stream_id = ?
        GROUP BY con.id
        ORDER BY con.email
    ");
    $contacts->execute([$selected_stream_id]);
    $contacts = $contacts->fetchAll();
}

$selected_cohort_id = $_GET['cohort_id'] ?? null;
$cohort_members = [];
$cohort_metrics = [];
if ($selected_cohort_id) {
    $cohort_members = $pdo->prepare("
        SELECT c.*, cc.joined_at
        FROM contacts c
        JOIN contact_cohorts cc ON c.id = cc.contact_id
        WHERE cc.cohort_id = ?
        ORDER BY c.email
    ");
    $cohort_members->execute([$selected_cohort_id]);
    $cohort_members = $cohort_members->fetchAll();

    $cohort_metrics = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT c.id) as total_members,
            AVG(cs.score) as avg_churn_score,
            SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END) as churned_count,
            (SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END) / COUNT(DISTINCT c.id)) * 100 as churn_rate,
            co.cost_per_user,
            co.revenue_per_user,
            (co.revenue_per_user - co.cost_per_user) * COUNT(DISTINCT c.id) as estimated_profit
        FROM cohorts co
        LEFT JOIN contact_cohorts cc ON co.id = cc.cohort_id
        LEFT JOIN contacts c ON cc.contact_id = c.id
        LEFT JOIN churn_scores cs ON c.id = cs.contact_id AND cs.scored_at = (
            SELECT MAX(scored_at) FROM churn_scores WHERE contact_id = c.id
        )
        LEFT JOIN churned_users cu ON c.id = cu.contact_id
        WHERE co.id = ?
    ");
    $cohort_metrics->execute([$selected_cohort_id]);
    $cohort_metrics = $cohort_metrics->fetch();
}
?>

<div class="cohorts-container">
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="cohorts-header">
        <h1>Contact Segments (Cohorts)</h1>
        <div class="stream-selector">
            <form method="GET">
                <select name="stream_id" onchange="this.form.submit()">
                    <?php foreach ($streams as $stream): ?>
                        <option value="<?= $stream['id'] ?>" 
                            <?= $selected_stream_id == $stream['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($stream['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="cohorts-layout">
        <div class="cohorts-sidebar">
            <div class="cohorts-list">
                <div class="list-header">
                    <h3>Segments</h3>
                    <button class="btn btn-primary" id="new-cohort-btn">+ New Segment</button>
                </div>
                
                <div class="cohort-items">
                    <?php foreach ($cohorts as $cohort): ?>
                        <a href="?stream_id=<?= $selected_stream_id ?>&cohort_id=<?= $cohort['id'] ?>" 
                           class="cohort-item <?= $selected_cohort_id == $cohort['id'] ? 'active' : '' ?>">
                            <span class="cohort-name"><?= htmlspecialchars($cohort['name']) ?></span>
                            <span class="cohort-meta">
                                <span class="member-count"><?= $cohort['member_count'] ?> members</span>
                                <span class="stream-name"><?= htmlspecialchars($cohort['stream_name']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="cohorts-main">
            <?php if ($selected_cohort_id): ?>
                <div class="cohort-detail">
                    <div class="cohort-header">
                        <h2><?= htmlspecialchars($cohorts[array_search($selected_cohort_id, array_column($cohorts, 'id'))]['name']) ?></h2>
                        <div class="cohort-actions">
                            <button class="btn btn-secondary" id="edit-cohort-btn">Edit</button>
                            <form method="POST" class="delete-cohort-form">
                                <input type="hidden" name="cohort_id" value="<?= $selected_cohort_id ?>">
                                <button type="submit" name="delete_cohort" class="btn btn-danger" 
                                        onclick="return confirm('Are you sure? This will remove all members from this segment.')">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <div class="cohort-metrics">
                        <div class="metric-card">
                            <div class="metric-value"><?= $cohort_metrics['total_members'] ?? 0 ?></div>
                            <div class="metric-label">Total Members</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?= round($cohort_metrics['avg_churn_score'] ?? 0, 1) ?>%</div>
                            <div class="metric-label">Avg Churn Risk</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value"><?= round($cohort_metrics['churn_rate'] ?? 0, 1) ?>%</div>
                            <div class="metric-label">Churn Rate</div>
                        </div>
                        <div class="metric-card">
                            <div class="metric-value">$<?= number_format($cohort_metrics['estimated_profit'] ?? 0, 2) ?></div>
                            <div class="metric-label">Est. Profit</div>
                        </div>
                    </div>
                    
                    <div class="cohort-members-section">
                        <div class="section-header">
                            <h3>Segment Members (<?= count($cohort_members) ?>)</h3>
                            <div class="member-actions">
                                <button class="btn btn-primary" id="add-members-btn">+ Add Members</button>
                                <button class="btn btn-secondary" id="export-members-btn">Export</button>
                            </div>
                        </div>
                        
                        <div class="members-table-container">
                            <table class="members-table">
                                <thead>
                                    <tr>
                                        <th><input type="checkbox" id="select-all-members"></th>
                                        <th>Email</th>
                                        <th>Username</th>
                                        <th>Joined Segment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cohort_members as $member): ?>
                                        <tr>
                                            <td><input type="checkbox" name="member_ids[]" value="<?= $member['id'] ?>"></td>
                                            <td><?= htmlspecialchars($member['email']) ?></td>
                                            <td><?= htmlspecialchars($member['username']) ?></td>
                                            <td><?= date('M j, Y', strtotime($member['joined_at'])) ?></td>
                                            <td>
                                                <a href="contacts.php?contact_id=<?= $member['id'] ?>" class="btn btn-sm btn-secondary">View</a>
                                                <form method="POST" class="remove-member-form">
                                                    <input type="hidden" name="cohort_id" value="<?= $selected_cohort_id ?>">
                                                    <input type="hidden" name="contact_ids[]" value="<?= $member['id'] ?>">
                                                    <button type="submit" name="remove_contacts_from_cohort" class="btn btn-sm btn-danger">
                                                        Remove
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
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
                    <h3>No Segment Selected</h3>
                    <p>Select a segment from the sidebar or create a new one to get started.</p>
                    <button class="btn btn-primary" id="new-cohort-btn-main">+ New Segment</button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal" id="new-cohort-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New Segment</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="cohort-form">
                <div class="form-group">
                    <label>Select Stream</label>
                    <select name="stream_id" required>
                        <option value="">-- Select a Stream --</option>
                        <?php foreach ($streams as $stream): ?>
                            <option value="<?= $stream['id'] ?>">
                                <?= htmlspecialchars($stream['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Segment Name</label>
                    <input type="text" name="name" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Cost Per User ($)</label>
                        <input type="number" name="cost_per_user" step="0.01" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Revenue Per User ($)</label>
                        <input type="number" name="revenue_per_user" step="0.01" min="0" value="0">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                    <button type="submit" name="create_cohort" class="btn btn-primary">Create Segment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="edit-cohort-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Edit Segment</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="edit-cohort-form">
                <input type="hidden" name="cohort_id" value="<?= $selected_cohort_id ?>">
                
                <div class="form-group">
                    <label>Segment Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($cohorts[array_search($selected_cohort_id, array_column($cohorts, 'id'))]['name'] ?? '') ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3"><?= htmlspecialchars($cohorts[array_search($selected_cohort_id, array_column($cohorts, 'id'))]['description'] ?? '') ?></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Cost Per User ($)</label>
                        <input type="number" name="cost_per_user" step="0.01" min="0" 
                               value="<?= $cohorts[array_search($selected_cohort_id, array_column($cohorts, 'id'))]['cost_per_user'] ?? 0 ?>">
                    </div>
                    <div class="form-group">
                        <label>Revenue Per User ($)</label>
                        <input type="number" name="revenue_per_user" step="0.01" min="0" 
                               value="<?= $cohorts[array_search($selected_cohort_id, array_column($cohorts, 'id'))]['revenue_per_user'] ?? 0 ?>">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                    <button type="submit" name="update_cohort" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal" id="add-members-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add Members to Segment</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="add-members-form">
                <input type="hidden" name="cohort_id" value="<?= $selected_cohort_id ?>">
                
                <div class="form-group">
                    <label>Available Contacts</label>
                    <div class="contacts-selector">
                        <table class="contacts-table">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>Email</th>
                                    <th>Username</th>
                                    <th>Current Segments</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contacts as $contact): ?>
                                    <tr>
                                        <td><input type="checkbox" name="contact_ids[]" value="<?= $contact['id'] ?>"></td>
                                        <td><?= htmlspecialchars($contact['email']) ?></td>
                                        <td><?= htmlspecialchars($contact['username']) ?></td>
                                        <td><?= htmlspecialchars($contact['cohorts']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                    <button type="submit" name="add_contacts_to_cohort" class="btn btn-primary">Add to Segment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="assets/js/cohorts.js"></script>
<?php
require_once 'includes/footer.php';
?>