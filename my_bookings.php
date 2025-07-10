<?php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_booking'])) {
    $booking_refs = $_POST['booking_refs'] ?? '';
    $booking_references = explode(',', $booking_refs);
    
    if (!empty($booking_references)) {
        try {
            $pdo->beginTransaction();
            
            $cancelled_count = 0;
            $errors = [];
            
            foreach ($booking_references as $booking_ref) {
                $booking_ref = trim($booking_ref);
                
                // Check if booking can be cancelled
                $stmt = $pdo->prepare("
                    SELECT b.*, s.departure_time 
                    FROM bookings b 
                    JOIN schedules s ON b.schedule_id = s.schedule_id 
                    WHERE b.booking_reference = ? AND b.passenger_id = ? AND b.booking_status IN ('pending', 'confirmed')
                ");
                $stmt->execute([$booking_ref, $user_id]);
                $booking = $stmt->fetch();
                
                if (!$booking) {
                    $errors[] = "Booking $booking_ref not found or cannot be cancelled.";
                    continue;
                }
                
                // Check timing (2 hours before departure)
                $departure_datetime = $booking['travel_date'] . ' ' . $booking['departure_time'];
                $hours_until = (strtotime($departure_datetime) - time()) / 3600;
                
                if ($hours_until < 2) {
                    $errors[] = "Cannot cancel $booking_ref - less than 2 hours to departure.";
                    continue;
                }
                
                // Cancel the booking
                $stmt = $pdo->prepare("UPDATE bookings SET booking_status = 'cancelled' WHERE booking_reference = ?");
                $stmt->execute([$booking_ref]);
                $cancelled_count++;
            }
            
            $pdo->commit();
            
            if ($cancelled_count > 0) {
                $message = "$cancelled_count booking(s) cancelled successfully. Contact support for refund assistance.";
            }
            
            if (!empty($errors)) {
                $error = implode(' ', $errors);
            }
            
        } catch (PDOException $e) {
            $pdo->rollback();
            $error = "Error cancelling bookings: " . $e->getMessage();
        }
    }
}

// Get all bookings and group them
try {
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_id, b.booking_reference, b.passenger_name, b.passenger_gender,
            b.travel_date, b.total_amount, b.booking_status, b.payment_status, b.booking_date,
            s.departure_time, s.arrival_time, s.schedule_id, s.bus_id,
            bus.bus_name, bus.bus_number, bus.bus_type,
            r.route_name, r.origin, r.destination,
            seat.seat_number,
            u.full_name as operator_name, u.phone as operator_phone
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN users u ON bus.operator_id = u.user_id
        WHERE b.passenger_id = ?
        ORDER BY b.travel_date DESC, s.departure_time DESC, b.booking_date DESC
    ");
    $stmt->execute([$user_id]);
    $all_bookings = $stmt->fetchAll();
    
    // Initialize arrays
    $upcoming_trips = [];
    $past_trips = [];
    $cancelled_trips = [];
    
    // Only process if we have bookings
    if (!empty($all_bookings)) {
        // Group bookings by trip
        $grouped_bookings = [];
        foreach ($all_bookings as $booking) {
            // Ensure all required fields exist
            if (empty($booking['travel_date']) || empty($booking['schedule_id']) || empty($booking['booking_date'])) {
                continue; // Skip invalid booking data
            }
            
            $group_key = $booking['travel_date'] . '_' . $booking['schedule_id'] . '_' . $booking['booking_date'];
            
            if (!isset($grouped_bookings[$group_key])) {
                $grouped_bookings[$group_key] = [
                    'trip_info' => $booking, // This contains all fields including bus_id
                    'bookings' => []
                ];
            }
            
            $grouped_bookings[$group_key]['bookings'][] = $booking;
        }
        
        // Categorize trips
        foreach ($grouped_bookings as $group) {
            $trip_info = $group['trip_info'];
            $bookings = $group['bookings'];
            
            // Check if any booking in group is cancelled or refunded
            $has_cancelled = false;
            foreach ($bookings as $booking) {
                if (in_array($booking['booking_status'], ['cancelled', 'refunded'])) {
                    $has_cancelled = true;
                    break;
                }
            }
            
            if ($has_cancelled) {
                $cancelled_trips[] = [
                    'trip_info' => $trip_info,
                    'bookings' => $bookings
                ];
            } else {
                // Check if trip is in the past
                $departure_datetime = strtotime($trip_info['travel_date'] . ' ' . $trip_info['departure_time']);
                $is_past = $departure_datetime < time();
                
                if ($is_past) {
                    $past_trips[] = [
                        'trip_info' => $trip_info,
                        'bookings' => $bookings
                    ];
                } else {
                    $upcoming_trips[] = [
                        'trip_info' => $trip_info,
                        'bookings' => $bookings
                    ];
                }
            }
        }
    }
    
} catch (PDOException $e) {
    $error = "Error loading bookings: " . $e->getMessage();
    $upcoming_trips = [];
    $past_trips = [];
    $cancelled_trips = [];
}

