<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is passenger
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'passenger') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get passenger statistics and info
try {
    // Get passenger info
    $stmt = $pdo->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $passenger_info = $stmt->fetch();

    // Get actual booking statistics
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_bookings FROM bookings WHERE passenger_id = ?");
    $stmt->execute([$user_id]);
    $total_bookings = $stmt->fetch()['total_bookings'] ?? 0;

    // Get upcoming trips
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as upcoming_trips 
        FROM bookings b 
        JOIN schedules s ON b.schedule_id = s.schedule_id 
        WHERE b.passenger_id = ? 
        AND b.booking_status IN ('pending', 'confirmed') 
        AND CONCAT(b.travel_date, ' ', s.departure_time) > NOW()
    ");
    $stmt->execute([$user_id]);
    $upcoming_trips = $stmt->fetch()['upcoming_trips'] ?? 0;

    // Get completed trips
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as completed_trips 
        FROM bookings b 
        JOIN schedules s ON b.schedule_id = s.schedule_id 
        WHERE b.passenger_id = ? 
        AND b.booking_status IN ('confirmed', 'completed') 
        AND CONCAT(b.travel_date, ' ', s.departure_time) <= NOW()
    ");
    $stmt->execute([$user_id]);
    $completed_trips = $stmt->fetch()['completed_trips'] ?? 0;

    // Get cancelled bookings (pending refund) - for tab count only
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cancelled_bookings 
        FROM bookings 
        WHERE passenger_id = ? AND booking_status = 'cancelled'
    ");
    $stmt->execute([$user_id]);
    $cancelled_bookings = $stmt->fetch()['cancelled_bookings'] ?? 0;

    // Get refunded bookings - for tab display only
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as refunded_bookings 
        FROM bookings 
        WHERE passenger_id = ? AND booking_status = 'refunded'
    ");
    $stmt->execute([$user_id]);
    $refunded_bookings = $stmt->fetch()['refunded_bookings'] ?? 0;

    // Get total spent (only confirmed/completed bookings)
    $stmt = $pdo->prepare("
        SELECT SUM(total_amount) as total_spent 
        FROM bookings 
        WHERE passenger_id = ? 
        AND booking_status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$user_id]);
    $total_spent = $stmt->fetch()['total_spent'] ?? 0;

    // Get recent bookings (last 5 active ones)
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_reference, b.passenger_name, b.travel_date, b.booking_status, b.total_amount,
            s.departure_time, s.arrival_time,
            r.route_name, r.origin, r.destination,
            seat.seat_number,
            bus.bus_id, bus.bus_name, bus.bus_number
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        WHERE b.passenger_id = ? AND b.booking_status IN ('pending', 'confirmed', 'completed')
        ORDER BY b.booking_date DESC
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $recent_bookings_raw = $stmt->fetchAll();

    // Convert seat numbers to horizontal layout for recent bookings too
    $recent_bookings = [];
    foreach ($recent_bookings_raw as $booking) {
        $booking['horizontal_seat_number'] = getHorizontalSeatNumber($booking['seat_number'], $booking['bus_id'], $pdo);
        $recent_bookings[] = $booking;
    }

    // Get refund history
    $stmt = $pdo->prepare("
        SELECT 
            b.booking_reference, b.passenger_name, b.travel_date, b.total_amount,
            b.booking_date, b.updated_at,
            s.departure_time,
            r.route_name, r.origin, r.destination,
            seat.seat_number,
            bus.bus_id, bus.bus_name, bus.bus_number,
            CASE 
                WHEN b.booking_status = 'cancelled' THEN 'Processing'
                WHEN b.booking_status = 'refunded' THEN 'Completed'
                ELSE 'Unknown'
            END as refund_status
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        WHERE b.passenger_id = ? AND b.booking_status IN ('cancelled', 'refunded')
        ORDER BY b.updated_at DESC
    ");
    $stmt->execute([$user_id]);
    $refund_history_raw = $stmt->fetchAll();

    // Convert seat numbers to horizontal layout
    $refund_history = [];
    foreach ($refund_history_raw as $refund) {
        $refund['horizontal_seat_number'] = getHorizontalSeatNumber($refund['seat_number'], $refund['bus_id'], $pdo);
        $refund_history[] = $refund;
    }

} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
}

