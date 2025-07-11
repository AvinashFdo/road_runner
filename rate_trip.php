@ -0,0 +1,331 @@
<?php
// Simple Rating System for Road Runner
// Save this as: rate_trip.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_ref = $_GET['booking_ref'] ?? '';
$message = '';
$error = '';

// Get booking details
$booking_details = null;
$existing_review = null;

if (empty($booking_ref)) {
    header('Location: my_bookings.php');
    exit();
}

try {
    // Get booking details
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_id, b.booking_reference, b.passenger_name, b.travel_date, b.total_amount,
            b.booking_status, b.passenger_id,
            s.departure_time, s.arrival_time,
            bus.bus_id, bus.bus_name, bus.bus_number, bus.bus_type, bus.amenities,
            r.route_name, r.origin, r.destination,
            u.full_name as operator_name,
            seat.seat_number
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN users u ON bus.operator_id = u.user_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        WHERE b.booking_reference = ? AND b.passenger_id = ?
    ");
    $stmt->execute([$booking_ref, $user_id]);
    $booking_details = $stmt->fetch();

    if (!$booking_details) {
        $error = "Booking not found.";
    } else {
        // Check if trip is completed
        $travel_datetime = strtotime($booking_details['travel_date'] . ' ' . $booking_details['departure_time']);
        $is_completed = $travel_datetime < time();

        if (!$is_completed) {
            $error = "You can only rate completed trips.";
        } else {
            // Check if user has already reviewed this trip
            $stmt = $pdo->prepare("SELECT * FROM reviews WHERE booking_id = ? AND passenger_id = ?");
            $stmt->execute([$booking_details['booking_id'], $user_id]);
            $existing_review = $stmt->fetch();
        }
    }
} catch (PDOException $e) {
    $error = "Error loading booking details: " . $e->getMessage();
}