// Function to get seat configuration for horizontal display
function getHorizontalSeatNumber($seatNumber, $busId, $pdo) {
    try {
        // If seat number is already a simple number, just return it
        if (is_numeric($seatNumber)) {
            return (int)$seatNumber;
        }
        
        // If it's in letter format (A1, B2, etc.), convert it
        if (preg_match('/^([A-Z])(\d+)$/', $seatNumber, $matches)) {
            $seatLetter = $matches[1];
            $seatRowNum = (int)$matches[2];
            
            // Get bus seat configuration
            $stmt = $pdo->prepare("SELECT seat_configuration FROM buses WHERE bus_id = ?");
            $stmt->execute([$busId]);
            $seatConfig = $stmt->fetch()['seat_configuration'] ?? '2x2';
            
            // Parse configuration
            $config = explode('x', $seatConfig);
            $leftSeats = (int)$config[0];
            $rightSeats = (int)$config[1];
            $seatsPerRow = $leftSeats + $rightSeats;
            
            $positionInRow = ord($seatLetter) - ord('A');
            $horizontalNumber = (($seatRowNum - 1) * $seatsPerRow) + $positionInRow + 1;
            
            return $horizontalNumber;
        }
        
        // If it's neither format, just return as-is
        return $seatNumber;
        
    } catch (PDOException $e) {
        return $seatNumber;
    }
}

