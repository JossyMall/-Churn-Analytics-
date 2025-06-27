<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Assuming this provides the HTML structure and common elements.

$user_id = $_SESSION['user_id'];

// --- PHP Error Reporting for Debugging ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- End PHP Error Reporting ---

// Get filter values from GET parameters
$selected_niche = isset($_GET['niche']) ? intval($_GET['niche']) : null;
$competitor_niche = isset($_GET['competitor_niche']) ? intval($_GET['competitor_niche']) : null;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Check if user has any streams (owned or shared) that could provide chart data
$user_streams_count_for_chart = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id)
    FROM streams s
    WHERE s.user_id = :user_id OR s.id IN (
        SELECT ts.stream_id FROM team_streams ts JOIN team_members tm ON ts.team_id = tm.team_id WHERE tm.user_id = :user_id_alt
    )
");
$user_streams_count_for_chart->execute([':user_id' => $user_id, ':user_id_alt' => $user_id]);
$has_relevant_streams_for_chart = $user_streams_count_for_chart->fetchColumn() > 0;

// Fetch all niches for filter dropdowns
$all_niches_stmt = $pdo->query("SELECT id, name FROM niches WHERE is_active = 1 ORDER BY name");
$all_niches = $all_niches_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
/* Minimalist CSS - No background */
body {
    /* Ensure no background is set here if header.php provides one */
    font-family: 'Inter', sans-serif; /* Using Inter font as per instructions */
}

.ranking-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    color: #333;
}

.ranking-section {
    margin-bottom: 40px;
    border: 1px solid #e0e0e0;
    border-radius: 4px;
    overflow: hidden;
}

.ranking-header {
    padding: 15px 20px;
    border-bottom: 1px solid #e0e0e0;
    background-color: #f8f9fa;
    display: flex; /* Added for alignment */
    justify-content: space-between; /* Added for alignment */
    align-items: center; /* Added for alignment */
}

.ranking-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
}

.ranking-body {
    padding: 20px;
}

.ranking-table {
    width: 100%;
    border-collapse: collapse;
}

.ranking-table th {
    text-align: left;
    padding: 12px 15px;
    border-bottom: 2px solid #e0e0e0;
    font-weight: 600;
}

.ranking-table td {
    padding: 10px 15px;
    border-bottom: 1px solid #e0e0e0;
}

.ranking-table tr:last-child td {
    border-bottom: none;
}

.ranking-table tr:hover td {
    background-color: #f5f5f5;
}

/* Custom Chart Container Styles */
.chart-container-wrapper {
    position: relative;
    background-color: #ffffff;
    border: 1px solid #e0e7ff;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    max-width: 900px; /* Adjust as needed within your layout */
    width: 100%;
    height: 400px; /* Fixed height for the canvas container */
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 1rem; /* Rounded corners */
    margin: 20px auto; /* Center the chart */
}

canvas {
    display: block;
    width: 100%;
    height: 100%;
    border-radius: 1rem; /* Apply to canvas as well */
}

/* Tooltip styling */
.chart-tooltip { /* Renamed to avoid conflict with other tooltips */
    position: absolute;
    background-color: rgba(30, 41, 59, 0.9); /* slate-800 with transparency */
    color: #ffffff;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    pointer-events: none; /* Allows mouse events to pass through */
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
    z-index: 100;
    white-space: nowrap;
    font-size: 0.875rem; /* text-sm */
    line-height: 1.25rem; /* leading-5 */
    transform: translate(-50%, -110%); /* Adjust position above the point */
}

.chart-tooltip.visible {
    opacity: 1;
}

.filter-control {
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}

.pagination {
    display: flex;
    gap: 5px;
    margin-top: 20px;
    list-style: none;
    padding: 0;
}

.pagination a {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-decoration: none;
    color: #333;
}

.pagination a:hover {
    background-color: #f0f0f0;
}

.pagination .active a {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
}

.trophy-icon {
    width: 18px;
    height: 18px;
    vertical-align: middle;
    margin-right: 5px;
}

