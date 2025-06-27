<?php
// about.php - About Us page
session_start();
// Include the database connection file.
// This is necessary to determine the logged-in status based on session data,
// consistent with other pages in the project.
require_once 'includes/db.php'; 

// Check if the user is logged in.
// This variable controls the text and destination of the CTA button in the header.
$logged_in = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Earndos - AI-Powered Customer Retention Platform</title>
    <!-- Link to Font Awesome for icons (e.g., hamburger menu) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Inline styles for a minimalistic design -->
    <style>
        /* Define CSS variables for consistent theming */
        :root {
            --primary-color: #667eea; /* A primary accent color */
            --secondary-color: #764ba2; /* A secondary accent color, often for hover/active states */
            --text-color: #2d3748; /* Darker text for headings and main content */
            --light-text-color: #718096; /* Lighter text for paragraphs and less emphasis */
            --background-light: #f8faff; /* Light background for sections */
        }

        /* Basic body styling: font, margins, padding for fixed header, text color, background */
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
            margin: 0;
            padding-top: 60px; /* Space for the fixed header */
            line-height: 1.6;
            color: var(--text-color);
            background-color: var(--background-light);
        }

        /* Container for content width and centering */
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px; /* Horizontal padding for smaller screens */
        }

        /* Header Styling: Fixed position, background with blur, shadow, and height */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            background: rgba(255, 255, 255, 0.95); /* Semi-transparent white background */
            backdrop-filter: blur(10px); /* Frosted glass effect */
            -webkit-backdrop-filter: blur(10px); /* For Safari */
            transition: all 0.3s ease; /* Smooth transitions for any changes */
            height: 60px; /* Fixed height for the header */
            display: flex;
            align-items: center; /* Vertically center content */
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Subtle shadow at the bottom */
        }
        
        /* Ensure content within the header container is aligned */
        .main-header .container {
            display: flex;
            justify-content: space-between; /* Space out logo and nav menu */
            align-items: center;
            width: 100%;
        }

        /* Logo styling within the header */
        .logo img {
            max-height: 40px; /* Max height for the logo image */
            width: auto;
            object-fit: contain;
        }

        /* Navigation menu styling */
        .nav-menu {
            display: flex; /* Display items in a row */
            list-style: none; /* Remove bullet points */
            margin: 0;
            padding: 0;
        }

        .nav-menu li {
            margin-left: 25px; /* Spacing between menu items */
        }

        .nav-menu a {
            text-decoration: none; /* Remove underline */
            color: var(--text-color); /* Default text color */
            font-weight: 600; /* Semi-bold text */
            transition: color 0.2s ease; /* Smooth color transition on hover */
        }

        .nav-menu a:hover {
            color: var(--primary-color); /* Highlight color on hover */
        }

        /* Call-to-action button styling within the nav menu */
        .nav-cta {
            background-color: var(--primary-color);
            color: white !important; /* Override other link styles */
            padding: 8px 18px;
            border-radius: 25px; /* Rounded corners */
            text-align: center;
            transition: background-color 0.2s ease, transform 0.2s ease; /* Smooth transitions */
        }

        .nav-cta:hover {
            background-color: var(--secondary-color); /* Change color on hover */
            transform: translateY(-2px); /* Slight lift effect */
        }

        /* Mobile Navigation Toggle (Hamburger icon) */
        .menu-toggle {
            display: none; /* Hidden by default on desktop */
            font-size: 24px;
            cursor: pointer;
            color: var(--text-color);
        }

        /* Main content section styling */
        .about-section {
            padding: 80px 0; /* Vertical padding for content sections */
            background-color: white; /* White background for better readability */
            border-bottom: 1px solid #eee; /* Subtle separator */
        }

        /* Remove border from the last section */
        .about-section:last-of-type {
            border-bottom: none;
        }

        /* Styling for main titles within sections */
        .section-title {
            font-size: 38px;
            font-weight: 700;
            color: var(--text-color);
            text-align: center;
            margin-bottom: 40px;
        }

        /* Styling for subtitles within sections */
        .section-subtitle {
            font-size: 24px;
            color: var(--light-text-color);
            text-align: center;
            margin-bottom: 60px;
            line-height: 1.4;
        }

        /* Styling for content blocks (e.g., "Our Mission", "What We Offer") */
        .content-block {
            margin-bottom: 40px; /* Space between content blocks */
        }

        .content-block h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color); /* Accent color for subheadings */
            margin-top: 40px;
            margin-bottom: 20px;
        }

        .content-block p {
            font-size: 17px;
            margin-bottom: 15px;
            color: var(--light-text-color);
        }

        .content-block ul {
            list-style-type: none; /* Remove default bullet points */
            padding: 0;
            margin: 20px 0;
        }

        .content-block ul li {
            margin-bottom: 10px;
            font-size: 17px;
            color: var(--light-text-color);
            position: relative;
            padding-left: 25px; /* Space for custom bullet */
        }

        /* Custom bullet point for list items */
        .content-block ul li:before {
            content: '•'; 
            color: var(--primary-color);
            position: absolute;
            left: 0;
            font-weight: bold;
            font-size: 1.2em; /* Larger bullet */
            line-height: 1;
        }

        /* Footer Styling: Dark background, white text, centered content */
        .footer {
            background-color: var(--text-color);
            color: white;
            padding: 40px 0;
            text-align: center;
            font-size: 15px;
        }

        .footer-links a {
            color: white;
            text-decoration: none;
            margin: 0 15px; /* Spacing between footer links */
            transition: opacity 0.2s ease; /* Smooth opacity change on hover */
        }

        .footer-links a:hover {
            opacity: 0.8; /* Slightly fade on hover */
        }

        .copyright {
            margin-top: 20px;
            opacity: 0.8; /* Slightly faded copyright text */
        }

        /* Responsive Adjustments for Mobile (768px and below) */
        @media (max-width: 768px) {
            .main-header .container {
                flex-wrap: nowrap; /* Prevent logo/toggle from wrapping */
            }
            .menu-toggle {
                display: block; /* Show hamburger icon on mobile */
                margin-left: auto; /* Push toggle to the right */
            }

            .nav-menu {
                display: none; /* Hide menu by default on mobile */
                flex-direction: column; /* Stack menu items vertically */
                position: absolute;
                top: 60px; /* Position below the header */
                left: 0;
                width: 100%;
                background: rgba(255, 255, 255, 0.98); /* Slightly more opaque background when open */
                backdrop-filter: blur(10px);
                -webkit-backdrop-filter: blur(10px);
                box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
                padding: 20px 0;
                text-align: center;
                z-index: 999;
                border-top: 1px solid #eee; /* Separator from header */
            }

            .nav-menu.active {
                display: flex; /* Show menu when 'active' class is present */
            }

            .nav-menu li {
                margin: 10px 0; /* Vertical spacing for mobile menu items */
            }

            .nav-menu li a {
                padding: 10px 15px; /* Larger tap area for mobile links */
                display: block;
            }

            .nav-menu li a.nav-cta {
                margin-top: 10px; /* Space above the CTA button on mobile */
            }

            /* Adjust font sizes for better readability on smaller screens */
            .section-title {
                font-size: 32px;
            }
            .section-subtitle {
                font-size: 18px;
            }
            .content-block h3 {
                font-size: 24px;
            }
            .content-block p, .content-block ul li {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Header Navigation (Consistent with index.php) -->
    <header class="main-header" id="mainHeader">
        <div class="container">
            <div class="logo">
                <img src="assets/images/logo.png" alt="Earndos Logo">
            </div>
            <!-- Mobile menu toggle button -->
            <div class="menu-toggle" id="mobileMenuToggle">
                <i class="fas fa-bars"></i>
            </div>
            <!-- Navigation menu -->
            <ul class="nav-menu" id="mainNavMenu">
                <li><a href="index.php">Home</a></li> <!-- Link back to the homepage -->
                <li><a href="https://earndos.com/io/niches.php" target="_blank">Niches</a></li>
                <li><a href="https://earndos.com/io/affiliate.php" target="_blank">Affiliate Program</a></li>
                <li><a href="#">SDKs</a></li>
                <li><a href="#">API</a></li>
                <li><a href="about.php" target="_blank">About Us</a></li>
                <!-- Conditional CTA button based on login status -->
                <li><a href="<?= $logged_in ? 'dashboard.php' : 'auth/register.php' ?>" class="nav-cta">
                    <?= $logged_in ? 'Dashboard' : 'Sign Up' ?>
                </a></li>
            </ul>
        </div>
    </header>

    <!-- About Section: Main content about Earndos -->
    <section class="about-section">
        <div class="container">
            <h1 class="section-title">About Earndos: Revolutionizing Customer Retention with AI</h1>
            <p class="section-subtitle">Earndos is an AI-powered customer retention platform born from the collaborative vision of software developers from Nigeria and Russia. We are a team passionate about creating innovative solutions that transcend global borders, empowering entrepreneurs, high-ticket coaches, and SaaS businesses to retain more customers and significantly boost their Lifetime Value (LTV).</p>

            <div class="content-block">
                <h3>Our Mission</h3>
                <p>In today's competitive landscape, customer churn is a critical challenge. Our mission at Earndos is to transform how businesses approach retention. We leverage cutting-edge Artificial Intelligence to provide actionable insights, predict potential churn, and automate win-back strategies, ensuring your customers stay engaged and loyal for longer.</p>
            </div>

            <div class="content-block">
                <h3>What We Offer</h3>
                <ul>
                    <li><strong>AI-Powered Churn Prediction:</strong> Our advanced machine learning models, utilizing the power of DeepSeek and ChatGPT, analyze vast datasets to predict customer churn likelihood with up to 90% accuracy. Get real-time insights into which customers are at risk and why.</li>
                    <li><strong>Intelligent Win-back Campaigns:</strong> Design and automate personalized win-back campaigns with dynamic offers and messaging. Our platform helps you re-engage at-risk customers effectively, turning potential losses into retained revenue.</li>
                    <li><strong>Comprehensive Customer Analytics:</strong> Track crucial metrics such as login frequency, feature usage, session duration, billing cycles, support interactions, and custom metrics. Gain a holistic understanding of your customer journey and identify patterns leading to churn.</li>
                    <li><strong>Real-Time Alerts & Integrations:</strong> Receive instant notifications via Slack, Microsoft Teams, Discord, email, or SMS when high-risk customers are detected. Our deep integrations with leading platforms like HubSpot, Salesforce, Segment, Stripe, Chargebee, and Zapier ensure seamless data flow and workflow automation.</li>
                    <li><strong>Collaborative Workspaces:</strong> Empower your team with centralized dashboards to collaborate on retention strategies, share insights, and coordinate efforts across sales, marketing, and support departments.</li>
                    <li><strong>Competitor Visit Intelligence:</strong> Stay ahead of the curve by tracking when your customers visit competitor websites. Receive alerts to proactively address potential churn and reinforce your value proposition.</li>
                    <li><strong>Flexible Tracking Options:</strong> Choose your preferred tracking method with support for GDPR/CCPA compliant tracking, non-GDPR/CCPA tracking, and a hybrid fallback option to ensure compliance and data privacy.</li>
                    <li><strong>Automated Workflows:</strong> Build complex, multi-step retention automation using an intuitive drag-and-drop interface, connecting data sources, logic conditions, and actions to streamline your entire retention process.</li>
                </ul>
            </div>

            <div class="content-block">
                <h3>Our Vision</h3>
                <p>We believe that every customer interaction is an opportunity for retention. Earndos is constantly evolving, driven by our commitment to innovation and our users' success. We are dedicated to providing a powerful, intuitive, and secure platform that helps businesses of all sizes unlock their full revenue potential by cultivating long-lasting customer relationships.</p>
            </div>

            <div class="content-block">
                <h3>Our Team</h3>
                <p>We are a diverse team of software developers with a shared passion for leveraging <strong>AI</strong> to solve real-world problems. Hailing from <strong>Nigeria and Russia</strong>, our combined expertise in data science, web development (PHP, HTML5, SQL), and user experience design allows us to build robust and user-friendly solutions. We are united by our belief in the transformative power of technology and our commitment to delivering a product that genuinely makes a difference for businesses worldwide. We thrive on creating new ideas that transcend geographical boundaries, helping entrepreneurs and SaaS products secure their customer base and achieve sustainable growth.</p>
            </div>
        </div>
    </section>

    <!-- Footer (Consistent with index.php) -->
    <footer class="footer">
        <div class="container">
            <div class="footer-links">
                <a href="about.php" target="_blank">About Us</a>
                <a href="contact.php" target="_blank">Contact</a>
                <a href="privacy.php">Privacy Policy</a>
                <a href="terms.php">Terms of Service</a>
            </div>
            <p class="copyright">© <?= date('Y') ?> Earndos. All rights reserved.</p>
        </div>
    </footer>

    <!-- JavaScript for Mobile Menu Toggle -->
    <script>
        // Get references to the mobile menu toggle button and the main navigation menu
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const mainNavMenu = document.getElementById('mainNavMenu');

        // Add a click event listener to the mobile menu toggle button
        mobileMenuToggle.addEventListener('click', function() {
            // Toggle the 'active' class on the navigation menu.
            // This class will control the visibility and styling of the mobile menu.
            mainNavMenu.classList.toggle('active');
        });

        // Optional: Close the mobile menu when a link inside it is clicked.
        // This improves user experience, especially for single-page applications or when navigating
        // to a different section on the same page.
        document.querySelectorAll('#mainNavMenu a').forEach(item => {
            item.addEventListener('click', () => {
                // If the menu is active, remove the 'active' class to close it.
                if (mainNavMenu.classList.contains('active')) {
                    mainNavMenu.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>