// Function to get booking status display
function getBookingStatusDisplay($status) {
    switch ($status) {
        case 'pending':
            return ['text' => 'Pending', 'class' => 'badge_operator'];
        case 'confirmed':
            return ['text' => 'Confirmed', 'class' => 'badge_active'];
        case 'completed':
            return ['text' => 'Completed', 'class' => 'badge_active'];
        case 'cancelled':
            return ['text' => 'Cancelled', 'class' => 'badge_inactive'];
        case 'refunded':
            return ['text' => 'Cancelled', 'class' => 'badge_inactive'];
        default:
            return ['text' => ucfirst($status), 'class' => 'badge_operator'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                        <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="search_buses.php">Search Buses</a></li>
                    <li><a href="send_parcel.php">Parcel</a></li>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="my_parcels.php">My Parcels</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">🎫 My Bookings</h2>

        <!-- Display Messages -->
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

        <!-- Booking Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo count($upcoming_trips); ?></div>
                <div class="stat_label">Upcoming Trips</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($past_trips); ?></div>
                <div class="stat_label">Past Trips</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($cancelled_trips); ?></div>
                <div class="stat_label">Cancelled</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">
                    <?php 
                    $total_bookings = 0;
                    foreach (array_merge($upcoming_trips, $past_trips, $cancelled_trips) as $trip) {
                        $total_bookings += count($trip['bookings']);
                    }
                    echo $total_bookings;
                    ?>
                </div>
                <div class="stat_label">Total Bookings</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="tabs mb_2">
            <button class="tab_btn active" onclick="showTab('upcoming')" id="upcoming-tab">
                🚌 Upcoming Trips (<?php echo count($upcoming_trips); ?>)
            </button>
            <button class="tab_btn" onclick="showTab('past')" id="past-tab">
                ✅ Past Trips (<?php echo count($past_trips); ?>)
            </button>
            <button class="tab_btn" onclick="showTab('cancelled')" id="cancelled-tab">
                ❌ Cancelled (<?php echo count($cancelled_trips); ?>)
            </button>
        </div>

        <!-- Upcoming Trips -->
        <div id="upcoming-content" class="tab_content active">
            <h3 class="mb_1">Upcoming Trips</h3>
            <?php if (empty($upcoming_trips)): ?>
                <div class="alert alert_info">
                    <p>No upcoming trips found. Book your next journey today!</p>
                </div>
            <?php else: ?>
                <?php foreach ($upcoming_trips as $trip): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    🚌 <?php echo htmlspecialchars($trip['trip_info']['route_name']); ?>
                                    <span class="badge badge_active" style="margin-left: 1rem;">Upcoming</span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>From:</strong> <?php echo htmlspecialchars($trip['trip_info']['origin']); ?> 
                                    <strong>To:</strong> <?php echo htmlspecialchars($trip['trip_info']['destination']); ?><br>
                                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip['trip_info']['travel_date'])); ?><br>
                                    <strong>Departure:</strong> <?php echo date('g:i A', strtotime($trip['trip_info']['departure_time'])); ?><br>
                                    <strong>Bus:</strong> <?php echo htmlspecialchars($trip['trip_info']['bus_name']); ?> (<?php echo htmlspecialchars($trip['trip_info']['bus_number']); ?>)
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <?php 
                                $departure_datetime = strtotime($trip['trip_info']['travel_date'] . ' ' . $trip['trip_info']['departure_time']);
                                $hours_until = ($departure_datetime - time()) / 3600;
                                ?>
                                
                                <a href="booking_details.php?ref=<?php echo urlencode($trip['bookings'][0]['booking_reference']); ?>" class="btn btn_primary" style="margin-bottom: 0.5rem;">
                                    📋 View Details
                                </a>
                                
                                <?php if ($hours_until >= 2): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirmCancellation();">
                                        <input type="hidden" name="booking_refs" value="<?php echo implode(',', array_column($trip['bookings'], 'booking_reference')); ?>">
                                        <button type="submit" name="cancel_booking" class="btn" style="background: #dc3545;">
                                            ❌ Cancel Trip
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <button class="btn" style="background: #6c757d;" disabled title="Cannot cancel within 2 hours of departure">
                                        ❌ Too Late to Cancel
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Booking Details -->
                        <?php foreach ($trip['bookings'] as $booking): ?>
                            <?php $status = getBookingStatusDisplay($booking['booking_status']); ?>
                            <div class="booking-detail">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong>📋 <?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                        <span class="badge <?php echo $status['class']; ?>" style="margin-left: 1rem;">
                                            <?php echo $status['text']; ?>
                                        </span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>LKR <?php echo number_format($booking['total_amount']); ?></strong>
                                    </div>
                                </div>
                                <div style="margin-top: 0.5rem; color: #666;">
                                    <strong>Passenger:</strong> <?php echo htmlspecialchars($booking['passenger_name']); ?> |
                                    <strong>Seat:</strong> 
                                    <?php 
                                    // Use the booking's bus_id directly
                                    $seat_display = getHorizontalSeatNumber($booking['seat_number'], $booking['bus_id'], $pdo);
                                    echo $seat_display;
                                    // Show original seat number too for debugging
                                    if ($seat_display != $booking['seat_number']) {
                                        echo " <small style='color: #999;'>(" . htmlspecialchars($booking['seat_number']) . ")</small>";
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="total-summary">
                            <div>Total Amount:</div>
                            <div>LKR <?php echo number_format(array_sum(array_column($trip['bookings'], 'total_amount'))); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Past Trips -->
        <div id="past-content" class="tab_content">
            <h3 class="mb_1">Past Trips</h3>
            <?php if (empty($past_trips)): ?>
                <div class="alert alert_info">
                    <p>No past trips found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($past_trips as $trip): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    🚌 <?php echo htmlspecialchars($trip['trip_info']['route_name']); ?>
                                    <span class="badge badge_success" style="margin-left: 1rem;">Completed</span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>From:</strong> <?php echo htmlspecialchars($trip['trip_info']['origin']); ?> 
                                    <strong>To:</strong> <?php echo htmlspecialchars($trip['trip_info']['destination']); ?><br>
                                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip['trip_info']['travel_date'])); ?><br>
                                    <strong>Bus:</strong> <?php echo htmlspecialchars($trip['trip_info']['bus_name']); ?>
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <a href="booking_details.php?ref=<?php echo urlencode($trip['bookings'][0]['booking_reference']); ?>" class="btn btn_primary" style="margin-bottom: 0.5rem;">
                                    📋 View Details
                                <?php 
                                // Check if user has already reviewed this trip
                                $has_review = false;
                                try {
                                    $stmt = $pdo->prepare("SELECT review_id FROM reviews WHERE booking_id = ? AND passenger_id = ?");
                                    $stmt->execute([$trip['trip_info']['booking_id'], $user_id]);
                                    $has_review = $stmt->fetch() ? true : false;
                                } catch (PDOException $e) {
                                    // If error, assume no review
                                }
                                ?>
                                
                                <a href="rate_trip.php?booking_ref=<?php echo urlencode($trip['trip_info']['booking_reference']); ?>" 
                                class="btn btn_primary" style="background: #28a745;">
                                    <?php echo $has_review ? '✏️ Edit Review' : '⭐ Rate Trip'; ?>
                                </a>
                                
                                <!-- View Reviews Button -->
                                <a href="view_reviews.php?bus_id=<?php echo urlencode($trip['trip_info']['bus_id']); ?>" 
                                class="btn" style="background: #6c757d;">📝 View Reviews</a>
                            </div>
                        </div>
                        
                        <!-- Booking Details -->
                        <?php foreach ($trip['bookings'] as $booking): ?>
                            <div class="booking-detail">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong>📋 <?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                        <span class="badge badge_active" style="margin-left: 1rem;">Completed</span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>LKR <?php echo number_format($booking['total_amount']); ?></strong>
                                    </div>
                                </div>
                                <div style="margin-top: 0.5rem; color: #666;">
                                    <strong>Passenger:</strong> <?php echo htmlspecialchars($booking['passenger_name']); ?> |
                                    <strong>Seat:</strong> 
                                    <?php 
                                    // Debug: Let's see what we're working with
                                    $seat_display = getHorizontalSeatNumber($booking['seat_number'], $trip['trip_info']['bus_id'], $pdo);
                                    echo $seat_display;
                                    // Show original seat number too for debugging
                                    if ($seat_display != $booking['seat_number']) {
                                        echo " <small style='color: #999;'>(" . htmlspecialchars($booking['seat_number']) . ")</small>";
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="total-summary">
                            <div>Total Amount:</div>
                            <div>LKR <?php echo number_format(array_sum(array_column($trip['bookings'], 'total_amount'))); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Cancelled Trips -->
        <div id="cancelled-content" class="tab_content">
            <h3 class="mb_1">Cancelled Bookings</h3>
            <?php if (empty($cancelled_trips)): ?>
                <div class="alert alert_info">
                    <p>No cancelled bookings found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cancelled_trips as $trip): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    🚌 <?php echo htmlspecialchars($trip['trip_info']['route_name']); ?>
                                    <span class="badge badge_inactive" style="margin-left: 1rem;">❌ Cancelled</span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>From:</strong> <?php echo htmlspecialchars($trip['trip_info']['origin']); ?> 
                                    <strong>To:</strong> <?php echo htmlspecialchars($trip['trip_info']['destination']); ?><br>
                                    <strong>Was scheduled for:</strong> <?php echo date('D, M j, Y', strtotime($trip['trip_info']['travel_date'])); ?><br>
                                    <strong>Bus:</strong> <?php echo htmlspecialchars($trip['trip_info']['bus_name']); ?><br>
                                    <strong>Cancelled:</strong> <?php echo date('M j, Y', strtotime($trip['trip_info']['booking_date'])); ?>
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <a href="booking_details.php?ref=<?php echo urlencode($trip['bookings'][0]['booking_reference']); ?>" class="btn btn_primary" style="margin-bottom: 0.5rem;">
                                    📋 View Details
                                </a>
                                <button class="btn" style="background: #6c757d; color: white; font-size: 0.9rem;" onclick="alert('For refund assistance, please contact support at +94 11 123 4567 or email support@roadrunner.lk')">
                                    📞 Contact Support
                                </button>
                            </div>
                        </div>
                        
                        <!-- Booking Details -->
                        <?php foreach ($trip['bookings'] as $booking): ?>
                            <div class="booking-detail">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong>📋 <?php echo htmlspecialchars($booking['booking_reference']); ?></strong>
                                        <span class="badge badge_inactive" style="margin-left: 1rem;">Cancelled</span>
                                    </div>
                                    <div style="text-align: right;">
                                        <strong>LKR <?php echo number_format($booking['total_amount']); ?></strong>
                                    </div>
                                </div>
                                <div style="margin-top: 0.5rem; color: #666;">
                                    <strong>Passenger:</strong> <?php echo htmlspecialchars($booking['passenger_name']); ?> |
                                    <strong>Seat:</strong> 
                                    <?php 
                                    // Debug: Let's see what we're working with
                                    $seat_display = getHorizontalSeatNumber($booking['seat_number'], $trip['trip_info']['bus_id'], $pdo);
                                    echo $seat_display;
                                    // Show original seat number too for debugging
                                    if ($seat_display != $booking['seat_number']) {
                                        echo " <small style='color: #999;'>(" . htmlspecialchars($booking['seat_number']) . ")</small>";
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <div class="total-summary">
                            <div>Amount:</div>
                            <div>LKR <?php echo number_format(array_sum(array_column($trip['bookings'], 'total_amount'))); ?></div>
                        </div>
                        
                        <!-- Cancellation Information -->
                        <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; font-size: 0.9rem;">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <span style="font-size: 1.2rem; margin-right: 0.5rem;">❌</span>
                                <strong>Booking Cancelled</strong>
                            </div>
                            <p style="margin: 0; color: #666;">
                                This booking has been cancelled. For refund inquiries or assistance, please contact our customer support team.
                            </p>
                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #666;">
                                <strong>📞 Support:</strong> +94 11 123 4567 | <strong>📧 Email:</strong> support@roadrunner.lk
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your journey, our priority!</p>
        </div>
    </footer>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab_content');
            contents.forEach(content => content.classList.remove('active'));
            
            // Remove active class from all tab buttons
            const buttons = document.querySelectorAll('.tab_btn');
            buttons.forEach(button => button.classList.remove('active'));
            
            // Show selected tab content
            document.getElementById(tabName + '-content').classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
        }

        function confirmCancellation() {
            return confirm('Are you sure you want to cancel this trip? This action cannot be undone. Contact support for refund assistance.');
        }
    </script>

    <style>
        .tabs {
            display: flex;
            border-bottom: 2px solid #e0e0e0;
            margin-bottom: 1rem;
        }

        .tab_btn {
            background: none;
            border: none;
            padding: 1rem 1.5rem;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            font-size: 1rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .tab_btn:hover {
            background: #f8f9fa;
        }

        .tab_btn.active {
            border-bottom-color: #007bff;
            color: #007bff;
            background: #f8f9fa;
        }

        .tab_content {
            display: none;
        }

        .tab_content.active {
            display: block;
        }

        .booking-group {
            margin-bottom: 1.5rem;
        }

        .trip-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }

        .trip-title {
            flex: 1;
        }

        .trip-actions {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .trip-actions .btn {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .booking-detail {
            padding: 1rem 1.5rem;
            background: #fff;
            border: 1px solid #e9ecef;
            border-top: none;
        }

        .booking-detail:last-of-type {
            border-bottom: none;
        }

        .total-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: #fff;
            border: 1px solid #e9ecef;
            border-top: none;
            border-radius: 0 0 8px 8px;
            font-weight: bold;
            font-size: 1.1rem;
        }

        

        @media (max-width: 768px) {
            .trip-header {
                flex-direction: column;
                gap: 1rem;
            }

            .trip-actions {
                width: 100%;
                flex-direction: row;
                justify-content: space-between;
            }

            .tabs {
                flex-direction: column;
            }

            .tab_btn {
                border-bottom: none;
                border-left: 3px solid transparent;
            }

            .tab_btn.active {
                border-left-color: #007bff;
                border-bottom-color: transparent;
            }
        }
    </style>
</body>
</html>