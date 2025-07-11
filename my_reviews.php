<?php
// Simple My Reviews System
// Save this as: my_reviews.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$reviews = [];
$error = '';

try {
    if ($user_type === 'passenger') {
        // Get passenger's reviews
        $stmt = $pdo->prepare("
            SELECT 
                r.*, 
                b.booking_reference, b.travel_date,
                bus.bus_name, bus.bus_number,
                rt.route_name, rt.origin, rt.destination,
                u.full_name as operator_name
            FROM reviews r
            JOIN bookings b ON r.booking_id = b.booking_id
            JOIN buses bus ON r.bus_id = bus.bus_id
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN routes rt ON s.route_id = rt.route_id
            JOIN users u ON bus.operator_id = u.user_id
            WHERE r.passenger_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $reviews = $stmt->fetchAll();

    } elseif ($user_type === 'operator') {
        // Get reviews for operator's buses
        $stmt = $pdo->prepare("
            SELECT 
                r.*, 
                u.full_name as reviewer_name,
                b.booking_reference, b.travel_date,
                bus.bus_name, bus.bus_number,
                rt.route_name, rt.origin, rt.destination
            FROM reviews r
            JOIN users u ON r.passenger_id = u.user_id
            JOIN bookings b ON r.booking_id = b.booking_id
            JOIN buses bus ON r.bus_id = bus.bus_id
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN routes rt ON s.route_id = rt.route_id
            WHERE bus.operator_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $reviews = $stmt->fetchAll();

    } elseif ($user_type === 'admin') {
        // Get all reviews for admin
        $stmt = $pdo->prepare("
            SELECT 
                r.*, 
                u.full_name as reviewer_name,
                b.booking_reference, b.travel_date,
                bus.bus_name, bus.bus_number,
                rt.route_name, rt.origin, rt.destination,
                op.full_name as operator_name
            FROM reviews r
            JOIN users u ON r.passenger_id = u.user_id
            JOIN bookings b ON r.booking_id = b.booking_id
            JOIN buses bus ON r.bus_id = bus.bus_id
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN routes rt ON s.route_id = rt.route_id
            JOIN users op ON bus.operator_id = op.user_id
            ORDER BY r.created_at DESC
        ");
        $stmt->execute();
        $reviews = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    $error = "Error loading reviews: " . $e->getMessage();
}

// Function to display star rating
function displayStars($rating)
{
    $stars = '';
    for ($i = 1; $i <= 5; $i++) {
        $stars .= $i <= $rating ? '★' : '☆';
    }
    return $stars;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php
        if ($user_type === 'passenger')
            echo 'My Reviews';
        elseif ($user_type === 'operator')
            echo 'Bus Reviews';
        else
            echo 'All Reviews';
        ?> - Road Runner
    </title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            text-align: center;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
        }

        .stat-label {
            color: #666;
        }

        .review-item {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: white;
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .review-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .review-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #2c3e50;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            font-weight: bold;
        }

        .review-rating {
            color: #ffc107;
            font-size: 1.2rem;
        }

        .review-text {
            line-height: 1.6;
            margin-bottom: 1rem;
        }

        .review-meta {
            font-size: 0.9rem;
            color: #666;
            border-top: 1px solid #eee;
            padding-top: 1rem;
        }

        .no-reviews {
            text-align: center;
            padding: 3rem;
            color: #666;
        }

        .edit-review-btn {
            background: #28a745;
            color: white;
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 0.9rem;
        }

        .edit-review-btn:hover {
            background: #218838;
            color: white;
        }
    </style>
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">
                    <img src="images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
                </div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <?php if ($user_type === 'passenger'): ?>
                        <li><a href="passenger/dashboard.php">Dashboard</a></li>
                        <li><a href="my_bookings.php">My Bookings</a></li>
                    <?php elseif ($user_type === 'operator'): ?>
                        <li><a href="operator/dashboard.php">Dashboard</a></li>
                        <li><a href="operator/manage_buses.php">My Buses</a></li>
                    <?php elseif ($user_type === 'admin'): ?>
                        <li><a href="admin/dashboard.php">Dashboard</a></li>
                        <li><a href="admin/manage_users.php">Manage Users</a></li>
                    <?php endif; ?>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h2>
                <?php
                if ($user_type === 'passenger')
                    echo '⭐ My Reviews';
                elseif ($user_type === 'operator')
                    echo '📊 Reviews for My Buses';
                else
                    echo '🗂️ All System Reviews';
                ?>
            </h2>
            <p>
                <?php
                if ($user_type === 'passenger')
                    echo 'Manage your trip reviews and feedback';
                elseif ($user_type === 'operator')
                    echo 'Customer feedback for your bus services';
                else
                    echo 'Overview of all customer reviews in the system';
                ?>
            </p>
        </div>

        <!-- Statistics -->
        <?php if (!empty($reviews)): ?>
            <?php
            $total_reviews = count($reviews);
            $average_rating = array_sum(array_column($reviews, 'rating')) / $total_reviews;
            $five_star = count(array_filter($reviews, function ($r) {
                return $r['rating'] == 5;
            }));
            $four_plus = count(array_filter($reviews, function ($r) {
                return $r['rating'] >= 4;
            }));
            ?>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $total_reviews; ?></div>
                    <div class="stat-label">Total Reviews</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo number_format($average_rating, 1); ?></div>
                    <div class="stat-label">Average Rating</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $five_star; ?></div>
                    <div class="stat-label">5-Star Reviews</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo round(($four_plus / $total_reviews) * 100); ?>%</div>
                    <div class="stat-label">4+ Star Rating</div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Reviews List -->
        <div class="reviews-container">
            <?php if (!empty($reviews)): ?>
                <?php foreach ($reviews as $review): ?>
                    <div class="review-item">
                        <div class="review-header">
                            <div class="review-info">
                                <div class="review-avatar">
                                    <?php
                                    if ($user_type === 'passenger') {
                                        echo strtoupper(substr($review['bus_name'], 0, 1));
                                    } else {
                                        echo strtoupper(substr($review['reviewer_name'], 0, 1));
                                    }
                                    ?>
                                </div>
                                <div>
                                    <div style="font-weight: bold;">
                                        <?php
                                        if ($user_type === 'passenger') {
                                            echo htmlspecialchars($review['bus_name']) . ' (' . htmlspecialchars($review['bus_number']) . ')';
                                        } else {
                                            echo htmlspecialchars($review['reviewer_name']);
                                        }
                                        ?>
                                    </div>
                                    <div style="font-size: 0.9rem; color: #666;">
                                        <?php echo date('M j, Y \a\t g:i A', strtotime($review['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <div class="review-rating">
                                    <?php echo displayStars($review['rating']); ?>
                                    <span
                                        style="font-weight: bold; margin-left: 0.5rem;"><?php echo $review['rating']; ?>/5</span>
                                </div>
                                <?php if ($user_type === 'passenger'): ?>
                                    <div style="margin-top: 0.5rem;">
                                        <a href="rate_trip.php?booking_ref=<?php echo urlencode($review['booking_reference']); ?>"
                                            class="edit-review-btn">Edit Review</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="review-text">
                            <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                        </div>

                        <div class="review-meta">
                            <strong>Trip Details:</strong>
                            <?php if ($user_type === 'admin'): ?>
                                Operator: <?php echo htmlspecialchars($review['operator_name']); ?> •
                                Bus: <?php echo htmlspecialchars($review['bus_name']); ?>
                                (<?php echo htmlspecialchars($review['bus_number']); ?>) •
                            <?php elseif ($user_type === 'operator'): ?>
                                Bus: <?php echo htmlspecialchars($review['bus_name']); ?>
                                (<?php echo htmlspecialchars($review['bus_number']); ?>) •
                            <?php endif; ?>
                            Route: <?php echo htmlspecialchars($review['route_name']); ?>
                            (<?php echo htmlspecialchars($review['origin']); ?> →
                            <?php echo htmlspecialchars($review['destination']); ?>)
                            • Travel Date: <?php echo date('M j, Y', strtotime($review['travel_date'])); ?>
                            • Booking: <?php echo htmlspecialchars($review['booking_reference']); ?>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="no-reviews">
                    <h3>No Reviews Found</h3>
                    <?php if ($user_type === 'passenger'): ?>
                        <p>You haven't written any reviews yet.</p>
                        <p>Complete a trip and share your experience!</p>
                        <a href="my_bookings.php" class="btn btn_primary">View My Bookings</a>
                    <?php elseif ($user_type === 'operator'): ?>
                        <p>Your buses haven't received any reviews yet.</p>
                        <p>Provide excellent service to get positive feedback!</p>
                    <?php else: ?>
                        <p>No reviews have been submitted in the system yet.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your feedback helps us improve!</p>
        </div>
    </footer>
</body>

</html>