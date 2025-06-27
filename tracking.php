<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];


$stmt = $pdo->prepare("SELECT s.*, n.name as niche_name, 
                      (SELECT COUNT(*) FROM features WHERE stream_id = s.id) as feature_count,
                      (SELECT COUNT(*) FROM competitors WHERE stream_id = s.id) as competitor_count
                      FROM streams s
                      LEFT JOIN niches n ON s.niche_id = n.id
                      WHERE s.user_id = ?
                      ORDER BY s.created_at DESC");
$stmt->execute([$user_id]);
$streams = $stmt->fetchAll();
?>

<div class="tracking-container">
    <div class="tracking-header">
        <div class="header-content">
            <h1><i class="fas fa-code"></i> Tracking Implementation</h1>
            <p class="subtitle">Integrate these snippets to start collecting churn data from your platforms</p>
            <div class="compliance-global-badge">
                <span class="badge gdpr-badge"><i class="fas fa-shield-alt"></i> GDPR Ready</span>
                <span class="badge ccpa-badge"><i class="fas fa-user-lock"></i> CCPA Compliant</span>
                <span class="badge hybrid-badge"><i class="fas fa-random"></i> Hybrid Tracking</span>
            </div>
        </div>
    </div>

    <?php if (empty($streams)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-plug"></i>
            </div>
            <h3>No Streams Configured</h3>
            <p>You need to create a stream first to get tracking code</p>
            <a href="streams.php" class="btn btn-primary">Create Stream</a>
        </div>
    <?php else: ?>
        <div class="stream-tracking-grid">
            <?php foreach ($streams as $stream): ?>
                <div class="stream-tracking-card">
                    <div class="card-header" style="border-left: 4px solid <?= htmlspecialchars($stream['color_code'] ?: '#3ac3b8') ?>">
                        <div class="header-title">
                            <h3><?= htmlspecialchars($stream['name']) ?></h3>
                            <span class="stream-meta">
                                <span class="meta-item"><i class="fas fa-tag"></i> <?= htmlspecialchars($stream['niche_name'] ?? 'No niche') ?></span>
                                <span class="meta-item"><i class="fas fa-cog"></i> <?= (int)$stream['feature_count'] ?> features</span>
                                <span class="meta-item"><i class="fas fa-bullseye"></i> <?= (int)$stream['competitor_count'] ?> competitors</span>
                                <span class="meta-item"><i class="fas fa-<?= $stream['is_app'] ? 'mobile-alt' : 'globe' ?>"></i> <?= $stream['is_app'] ? 'Mobile App' : 'Website' ?></span>
                            </span>
                        </div>
                        <div class="tracking-code-label">
                            <span>Tracking ID:</span>
                            <code class="small-code"><?= htmlspecialchars($stream['tracking_code']) ?></code>
                        </div>
                    </div>
                    
                    <div class="card-body">
                        <?php if ($stream['is_app']): ?>
                            <div class="implementation-section">
                                <h4><i class="fas fa-mobile-alt"></i> Mobile API Integration</h4>
                                <p>Track user behavior by making POST requests to our endpoint:</p>
                                
                                <div class="code-block">
                                    <div class="code-toolbar">
                                        <span class="lang-badge">JavaScript</span>
                                        <button class="copy-btn" onclick="copyToClipboard(this)">
                                            <i class="far fa-copy"></i> Copy
                                        </button>
                                    </div>
                                    <pre class="language-javascript"><code>fetch("https://earndos.com/io/api/track", {
  method: "POST",
  headers: {
    "Content-Type": "application/json",
    "Authorization": "Bearer <?= htmlspecialchars($stream['tracking_code']) ?>"
  },
  body: JSON.stringify({
    user_id: "USER_UNIQUE_ID",
    event: "login",
    feature: "dashboard",
    duration: 120,
    metadata: {}
  })
});</code></pre>
                                </div>
                                
                                <div class="api-reference">
                                    <h5><i class="fas fa-book"></i> API Reference</h5>
                                    <div class="endpoint-card">
                                        <div class="endpoint-method post">POST</div>
                                        <div class="endpoint-url">https://earndos.com/io/api/track</div>
                                    </div>
                                    <table class="params-table">
                                        <thead>
                                            <tr>
                                                <th>Parameter</th>
                                                <th>Type</th>
                                                <th>Required</th>
                                                <th>Description</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><code>user_id</code></td>
                                                <td>String</td>
                                                <td>Yes</td>
                                                <td>Your platform's user ID</td>
                                            </tr>
                                            <tr>
                                                <td><code>event</code></td>
                                                <td>String</td>
                                                <td>Yes</td>
                                                <td>Event type</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="implementation-section">
                                <h4><i class="fas fa-globe"></i> Website Tracking</h4>
                                <p>Add this code to your website's <code>&lt;head&gt;</code> section:</p>
                                
                                <div class="code-block">
                                    <div class="code-toolbar">
                                        <span class="lang-badge">HTML</span>
                                        <button class="copy-btn" onclick="copyToClipboard(this)">
                                            <i class="far fa-copy"></i> Copy
                                        </button>
                                    </div>
                                    <pre class="language-html"><code>&lt;script src="https://earndos.com/io/tracker.js?code=<?= htmlspecialchars($stream['tracking_code']) ?>"&gt;&lt;/script&gt;</code></pre>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="tracking-features">
                            <h5><i class="fas fa-tachometer-alt"></i> Automatic Tracking</h5>
                            <div class="features-grid">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-user-clock"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>Session Tracking</h6>
                                        <p>Automatic session duration and frequency</p>
                                    </div>
                                </div>
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-mouse-pointer"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>Feature Usage</h6>
                                        <p>Tracks visits to your configured feature URLs</p>
                                    </div>
                                </div>
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-route"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>User Flow</h6>
                                        <p>Visualize user journeys through your platform</p>
                                    </div>
                                </div>
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-radar"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>Competitor Detection</h6>
                                        <p>Detect when users visit competitor sites</p>
                                    </div>
                                </div>
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-lightbulb"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>Competitor Intelligence</h6>
                                        <p>Identify which competitors attract your users</p>
                                    </div>
                                </div>
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-funnel-dollar"></i>
                                    </div>
                                    <div class="feature-text">
                                        <h6>Churn Prediction</h6>
                                        <p>AI-powered prediction of at-risk users</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="advanced-tracking-links">
                            <div class="advanced-card">
                                <div class="advanced-icon">
                                    <i class="fas fa-link"></i>
                                </div>
                                <div class="advanced-content">
                                    <h5>Feature Tracking</h5>
                                    <p>Configure specific URLs as features in your dashboard to track usage.</p>
                                    <a href="features.php?stream_id=<?= $stream['id'] ?>" class="advanced-link">
                                        Set up feature tracking <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                            <div class="advanced-card">
                                <div class="advanced-icon">
                                    <i class="fas fa-crosshairs"></i>
                                </div>
                                <div class="advanced-content">
                                    <h5>Competitor Tracking</h5>
                                    <p>Add competitor URLs to detect when users visit rival sites.</p>
                                    <a href="competitors.php?stream_id=<?= $stream['id'] ?>" class="advanced-link">
                                        Configure competitors <i class="fas fa-arrow-right"></i>
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="compliance-section">
                            <h4><i class="fas fa-shield-alt"></i> Privacy Compliance</h4>
                            <div class="compliance-cards">
                                <div class="compliance-card gdpr-card">
                                    <div class="compliance-icon">
                                        <i class="fas fa-user-shield"></i>
                                    </div>
                                    <div class="compliance-content">
                                        <h5>GDPR Ready</h5>
                                        <p>Automatically respects cookie consent. No tracking until approved.</p>
                                    </div>
                                </div>
                                <div class="compliance-card hybrid-card">
                                    <div class="compliance-icon">
                                        <i class="fas fa-random"></i>
                                    </div>
                                    <div class="compliance-content">
                                        <h5>Hybrid Tracking</h5>
                                        <p>When cookies are blocked, uses session-based anonymous tracking.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<link rel="stylesheet" href="assets/css/tracking-enhanced.css">

<script>
function copyToClipboard(button) {
    const codeBlock = button.closest('.code-block').querySelector('code');
    const range = document.createRange();
    range.selectNode(codeBlock);
    window.getSelection().removeAllRanges();
    window.getSelection().addRange(range);
    
    try {
        const successful = document.execCommand('copy');
        showToast(successful ? 'Code copied!' : 'Failed to copy');
    } catch (err) {
        showToast('Error copying code');
    }
    
    window.getSelection().removeAllRanges();
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.className = 'global-toast';
    toast.innerHTML = `
        <div class="toast-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="toast-message">${message}</div>
    `;
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.classList.add('show');
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toast.remove();
            }, 300);
        }, 3000);
    }, 10);
}
</script>

<?php include 'includes/footer.php'; ?>