// Function to convert database seat number to horizontal visual layout number
function getHorizontalSeatNumber($seatNumber, $busId, $pdo)
{
    try {
        // Get bus seat configuration
        $stmt = $pdo->prepare("SELECT seat_configuration FROM buses WHERE bus_id = ?");
        $stmt->execute([$busId]);
        $seatConfig = $stmt->fetch()['seat_configuration'] ?? '2x2';

        // Parse configuration
        $config = explode('x', $seatConfig);
        $leftSeats = (int) $config[0];
        $rightSeats = (int) $config[1];
        $seatsPerRow = $leftSeats + $rightSeats;

        // Get all seats for this bus ordered by seat_number
        $stmt = $pdo->prepare("SELECT seat_number FROM seats WHERE bus_id = ? ORDER BY seat_number ASC");
        $stmt->execute([$busId]);
        $allSeats = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $seatLetter = substr($seatNumber, 0, 1);
        $seatRowNum = (int) substr($seatNumber, 1);
        $positionInRow = ord($seatLetter) - ord('A');
        $horizontalNumber = (($seatRowNum - 1) * $seatsPerRow) + $positionInRow + 1;

        return $horizontalNumber;
    } catch (PDOException $e) {
        return $seatNumber;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dashboard - Road Runner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="dashboard.php">My Dashboard</a></li>
                    <li><a href="../search_buses.php">Search Buses</a></li>
                    <li><a href="../send_parcel.php">Parcel</a></li>
                    <li><a href="../my_bookings.php">My Bookings</a></li>
                    <li><a href="../my_parcels.php">My Parcels</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! Ready for your next journey?
        </div>

        <!-- Display Errors -->
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Clean Statistics Cards -->
        <div class="dashboard_grid">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_bookings; ?></div>
                <div class="stat_label">Total Bookings</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $upcoming_trips; ?></div>
                <div class="stat_label">Upcoming Trips</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $completed_trips; ?></div>
                <div class="stat_label">Completed Trips</div>
            </div>

            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($total_spent); ?></div>
                <div class="stat_label">Total Spent</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div style="border-bottom: 2px solid #eee; margin: 2rem 0;">
            <div style="display: flex; gap: 2rem;">
                <button class="tab_btn active" onclick="showTab('overview')" id="overview-tab">
                    Overview
                </button>
                <button class="tab_btn" onclick="showTab('profile')" id="profile-tab">
                    Profile
                </button>
            </div>
        </div>

        <!-- Overview Tab -->
        <div id="overview-content" class="tab_content active">
            <!-- Two Column Layout -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">

                <!-- Recent Bookings -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">Recent Bookings</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Route</th>
                                <th>Date & Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_bookings)): ?>
                                <?php foreach ($recent_bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong><br>
                                            <small>Seat: <?php echo $booking['horizontal_seat_number']; ?></small>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($booking['origin']); ?> →
                                                <?php echo htmlspecialchars($booking['destination']); ?></strong><br>
                                            <small><?php echo htmlspecialchars($booking['bus_name']); ?>
                                                (<?php echo htmlspecialchars($booking['bus_number']); ?>)</small>
                                        </td>
                                        <td>
                                            <?php echo date('M j, Y', strtotime($booking['travel_date'])); ?><br>
                                            <small><?php echo date('g:i A', strtotime($booking['departure_time'])); ?></small>
                                        </td>
                                        <td>
                                            <span
                                                class="badge badge_<?php echo $booking['booking_status'] === 'confirmed' ? 'active' : ($booking['booking_status'] === 'pending' ? 'operator' : 'inactive'); ?>">
                                                <?php echo ucfirst($booking['booking_status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text_center" style="color: #666;">
                                        No bookings found. <a href="../search_buses.php">Book your first trip!</a>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($recent_bookings)): ?>
                        <div class="p_1">
                            <a href="../my_bookings.php" class="btn btn_primary">View All Bookings</a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">Quick Actions</h3>
                    <div class="p_2">
                        <div style="display: grid; gap: 1rem;">
                            <button class="btn btn_primary" onclick="window.location.href='../search_buses.php'"
                                style="width: 100%;">
                                🔍 Search & Book Buses
                            </button>
                            <button class="btn btn_primary" onclick="window.location.href='../my_bookings.php'"
                                style="width: 100%;">
                                🎫 View My Bookings
                            </button>
                            <button class="btn btn_success" onclick="window.location.href='../send_parcel.php'"
                                style="width: 100%;">
                                📦 Send Parcel
                            </button>
                            <button class="btn btn_primary" onclick="window.location.href='../my_reviews.php'"
                                style="width: 100%;">
                                ⭐ My Reviews
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Tab -->
        <div id="profile-content" class="tab_content">
            <div class="table_container">
                <h3 class="p_1 mb_1">Profile Information</h3>
                <?php if ($passenger_info): ?>
                    <table class="table">
                        <tr>
                            <td><strong>Full Name:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['full_name']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Email:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['email']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Phone:</strong></td>
                            <td><?php echo htmlspecialchars($passenger_info['phone']); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Member Since:</strong></td>
                            <td><?php echo date('M j, Y', strtotime($passenger_info['created_at'])); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Account Status:</strong></td>
                            <td><span class="badge badge_active">Active</span></td>
                        </tr>
                    </table>
                    <div class="p_1">
                        <a href="edit_profile.php" class="btn btn_primary">Edit Profile</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Safe travels!</p>
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
    </script>
</body>

</html>