<?php
// Simple View Reviews System
// Save this as: view_reviews.php

session_start();
require_once 'db_connection.php';

$bus_id = $_GET['bus_id'] ?? '';
$error = '';
$reviews = [];
$bus_info = null;

if (empty($bus_id)) {
    $error = "No bus specified.";
} else {
    try {
        // Get bus information
        $stmt = $pdo->prepare("
            SELECT b.*, u.full_name as operator_name 
            FROM buses b 
            JOIN users u ON b.operator_id = u.user_id 
            WHERE b.bus_id = ?
        ");
        $stmt->execute([$bus_id]);
        $bus_info = $stmt->fetch();
        
        if (!$bus_info) {
            $error = "Bus not found.";
        } else {
            // Get reviews for this bus
            $stmt = $pdo->prepare("
                SELECT 
                    r.*, 
                    u.full_name as reviewer_name,
                    b.travel_date,
                    rt.route_name, rt.origin, rt.destination
                FROM reviews r
                JOIN users u ON r.passenger_id = u.user_id
                JOIN bookings b ON r.booking_id = b.booking_id
                JOIN schedules s ON b.schedule_id = s.schedule_id
                JOIN routes rt ON s.route_id = rt.route_id
                WHERE r.bus_id = ?
                ORDER BY r.created_at DESC
            ");
            $stmt->execute([$bus_id]);
            $reviews = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        $error = "Error loading reviews: " . $e->getMessage();
    }
}

// Calculate average rating
$total_reviews = count($reviews);
$average_rating = 0;
if ($total_reviews > 0) {
    $total_rating = array_sum(array_column($reviews, 'rating'));
    $average_rating = $total_rating / $total_reviews;
}

// Function to display star rating
function displayStars($rating) {
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
    <title>Bus Reviews - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .bus-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .rating-summary {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1rem;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .rating-number {
            font-size: 3rem;
            font-weight: bold;
            color: #ffc107;
        }
        
        .rating-stars {
            font-size: 1.5rem;
            color: #ffc107;
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
        
        .reviewer-info {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .reviewer-avatar {
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
        
        .bus-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="search.php">Search Buses</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <li><a href="<?php echo $_SESSION['user_type'] === 'passenger' ? 'passenger/dashboard.php' : ($_SESSION['user_type'] === 'operator' ? 'operator/dashboard.php' : 'admin/dashboard.php'); ?>">Dashboard</a></li>
                        <li><a href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>
            
            <!-- Bus Information -->
            <div class="bus-header">
                <h2 style="margin-bottom: 1rem;">🚌 <?php echo htmlspecialchars($bus_info['bus_name']); ?></h2>
                <div class="bus-info-grid">
                    <div><strong>Bus Number:</strong> <?php echo htmlspecialchars($bus_info['bus_number']); ?></div>
                    <div><strong>Type:</strong> <?php echo htmlspecialchars($bus_info['bus_type']); ?></div>
                    <div><strong>Seats:</strong> <?php echo htmlspecialchars($bus_info['total_seats']); ?></div>
                    <div><strong>Operator:</strong> <?php echo htmlspecialchars($bus_info['operator_name']); ?></div>
                </div>
                <?php if ($bus_info['amenities']): ?>
                    <div style="margin-top: 1rem;">
                        <strong>Amenities:</strong> <?php echo htmlspecialchars($bus_info['amenities']); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Rating Summary -->
            <?php if ($total_reviews > 0): ?>
                <div class="rating-summary">
                    <div class="rating-number"><?php echo number_format($average_rating, 1); ?></div>
                    <div>
                        <div class="rating-stars">
                            <?php echo displayStars(round($average_rating)); ?>
                        </div>
                        <div style="color: #666;">
                            Based on <?php echo $total_reviews; ?> review<?php echo $total_reviews != 1 ? 's' : ''; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Reviews List -->
            <div class="reviews-container">
                <h3 style="margin-bottom: 1rem;">Customer Reviews</h3>
                
                <?php if ($total_reviews > 0): ?>
                    <?php foreach ($reviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <div class="reviewer-info">
                                    <div class="reviewer-avatar">
                                        <?php echo strtoupper(substr($review['reviewer_name'], 0, 1)); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: bold;"><?php echo htmlspecialchars($review['reviewer_name']); ?></div>
                                        <div style="font-size: 0.9rem; color: #666;">
                                            <?php echo date('M j, Y', strtotime($review['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="review-rating">
                                    <?php echo displayStars($review['rating']); ?>
                                    <span style="font-weight: bold; margin-left: 0.5rem;"><?php echo $review['rating']; ?>/5</span>
                                </div>
                            </div>
                            
                            <div class="review-text">
                                <?php echo nl2br(htmlspecialchars($review['review_text'])); ?>
                            </div>
                            
                            <div class="review-meta">
                                <strong>Trip:</strong> <?php echo htmlspecialchars($review['route_name']); ?> 
                                (<?php echo htmlspecialchars($review['origin']); ?> → <?php echo htmlspecialchars($review['destination']); ?>)
                                • Travel Date: <?php echo date('M j, Y', strtotime($review['travel_date'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                <?php else: ?>
                    <div class="no-reviews">
                        <h3>No Reviews Yet</h3>
                        <p>This bus hasn't received any reviews yet.</p>
                        <p>Be the first to share your experience!</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Trusted reviews from real passengers.</p>
        </div>
    </footer>
</body>
</html>