.user-highlight {
    background-color: #3ac3b8 !important;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    background-color: #f0f4ff; /* Light blueish background */
    border: 1px solid #d0d8f0;
    border-radius: 8px;
    color: #4a5568; /* Gray-700 */
    margin-top: 20px;
}
.empty-state h3 {
    font-size: 1.5rem;
    margin-bottom: 10px;
    color: #2d3748; /* Gray-800 */
}
.empty-state p {
    font-size: 1rem;
    margin-bottom: 20px;
}
.empty-state .btn {
    display: inline-block;
    padding: 10px 20px;
    background-color: #4f46e5; /* Indigo-600 */
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-weight: 500;
    transition: background-color 0.2s ease;
}
.empty-state .btn:hover {
    background-color: #4338ca; /* Indigo-700 */
}
</style>

<div class="ranking-container">
    <h1 class="ranking-title">Performance Rankings</h1>
    
    <div class="ranking-section">
        <div class="ranking-header">
            <h2 class="ranking-title">Your Churn Rate vs Niche Average (Last 12 Months)</h2>
        </div>
        <div class="ranking-body">
            <?php if (!$has_relevant_streams_for_chart): ?>
                <div class="empty-state">
                    <h3>No Relevant Stream Data Found</h3>
                    <p>To see churn rate comparisons, you need to have at least one stream with associated contacts and some churn data.</p>
                    <a href="streams.php" class="btn">Create Stream</a>
                </div>
            <?php else: ?>
                <div class="chart-container-wrapper">
                    <canvas id="myLineChart"></canvas>
                    <div id="chartTooltip" class="chart-tooltip"></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="ranking-section">
        <div class="ranking-header">
            <h2 class="ranking-title">Stream Performance Ranking</h2>
            <form method="get" class="filter-control">
                <select name="niche" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Niches</option>
                    <?php
                    foreach ($all_niches as $niche) {
                        $selected = ($selected_niche == $niche['id']) ? 'selected' : '';
                        // Fix: Use null coalescing for $niche['name'] to prevent deprecation warning
                        echo "<option value='{$niche['id']}' $selected>" . htmlspecialchars($niche['name'] ?? '') . "</option>";
                    }
                    ?>
                </select>
            </form>
        </div>
        <div class="ranking-body">
            <?php
            // Get streams with churn data
            $stream_ranking_query = "
                SELECT s.id, s.name, s.acquisition_cost, s.user_id AS stream_owner_user_id, n.name AS niche_name,
                       COUNT(c.id) AS total_contacts,
                       SUM(CASE WHEN ch.contact_id IS NOT NULL THEN 1 ELSE 0 END) AS churned_count
                FROM streams s
                LEFT JOIN niches n ON s.niche_id = n.id
                LEFT JOIN contacts c ON s.id = c.stream_id
                LEFT JOIN churned_users ch ON c.id = ch.contact_id
            ";
            
            $ranking_params = [];
            if ($selected_niche) {
                $stream_ranking_query .= " WHERE s.niche_id = :niche_id_rank";
                $ranking_params[':niche_id_rank'] = $selected_niche;
            }
            
            $stream_ranking_query .= " GROUP BY s.id, s.name, s.acquisition_cost, s.user_id, n.name
                                   ORDER BY churned_count ASC, total_contacts DESC"; // Order by lower churn first
            
            $stmt_ranking = $pdo->prepare($stream_ranking_query);
            $stmt_ranking->execute($ranking_params);
            
            if ($stmt_ranking->rowCount() == 0): ?>
                <div class="empty-state">
                    <h3>No Ranking Data Available</h3>
                    <p>There's not enough data to generate rankings for the selected filters.</p>
                </div>
            <?php else: ?>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Stream</th>
                            <th>Churn Rate</th>
                            <th>Industry</th>
                            <th>Acquisition Cost</th>
                            <th>Cost/Churn Ratio</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $rank = 0;
                        while ($stream = $stmt_ranking->fetch(PDO::FETCH_ASSOC)) {
                            $rank++;
                            $churn_rate = $stream['total_contacts'] > 0 ?
                                ($stream['churned_count'] / $stream['total_contacts']) * 100 : 0;
                            // Ensure churned_count is not zero to avoid division by zero
                            $cost_churn_ratio = ($stream['churned_count'] > 0) ? number_format($stream['acquisition_cost'] / $stream['churned_count'], 2) : 'N/A';
                            
                            echo "<tr" . ($stream['stream_owner_user_id'] == $user_id ? " class='user-highlight'" : "") . ">";
                            echo "<td>";
                            if ($rank <= 3) {
                                echo "<svg class='trophy-icon' viewBox='0 0 24 24'><path fill='gold' d='M12 2L15 8H22L17 12L20 18L12 15L4 18L7 12L2 8H9L12 2Z'/></svg>";
                            }
                            echo $rank . "</td>";
                            echo "<td>" . htmlspecialchars($stream['name']) . "</td>";
                            echo "<td>" . number_format($churn_rate, 2) . "%</td>";
                            echo "<td>" . htmlspecialchars($stream['niche_name'] ?? '') . "</td>"; // Fix: null coalescing for niche_name
                            echo "<td>$" . number_format($stream['acquisition_cost'] ?? 0, 2) . "</td>"; // Fix: null coalescing
                            echo "<td>" . htmlspecialchars($cost_churn_ratio) . "</td>"; // Display 'N/A' if churned_count is 0
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="ranking-section">
        <div class="ranking-header">
            <h2 class="ranking-title">Competitors of Your Competitors</h2>
            <form method="get" class="filter-control">
                <input type="hidden" name="niche" value="<?= htmlspecialchars($selected_niche ?? '') ?>">
                <select name="competitor_niche" class="filter-select" onchange="this.form.submit()">
                    <option value="">All Niches (Your Streams)</option>
                    <?php
                    foreach ($all_niches as $niche) {
                        $selected = ($competitor_niche == $niche['id']) ? 'selected' : '';
                        echo "<option value='{$niche['id']}' $selected>" . htmlspecialchars($niche['name'] ?? '') . "</option>"; // Fix: null coalescing
                    }
                    ?>
                </select>
            </form>
        </div>
        <div class="ranking-body">
            <?php
            // First, get the niches of the current user's streams
            $user_niche_ids = [];
            $stmt_user_niches = $pdo->prepare("SELECT DISTINCT niche_id FROM streams WHERE user_id = :user_id_niche AND niche_id IS NOT NULL");
            $stmt_user_niches->execute([':user_id_niche' => $user_id]);
            $user_niche_ids_raw = $stmt_user_niches->fetchAll(PDO::FETCH_COLUMN);
            if (!empty($user_niche_ids_raw)) {
                $user_niche_ids = array_filter(array_unique($user_niche_ids_raw));
            }

            // Start the competitor query
            $competitor_query = "
                SELECT c.name, c.url, c.is_pricing, n.name AS niche_name,
                       GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') AS stream_names
                FROM competitors c
                JOIN streams s ON c.stream_id = s.id
                JOIN niches n ON s.niche_id = n.id
                WHERE s.user_id != :current_user_id_comp -- Competitors added by OTHER users
            ";
            
            $competitor_params = [':current_user_id_comp' => $user_id];
            $where_clauses = [];

            // Filter by selected competitor_niche if provided (this takes precedence)
            if ($competitor_niche) {
                $where_clauses[] = "s.niche_id = :competitor_niche_id";
                $competitor_params[':competitor_niche_id'] = $competitor_niche;
            } else if (!empty($user_niche_ids)) {
                // If no specific competitor_niche is selected, filter by the logged-in user's niches
                // This requires preparing a dynamic IN clause.
                $niche_placeholders = implode(',', array_fill(0, count($user_niche_ids), '?'));
                $where_clauses[] = "s.niche_id IN ({$niche_placeholders})";
                // Add niche IDs to competitor_params as positional parameters (keys will be numeric)
                $competitor_params = array_merge($competitor_params, array_values($user_niche_ids));
            } else {
                // If user has no niches and no specific competitor_niche is selected,
                // force no results for this section to avoid displaying irrelevant data.
                $where_clauses[] = "1 = 0"; // Force no results
            }

            if (!empty($where_clauses)) {
                $competitor_query .= " AND " . implode(" AND ", $where_clauses);
            }
            
            $competitor_query .= " GROUP BY c.id, c.name, c.url, c.is_pricing, n.name
                                   ORDER BY c.name ASC
                                   LIMIT :limit OFFSET :offset";
            
            $stmt_competitors = $pdo->prepare($competitor_query);
            
            // Bind the parameters dynamically, taking into account the varying number of niche_ids
            $bind_index = 1;
            foreach ($competitor_params as $param_key => $param_value) {
                if (is_string($param_key) && strpos($param_key, ':') === 0) { // Named parameter
                    $stmt_competitors->bindValue($param_key, $param_value);
                } else { // Positional parameter (for user_niche_ids in IN clause)
                    $stmt_competitors->bindValue($bind_index++, $param_value);
                }
            }
            // Bind LIMIT and OFFSET last, as their parameter names are fixed
            $stmt_competitors->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt_competitors->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt_competitors->execute();
            
            if ($stmt_competitors->rowCount() == 0): ?>
                <div class="empty-state">
                    <h3>No Competitors Found</h3>
                    <p>No competitors match your criteria. This could be because you don't have streams in common niches with other users, or no competitors are added for the selected niche.</p>
                </div>
            <?php else: ?>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th>Competitor Name</th>
                            <th>URL</th>
                            <th>Pricing Page</th>
                            <th>Niche</th>
                            <th>Streams Listing</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        while ($competitor = $stmt_competitors->fetch(PDO::FETCH_ASSOC)) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($competitor['name'] ?? '') . "</td>"; // Fix: null coalescing
                            echo "<td><a href='" . htmlspecialchars($competitor['url'] ?? '') . "' target='_blank'>" . htmlspecialchars($competitor['url'] ?? '') . "</a></td>"; // Fix: null coalescing
                            echo "<td>" . ($competitor['is_pricing'] ? 'Yes' : 'No') . "</td>";
                            echo "<td>" . htmlspecialchars($competitor['niche_name'] ?? '') . "</td>"; // Fix: null coalescing
                            echo "<td>" . htmlspecialchars($competitor['stream_names'] ?? '') . "</td>"; // Fix: null coalescing
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
                
                <?php
                // Count query for pagination, must match filtering logic of main query
                $count_query = "SELECT COUNT(DISTINCT c.id) AS total
                                FROM competitors c
                                JOIN streams s ON c.stream_id = s.id";
                
                $count_params = [':current_user_id_count' => $user_id];
                $count_where_clauses = ["s.user_id != :current_user_id_count"];

                if ($competitor_niche) {
                    $count_where_clauses[] = "s.niche_id = :competitor_niche_id_count";
                    $count_params[':competitor_niche_id_count'] = $competitor_niche;
                } else if (!empty($user_niche_ids)) {
                    $niche_placeholders_count = implode(',', array_fill(0, count($user_niche_ids), '?'));
                    $count_where_clauses[] = "s.niche_id IN ({$niche_placeholders_count})";
                    $count_params = array_merge($count_params, array_values($user_niche_ids));
                } else {
                    $count_where_clauses[] = "1 = 0"; // Match no results if no niches/filter
                }
                
                if (!empty($count_where_clauses)) {
                    $count_query .= " WHERE " . implode(" AND ", $count_where_clauses);
                }

                $stmt_count = $pdo->prepare($count_query);
                
                $count_bind_index = 1;
                foreach ($count_params as $param_key => $param_value) {
                     if (is_string($param_key) && strpos($param_key, ':') === 0) { // Named parameter
                        $stmt_count->bindValue($param_key, $param_value);
                    } else { // Positional parameter
                        $stmt_count->bindValue($count_bind_index++, $param_value);
                    }
                }
                $stmt_count->execute();
                $total = $stmt_count->fetchColumn();
                $total_pages = ceil($total / $limit);
                
                if ($total_pages > 1) {
                    echo '<ul class="pagination">';
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $active = ($i == $page) ? 'active' : '';
                        // Preserve existing GET parameters
                        $query_params = array_merge($_GET, ['page' => $i]);
                        $query_string = http_build_query($query_params);
                        echo "<li class='$active'><a href='?" . htmlspecialchars($query_string) . "'>$i</a></li>";
                    }
                    echo '</ul>';
                }
                ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="ranking-section">
        <div class="ranking-header">
            <h2 class="ranking-title">Affiliate Leaderboard</h2>
        </div>
        <div class="ranking-body">
            <?php
            $affiliate_query = "
                SELECT u.id, u.username, 
                       COUNT(r.id) AS referral_count,
                       SUM(CASE WHEN r.has_converted = 1 THEN 1 ELSE 0 END) AS conversion_count,
                       SUM(ae.amount) AS total_earnings -- Sum from affiliate_earnings table
                FROM users u
                LEFT JOIN affiliate_referrals r ON u.id = r.referrer_id
                LEFT JOIN affiliate_earnings ae ON u.id = ae.user_id AND ae.source = 'referral'
                GROUP BY u.id, u.username
                ORDER BY total_earnings DESC, referral_count DESC
                LIMIT 10
            ";
            
            $affiliates_top_10 = $pdo->query($affiliate_query)->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($affiliates_top_10)): // Check if the fetched top 10 is empty
            ?>
                <div class="empty-state">
                    <h3>No Affiliate Data</h3>
                    <p>There are no affiliate referrals or earnings recorded yet.</p>
                </div>
            <?php else: ?>
                <table class="ranking-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Affiliate</th>
                            <th>Referrals</th>
                            <th>Conversions</th>
                            <th>Earnings</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $affiliate_rank = 0;
                        $user_rank_data = null;
                        
                        // Fetch ALL affiliates for exact user rank determination, then filter top 10 for display
                        $all_affiliates_for_rank_query = "
                            SELECT u.id, 
                                   COUNT(r.id) AS referral_count,
                                   SUM(ae.amount) AS total_earnings
                            FROM users u
                            LEFT JOIN affiliate_referrals r ON u.id = r.referrer_id
                            LEFT JOIN affiliate_earnings ae ON u.id = ae.user_id AND ae.source = 'referral'
                            GROUP BY u.id
                            ORDER BY total_earnings DESC, referral_count DESC
                        ";
                        $all_affiliates_for_rank = $pdo->query($all_affiliates_for_rank_query)->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($all_affiliates_for_rank as $key => $affiliate_data_for_rank) {
                            if ($affiliate_data_for_rank['id'] == $user_id) {
                                $user_rank_data = ['rank' => $key + 1, 'data' => $affiliate_data_for_rank];
                                break;
                            }
                        }

                        foreach ($affiliates_top_10 as $affiliate) {
                            $affiliate_rank++; // This rank is only for the displayed top 10
                            
                            echo "<tr" . ($affiliate['id'] == $user_id ? " class='user-highlight'" : "") . ">";
                            echo "<td>";
                            if ($affiliate_rank <= 3) {
                                echo "<svg class='trophy-icon' viewBox='0 0 24 24'><path fill='gold' d='M12 2L15 8H22L17 12L20 18L12 15L4 18L7 12L2 8H9L12 2Z'/></svg>";
                            }
                            echo $affiliate_rank . "</td>";
                            echo "<td>" . htmlspecialchars($affiliate['username']) . ($affiliate['id'] == $user_id ? " (You)" : "") . "</td>";
                            echo "<td>" . htmlspecialchars($affiliate['referral_count'] ?? 0) . "</td>"; // Fix: null coalescing
                            echo "<td>" . htmlspecialchars($affiliate['conversion_count'] ?? 0) . "</td>"; // Fix: null coalescing
                            // Fix: Use null coalescing operator for total_earnings to prevent deprecation warning
                            echo "<td>$" . number_format($affiliate['total_earnings'] ?? 0, 2) . "</td>";
                            echo "</tr>";
                        }
                        ?>
                    </tbody>
                </table>
                
                <?php if ($user_rank_data && $user_rank_data['rank'] > $limit) : // If user is not in top 10 (and has data) ?>
                    <div style="margin-top: 15px; padding: 10px; border: 1px solid #e0e0e0; border-radius: 4px; background-color: #f8f8f8;">
                        Your current rank: <strong><?= $user_rank_data['rank'] ?></strong> (<?= $user_rank_data['data']['referral_count'] ?? 0 ?> referrals, 
                        $<?= number_format($user_rank_data['data']['total_earnings'] ?? 0, 2) ?> total earnings)
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const chartContainerWrapper = document.querySelector('.chart-container-wrapper');
    const canvas = document.getElementById('myLineChart');
    const tooltip = document.getElementById('chartTooltip');
    const ctx = canvas.getContext('2d'); // Initialize ctx once here, accessible by renderChart and event listeners.

    // Define demo data that matches the structure required by drawComparisonChart
    const today = new Date();
    const demoLabels = [];
    const demoUserChurnRates = [];
    const demoNicheAverages = [];
    for (let i = 11; i >= 0; i--) {
        const d = new Date(today.getFullYear(), today.getMonth() - i, 1);
        demoLabels.push(d.toLocaleString('en-us', { month: 'short', year: 'numeric' }));
        demoUserChurnRates.push(parseFloat((Math.random() * (15 - 10) + 10).toFixed(2))); // Churn between 10-15%
        demoNicheAverages.push(parseFloat((Math.random() * (14 - 9) + 9).toFixed(2))); // Churn between 9-14%
    }

    const demoSeries = [
        { label: 'Your Churn Rate (Demo)', data: demoLabels.map((label, index) => ({ label: label, value: demoUserChurnRates[index] })), color: '#4f46e5', gradientStart: 'rgba(79, 70, 229, 0.2)', gradientEnd: 'rgba(79, 70, 229, 0)' }, // Indigo-600
        { label: 'Niche Average (Demo)', data: demoLabels.map((label, index) => ({ label: label, value: demoNicheAverages[index] })), color: '#ef4444', gradientStart: 'rgba(239, 68, 68, 0.2)', gradientEnd: 'rgba(239, 68, 68, 0)' } // Red-500
    ];

    <?php if ($has_relevant_streams_for_chart): ?>
    fetch('includes/get_churn_data.php?user_id=<?= $user_id ?>')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.json();
        })
        .then(data => {
            let chartSeriesToUse = [];
            if (data.labels && data.labels.length > 0 && data.userChurnRates && data.userChurnRates.length > 0 && data.nicheAverages && data.nicheAverages.length > 0) {
                const userChartData = data.labels.map((label, index) => ({ label: label, value: data.userChurnRates[index] }));
                const nicheChartData = data.labels.map((label, index) => ({ label: label, value: data.nicheAverages[index] }));

                chartSeriesToUse = [
                    { label: 'Your Churn Rate', data: userChartData, color: '#4f46e5', gradientStart: 'rgba(79, 70, 229, 0.2)', gradientEnd: 'rgba(79, 70, 229, 0)' },
                    { label: 'Niche Average', data: nicheChartData, color: '#ef4444', gradientStart: 'rgba(239, 68, 68, 0.2)', gradientEnd: 'rgba(239, 68, 68, 0)' }
                ];
                drawComparisonChart(canvas, tooltip, chartSeriesToUse);
            } else {
                console.warn('Fetched churn data is empty. Displaying demo data.');
                if (chartContainerWrapper) {
                    chartContainerWrapper.innerHTML = `
                        <div class="empty-state">
                            <h3>Displaying Demo Churn Data</h3>
                            <p>We don't have enough real data for your streams yet. This chart shows sample data. Ensure your streams have contacts and recorded churn events.</p>
                        </div>
                    `;
                }
                drawComparisonChart(canvas, tooltip, demoSeries); // Pass the demo series
            }
        })
        .catch(error => {
            console.error('Error loading chart data:', error);
            if (chartContainerWrapper) {
                chartContainerWrapper.innerHTML = `
                    <div class="empty-state">
                        <h3>Error Loading Data - Showing Demo</h3>
                        <p>We couldn't load the comparison data (${error.message}). This chart shows sample data.</p>
                    </div>
                `;
            }
            drawComparisonChart(canvas, tooltip, demoSeries); // Pass demo data on error
        });
    <?php else: ?>
        // If no relevant streams for chart (PHP check), show empty state already and also render demo chart.
        drawComparisonChart(canvas, tooltip, demoSeries);
    <?php endif; ?>

    // --- Chart Drawing Function (adapted from your provided example) ---
    function drawComparisonChart(canvas, tooltip, series) {
        // ctx is already initialized in the outer scope
        if (!canvas || !ctx) {
            console.error('Canvas element or 2D context not found. Chart cannot be drawn.');
            // This empty state message for missing canvas/context would ideally be a more permanent structural issue.
            // For now, let the calling code handle the empty-state rendering based on $has_relevant_streams_for_chart
            // or fetch errors. This check here is more for extreme cases.
            return;
        }

        const config = {
            padding: 40,
            pointRadius: 6,
            gridColor: '#e0e7ff', // Tailwind blue-100
            axisLabelColor: '#6b7280', // Tailwind gray-500
        };

        // Define getX and getY functions in a scope accessible by renderChart and mousemove listener
        const getX = (index, totalPoints) => config.padding + (index / (totalPoints - 1)) * (canvas.offsetWidth - 2 * config.padding);

        let getY_func; // Declare this variable so it's accessible throughout drawComparisonChart

        function renderChart() {
            const devicePixelRatio = window.devicePixelRatio || 1;
            canvas.width = canvas.offsetWidth * devicePixelRatio;
            canvas.height = canvas.offsetHeight * devicePixelRatio;
            ctx.scale(devicePixelRatio, devicePixelRatio);

            ctx.clearRect(0, 0, canvas.offsetWidth, canvas.offsetHeight);

            const width = canvas.offsetWidth;
            const height = canvas.offsetHeight;
            const { padding, pointRadius, gridColor, axisLabelColor } = config;

            const chartWidth = width - 2 * padding;
            const chartHeight = height - 2 * padding;

            // Collect all values from all series to determine global min/max
            const allValues = series.flatMap(s => s.data.map(d => d.value));
            if (allValues.length === 0) {
                 ctx.fillStyle = axisLabelColor;
                 ctx.font = '16px Inter';
                 ctx.textAlign = 'center';
                 ctx.textBaseline = 'middle';
                 ctx.fillText('No data to display for chart.', width / 2, height / 2);
                 return;
            }

            const maxValue = Math.max(...allValues);
            const minValue = Math.min(...allValues);
            
            const effectiveMinValue = minValue > 0 ? 0 : minValue; // Start Y-axis from 0 if all values are positive
            const effectiveMaxValue = maxValue * 1.1; // Add 10% buffer at the top
            const effectiveValueRange = effectiveMaxValue - effectiveMinValue;

            // Define getY_func based on effectiveValueRange
            if (effectiveValueRange === 0) {
                // All values are the same (flat line). Create a small buffer around the value.
                const buffer = maxValue * 0.1 || 10; 
                const paddedMinValue = maxValue - buffer;
                const paddedMaxValue = maxValue + buffer;
                const paddedValueRange = paddedMaxValue - paddedMinValue;

                getY_func = (value) => {
                    const normalizedValue = (value - paddedMinValue) / paddedValueRange;
                    return height - padding - (normalizedValue * chartHeight);
                };

                // Draw Y-axis label for single value (if needed, otherwise grid lines take care)
                ctx.fillStyle = axisLabelColor;
                ctx.font = '12px Inter';
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                ctx.fillText(`${Math.round(maxValue)}%`, padding - 10, getY_func(maxValue));

            } else {
                // Normal scaling for varying values
                getY_func = (value) => {
                    const normalizedValue = (value - effectiveMinValue) / effectiveValueRange;
                    return height - padding - (normalizedValue * chartHeight);
                };

                // --- Draw Grid Lines and Labels (Y-axis) ---
                const numYLabels = 5;
                for (let i = 0; i <= numYLabels; i++) {
                    const value = effectiveMinValue + (effectiveValueRange / numYLabels) * i;
                    const y = getY_func(value);

                    ctx.beginPath();
                    ctx.moveTo(padding, y);
                    ctx.lineTo(width - padding, y);
                    ctx.strokeStyle = gridColor;
                    ctx.lineWidth = 1;
                    ctx.globalAlpha = 0.5;
                    ctx.stroke();
                    ctx.globalAlpha = 1;

                    ctx.fillStyle = axisLabelColor;
                    ctx.font = '12px Inter';
                    ctx.textAlign = 'right';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(`${Math.round(value)}%`, padding - 10, y); // Add % to churn rate labels
                }
            }
            
            // --- Draw X-axis Labels ---
            ctx.fillStyle = axisLabelColor;
            ctx.font = '12px Inter';
            ctx.textBaseline = 'top';
            const totalDataPoints = series[0].data.length; // Assuming all series have the same labels and length
            if (totalDataPoints > 0) {
                series[0].data.forEach((d, i) => {
                    const x = getX(i, totalDataPoints);
                    ctx.textAlign = (i === 0) ? 'left' : (i === totalDataPoints - 1) ? 'right' : 'center';
                    ctx.fillText(d.label, x, height - padding + 15);
                });
            }


            // --- Draw Each Line and Gradient Fill ---
            series.forEach(s => {
                if (s.data.length === 0) return;

                // Draw gradient fill
                ctx.beginPath();
                ctx.moveTo(getX(0, s.data.length), getY_func(effectiveMinValue)); // Start at bottom-left of chart area
                s.data.forEach((d, i) => {
                    const x = getX(i, s.data.length);
                    const y = getY_func(d.value);
                    if (i === 0) {
                        ctx.lineTo(x, y);
                    } else {
                        const prevX = getX(i - 1, s.data.length);
                        const prevY = getY_func(s.data[i - 1].value);
                        const cp1x = (prevX + x) / 2; // Control point 1 x
                        const cp1y = prevY;            // Control point 1 y (horizontal from prev)
                        const cp2x = (prevX + x) / 2; // Control point 2 x
                        const cp2y = y;                // Control point 2 y (horizontal from current)
                        ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                    }
                });
                ctx.lineTo(getX(s.data.length - 1, s.data.length), getY_func(effectiveMinValue)); // End at bottom-right of chart area
                ctx.closePath();

                const gradient = ctx.createLinearGradient(0, padding, 0, height - padding);
                gradient.addColorStop(0, s.gradientStart);
                gradient.addColorStop(1, s.gradientEnd);
                ctx.fillStyle = gradient;
                ctx.fill();

                // Draw the line
                ctx.beginPath();
                s.data.forEach((d, i) => {
                    const x = getX(i, s.data.length);
                    const y = getY_func(d.value);
                    if (i === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        const prevX = getX(i - 1, s.data.length);
                        const prevY = getY_func(s.data[i - 1].value);
                        const cp1x = (prevX + x) / 2;
                        const cp1y = prevY;
                        const cp2x = (prevX + x) / 2;
                        const cp2y = y;
                        ctx.bezierCurveTo(cp1x, cp1y, cp2x, cp2y, x, y);
                    }
                });
                ctx.strokeStyle = s.color;
                ctx.lineWidth = 3;
                ctx.stroke();

                // Draw Data Points
                s.data.forEach((d, i) => {
                    const x = getX(i, s.data.length);
                    const y = getY_func(d.value);

                    ctx.beginPath();
                    ctx.arc(x, y, pointRadius, 0, Math.PI * 2);
                    ctx.fillStyle = s.color; // Point color same as line
                    ctx.fill();
                    ctx.strokeStyle = '#ffffff'; // White border for points
                    ctx.lineWidth = 2;
                    ctx.stroke();
                });
            });
        }

        // --- Interactive Tooltip Logic ---
        let animationFrameId = null;

        canvas.addEventListener('mousemove', (e) => {
            cancelAnimationFrame(animationFrameId);

            animationFrameId = requestAnimationFrame(() => {
                const rect = canvas.getBoundingClientRect();
                const mouseX = (e.clientX - rect.left);
                const mouseY = (e.clientY - rect.top);

                let closestPoint = null;
                let minDistance = Infinity;
                const tolerance = config.pointRadius * 2; // Increased hit area for points

                series.forEach(s => {
                    s.data.forEach((d, i) => {
                        const x = getX(i, s.data.length); // getX is now defined
                        const y = getY_func(d.value);      // getY_func is now defined
                        const distance = Math.sqrt(Math.pow(mouseX - x, 2) + Math.pow(mouseY - y, 2));

                        if (distance < minDistance && distance < tolerance) {
                            minDistance = distance;
                            closestPoint = {
                                label: s.label,
                                dataPoint: d,
                                x: x,
                                y: y
                            };
                        }
                    });
                });

                if (closestPoint) {
                    tooltip.innerHTML = `<strong><span class="math-inline">\{closestPoint\.label\}</strong\><br\></span>{closestPoint.dataPoint.label}: <strong>${closestPoint.dataPoint.value}%</strong>`;
                    // Position tooltip relative to document based on canvas position and mouse position
                    tooltip.style.left = `${closestPoint.x + rect.left}px`;
                    tooltip.style.top = `${closestPoint.y + rect.top}px`;
                    tooltip.classList.add('visible');
                } else {
                    tooltip.classList.remove('visible');
                }
            });
        });

        canvas.addEventListener('mouseleave', () => {
            cancelAnimationFrame(animationFrameId);
            tooltip.classList.remove('visible');
        });

        // --- Responsiveness ---
        let resizeTimeout;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(() => {
                renderChart();
            }, 100);
        });

        // Initial draw
        renderChart();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>