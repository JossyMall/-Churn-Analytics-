<?php
session_start();
require_once 'includes/db.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_affiliate_errors.log');

$membership_plans = [];
try {
    $stmt = $pdo->prepare("
        SELECT ml.id, ml.name, ml.promo_price, ar.percentage
        FROM membership_levels ml
        LEFT JOIN affiliate_rewards ar ON ml.id = ar.membership_id
        WHERE ml.is_active = 1
        ORDER BY ml.promo_price ASC
    ");
    $stmt->execute();
    $membership_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($membership_plans as &$plan) {
        if (empty($plan['percentage'])) {
            $stmt_default_commission = $pdo->query("SELECT value FROM config WHERE setting = 'default_affiliate_commission_percentage'");
            $default_percentage = $stmt_default_commission->fetchColumn();
            $plan['percentage'] = $default_percentage ? floatval($default_percentage) : 30.00;
        }
        $plan['commission_amount'] = ($plan['promo_price'] * $plan['percentage']) / 100;
    }
    unset($plan);

} catch (PDOException $e) {
    error_log("Database error fetching membership plans for affiliate page: " . $e->getMessage());
    $membership_plans = [
        ['id' => 1, 'name' => 'Starter Plan', 'promo_price' => 297.00, 'percentage' => 30.00, 'commission_amount' => 89.10],
        ['id' => 2, 'name' => 'Professional Plan', 'promo_price' => 597.00, 'percentage' => 30.00, 'commission_amount' => 179.10],
        ['id' => 3, 'name' => 'Enterprise Plan', 'promo_price' => 997.00, 'percentage' => 30.00, 'commission_amount' => 299.10],
        ['id' => 4, 'name' => 'Premium Enterprise', 'promo_price' => 1997.00, 'percentage' => 30.00, 'commission_amount' => 599.10],
    ];
}

if (!defined('BASE_URL')) {
    define('BASE_URL', 'http://localhost/churn-analytics');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Our Affiliate Program - Churn Analytics Pro!</title>
    <style>
        :root {
            --primary-green: #3ac3b8; /* Main accent color */
            --dark-blue-gradient-start: #667eea;
            --dark-blue-gradient-end: #764ba2;
            --text-dark: #333;
            --text-light: #666;
            --bg-light: #f8fafc;
            --bg-medium: #e2e8f0;
            --white: #ffffff;
            --shadow-light: rgba(0,0,0,0.1);
            --shadow-medium: rgba(0,0,0,0.15);
            --shadow-heavy: rgba(0,0,0,0.3);
            --border-color: #e5e7eb;
            --star-color: #fbbf24;

            /* Rainbow Border Colors for Animation */
            --rainbow-color-1: #FF0000; /* Red */
            --rainbow-color-2: #FF7F00; /* Orange */
            --rainbow-color-3: #FFFF00; /* Yellow */
            --rainbow-color-4: #00FF00; /* Green */
            --rainbow-color-5: #0000FF; /* Blue */
            --rainbow-color-6: #4B0082; /* Indigo */
            --rainbow-color-7: #9400D3; /* Violet */
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: var(--text-dark);
            background: var(--bg-light);
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary-green) 0%, #2f9a91 100%); /* Green gradient */
            color: white;
            padding: 80px 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grain" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="25" cy="25" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="75" cy="75" r="1" fill="rgba(255,255,255,0.1)"/><circle cx="50" cy="10" r="0.5" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="100" height="100" fill="url(%23grain)"/></svg>');
            animation: float 20s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        .header-content {
            position: relative;
            z-index: 2;
        }

        .header h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px var(--shadow-heavy);
        }

        .header p {
            font-size: 1.3rem;
            margin-bottom: 30px;
            opacity: 0.9;
        }

        .highlight {
            background: linear-gradient(120deg, #a8edea 0%, #fed6e3 100%);
            padding: 2px 8px;
            border-radius: 4px;
            color: var(--text-dark);
            font-weight: 600;
        }

        /* Call to Action Button - Primary Style with Rainbow Border */
        .btn-primary {
            display: inline-block;
            background: var(--primary-green);
            color: white;
            padding: 15px 30px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 5px 15px rgba(58, 195, 184, 0.3);
            margin-top: 30px; /* Added for spacing from paragraph */

            /* Rainbow Border Styles */
            position: relative; /* Needed for pseudo-elements */
            z-index: 1; /* Place above pseudo-element */
            overflow: hidden; /* Hide overflow of pseudo-element */
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: conic-gradient(
                var(--rainbow-color-1),
                var(--rainbow-color-2),
                var(--rainbow-color-3),
                var(--rainbow-color-4),
                var(--rainbow-color-5),
                var(--rainbow-color-6),
                var(--rainbow-color-7),
                var(--rainbow-color-1) /* Repeat first color to ensure smooth loop */
            );
            border-radius: 32px; /* Slightly larger than button border-radius */
            z-index: -1; /* Place behind the button content */
            animation: rainbow-border 4s linear infinite; /* Animation */
            background-size: 200% 200%; /* Ensure gradient covers full area for movement */
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(58, 195, 184, 0.4);
        }

        @keyframes rainbow-border {
            0% {
                background-position: 0% 50%;
                transform: rotate(0deg);
            }
            100% {
                background-position: 100% 50%;
                transform: rotate(360deg);
            }
        }
        
        .btn-secondary {
            display: inline-block;
            background: none;
            border: 2px solid var(--primary-green);
            color: var(--primary-green);
            padding: 15px 30px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: var(--primary-green);
            color: white;
            box-shadow: 0 5px 15px rgba(58, 195, 184, 0.3);
        }


        /* Stats Section */
        .stats-section {
            background: white;
            padding: 60px 0;
            margin-top: -40px;
            position: relative;
            z-index: 3;
            border-radius: 20px 20px 0 0;
            box-shadow: 0 -10px 30px var(--shadow-light);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            text-align: center;
        }

        .stat-item {
            padding: 20px;
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 700;
            color: var(--primary-green); /* Green accent */
            display: block;
            margin-bottom: 10px;
        }

        .stat-label {
            color: var(--text-light);
            font-size: 1.1rem;
        }

        /* General Section Styling */
        section {
            padding: 80px 0;
        }
        
        .section-title {
            text-align: center;
            font-size: 2.5rem;
            margin-bottom: 20px;
            color: var(--text-dark);
            font-weight: 700;
        }

        .section-subtitle {
            text-align: center;
            font-size: 1.2rem;
            color: var(--text-light);
            margin-bottom: 60px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Features/Benefits Section */
        .benefits-section {
            background: linear-gradient(135deg, var(--bg-light) 0%, var(--bg-medium) 100%);
        }

        .benefits-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .benefit-card {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 10px 30px var(--shadow-light);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .benefit-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary-green); /* Green accent */
        }

        .benefit-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px var(--shadow-medium);
        }

        .benefit-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-green); /* Green accent */
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 20px;
            color: white;
            font-size: 1.5rem;
        }
        .benefit-icon svg {
            width: 30px;
            height: 30px;
            fill: white;
        }

        .benefit-card h3 {
            font-size: 1.5rem;
            margin-bottom: 15px;
            color: var(--text-dark);
        }

        .benefit-card p {
            color: var(--text-light);
            line-height: 1.7;
        }

        /* How It Works Section */
        .how-it-works-section {
            background: var(--white);
            padding: 80px 0;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 40px;
            text-align: center;
            margin-top: 50px;
        }

        .step-item {
            background: var(--bg-light);
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 5px 15px var(--shadow-light);
            transition: transform 0.3s ease;
            border-bottom: 4px solid var(--primary-green);
        }
        .step-item:hover {
            transform: translateY(-5px);
        }

        .step-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 15px;
        }

        .step-item svg {
            width: 50px;
            height: 50px;
            fill: var(--primary-green);
            margin-bottom: 15px;
        }
        
        .step-item h3 {
            font-size: 1.4rem;
            margin-bottom: 10px;
            color: var(--text-dark);
        }

        .step-item p {
            color: var(--text-light);
            font-size: 1rem;
        }
        

        /* Calculator Section */
        .calculator-section {
            padding: 80px 0;
            background: var(--bg-light);
        }

        .calculator-container {
            max-width: 800px;
            margin: 0 auto;
            background: linear-gradient(135deg, var(--bg-light) 0%, var(--bg-medium) 100%);
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 15px 35px var(--shadow-light);
        }

        .calculator-form {
            display: grid;
            gap: 30px;
        }

        .form-group {
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 12px;
            font-weight: 600;
            color: #374151;
            font-size: 1.1rem;
        }

        .form-group select {
            width: 100%;
            padding: 15px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-size: 1rem;
            background: white;
            transition: border-color 0.3s ease;
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary-green); /* Green accent */
            box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1); /* Green shadow */
        }

        .slider-container {
            margin-top: 10px;
        }

        .slider {
            width: 100%;
            height: 8px;
            border-radius: 5px;
            background: var(--border-color);
            outline: none;
            appearance: none;
            position: relative;
        }

        .slider::-webkit-slider-thumb {
            appearance: none;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary-green); /* Green accent */
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(58, 195, 184, 0.3); /* Green shadow */
            transition: transform 0.2s ease;
        }

        .slider::-webkit-slider-thumb:hover {
            transform: scale(1.1);
        }

        .slider::-moz-range-thumb {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--primary-green); /* Green accent */
            cursor: pointer;
            border: none;
            box-shadow: 0 2px 10px rgba(58, 195, 184, 0.3); /* Green shadow */
        }

        .slider-labels {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .slider-value {
            text-align: center;
            font-weight: 700;
            font-size: 1.2rem;
            color: var(--primary-green); /* Green accent */
            margin-top: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border: 2px solid var(--border-color);
        }

        .calculator-result {
            text-align: center;
            margin-top: 30px;
            padding: 30px;
            background: white;
            border-radius: 15px;
            border: 3px solid var(--primary-green); /* Green accent */
            position: relative;
            overflow: hidden;
        }

        .calculator-result::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(58, 195, 184, 0.05), rgba(47, 154, 145, 0.05)); /* Lighter green gradient */
            z-index: 1;
        }

        .calculator-result > * {
            position: relative;
            z-index: 2;
        }

        .calculator-result h3 {
            margin-bottom: 15px;
            color: #374151;
            font-size: 1.3rem;
        }

        .earnings-amount {
            font-size: 3rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-green), #2f9a91); /* Green gradient */
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
        }

        .earnings-period {
            color: #6b7280;
            font-size: 1.1rem;
            margin-top: 5px;
        }

        /* Testimonials Section */
        .testimonials-section {
            padding: 80px 0;
            background: linear-gradient(135deg, var(--primary-green) 0%, #2f9a91 100%); /* Green gradient */
            color: white;
        }

        .testimonials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
        }

        .testimonial-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(5px);
            padding: 40px;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.3s ease, background 0.3s ease;
        }

        .testimonial-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.15);
        }

        .stars {
            color: var(--star-color);
            font-size: 1.2rem;
            margin-bottom: 20px;
        }

        .testimonial-text {
            font-style: italic;
            margin-bottom: 25px;
            line-height: 1.7;
            font-size: 1.1rem;
        }

        .testimonial-author {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .author-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .author-info h4 {
            margin-bottom: 5px;
            font-weight: 600;
        }

        .author-info p {
            opacity: 0.8;
            font-size: 0.9rem;
        }

        /* FAQ Section */
        .faq-section {
            background: var(--white);
            padding: 80px 0;
        }

        .faq-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .faq-item {
            background: var(--bg-light);
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 2px 10px var(--shadow-light);
        }

        .faq-question {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .faq-question svg {
            transition: transform 0.3s ease;
        }
        .faq-question.active svg {
            transform: rotate(180deg);
        }

        .faq-answer {
            color: var(--text-light);
            font-size: 1rem;
            margin-top: 15px;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease-out, opacity 0.4s ease-out;
            opacity: 0;
        }
        .faq-answer.active {
            max-height: 200px; /* Adjust based on expected content height */
            opacity: 1;
        }


        /* CTA Section - bottom */
        .cta-section {
            padding: 80px 0;
            background: var(--bg-medium);
            text-align: center;
        }

        .cta-content {
            max-width: 700px;
            margin: 0 auto;
            background: var(--white);
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px var(--shadow-light);
        }
        .cta-content .section-title {
            margin-bottom: 15px;
        }
        .cta-content .section-subtitle {
            margin-bottom: 30px;
        }

        .cta-image {
            width: 100%;
            max-width: 500px;
            height: 280px;
            background: #f3f4f6;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 30px auto;
            color: #9ca3af;
            font-size: 1.1rem;
            border: 2px dashed #d1d5db;
            overflow: hidden;
        }
        .cta-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Footer */
        .footer {
            background: #1f2937;
            color: white;
            padding: 40px 0;
            text-align: center;
            font-size: 0.9rem;
        }
        .footer a {
            color: var(--primary-green);
            text-decoration: none;
            transition: color 0.2s ease;
        }
        .footer a:hover {
            color: #68d391;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header {
                padding: 60px 0;
            }
            .header h1 {
                font-size: 2.5rem;
            }
            
            .header p {
                font-size: 1.1rem;
            }
            
            .stats-grid, .benefits-grid, .testimonials-grid, .steps-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .calculator-container {
                padding: 30px 20px;
            }

            .section-title {
                font-size: 2rem;
            }

            .section-subtitle {
                font-size: 1rem;
                margin-bottom: 40px;
            }
            .btn-primary {
                 padding: 12px 25px; /* Adjust padding for smaller screens */
                 font-size: 1rem;
            }
            .btn-primary::before {
                border-radius: 30px; /* Adjust border-radius to match button on smaller screens */
            }
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="container">
            <div class="header-content">
                <h1>Unlock Unlimited Earning Potential with Our Affiliate Program</h1>
                <p>Partner with <span class="highlight">Churn Analytics Pro</span> and help SaaS companies revolutionize their customer retention while you earn generous recurring commissions.</p>
                <a href="https://earndos.com/io/affiliates.php" class="btn-primary">Become an Affiliate Partner Now</a>
            </div>
        </div>
    </header>

    <section class="stats-section">
        <div class="container">
            <div class="stats-grid">
                <div class="stat-item">
                    <span class="stat-number">$2,847</span>
                    <div class="stat-label">Average Monthly Earnings</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">45%</span> <div class="stat-label">Average Churn Reduction Achieved by Our Clients</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">500+</span>
                    <div class="stat-label">Satisfied Affiliate Partners</div>
                </div>
                <div class="stat-item">
                    <span class="stat-number">98%</span>
                    <div class="stat-label">Client Satisfaction Rate with Churn Analytics Pro</div>
                </div>
            </div>
        </div>
    </section>

    <section class="benefits-section">
        <div class="container">
            <h2 class="section-title">Why Partner With Us?</h2>
            <p class="section-subtitle">Join an exclusive network of marketers promoting a truly impactful solution, designed to deliver exceptional value and recurring income.</p>
            
            <div class="benefits-grid">
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.22l5.5 2.45-5.5 2.45-5.5-2.45L12 3.22zM12 19.3c-3.12-1.4-6-5.52-6-9.3v-5.2l6-2.67 6 2.67v5.2c0 3.78-2.88 7.9-6 9.3zm-1-10h2v4h-2v-4z"/></svg>
                    </div>
                    <h3>Generous Recurring Commissions</h3>
                    <p>Earn a high percentage commission on every single subscription you refer, month after month, for the lifetime of the customer. Your efforts pay off continuously.</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>
                    </div>
                    <h3>High-Demand Product</h3>
                    <p>Churn is a critical pain point for every SaaS business. Our AI-powered solution directly addresses this, making it an easy sell with proven results and high conversion rates.</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-9 14l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8.01l-9 9z"/></svg>
                    </div>
                    <h3>Dedicated Affiliate Support</h3>
                    <p>Access a dedicated affiliate manager, comprehensive marketing materials, real-time tracking, and exclusive insights to maximize your promotional efforts.</p>
                </div>
                
                <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M22 6c0-1.1-.9-2-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6zm-2 0l-8 5-8-5h16zm0 12H4V8l8 5 8-5v10z"/></svg>
                    </div>
                    <h3>Exclusive Marketing Resources</h3>
                    <p>Utilize our professionally designed banners, email swipe files, landing page templates, and content ideas to effortlessly attract and convert your audience.</p>
                </div>

                 <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                    </div>
                    <h3>Proven Sales Funnels</h3>
                    <p>Our optimized sales funnels are designed to convert visitors into paying customers efficiently, meaning more successful referrals for you with less effort.</p>
                </div>

                <div class="benefit-card">
                    <div class="benefit-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-2 .89-2 2v11c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>
                    </div>
                    <h3>Simple & Fast Payouts</h3>
                    <p>Get paid reliably and quickly via your preferred method (PayPal, Bank Transfer, USDT). Transparent tracking ensures you always know your earnings.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="how-it-works-section">
        <div class="container">
            <h2 class="section-title">How Our Affiliate Program Works</h2>
            <p class="section-subtitle">Getting started is simple. Follow these three easy steps to kickstart your recurring revenue stream.</p>

            <div class="steps-grid">
                <div class="step-item">
                    <div class="step-number">1</div>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                    <h3>Sign Up & Get Your Link</h3>
                    <p>Quickly join our program. You'll instantly receive a unique affiliate link and access to your personal dashboard.</p>
                </div>
                <div class="step-item">
                    <div class="step-number">2</div>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.22l5.5 2.45-5.5 2.45-5.5-2.45L12 3.22zM12 19.3c-3.12-1.4-6-5.52-6-9.3v-5.2l6-2.67 6 2.67v5.2c0 3.78-2.88 7.9-6 9.3zm-1-10h2v4h-2v-4z"/></svg>
                    <h3>Promote Churn Analytics Pro</h3>
                    <p>Share your link with SaaS businesses, founders, and marketing agencies. Use our provided resources to amplify your reach.</p>
                </div>
                <div class="step-item">
                    <div class="step-number">3</div>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
                    <h3>Earn Recurring Commissions</h3>
                    <p>When someone subscribes through your link, you'll earn a recurring commission for as long as they remain a paying customer.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="calculator-section">
        <div class="container">
            <h2 class="section-title">Calculate Your Potential Earnings</h2>
            <p class="section-subtitle">See how much you could earn promoting Churn Analytics Pro to businesses looking to grow.</p>
            
            <div class="calculator-container">
                <div class="calculator-form">
                    <div class="form-group">
                        <label for="planSelect">Select Membership Plan</label>
                        <select id="planSelect">
                            <?php foreach ($membership_plans as $plan): ?>
                                <option
                                    value="<?= htmlspecialchars($plan['promo_price']) ?>"
                                    data-commission-percentage="<?= htmlspecialchars($plan['percentage']) ?>">
                                    <?= htmlspecialchars($plan['name']) ?> - $<?= number_format($plan['promo_price'], 2) ?>/month (<?= number_format($plan['percentage'], 0) ?>% commission)
                                </option>
                            <?php endforeach; ?>
                            <?php if (empty($membership_plans)): ?>
                                <option value="0" data-commission-percentage="0">No plans available</option>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="referralSlider">Number of Referrals Per Month</label>
                        <div class="slider-container">
                            <input type="range" id="referralSlider" class="slider" min="10" max="500" value="10" step="10">
                            <div class="slider-labels">
                                <span>10</span>
                                <span>500</span>
                            </div>
                            <div class="slider-value" id="referralValue">10 referrals</div>
                        </div>
                    </div>
                    
                    <div class="calculator-result">
                        <h3>Your Estimated Monthly Earnings</h3>
                        <p class="earnings-amount" id="estimatedEarnings">$0.00</p>
                        <p class="earnings-period">per month recurring</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="testimonials-section">
        <div class="container">
            <h2 class="section-title">What Our Affiliates Say</h2>
            <p class="section-subtitle">Don't just take our word for it – hear from our satisfied affiliate partners!</p>
            
            <div class="testimonials-grid">
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"Churn Analytics Pro has been a phenomenal addition to my portfolio. The product virtually sells itself, and the recurring commissions are incredibly generous. I've consistently earned over $3,500 monthly since joining!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">SM</div>
                        <div class="author-info">
                            <h4>Sarah Mitchell</h4>
                            <p>SaaS Marketing Consultant</p>
                        </div>
                    </div>
                </div>
                
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"As a content creator, finding products that truly resonate with my audience is key. Churn Analytics Pro is a perfect fit. My audience loves it, and I love the predictable, high-value commissions. It's a win-win!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">DM</div>
                        <div class="author-info">
                            <h4>David Morales</h4>
                            <p>Digital Content Creator</p>
                        </div>
                    </div>
                </div>
                
                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"The support from the affiliate team is outstanding. They truly want you to succeed, providing all the resources you need. This program genuinely feels like a partnership, not just another link to promote."</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">KP</div>
                        <div class="author-info">
                            <h4>Karen Peréz</h4>
                            <p>Affiliate Marketing Specialist</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"I've been in affiliate marketing for years, and Churn Analytics Pro stands out. The recurring revenue model is fantastic, and the demand for churn reduction solutions ensures a steady stream of earnings. Highly recommend!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">RL</div>
                        <div class="author-info">
                            <h4>Robert Lee</h4>
                            <p>Seasoned Affiliate Marketer</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"This program is incredibly transparent. I can track every click and conversion, and payouts are always on time. It’s refreshing to work with a company that values its partners so much."</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">AT</div>
                        <div class="author-info">
                            <h4>Aisha Traore</h4>
                            <p>Business Development</p>
                        </div>
                    </div>
                </div>

                <div class="testimonial-card">
                    <div class="stars">★★★★★</div>
                    <p class="testimonial-text">"The impact of Churn Analytics Pro on businesses is undeniable. Promoting a product that genuinely helps clients succeed makes my job easy and rewarding. My referrals stick around, and so do my commissions!"</p>
                    <div class="testimonial-author">
                        <div class="author-avatar">JW</div>
                        <div class="author-info">
                            <h4>Jason Wells</h4>
                            <p>Entrepreneur & Agency Owner</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="faq-section">
        <div class="container">
            <h2 class="section-title">Frequently Asked Questions</h2>
            <p class="section-subtitle">Find quick answers to the most common questions about our affiliate program.</p>
            <div class="faq-grid">
                <div class="faq-item">
                    <div class="faq-question">
                        What is the commission rate?
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        We offer a generous recurring commission rate of 30% on all referred subscriptions. This means you earn a percentage of every payment made by customers you refer, for as long as they remain subscribed to Churn Analytics Pro.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        How often are payouts made?
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        Payouts are processed monthly, typically within the first 5 business days of each month, for the commissions earned in the previous month. We ensure a smooth and timely payment process.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        What are the payout methods?
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        We offer flexible payout options including PayPal, direct bank transfer, and USDT. You can select your preferred method in your affiliate dashboard settings.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        Is there a minimum payout threshold?
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        Yes, there is a minimum payout threshold of $100. Once your accumulated earnings reach this amount, your commissions will be eligible for payout in the next payment cycle.
                    </div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">
                        What kind of support do affiliates receive?
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-chevron-down"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </div>
                    <div class="faq-answer">
                        Our affiliates receive comprehensive support including access to a dedicated affiliate manager, a rich library of marketing materials (banners, email templates, landing page designs), and real-time performance analytics through your dashboard.
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="cta-section" id="join-now">
        <div class="container">
            <div class="cta-content">
                <h2 class="section-title">Ready to Transform Your Earnings?</h2>
                <p class="section-subtitle">Join the Churn Analytics Pro Affiliate Program today and start building a sustainable recurring income stream by promoting a product that genuinely helps businesses thrive.</p>
                
                <div class="cta-image">
                    <img src="<?= htmlspecialchars(BASE_URL) ?>/assets/images/affiliate-dashboard-preview.png" alt="Affiliate Success Dashboard" onerror="this.onerror=null;this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%239ca3af\' stroke-width=\'1.5\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\' ry=\'2\'%3E%3C/rect%3E%3Cline x1=\'3\' y1=\'9\' x2=\'21\' y2=\'9\'%3E%3C/line%3E%3Cpath d=\'M10 21V9\'%3E%3C/path%3E%3Cpath d=\'M14 21V9\'%3E%3C/path%3E%3Cpath d=\'M7 3v6\'%3E%3C/path%3E%3Cpath d=\'M17 3v6\'%3E%3C/path%3E%3C/svg%3E';">
                </div>
                
                <a href="<?= htmlspecialchars(BASE_URL) ?>/affiliates.php" class="btn-primary">Sign Up Free - Start Earning!</a>
                
                <p style="margin-top: 20px; color: #6b7280; font-size: 0.9rem;">
                    ✓ No application fees &nbsp;&nbsp;&nbsp; ✓ Instant approval &nbsp;&nbsp;&nbsp; ✓ World-class support
                </p>
            </div>
        </div>
    </section>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Earndos Business Insights. All rights reserved. | <a href="https://earndos.com/terms/">Terms of Service</a> | <a href="https://earndos.com/privacy/">Privacy Policy</a></p>
        </div>
    </footer>

    <script>
        // Calculator logic
        const planSelect = document.getElementById('planSelect');
        const referralSlider = document.getElementById('referralSlider');
        const referralValueDisplay = document.getElementById('referralValue');
        const estimatedEarningsDisplay = document.getElementById('estimatedEarnings');

        function updateCalculation() {
            const selectedOption = planSelect.selectedOptions[0];
            const planPrice = parseFloat(selectedOption.value);
            const commissionPercentage = parseFloat(selectedOption.dataset.commissionPercentage);
            const referrals = parseInt(referralSlider.value);
            
            const commissionPerReferral = (planPrice * commissionPercentage) / 100;
            const totalEarnings = commissionPerReferral * referrals;
            
            referralValueDisplay.textContent = referrals + (referrals === 1 ? ' referral' : ' referrals');
            estimatedEarningsDisplay.textContent = '$' + totalEarnings.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        planSelect.addEventListener('change', updateCalculation);
        referralSlider.addEventListener('input', updateCalculation);
        
        updateCalculation(); // Initialize calculation on page load

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, observerOptions);

        document.querySelectorAll('.benefit-card, .testimonial-card, .stat-item, .step-item').forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(20px)';
            el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            observer.observe(el);
        });

        // FAQ Toggle Functionality
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', () => {
                const answer = question.nextElementSibling;
                const svg = question.querySelector('svg');

                question.classList.toggle('active');
                answer.classList.toggle('active');
            });
        });
    </script>
</body>
</html>