// Handle review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review'])) {
    $rating = (int) ($_POST['rating'] ?? 0);
    $review_text = trim($_POST['review_text'] ?? '');

    // Validation
    if ($rating < 1 || $rating > 5) {
        $error = "Please select a rating between 1 and 5 stars.";
    } elseif (empty($review_text)) {
        $error = "Please write a review.";
    } elseif (strlen($review_text) < 10) {
        $error = "Review must be at least 10 characters long.";
    } else {
        try {
            if ($existing_review) {
                // Update existing review
                $stmt = $pdo->prepare("UPDATE reviews SET rating = ?, review_text = ? WHERE booking_id = ? AND passenger_id = ?");
                $stmt->execute([$rating, $review_text, $booking_details['booking_id'], $user_id]);
                $message = "Your review has been updated successfully!";
            } else {
                // Create new review
                $stmt = $pdo->prepare("INSERT INTO reviews (booking_id, passenger_id, bus_id, rating, review_text) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$booking_details['booking_id'], $user_id, $booking_details['bus_id'], $rating, $review_text]);
                $message = "Thank you for your review!";
            }

            // Refresh review data
            $stmt = $pdo->prepare("SELECT * FROM reviews WHERE booking_id = ? AND passenger_id = ?");
            $stmt->execute([$booking_details['booking_id'], $user_id]);
            $existing_review = $stmt->fetch();

        } catch (PDOException $e) {
            $error = "Error submitting review: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Your Trip - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .rating-stars {
            display: flex;
            gap: 0.5rem;
            margin: 1rem 0;
            justify-content: center;
        }

        .rating-star {
            font-size: 2rem;
            color: #ddd;
            cursor: pointer;
            transition: color 0.2s ease;
        }

        .rating-star:hover,
        .rating-star.selected {
            color: #ffc107;
        }

        .trip-info {
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .trip-info h3 {
            color: #2c3e50;
            margin-bottom: 1rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .existing-review {
            background: #e8f5e8;
            border: 1px solid #d4edda;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .review-rating {
            color: #ffc107;
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
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
                    <li><a href="passenger/dashboard.php">Dashboard</a></li>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>⭐ Rate Your Trip</h2>
            <a href="my_bookings.php" class="btn" style="background: #6c757d;">← Back to Bookings</a>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert_success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($booking_details && empty($error)): ?>

            <!-- Trip Information -->
            <div class="trip-info">
                <h3>🎫 Trip Details</h3>
                <div class="info-grid">
                    <div>
                        <p><strong>Route:</strong> <?php echo htmlspecialchars($booking_details['route_name']); ?></p>
                        <p><strong>From:</strong> <?php echo htmlspecialchars($booking_details['origin']); ?></p>
                        <p><strong>To:</strong> <?php echo htmlspecialchars($booking_details['destination']); ?></p>
                        <p><strong>Travel Date:</strong>
                            <?php echo date('D, M j, Y', strtotime($booking_details['travel_date'])); ?></p>
                    </div>
                    <div>
                        <p><strong>Bus:</strong> <?php echo htmlspecialchars($booking_details['bus_name']); ?></p>
                        <p><strong>Bus Number:</strong> <?php echo htmlspecialchars($booking_details['bus_number']); ?></p>
                        <p><strong>Type:</strong> <?php echo htmlspecialchars($booking_details['bus_type']); ?></p>
                        <p><strong>Seat:</strong> <?php echo htmlspecialchars($booking_details['seat_number']); ?></p>
                    </div>
                </div>
            </div>

            <!-- Existing Review -->
            <?php if ($existing_review): ?>
                <div class="existing-review">
                    <h4>✅ Your Current Review</h4>
                    <div class="review-rating">
                        <?php
                        for ($i = 1; $i <= 5; $i++) {
                            echo $i <= $existing_review['rating'] ? '★' : '☆';
                        }
                        ?>
                        (<?php echo $existing_review['rating']; ?>/5)
                    </div>
                    <p><?php echo htmlspecialchars($existing_review['review_text']); ?></p>
                    <small>Reviewed on: <?php echo date('M j, Y', strtotime($existing_review['created_at'])); ?></small>
                </div>
            <?php endif; ?>

            <!-- Review Form -->
            <div class="form_container">
                <h3><?php echo $existing_review ? 'Update Your Review' : 'Write Your Review'; ?></h3>

                <form method="POST" id="reviewForm">
                    <!-- Rating -->
                    <div class="form_group">
                        <label>Rating: *</label>
                        <div class="rating-stars" id="ratingStars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span
                                    class="rating-star <?php echo ($existing_review && $i <= $existing_review['rating']) ? 'selected' : ''; ?>"
                                    data-rating="<?php echo $i; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" name="rating" id="ratingInput"
                            value="<?php echo $existing_review ? $existing_review['rating'] : ''; ?>" required>
                    </div>

                    <!-- Review Text -->
                    <div class="form_group">
                        <label for="review_text">Your Review: *</label>
                        <textarea id="review_text" name="review_text" class="form_control" rows="5"
                            placeholder="Share your experience about this trip..."
                            required><?php echo $existing_review ? htmlspecialchars($existing_review['review_text']) : ''; ?></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div style="text-align: center; margin-top: 2rem;">
                        <button type="submit" name="submit_review" class="btn btn_primary">
                            <?php echo $existing_review ? 'Update Review' : 'Submit Review'; ?>
                        </button>
                    </div>
                </form>
            </div>

        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your feedback matters!</p>
        </div>
    </footer>

    <script>
        // Rating stars functionality
        const stars = document.querySelectorAll('.rating-star');
        const ratingInput = document.getElementById('ratingInput');

        stars.forEach(star => {
            star.addEventListener('click', function () {
                const rating = parseInt(this.getAttribute('data-rating'));
                ratingInput.value = rating;

                // Update star display
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('selected');
                    } else {
                        s.classList.remove('selected');
                    }
                });
            });
        });

        // Form validation
        document.getElementById('reviewForm').addEventListener('submit', function (e) {
            const rating = parseInt(ratingInput.value);
            const reviewText = document.getElementById('review_text').value.trim();

            if (!rating || rating < 1 || rating > 5) {
                e.preventDefault();
                alert('Please select a rating.');
                return;
            }

            if (reviewText.length < 10) {
                e.preventDefault();
                alert('Please write at least 10 characters in your review.');
                return;
            }
        });
    </script>
</body>

</html>