<?php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']);
$user_name = $is_logged_in ? $_SESSION['user_name'] : '';
$user_type = $is_logged_in ? $_SESSION['user_type'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Runner - Online Bus Booking</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Header and Navigation -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <?php if ($is_logged_in): ?>
                        <?php if ($user_type === 'admin'): ?>
                            <li><a href="admin/dashboard.php">Admin Panel</a></li>
                        <?php elseif ($user_type === 'operator'): ?>
                            <li><a href="operator/dashboard.php">Operator Panel</a></li>
                        <?php elseif ($user_type === 'passenger'): ?>
                            <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                            <li><a href="search_buses.php">Search Buses</a></li>
                            <li><a href="my_bookings.php">My Bookings</a></li>
                            <li><a href="my_parcels.php">My Parcels</a></li>
                        <?php endif; ?>
                        <li><a href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <?php if ($is_logged_in): ?>
            <div class="container" style="padding: 20px 0;">
                <div class="user_info">
                    Welcome back, <?php echo htmlspecialchars($user_name); ?>! 
                    (<?php echo ucfirst($user_type); ?>)
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
            <div class="container" style="padding: 20px 0;">
                <div class="alert alert_success">
                    You have been successfully logged out. Thank you for using Road Runner!
                </div>
            </div>
        <?php endif; ?>

        <!-- Hero Section -->
        <section class="hero">
            <div class="container">
                <h1>Travel Smart with Road Runner</h1>
                <p>Book your bus tickets online with our innovative gender-based seat selection</p>
                <?php if (!$is_logged_in): ?>
                    <a href="register.php" class="btn">Get Started</a>
                <?php else: ?>
                    <a href="search_buses.php" class="btn">Search Buses</a>
                <?php endif; ?>
            </div>
        </section>

        <!-- Features Section -->
        <section class="features">
            <div class="container">
                <h2 style="text-align: center; margin-bottom: 1rem;">Why Choose Road Runner?</h2>
                <div class="features_grid">
                    <div class="feature_card">
                        <div class="feature_icon">🎯</div>
                        <h3>Smart Seat Selection</h3>
                        <p>Our unique gender-based seat visualization helps you choose seats with comfort and privacy in mind.</p>
                    </div>
                    <div class="feature_card">
                        <div class="feature_icon">⚡</div>
                        <h3>Quick Booking</h3>
                        <p>Book your tickets in minutes with our streamlined booking process and instant confirmation.</p>
                    </div>
                    <div class="feature_card">
                        <div class="feature_icon">🔒</div>
                        <h3>Secure Payments</h3>
                        <p>Your payment information is protected with industry-standard security measures.</p>
                    </div>
                    <div class="feature_card">
                        <div class="feature_icon">📱</div>
                        <h3>Digital Tickets</h3>
                        <p>Get instant digital tickets that you can download or print for hassle-free travel.</p>
                    </div>
                    <div class="feature_card">
                        <div class="feature_icon">📦</div>
                        <h3>Parcel Service</h3>
                        <p>Send parcels along with passenger routes for convenient and affordable delivery.</p>
                    </div>
                    <div class="feature_card">
                        <div class="feature_icon">⭐</div>
                        <h3>Reviews & Ratings</h3>
                        <p>Read reviews from other passengers and rate your travel experience.</p>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. All rights reserved. | Online Bus Booking System</p>
        </div>
    </footer>
</body>
</html>