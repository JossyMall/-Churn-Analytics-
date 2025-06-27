Here's the complete reworked `reports.php` with full export functionality and cohort reporting:

```php
<?php
require_once 'includes/header.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
$user_id = $_SESSION['user_id'];

// Handle exports
if (isset($_GET['export'])) {
    $stream_id = isset($_GET['stream_id']) ? intval($_GET['stream_id']) : 0;
    
    if ($_GET['export'] === 'csv') {
        exportCSV($pdo, $user_id, $stream_id);
    } elseif ($_GET['export'] === 'pdf') {
        exportPDF($pdo, $user_id, $stream_id);
    }
    exit;
}

// Get user's streams
$stmt = $pdo->prepare("SELECT id, name FROM streams WHERE user_id = ? OR team_id IN (SELECT team_id FROM team_members WHERE user_id = ?)");
$stmt->execute([$user_id, $user_id]);
$streams = $stmt->fetchAll();

// Get selected stream
$selected_stream = isset($_GET['stream_id']) ? intval($_GET['stream_id']) : ($streams[0]['id'] ?? 0);
$stream_name = '';
$report_data = [];
$cohort_data = [];
$metrics = [];

if ($selected_stream > 0) {
    // Get stream name
    $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
    $stmt->execute([$selected_stream]);
    $stream = $stmt->fetch();
    $stream_name = $stream['name'];

    // Get churn scores
    $stmt = $pdo->prepare("SELECT c.id, c.username, c.email, cs.score, cs.scored_at 
                          FROM contacts c
                          JOIN churn_scores cs ON c.id = cs.contact_id
                          WHERE c.stream_id = ?
                          ORDER BY cs.score DESC, cs.scored_at DESC");
    $stmt->execute([$selected_stream]);
    $report_data = $stmt->fetchAll();

    // Get winback suggestions
    $suggestions = [];
    $stmt = $pdo->prepare("SELECT contact_id, suggestion FROM winback_suggestions 
                          WHERE contact_id IN (SELECT id FROM contacts WHERE stream_id = ?)
                          ORDER BY suggested_at DESC");
    $stmt->execute([$selected_stream]);
    $suggestions = $stmt->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_COLUMN);

    // Get churned users count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM churned_users 
                          WHERE contact_id IN (SELECT id FROM contacts WHERE stream_id = ?)");
    $stmt->execute([$selected_stream]);
    $churned_count = $stmt->fetchColumn();

    // Get resurrected users count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM resurrected_users 
                          WHERE contact_id IN (SELECT id FROM contacts WHERE stream_id = ?)");
    $stmt->execute([$selected_stream]);
    $resurrected_count = $stmt->fetchColumn();

    // Get total contacts
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE stream_id = ?");
    $stmt->execute([$selected_stream]);
    $total_contacts = $stmt->fetchColumn();

    // Calculate churn rate
    $churn_rate = $total_contacts > 0 ? ($churned_count / $total_contacts) * 100 : 0;

    // Get cohort data
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.description, COUNT(cc.contact_id) as member_count,
               c.cost_per_user, c.revenue_per_user,
               AVG(cs.score) as avg_churn_score,
               SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END) as churned_count
        FROM cohorts c
        LEFT JOIN contact_cohorts cc ON c.id = cc.cohort_id
        LEFT JOIN contacts con ON cc.contact_id = con.id
        LEFT JOIN churn_scores cs ON con.id = cs.contact_id AND cs.scored_at = (
            SELECT MAX(scored_at) FROM churn_scores WHERE contact_id = con.id
        )
        LEFT JOIN churned_users cu ON con.id = cu.contact_id
        WHERE c.stream_id = ?
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute([$selected_stream]);
    $cohort_data = $stmt->fetchAll();

    // Calculate metrics
    $metrics = [
        'total_contacts' => $total_contacts,
        'churned_count' => $churned_count,
        'resurrected_count' => $resurrected_count,
        'churn_rate' => $churn_rate,
        'total_cohorts' => count($cohort_data),
        'total_cohort_members' => array_sum(array_column($cohort_data, 'member_count'))
    ];
}

function exportCSV($pdo, $user_id, $stream_id) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="churn_report_' . $stream_id . '_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Main report data
    $stmt = $pdo->prepare("SELECT c.id, c.username, c.email, cs.score, cs.scored_at 
                          FROM contacts c
                          JOIN churn_scores cs ON c.id = cs.contact_id
                          WHERE c.stream_id = ?
                          ORDER BY cs.score DESC, cs.scored_at DESC");
    $stmt->execute([$stream_id]);
    
    // Write headers
    fputcsv($output, ['ID', 'Username', 'Email', 'Churn Score', 'Last Scored']);
    
    // Write data
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    
    // Cohort data
    fputcsv($output, []);
    fputcsv($output, ['Cohort Report']);
    fputcsv($output, ['ID', 'Name', 'Members', 'Avg Churn Score', 'Churned Members', 'Cost Per User', 'Revenue Per User']);
    
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, COUNT(cc.contact_id) as member_count,
               AVG(cs.score) as avg_churn_score,
               SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END) as churned_count,
               c.cost_per_user, c.revenue_per_user
        FROM cohorts c
        LEFT JOIN contact_cohorts cc ON c.id = cc.cohort_id
        LEFT JOIN contacts con ON cc.contact_id = con.id
        LEFT JOIN churn_scores cs ON con.id = cs.contact_id AND cs.scored_at = (
            SELECT MAX(scored_at) FROM churn_scores WHERE contact_id = con.id
        )
        LEFT JOIN churned_users cu ON con.id = cu.contact_id
        WHERE c.stream_id = ?
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute([$stream_id]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
    
    fclose($output);
}

function exportPDF($pdo, $user_id, $stream_id) {
    require_once 'assets/libs/tcpdf/tcpdf.php';
    
    // Get stream name
    $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
    $stmt->execute([$stream_id]);
    $stream = $stmt->fetch();
    $stream_name = $stream['name'];
    
    // Create PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(PDF_CREATOR);
    $pdf->SetAuthor('Churn Analytics');
    $pdf->SetTitle('Churn Report - ' . $stream_name);
    $pdf->SetSubject('Churn Analytics Report');
    $pdf->SetKeywords('Churn, Analytics, Report');
    
    $pdf->setHeaderData('', 0, 'Churn Analytics Report', $stream_name . "\nGenerated on " . date('F j, Y'));
    $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
    $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->AddPage();
    
    // Main report data
    $stmt = $pdo->prepare("SELECT c.id, c.username, c.email, cs.score, cs.scored_at 
                          FROM contacts c
                          JOIN churn_scores cs ON c.id = cs.contact_id
                          WHERE c.stream_id = ?
                          ORDER BY cs.score DESC, cs.scored_at DESC");
    $stmt->execute([$stream_id]);
    $report_data = $stmt->fetchAll();
    
    // Metrics
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM contacts WHERE stream_id = ?");
    $stmt->execute([$stream_id]);
    $total_contacts = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM churned_users WHERE contact_id IN (SELECT id FROM contacts WHERE stream_id = ?)");
    $stmt->execute([$stream_id]);
    $churned_count = $stmt->fetchColumn();
    
    $churn_rate = $total_contacts > 0 ? ($churned_count / $total_contacts) * 100 : 0;
    
    // Add metrics summary
    $html = '<h2>Metrics Summary</h2>
    <table border="1" cellpadding="4">
        <tr>
            <th>Total Contacts</th>
            <th>Churned Users</th>
            <th>Churn Rate</th>
        </tr>
        <tr>
            <td>' . $total_contacts . '</td>
            <td>' . $churned_count . '</td>
            <td>' . number_format($churn_rate, 1) . '%</td>
        </tr>
    </table><br>';
    
    // Add churn risk table
    $html .= '<h2>Churn Risk Analysis</h2>
    <table border="1" cellpadding="4">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Churn Score</th>
            <th>Last Scored</th>
        </tr>';
    
    foreach ($report_data as $row) {
        $html .= '<tr>
            <td>' . $row['id'] . '</td>
            <td>' . $row['username'] . '</td>
            <td>' . $row['email'] . '</td>
            <td>' . number_format($row['score'], 1) . '%</td>
            <td>' . date('M j, Y', strtotime($row['scored_at'])) . '</td>
        </tr>';
    }
    
    $html .= '</table>';
    
    // Cohort data
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, COUNT(cc.contact_id) as member_count,
               AVG(cs.score) as avg_churn_score,
               SUM(CASE WHEN cu.id IS NOT NULL THEN 1 ELSE 0 END) as churned_count,
               c.cost_per_user, c.revenue_per_user
        FROM cohorts c
        LEFT JOIN contact_cohorts cc ON c.id = cc.cohort_id
        LEFT JOIN contacts con ON cc.contact_id = con.id
        LEFT JOIN churn_scores cs ON con.id = cs.contact_id AND cs.scored_at = (
            SELECT MAX(scored_at) FROM churn_scores WHERE contact_id = con.id
        )
        LEFT JOIN churned_users cu ON con.id = cu.contact_id
        WHERE c.stream_id = ?
        GROUP BY c.id
        ORDER BY c.name
    ");
    $stmt->execute([$stream_id]);
    $cohort_data = $stmt->fetchAll();
    
    if (!empty($cohort_data)) {
        $html .= '<br><h2>Cohort Analysis</h2>
        <table border="1" cellpadding="4">
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Members</th>
                <th>Avg Churn Score</th>
                <th>Churned</th>
                <th>Cost/User</th>
                <th>Revenue/User</th>
            </tr>';
        
        foreach ($cohort_data as $row) {
            $html .= '<tr>
                <td>' . $row['id'] . '</td>
                <td>' . $row['name'] . '</td>
                <td>' . $row['member_count'] . '</td>
                <td>' . number_format($row['avg_churn_score'], 1) . '%</td>
                <td>' . $row['churned_count'] . '</td>
                <td>$' . number_format($row['cost_per_user'], 2) . '</td>
                <td>$' . number_format($row['revenue_per_user'], 2) . '</td>
            </tr>';
        }
        
        $html .= '</table>';
    }
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output('churn_report_' . $stream_id . '_' . date('Y-m-d') . '.pdf', 'D');
}
?>

<div class="reports-container">
    <h1>Churn Analytics Reports</h1>
    
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
    
    <?php if ($selected_stream > 0): ?>
        <div class="report-header">
            <h2><?= htmlspecialchars($stream_name) ?></h2>
            <div class="stats-summary">
                <div class="stat-card">
                    <h3>Total Contacts</h3>
                    <p><?= number_format($metrics['total_contacts']) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Churned Users</h3>
                    <p><?= number_format($metrics['churned_count']) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Resurrected Users</h3>
                    <p><?= number_format($metrics['resurrected_count']) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Churn Rate</h3>
                    <p><?= number_format($metrics['churn_rate'], 1) ?>%</p>
                </div>
                <div class="stat-card">
                    <h3>Cohorts</h3>
                    <p><?= number_format($metrics['total_cohorts']) ?></p>
                </div>
                <div class="stat-card">
                    <h3>Cohort Members</h3>
                    <p><?= number_format($metrics['total_cohort_members']) ?></p>
                </div>
            </div>
        </div>
        
        <div class="report-tabs">
            <button class="tab-btn active" data-tab="churn-tab">Churn Risk</button>
            <button class="tab-btn" data-tab="cohort-tab">Cohorts</button>
        </div>
        
        <div id="churn-tab" class="tab-content active">
            <div class="churn-risk-table">
                <h3>Churn Risk Analysis</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Contact</th>
                            <th>Email</th>
                            <th>Churn Score</th>
                            <th>Last Scored</th>
                            <th>Winback Suggestions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['username'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($row['email'] ?? 'N/A') ?></td>
                                <td>
                                    <div class="score-bar">
                                        <div class="score-fill" style="width: <?= $row['score'] ?>%"></div>
                                        <span class="score-text"><?= number_format($row['score'], 1) ?>%</span>
                                    </div>
                                </td>
                                <td><?= date('M j, Y', strtotime($row['scored_at'])) ?></td>
                                <td>
                                    <?php if (isset($suggestions[$row['id']])): ?>
                                        <ul class="suggestion-list">
                                            <?php foreach ($suggestions[$row['id']] as $suggestion): ?>
                                                <li><?= htmlspecialchars($suggestion) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else: ?>
                                        No suggestions
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div id="cohort-tab" class="tab-content">
            <div class="cohort-table">
                <h3>Cohort Analysis</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Members</th>
                            <th>Avg Churn Score</th>
                            <th>Churned</th>
                            <th>Cost/User</th>
                            <th>Revenue/User</th>
                            <th>Profit/User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cohort_data as $cohort): ?>
                            <tr>
                                <td><?= htmlspecialchars($cohort['name']) ?></td>
                                <td><?= $cohort['member_count'] ?></td>
                                <td><?= number_format($cohort['avg_churn_score'], 1) ?>%</td>
                                <td><?= $cohort['churned_count'] ?></td>
                                <td>$<?= number_format($cohort['cost_per_user'], 2) ?></td>
                                <td>$<?= number_format($cohort['revenue_per_user'], 2) ?></td>
                                <td>$<?= number_format($cohort['revenue_per_user'] - $cohort['cost_per_user'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="export-actions">
            <a href="reports.php?export=csv&stream_id=<?= $selected_stream ?>" class="export-btn">Export as CSV</a>
            <a href="reports.php?export=pdf&stream_id=<?= $selected_stream ?>" class="export-btn">Export as PDF</a>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <p>No streams available. Please create a stream first.</p>
        </div>
    <?php endif; ?>
</div>

<style>
    .reports-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
    
    .report-header {
        margin-bottom: 30px;
    }
    
    .stats-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .stat-card {
        background: white;
        border: 1px solid #eee;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
    }
    
    .stat-card h3 {
        color: #666;
        font-size: 1rem;
        margin-bottom: 10px;
    }
    
    .stat-card p {
        font-size: 1.5rem;
        font-weight: bold;
        color: #3ac3b8;
        margin: 0;
    }
    
    .report-tabs {
        display: flex;
        border-bottom: 1px solid #ddd;
        margin-bottom: 20px;
    }
    
    .tab-btn {
        padding: 10px 20px;
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        cursor: pointer;
        font-weight: 500;
    }
    
    .tab-btn.active {
        border-bottom-color: #3ac3b8;
        color: #3ac3b8;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .churn-risk-table, .cohort-table {
        margin-top: 30px;
        overflow-x: auto;
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
    
    .score-bar {
        position: relative;
        height: 24px;
        background: #f0f0f0;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .score-fill {
        height: 100%;
        background: linear-gradient(90deg, #ff6b6b, #ff8e8e);
    }
    
    .score-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 0.8rem;
        color: #333;
    }
    
    .suggestion-list {
        margin: 0;
        padding-left: 20px;
    }
    
    .suggestion-list li {
        margin-bottom: 5px;
        font-size: 0.9rem;
    }
    
    .export-actions {
        margin-top: 30px;
        display: flex;
        gap: 15px;
    }
    
    .export-btn {
        background: #3ac3b8;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        text-decoration: none;
        display: inline-block;
    }
    
    .empty-state {
        padding: 40px;
        text-align: center;
        color: #666;
        border: 1px dashed #ddd;
        border-radius: 8px;
    }
</style>

<script>
    // Stream selector change
    document.getElementById('streamSelect').addEventListener('change', function() {
        window.location.href = 'reports.php?stream_id=' + this.value;
    });

    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            // Remove active class from all buttons and content
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId).classList.add('active');
        });
    });
</script>

<?php
require_once 'includes/footer.php';
?>
```