<?php
// contact.php - Contact Us page for Earndos
session_start(); // Start the session to store captcha answer

// Include the database connection file.
// This is done for consistency with other pages like index.php and about.php,
// though direct database operations are not performed on this page.
require_once 'includes/db.php'; 

// Check if the user is logged in.
// This variable controls the text and destination of the CTA button in the header.
$logged_in = isset($_SESSION['user_id']);

// Define contact details
$support_email = "support@earndos.com";
$ceo_email = "angelchristcee@gmail.com";
$ceo_whatsapp = "+79644165577"; // International format for WhatsApp link

$form_message = ''; // Message to display to the user (success/error)
$form_error_type = ''; // CSS class for message type (e.g., 'success', 'error')

// Initialize form fields to re-populate after submission attempts
$name = '';
$email = '';
$subject = '';
$message_content = '';

// Generate a new captcha question for each page load or failed submission
$num1 = rand(1, 10);
$num2 = rand(1, 10);
$_SESSION['captcha_answer'] = $num1 + $num2;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate user inputs
    $name = htmlspecialchars(trim($_POST['name'] ?? ''));
    $email = htmlspecialchars(trim($_POST['email'] ?? ''));
    $subject = htmlspecialchars(trim($_POST['subject'] ?? ''));
    $message_content = htmlspecialchars(trim($_POST['message'] ?? ''));
    $user_captcha = (int)($_POST['captcha'] ?? 0);

    $errors = [];

    // Basic validation
    if (empty($name)) {
        $errors[] = "Name is required.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    }
    if (empty($subject)) {
        $errors[] = "Subject is required.";
    }
    if (empty($message_content)) {
        $errors[] = "Message is required.";
    }

    // Captcha validation
    if ($user_captcha !== $_SESSION['captcha_answer']) {
        $errors[] = "Incorrect captcha answer. Please try again.";
        // Regenerate captcha on failure to prevent multiple attempts with the same question
        $num1 = rand(1, 10);
        $num2 = rand(1, 10);
        $_SESSION['captcha_answer'] = $num1 + $num2;
    }

    if (empty($errors)) {
        // All validations passed, attempt to send email
        $to = $support_email;
        $email_subject = "Contact Form: " . $subject;
        $email_body = "Name: " . $name . "\n"
                    . "Email: " . $email . "\n"
                    . "Subject: " . $subject . "\n\n"
                    . "Message:\n" . $message_content;

        // Email headers
        $headers = "From: \"" . $name . "\" <" . $email . ">\r\n";
        $headers .= "Reply-To: " . $email . "\r\n";
        $headers .= "X-Mailer: PHP/" . phpversion();

        // Send the email
        // NOTE: In a full production environment, it's highly recommended to use an SMTP library
        // (like PHPMailer) for reliable email delivery, especially considering the project's
        // global email settings specified in the documentation. For this minimalistic setup,
        // PHP's built-in mail() function is used.
        if (mail($to, $email_subject, $email_body, $headers)) {
            $form_message = "Your message has been sent successfully. We will get back to you soon!";
            $form_error_type = 'success';
            // Clear form fields on successful submission
            $name = '';
            $email = '';
            $subject = '';
            $message_content = '';
        } else {
            $form_message = "There was an error sending your message. Please try again later.";
            $form_error_type = 'error';
        }
    } else {
        // Display validation errors
        $form_message = implode("<br>", $errors);
        $form_error_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Earndos - Get in Touch</title>
    <!-- Link to Font Awesome for icons (e.g., hamburger menu, WhatsApp) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Inline styles for a minimalistic design consistent with about.php -->
    <style>
        /* Define CSS variables for consistent theming */
        :root {
            --primary-color: #667eea; /* A primary accent color */
            --secondary-color: #764ba2; /* A secondary accent color, often for hover/active states */
            --text-color: #2d3748; /* Darker text for headings and main content */
            --light-text-color: #718096; /* Lighter text for paragraphs and less emphasis */
            --background-light: #f8faff; /* Light background for sections */
            --success-color: #28a745; /* Color for success messages */
            --error-color: #dc3545; /* Color for error messages */
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

        /* Main contact section styling */
        .contact-section {
            padding: 80px 0; /* Vertical padding for content sections */
            background-color: white; /* White background for better readability */
        }

        /* Styling for main titles within sections */
        .section-title {
            font-size: 38px;
            font-weight: 700;
            color: var(--text-color);
            text-align: center;
            margin-bottom: 20px;
        }

        /* Styling for subtitles within sections */
        .section-subtitle {
            font-size: 20px; /* Slightly smaller for contact page */
            color: var(--light-text-color);
            text-align: center;
            margin-bottom: 60px;
            line-height: 1.4;
        }

        /* Contact information block */
        .contact-info {
            text-align: center;
            margin-bottom: 60px;
        }

        .contact-info p {
            font-size: 18px;
            margin-bottom: 10px;
            color: var(--light-text-color);
        }

        .contact-info a {
            color: var(--primary-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .contact-info a:hover {
            color: var(--secondary-color);
        }

        /* WhatsApp Button styling */
        .whatsapp-button {
            display: inline-flex; /* Allows text and icon to be on one line */
            align-items: center;
            background-color: #25d366; /* WhatsApp green */
            color: white;
            padding: 12px 25px;
            border-radius: 30px;
            text-decoration: none;
            font-weight: 600;
            font-size: 18px;
            margin-top: 20px;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .whatsapp-button i {
            margin-right: 10px;
            font-size: 22px;
        }

        .whatsapp-button:hover {
            background-color: #1da851; /* Darker green on hover */
            transform: translateY(-2px); /* Slight lift effect */
        }

        /* Contact Form styling */
        .contact-form {
            max-width: 700px;
            margin: 0 auto; /* Center the form */
            padding: 40px;
            background-color: #fcfcfc; /* Slightly off-white for the form background */
            border-radius: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05); /* Subtle shadow */
        }

        .contact-form h3 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
            text-align: center;
            margin-bottom: 30px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block; /* Label on its own line */
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--text-color);
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            color: var(--text-color);
            background-color: #fff;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            box-sizing: border-box; /* Include padding in element's total width and height */
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2); /* Focus ring effect */
            outline: none; /* Remove default outline */
        }

        .form-group textarea {
            min-height: 120px; /* Minimum height for textarea */
            resize: vertical; /* Allow vertical resizing */
        }

        .form-group .captcha-question {
            font-weight: 600;
            margin-bottom: 10px;
        }

        .form-group input[type="number"] {
            width: 100px; /* Shorter width for captcha input */
            text-align: center;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            color: var(--text-color);
            box-sizing: border-box;
        }

        .form-group input[type="number"]:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.2);
            outline: none;
        }

        /* Submit button styling */
        .submit-button {
            display: block;
            width: 100%;
            padding: 15px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s ease, transform 0.2s ease;
        }

        .submit-button:hover {
            background-color: var(--secondary-color);
            transform: translateY(-2px);
        }

        /* Message display styling (success/error) */
        .form-message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }

        .form-message.success {
            background-color: #d4edda;
            color: var(--success-color);
            border: 1px solid #c3e6cb;
        }

        .form-message.error {
            background-color: #f8d7da;
            color: var(--error-color);
            border: 1px solid #f5c6cb;
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
            .contact-info p {
                font-size: 16px;
            }
            .contact-form {
                padding: 25px; /* Reduce padding on mobile */
            }
            .contact-form h3 {
                font-size: 24px;
            }
            .form-group input[type="text"],
            .form-group input[type="email"],
            .form-group textarea,
            .form-group input[type="number"] {
                padding: 10px;
                font-size: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- Header Navigation (Consistent with index.php and about.php) -->
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
                <li><a href="index.php">Home</a></li>
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

    <!-- Contact Section -->
    <section class="contact-section">
        <div class="container">
            <h1 class="section-title">Get in Touch with Earndos</h1>
            <p class="section-subtitle">We're here to help you reduce churn and grow your business. Reach out to us through any of the channels below.</p>

            <div class="contact-info">
                <p><strong>Support Email:</strong> <a href="mailto:<?= htmlspecialchars($support_email) ?>"><?= htmlspecialchars($support_email) ?></a></p>
                <p><strong>CEO's Email:</strong> <a href="mailto:<?= htmlspecialchars($ceo_email) ?>"><?= htmlspecialchars($ceo_email) ?></a></p>
                <p><strong>CEO's WhatsApp:</strong> <?= htmlspecialchars($ceo_whatsapp) ?></p>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $ceo_whatsapp) ?>" target="_blank" class="whatsapp-button">
                    <i class="fab fa-whatsapp"></i> Chat on WhatsApp
                </a>
            </div>

            <div class="contact-form">
                <h3>Send Us a Message</h3>
                <?php if (!empty($form_message)): ?>
                    <div class="form-message <?= $form_error_type ?>">
                        <?= $form_message ?>
                    </div>
                <?php endif; ?>
                <form action="contact.php" method="POST">
                    <div class="form-group">
                        <label for="name">Your Name</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Your Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="subject">Subject</label>
                        <input type="text" id="subject" name="subject" value="<?= htmlspecialchars($subject) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="message">Message</label>
                        <textarea id="message" name="message" required><?= htmlspecialchars($message_content) ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="captcha">
                            <span class="captcha-question">What is <?= $num1 ?> + <?= $num2 ?>?</span>
                        </label>
                        <input type="number" id="captcha" name="captcha" required autocomplete="off">
                    </div>
                    <button type="submit" class="submit-button">Send Message</button>
                </form>
            </div>
        </div>
    </section>

    <!-- Footer (Consistent with index.php and about.php) -->
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
        document.querySelectorAll('#mainNavMenu a').forEach(item => {
            item.addEventListener('click', () => {
                if (mainNavMenu.classList.contains('active')) {
                    mainNavMenu.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>
