<?php
// index.php - Standalone landing page
session_start();
require_once 'includes/db.php';

// Check if user is logged in
$logged_in = isset($_SESSION['user_id']);

// Get featured membership levels
$stmt = $pdo->query("SELECT id, name, standard_price, promo_price, yearly_discount, features
                     FROM membership_levels
                     WHERE is_active = 1
                     ORDER BY standard_price ASC
                     LIMIT 3");
$membership_levels = $stmt->fetchAll();

// Enhanced testimonials data
$testimonials = [
    [
        'quote' => "Reduced our churn rate by 35% in just 3 months with actionable insights. The Win-back campaigns are incredibly effective.",
        'author' => "Sarah Johnson",
        'title' => "CEO, TechStart Inc.",
        'image' => "assets/images/testimonial1.jpg",
        'company_logo' => "assets/images/logo-techstart.png"
    ],
    [
        'quote' => "The AI predictions are scarily accurate. We've saved thousands in potential lost revenue with real-time Slack alerts.",
        'author' => "Michael Chen",
        'title' => "Growth Lead, AppMetrics",
        'image' => "assets/images/testimonial2.jpg",
        'company_logo' => "assets/images/logo-appmetrics.png"
    ],
    [
        'quote' => "The HubSpot integration and collaborative workspaces transformed our retention strategy. ROI was immediate.",
        'author' => "David Wilson",
        'title' => "CMO, CloudSolutions",
        'image' => "assets/images/testimonial3.jpg",
        'company_logo' => "assets/images/logo-cloudsolutions.png"
    ],
    [
        'quote' => "Competitor visit intelligence gave us insights we never had before. Our team productivity increased by 50%.",
        'author' => "Emily Rodriguez",
        'title' => "VP Marketing, DataFlow",
        'image' => "assets/images/testimonial4.jpg",
        'company_logo' => "assets/images/logo-dataflow.png"
    ],
    [
        'quote' => "Earndos helped us identify key churn indicators we were missing. Our proactive engagement has never been better!",
        'author' => "Jessica Lee",
        'title' => "Head of Customer Success, InnovateNow",
        'image' => "assets/images/testimonial5.jpg", // Assuming you have an image for this
        'company_logo' => "assets/images/logo-innovatenow.png" // Assuming you have a logo for this
    ],
    [
        'quote' => "The custom workflows and Segment integration have streamlined our operations and significantly boosted our retention efforts.",
        'author' => "Robert Davis",
        'title' => "Product Manager, GrowthSphere",
        'image' => "assets/images/testimonial6.jpg", // Assuming you have an image for this
        'company_logo' => "assets/images/logo-growthsphere.png" // Assuming you have a logo for this
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Earndos - AI-Powered Customer Retention Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Header transparency and scroll effect */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            /*background: rgba(255, 255, 255, 0.95);*/
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            /*border-bottom: 1px solid rgba(0, 0, 0, 0.1);*/
            height: 60px; /* Thinner header */
            display: flex;
            align-items: center;
        }
        
        .main-header.scrolled {
            background: rgba(255, 255, 255, 0%);
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.1);
            height: 70px; /* Slightly taller on scroll if desired, or keep 60px */
        }
        
        body {
            padding-top: 60px; /* Account for fixed header */
        }
        
        /* Logo styling */
        .logo {
            display: flex;
            align-items: center;
            height: 100%; /* Take full height of header */
        }
        
        .logo img {
            max-height: 40px; /* Adjust this value to fit your header */
            width: auto;
            object-fit: contain;
            margin-right: 30px;
        }
        
        /* Mobile Navigation Toggle */
        .menu-toggle {
            display: none; /* Hidden by default on desktop */
            font-size: 24px;
            cursor: pointer;
            color: #2d3748;
            margin-left: auto; /* Pushes toggle to the right */
        }

        .nav-menu {
            display: flex;
            align-items: center;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        @media (max-width: 768px) {
            .menu-toggle {
                display: block; /* Show on mobile */
            }

            .nav-menu {
                display: none; /* Hide menu by default on mobile */
                flex-direction: column;
                position: absolute;
                top: 60px; /* Below the header */
                left: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.98);
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                padding: 20px 0;
                text-align: center;
                z-index: 999;
            }

            .nav-menu.active {
                display: flex; /* Show menu when active */
            }

            .nav-menu li {
                margin: 10px 0;
            }

            .nav-menu li a {
                color: #2d3748;
                padding: 10px 15px;
                display: block;
            }

            .nav-menu li a.nav-cta {
                margin-top: 10px;
            }
        }

        /* Enhanced features section */
        .enhanced-features-section {
            background: linear-gradient(135deg, #ffddfb 0%, #defffc 100%);
            padding: 100px 0;
        }
        
        .features-showcase {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 30px;
            margin-top: 60px;
        }
        
        .feature-showcase-card {
            background: white;
            padding: 40px 30px;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            text-align: center;
            transition: all 0.3s ease;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .feature-showcase-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
        }
        
        .feature-showcase-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 25px;
            background: linear-gradient(135deg, #3ac3b8 0%, #3ac3b8 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 35px;
            color: #050006;
        }
        
        .feature-showcase-card h3 {
            font-size: 24px;
            margin-bottom: 15px;
            color: #2d3748;
            font-weight: 700;
        }
        
        .feature-showcase-card p {
            color: #718096;
            line-height: 1.6;
            font-size: 16px;
        }
        
        /* Enhanced testimonials */
        .testimonials-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 100px 0;
            color: white;
        }
        
        .testimonials-section .section-title {
            color: white;
            text-align: center;
            font-size: 42px;
            margin-bottom: 20px;
        }
        
        .testimonials-section .section-subtitle {
            text-align: center;
            font-size: 18px;
            opacity: 0.9;
            margin-bottom: 60px;
        }
        
        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin-top: 60px;
        }
        
        .testimonial-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 40px;
            transition: all 0.3s ease;
        }
        
        .testimonial-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.2);
        }
        
        .testimonial-header {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .testimonial-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            margin-right: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
        }
        
        .testimonial-info h4 {
            margin: 0 0 5px 0;
            font-size: 18px;
            font-weight: 600;
        }
        
        .testimonial-info p {
            margin: 0;
            opacity: 0.8;
            font-size: 14px;
        }
        
        .company-logo {
            width: 40px;
            height: 40px;
            margin-left: auto;
            opacity: 0.7;
        }
        
        .testimonial-quote {
            font-size: 16px;
            line-height: 1.7;
            font-style: italic;
            position: relative;
            padding-left: 30px;
        }
        
        .testimonial-quote::before {
            content: '"';
            position: absolute;
            left: 0;
            top: -10px;
            font-size: 40px;
            opacity: 0.5;
        }
        
        .stars {
            display: flex;
            margin-bottom: 20px;
        }
        
        .star {
            color: #ffd700;
            font-size: 18px;
            margin-right: 3px;
        }
        
        /* Stats section */
        .stats-section {
            background: #2d3748;
            color: white;
            padding: 80px 0;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 50px;
            text-align: center;
        }
        
        .stat-item h3 {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-item p {
            font-size: 18px;
            opacity: 0.9;
        }
        
        /* Integration badges */
        .integration-badges {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 40px;
            margin-bottom: 40px;
        }
        
        .integration-badge {
            background: white;
            padding: 15px 25px;
            border-radius: 50px;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .integration-badge:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .integration-badge i {
            font-size: 20px;
        }
        
        .integration-badge span {
            font-weight: 600;
            color: #2d3748;
        }
        
        /* Responsive adjustments */
        @media (max-width: 768px) {
            .testimonials-grid {
                grid-template-columns: 1fr;
            }
            
            .features-showcase {
                grid-template-columns: 1fr;
            }
            
            .integration-badges {
                justify-content: center;
            }
            
            .integration-badge {
                padding: 12px 20px;
            }
            
            .logo img {
                max-height: 35px; /* Slightly smaller on mobile */
            }
        }
    </style>
</head>
<body>
    <header class="main-header" id="mainHeader">
        <div class="container">
            <nav style="display: flex; justify-content: space-between; align-items: center; width: 100%;">
                <div class="logo">
                    <img src="assets/images/logo.png" alt="Earndos Logo">
                </div>
                <div class="menu-toggle" id="mobileMenuToggle">
                    <i class="fas fa-bars"></i>
                </div>
                <ul class="nav-menu" id="mainNavMenu">
                    <li><a href="https://earndos.com/io/niches.php" target="_blank">Niches</a></li>
                    <li><a href="https://earndos.com/io/affiliate.php" target="_blank">Affiliate Program</a></li>
                    <li><a href="#">SDKs</a></li>
                    <li><a href="#">API</a></li>
                    <li><a href="about.php" target="_blank">About Us</a></li>
                    <li><a href="<?= $logged_in ? 'dashboard.php' : 'auth/register.php' ?>" class="nav-cta">
                        <?= $logged_in ? 'Dashboard' : 'Sign Up' ?>
                    </a></li>
                </ul>
            </nav>
        </div>
    </header>

    <section class="hero-section">
        <div class="container">
            <div class="hero-content">
                <h1>AI-Powered Customer Retention Platform</h1>
                <p class="subtitle">Predict, prevent, and recover customer churn with advanced analytics, intelligent win-back campaigns, and seamless team collaboration</p>
                
                <div class="cta-buttons">
                    <?php if ($logged_in): ?>
                        <a href="dashboard.php" class="btn primary">Go to Dashboard</a>
                    <?php else: ?>
                        <a href="auth/register.php" class="btn primary">Get Started Free</a>
                        <a href="auth/login.php" class="btn secondary">Login</a>
                    <?php endif; ?>
                </div>
                
                <div class="integration-badges">
                    <div class="integration-badge">
                        <i class="fab fa-slack" style="color: #4A154B;"></i>
                        <span>Slack</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fab fa-microsoft" style="color: #0078D4;"></i>
                        <span>Teams</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fab fa-hubspot" style="color: #FF7A59;"></i>
                        <span>HubSpot</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fab fa-salesforce" style="color: #00A1E0;"></i>
                        <span>Salesforce</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fas fa-cube" style="color: #00B140;"></i> <span>Segment</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fab fa-stripe" style="color: #6772E5;"></i>
                        <span>Stripe</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fas fa-money-check-alt" style="color: #00BCD4;"></i> <span>Chargebee</span>
                    </div>
                    <div class="integration-badge">
                        <i class="fas fa-bolt" style="color: #FF4A00;"></i> <span>Zapier</span>
                    </div>
                </div>
            </div>
            
            <div class="hero-image">
                <img src="assets/images/analytics-dashboard.png" alt="Earndos Analytics Dashboard Preview">
            </div>
        </div>
    </section>

    <section class="video-section">
        <div class="container">
            <div class="video-container">
                <div class="video-wrapper">
                    <iframe src="https://www.youtube.com/embed/wETKa9xtxFQ" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
                </div>
                <div class="video-content">
                    <h2>See Our Platform in Action</h2>
                    <p>Watch this 2-minute demo to see how our AI-powered churn analytics can transform your customer retention strategy.</p>
                    <p>Discover how we help businesses like yours identify at-risk customers before they leave and take proactive measures to keep them engaged.</p>
                    <a href="<?= $logged_in ? 'dashboard.php' : 'auth/register.php' ?>" class="btn primary">
                        <?= $logged_in ? 'Access Dashboard' : 'Start Free Trial' ?>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section class="enhanced-features-section">
        <div class="container">
            <h2 class="section-title">Powerful Features for Complete Customer Retention</h2>
            <p class="section-subtitle">Everything you need to understand, predict, and prevent customer churn</p>
            
            <div class="features-showcase">
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-robot"></i>
                    </div>
                    <h3>Intelligent Win-back Campaigns</h3>
                    <p>AI-powered automated campaigns with personalized messaging and dynamic offers that adapt based on customer behavior and preferences.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-users-cog"></i>
                    </div>
                    <h3>Collaborative Team Workspaces</h3>
                    <p>Centralized dashboards where teams can collaborate, share insights, and coordinate retention efforts across departments.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-crosshairs"></i>
                    </div>
                    <h3>Precision Audience Targeting</h3>
                    <p>Advanced segmentation and targeting capabilities to reach the right customers with the right message at the perfect time.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fab fa-slack"></i>
                    </div>
                    <h3>Real-time Slack Alerts</h3>
                    <p>Instant notifications in your Slack channels when high-risk customers are detected or when campaigns need attention.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fab fa-microsoft"></i>
                    </div>
                    <h3>Microsoft Teams Integration</h3>
                    <p>Seamless integration with Microsoft Teams for notifications, collaboration, and workflow management.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fab fa-hubspot"></i>
                    </div>
                    <h3>HubSpot Ecosystem Integration</h3>
                    <p>Deep integration with HubSpot CRM, Marketing Hub, and Service Hub for unified customer data and workflows.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fab fa-salesforce"></i>
                    </div>
                    <h3>Seamless Salesforce Connection</h3>
                    <p>Native Salesforce integration that syncs customer data, updates records, and triggers automated workflows.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-code"></i>
                    </div>
                    <h3>Developer-friendly SDK</h3>
                    <p>Comprehensive SDK and APIs that allow developers to integrate churn analytics into any application or workflow.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-eye"></i>
                    </div>
                    <h3>Competitor Visit Intelligence</h3>
                    <p>Track when your customers visit competitor websites and receive alerts to proactively address potential churn.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <h3>Proven Churn Reduction</h3>
                    <p>Machine learning models trained on millions of data points, delivering up to 90% accuracy in churn prediction.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-bell"></i>
                    </div>
                    <h3>Intelligent Alert System</h3>
                    <p>Smart notifications that learn from your team's actions and prioritize alerts based on impact and urgency.</p>
                </div>
                
                <div class="feature-showcase-card">
                    <div class="feature-showcase-icon">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <h3>Advanced Workflow Automation</h3>
                    <p>Complex multi-step workflows that automate your entire retention process from detection to recovery.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <h3>90%</h3>
                    <p>Prediction Accuracy</p>
                </div>
                <div class="stat-item">
                    <h3>45%</h3>
                    <p>Average Churn Reduction</p>
                </div>
                <div class="stat-item">
                    <h3>10k+</h3>
                    <p>Businesses Trust Us</p>
                </div>
                <div class="stat-item">
                    <h3>$50M+</h3>
                    <p>Revenue Saved</p>
                </div>
            </div>
        </div>
    </section>

    <section class="pricing-section">
        <div class="container">
            <h2 class="section-title">Simple, Transparent Pricing</h2>
            <p class="section-subtitle">Start with a free plan and upgrade as you grow</p>
            
            <div class="pricing-toggle">
                <span>Monthly</span>
                <label class="switch">
                    <input type="checkbox" id="pricingToggle">
                    <span class="slider round"></span>
                </label>
                <span>Yearly <span class="discount-badge">Save 20%</span></span>
            </div>
            
            <div class="pricing-grid">
                <?php foreach ($membership_levels as $level): ?>
                    <div class="pricing-card">
                        <h3><?= htmlspecialchars($level['name']) ?></h3>
                        
                        <div class="price monthly-price">
                            <span class="old-price">$<?= number_format($level['standard_price'], 2) ?></span>
                            <span class="current-price">$<?= number_format($level['promo_price'], 2) ?></span>
                            <span class="period">per month</span>
                        </div>
                        
                        <div class="price yearly-price" style="display: none;">
                            <?php
                            $yearly_price = $level['promo_price'] * 12 * (1 - ($level['yearly_discount']/100));
                            $yearly_savings = ($level['promo_price'] * 12) - $yearly_price;
                            ?>
                            <span class="current-price">$<?= number_format($yearly_price, 2) ?></span>
                            <span class="period">per year</span>
                            <span class="savings">Save $<?= number_format($yearly_savings, 2) ?></span>
                        </div>
                        
                        <ul class="features-list">
                            <?php
                            $features = explode("\n", $level['features']);
                            foreach ($features as $feature):
                                if (trim($feature)): ?>
                                    <li><?= htmlspecialchars(trim($feature)) ?></li>
                                <?php endif;
                            endforeach; ?>
                        </ul>
                        
                        <a href="<?= $logged_in ? 'membership.php' : 'auth/register.php' ?>" class="btn primary">
                            <?= $logged_in ? 'Upgrade Now' : 'Get Started' ?>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <div class="enterprise-contact">
                <p>Need custom enterprise pricing? <a href="#contact">Contact our sales team</a></p>
            </div>
        </div>
    </section>

    <section class="testimonials-section">
        <div class="container">
            <h2 class="section-title">Trusted by Leading SaaS Companies</h2>
            <p class="section-subtitle">See how businesses like yours are reducing churn and growing revenue</p>
            
            <div class="testimonials-grid">
                <?php foreach ($testimonials as $testimonial): ?>
                    <div class="testimonial-card">
                        <div class="stars">
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <span class="star">★</span>
                            <?php endfor; ?>
                        </div>
                        
                        <div class="testimonial-quote">
                            <?= htmlspecialchars($testimonial['quote']) ?>
                        </div>
                        
                        <div class="testimonial-header">
                            <img src="<?= $testimonial['image'] ?>" alt="<?= $testimonial['author'] ?>" class="testimonial-avatar">
                            <div class="testimonial-info">
                                <h4><?= htmlspecialchars($testimonial['author']) ?></h4>
                                <p><?= htmlspecialchars($testimonial['title']) ?></p>
                            </div>
                            <img src="<?= $testimonial['company_logo'] ?>" alt="Company Logo" class="company-logo">
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="cta-section">
        <div class="container">
            <h2 class="section-title">Ready to Reduce Your Churn?</h2>
            <p>Join tens of savvy businesses using our platform to retain more customers and grow revenue quickly.</p>
            <a href="<?= $logged_in ? 'dashboard.php' : 'auth/register.php' ?>" class="btn primary large">
                <?= $logged_in ? 'Go to Dashboard' : 'Start Your Free Trial' ?>
            </a>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <div class="footer-links">
                <a href="about.php" target="_blank">About Us</a>
                <a href="contact.php" target="_blank">Contact</a>
                <a href="https://earndos.com/privacy">Privacy Policy</a>
                <a href="https://earndos.com/terms">Terms of Service</a>
            </div>
            <p class="copyright">© <?= date('Y') ?> Earndos. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Header scroll effect
        window.addEventListener('scroll', function() {
            const header = document.getElementById('mainHeader');
            if (window.scrollY > 50) {
                header.classList.add('scrolled');
            } else {
                header.classList.remove('scrolled');
            }
        });

        // Pricing toggle functionality
        const pricingToggle = document.getElementById('pricingToggle');
        const monthlyPrices = document.querySelectorAll('.monthly-price');
        const yearlyPrices = document.querySelectorAll('.yearly-price');
        
        pricingToggle.addEventListener('change', function() {
            if (this.checked) {
                monthlyPrices.forEach(el => el.style.display = 'none');
                yearlyPrices.forEach(el => el.style.display = 'block');
            } else {
                monthlyPrices.forEach(el => el.style.display = 'block');
                yearlyPrices.forEach(el => el.style.display = 'none');
            }
        });

        // Mobile Menu Toggle
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mainNavMenu = document.getElementById('mainNavMenu');

        mobileMenuToggle.addEventListener('click', function() {
            mainNavMenu.classList.toggle('active');
        });

        // Animate feature cards on scroll
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe all feature cards
        document.querySelectorAll('.feature-showcase-card, .testimonial-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'all 0.6s ease';
            observer.observe(card);
        });

        // Counter animation for stats
        function animateCounter(element, target) {
            let current = 0;
            const increment = target / 100;
            const timer = setInterval(() => {
                current += increment;
                if (current >= target) {
                    current = target;
                    clearInterval(timer);
                }
                
                if (element.textContent.includes('k+')) {
                    element.textContent = Math.floor(current / 1000) + 'k+';
                } else if (element.textContent.includes('M+')) {
                    element.textContent = '$' + Math.floor(current / 1000000) + 'M+';
                } else {
                    element.textContent = Math.floor(current) + '%';
                }
            }, 20);
        }

        // Animate stats when they come into view
        const statsObserver = new IntersectionObserver(function(entries) {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const h3 = entry.target.querySelector('h3');
                    const text = h3.textContent;
                    let target = parseFloat(text.replace(/[^\d.]/g, '')); // Use parseFloat for potential decimals
                    
                    if (text.includes('$') && text.includes('M')) {
                        target = target * 1000000;
                    } else if (text.includes('k')) {
                        target = target * 1000;
                    }
                    
                    animateCounter(h3, target);
                    statsObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        document.querySelectorAll('.stat-item').forEach(stat => {
            statsObserver.observe(stat);
        });
    </script>
</body>
</html>