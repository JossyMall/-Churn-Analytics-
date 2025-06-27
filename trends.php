<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];
// Enable error reporting for debugging
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
// trends.php - Feature Trends Analysis



// Get all unique tags from features across user's streams
$stmt = $pdo->prepare("SELECT DISTINCT f.tags 
                      FROM features f
                      JOIN streams s ON f.stream_id = s.id
                      WHERE s.user_id = ?");
$stmt->execute([$user_id]);
$all_tags = [];
while ($row = $stmt->fetch()) {
    if (!empty($row['tags'])) {
        $tags = explode(',', $row['tags']);
        $all_tags = array_merge($all_tags, array_map('trim', $tags));
    }
}
$all_tags = array_unique($all_tags);

// Get monthly trend data for the selected tag
$selected_tag = $_GET['tag'] ?? '';
$trend_data = [];
$popularity_data = [];

if (!empty($selected_tag)) {
    // Get trend data for the selected tag
    $stmt = $pdo->prepare("SELECT 
                          DATE_FORMAT(md.recorded_at, '%Y-%m') AS month,
                          COUNT(*) AS usage_count
                          FROM metric_data md
                          JOIN features f ON md.metric_id = (SELECT id FROM churn_metrics WHERE name = 'feature_usage')
                          JOIN streams s ON f.stream_id = s.id
                          WHERE s.user_id = ? 
                          AND f.tags LIKE ?
                          GROUP BY DATE_FORMAT(md.recorded_at, '%Y-%m')
                          ORDER BY month");
    $stmt->execute([$user_id, "%$selected_tag%"]);
    $trend_data = $stmt->fetchAll();

    // Get popularity comparison data
    $stmt = $pdo->prepare("SELECT 
                          f.name AS feature_name,
                          COUNT(md.id) AS usage_count
                          FROM metric_data md
                          JOIN features f ON md.metric_id = (SELECT id FROM churn_metrics WHERE name = 'feature_usage')
                          JOIN streams s ON f.stream_id = s.id
                          WHERE s.user_id = ? 
                          AND f.tags LIKE ?
                          GROUP BY f.name
                          ORDER BY usage_count DESC
                          LIMIT 10");
    $stmt->execute([$user_id, "%$selected_tag%"]);
    $popularity_data = $stmt->fetchAll();
}

// Get top trending tags - FIXED QUERY
$stmt = $pdo->prepare("SELECT 
                      tag,
                      COUNT(*) AS trend_count
                      FROM (
                          SELECT TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(f.tags, ',', n.n), ',', -1)) AS tag
                          FROM features f
                          JOIN streams s ON f.stream_id = s.id
                          JOIN (
                              SELECT 1 AS n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
                          ) n ON LENGTH(REPLACE(f.tags, ' ', '')) - LENGTH(REPLACE(f.tags, ',', '')) >= n.n - 1
                          WHERE s.user_id = ? AND f.tags != ''
                      ) AS extracted_tags
                      GROUP BY tag
                      ORDER BY trend_count DESC
                      LIMIT 5");
$stmt->execute([$user_id]);
$top_tags = $stmt->fetchAll();
?>

<div class="trends-container">
    <h1>Feature Trends Analysis</h1>
    <p class="subtitle">Track how different features are being used over time</p>

    <div class="trends-controls">
        <form method="get" class="tag-selector">
            <label for="tagSelect">Select a Tag:</label>
            <select id="tagSelect" name="tag" onchange="this.form.submit()">
                <option value="">-- Select a Tag --</option>
                <?php foreach ($all_tags as $tag): ?>
                    <option value="<?= htmlspecialchars($tag) ?>" <?= $selected_tag === $tag ? 'selected' : '' ?>>
                        <?= htmlspecialchars($tag) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!empty($selected_tag)): ?>
        <div class="trends-section">
            <h2>Trend for: <?= htmlspecialchars($selected_tag) ?></h2>
            
            <div class="chart-container">
                <canvas id="trendChart"></canvas>
            </div>
        </div>

        <div class="popularity-section">
            <h2>Most Popular Features with this Tag</h2>
            
            <div class="chart-container">
                <canvas id="popularityChart"></canvas>
            </div>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Select a tag to view trends</h3>
            <p>Choose from your feature tags to see usage patterns over time</p>
        </div>
    <?php endif; ?>

    <div class="top-tags-section">
        <h2>Top Trending Tags</h2>
        
        <div class="tags-grid">
            <?php foreach ($top_tags as $tag): ?>
                <a href="trends.php?tag=<?= urlencode($tag['tag']) ?>" class="tag-card">
                    <span class="tag-name"><?= htmlspecialchars($tag['tag']) ?></span>
                    <span class="tag-count"><?= $tag['trend_count'] ?> uses</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<style>
    .trends-container {
        border-radius: 8px;
        padding: 20px;
    }
    
    .trends-container h1 {
        color: #2c3e50;
        margin-bottom: 5px;
    }
    
    .subtitle {
        color: #7f8c8d;
        margin-bottom: 30px;
    }
    
    .trends-controls {
        margin-bottom: 30px;
        padding: 20px;
        background: #3ac3b8;
        border-radius: 8px;
    }
    
    .tag-selector {
        display: flex;
        align-items: center;
        gap: 15px;
    }
    
    .tag-selector label {
        font-weight: 500;
        color: #2c3e50;
    }
    
    .tag-selector select {
        padding: 10px 15px;
        border-radius: 6px;
        border: 1px solid #ddd;
        min-width: 250px;
    }
    
    .trends-section, .popularity-section {
        margin-bottom: 40px;
        padding: 20px;
        border-radius: 8px;
    }
    
    .trends-section h2, .popularity-section h2 {
        color: #2c3e50;
        margin-bottom: 20px;
        font-size: 1.3rem;
    }
    
    .chart-container {
        position: relative;
        height: 400px;
        margin-top: 20px;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        border-radius: 8px;
        margin-bottom: 40px;
    }
    
    .empty-icon {
        font-size: 3rem;
        color: #3ac3b8;
        margin-bottom: 20px;
    }
    
    .empty-state h3 {
        color: #2c3e50;
        margin-bottom: 10px;
    }
    
    .empty-state p {
        color: #7f8c8d;
    }
    
    .top-tags-section {
        margin-top: 40px;
    }
    
    .tags-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }
    
    .tag-card {
        display: block;
        padding: 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        transition: all 0.3s ease;
        text-decoration: none;
    }
    
    .tag-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    }
    
    .tag-name {
        display: block;
        font-weight: 500;
        color: #2c3e50;
        margin-bottom: 10px;
    }
    
    .tag-count {
        display: block;
        color: #3ac3b8;
        font-size: 0.9rem;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    <?php if (!empty($selected_tag) && !empty($trend_data)): ?>
        // Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode(array_column($trend_data, 'month')) ?>,
                datasets: [{
                    label: 'Usage Count',
                    data: <?= json_encode(array_column($trend_data, 'usage_count')) ?>,
                    backgroundColor: 'rgba(58, 195, 184, 0.2)',
                    borderColor: 'rgba(58, 195, 184, 1)',
                    borderWidth: 2,
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Monthly Usage Trend for <?= htmlspecialchars($selected_tag) ?>'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Usage Count'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Month'
                        }
                    }
                }
            }
        });
    <?php endif; ?>

    <?php if (!empty($selected_tag) && !empty($popularity_data)): ?>
        // Popularity Chart
        const popularityCtx = document.getElementById('popularityChart').getContext('2d');
        const popularityChart = new Chart(popularityCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($popularity_data, 'feature_name')) ?>,
                datasets: [{
                    label: 'Usage Count',
                    data: <?= json_encode(array_column($popularity_data, 'usage_count')) ?>,
                    backgroundColor: 'rgba(58, 195, 184, 0.6)',
                    borderColor: 'rgba(58, 195, 184, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    title: {
                        display: true,
                        text: 'Most Popular Features with <?= htmlspecialchars($selected_tag) ?> Tag'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Usage Count'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Feature Name'
                        }
                    }
                }
            }
        });
    <?php endif; ?>
</script>

<?php
require_once 'includes/footer.php';
?>