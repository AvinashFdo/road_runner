<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and is operator
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header("Location: ../login.php");
    exit();
}

$operator_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_booking_status':
                try {
                    $booking_id = $_POST['booking_id'];
                    $status = $_POST['status'];

                    // Verify booking belongs to this operator's bus
                    $verify_stmt = $pdo->prepare("
                        SELECT b.booking_id 
                        FROM bookings b
                        JOIN schedules s ON b.schedule_id = s.schedule_id
                        JOIN buses bus ON s.bus_id = bus.bus_id
                        WHERE b.booking_id = ? AND bus.operator_id = ?
                    ");
                    $verify_stmt->execute([$booking_id, $operator_id]);

                    if ($verify_stmt->fetch()) {
                        $stmt = $pdo->prepare("UPDATE bookings SET booking_status = ? WHERE booking_id = ?");
                        $stmt->execute([$status, $booking_id]);
                        $message = "Booking status updated successfully!";
                    } else {
                        $error = "You don't have permission to update this booking.";
                    }
                } catch (Exception $e) {
                    $error = "Error updating booking status: " . $e->getMessage();
                }
                break;

            case 'update_parcel_status':
                try {
                    $parcel_id = $_POST['parcel_id'];
                    $status = $_POST['status'];

                    // Verify parcel is on this operator's route
                    $verify_stmt = $pdo->prepare("
                        SELECT p.parcel_id 
                        FROM parcels p
                        JOIN routes r ON p.route_id = r.route_id
                        JOIN schedules s ON r.route_id = s.route_id
                        JOIN buses bus ON s.bus_id = bus.bus_id
                        WHERE p.parcel_id = ? AND bus.operator_id = ? AND s.status = 'active'
                    ");
                    $verify_stmt->execute([$parcel_id, $operator_id]);

                    if ($verify_stmt->fetch()) {
                        $stmt = $pdo->prepare("UPDATE parcels SET status = ? WHERE parcel_id = ?");
                        $stmt->execute([$status, $parcel_id]);
                        $message = "Parcel status updated successfully!";
                    } else {
                        $error = "You don't have permission to update this parcel.";
                    }
                } catch (Exception $e) {
                    $error = "Error updating parcel status: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get statistics for this operator
try {
    // Booking statistics for this operator's buses (match dashboard calculation)
    $booking_stats_sql = "
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN b.booking_status = 'pending' THEN 1 ELSE 0 END) as pending_bookings,
            SUM(CASE WHEN b.booking_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
            SUM(CASE WHEN b.booking_status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
            SUM(CASE WHEN b.booking_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_bookings,
            SUM(CASE WHEN b.booking_status IN ('confirmed', 'completed') THEN b.total_amount ELSE 0 END) as total_booking_revenue
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        WHERE bus.operator_id = ?
    ";
    $booking_stats_stmt = $pdo->prepare($booking_stats_sql);
    $booking_stats_stmt->execute([$operator_id]);
    $booking_stats = $booking_stats_stmt->fetch();

    // Parcel statistics for this operator's routes (exact match with dashboard)
    $parcel_stats_sql = "
        SELECT 
            COUNT(DISTINCT p.parcel_id) as total_parcels,
            SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) as pending_parcels,
            SUM(CASE WHEN p.status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_parcels,
            SUM(CASE WHEN p.status = 'delivered' THEN 1 ELSE 0 END) as delivered_parcels,
            SUM(CASE WHEN p.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_parcels,
            SUM(p.delivery_cost) as total_parcel_revenue
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        WHERE EXISTS (
            SELECT 1 FROM schedules s 
            JOIN buses b ON s.bus_id = b.bus_id 
            WHERE s.route_id = r.route_id 
            AND b.operator_id = ? 
            AND s.status = 'active'
        )
    ";
    $parcel_stats_stmt = $pdo->prepare($parcel_stats_sql);
    $parcel_stats_stmt->execute([$operator_id]);
    $parcel_stats = $parcel_stats_stmt->fetch();

    // Get all bookings for this operator's buses
    $bookings_sql = "
        SELECT 
            b.*,
            u.full_name as passenger_full_name,
            u.email as passenger_email,
            bus.bus_name,
            bus.bus_number,
            r.route_name,
            r.origin,
            r.destination,
            sch.departure_time,
            st.seat_number
        FROM bookings b
        JOIN users u ON b.passenger_id = u.user_id
        JOIN schedules sch ON b.schedule_id = sch.schedule_id
        JOIN buses bus ON sch.bus_id = bus.bus_id
        JOIN routes r ON sch.route_id = r.route_id
        JOIN seats st ON b.seat_id = st.seat_id
        WHERE bus.operator_id = ?
        ORDER BY b.travel_date DESC, b.booking_date DESC
    ";
    $bookings_stmt = $pdo->prepare($bookings_sql);
    $bookings_stmt->execute([$operator_id]);
    $bookings = $bookings_stmt->fetchAll();

    // Get all parcels for this operator's routes
    $parcels_sql = "
        SELECT DISTINCT
            p.*,
            u.full_name as sender_full_name,
            u.email as sender_email,
            r.route_name,
            r.origin,
            r.destination
        FROM parcels p
        JOIN users u ON p.sender_id = u.user_id
        JOIN routes r ON p.route_id = r.route_id
        JOIN schedules s ON r.route_id = s.route_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        WHERE bus.operator_id = ? AND s.status = 'active'
        ORDER BY p.travel_date DESC, p.created_at DESC
    ";
    $parcels_stmt = $pdo->prepare($parcels_sql);
    $parcels_stmt->execute([$operator_id]);
    $parcels = $parcels_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Error loading data: " . $e->getMessage();
    $booking_stats = ['total_bookings' => 0, 'pending_bookings' => 0, 'confirmed_bookings' => 0, 'completed_bookings' => 0, 'cancelled_bookings' => 0, 'total_booking_revenue' => 0];
    $parcel_stats = ['total_parcels' => 0, 'pending_parcels' => 0, 'in_transit_parcels' => 0, 'delivered_parcels' => 0, 'cancelled_parcels' => 0, 'total_parcel_revenue' => 0];
    $bookings = [];
    $parcels = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Bookings - Road Runner Operator</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">
                    <img src="../images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
                </div>
                <ul class="nav_links">
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Operator Navigation -->
    <div class="operator_nav">
        <div class="container">
            <div class="operator_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="buses.php">Buses</a>
                <a href="schedules.php">Schedules</a>
                <a href="parcels.php">Parcel Management</a>
                <a href="bookings.php">View All Bookings</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">📋 My Bookings & Parcels</h2>

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

        <!-- Overall Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $booking_stats['total_bookings']; ?></div>
                <div class="stat_label">My Bus Bookings</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_stats['total_parcels']; ?></div>
                <div class="stat_label">Route Parcels</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">LKR
                    <?php echo number_format($booking_stats['total_booking_revenue'] + $parcel_stats['total_parcel_revenue']); ?>
                </div>
                <div class="stat_label">Total Revenue</div>
            </div>
        </div>

        <!-- Two Column Layout for Summary -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">

            <!-- Booking Status Summary -->
            <div class="table_container">
                <h3 class="p_1 mb_1">🚌 My Bus Bookings Status</h3>
                <div style="padding: 1rem;">
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_pending">Pending</span>
                        <strong><?php echo $booking_stats['pending_bookings']; ?> bookings</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_confirmed">Confirmed</span>
                        <strong><?php echo $booking_stats['confirmed_bookings']; ?> bookings</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_completed">Completed</span>
                        <strong><?php echo $booking_stats['completed_bookings']; ?> bookings</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_cancelled">Cancelled</span>
                        <strong><?php echo $booking_stats['cancelled_bookings']; ?> bookings</strong>
                    </div>

                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; text-align: center;">
                        <strong style="font-size: 1.2rem; color: #f39c12;">
                            Revenue: LKR <?php echo number_format($booking_stats['total_booking_revenue']); ?>
                        </strong>
                    </div>
                </div>
            </div>

            <!-- Parcel Status Summary -->
            <div class="table_container">
                <h3 class="p_1 mb_1">📦 Route Parcel Status</h3>
                <div style="padding: 1rem;">
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_pending">Pending</span>
                        <strong><?php echo $parcel_stats['pending_parcels']; ?> parcels</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_in_transit">In Transit</span>
                        <strong><?php echo $parcel_stats['in_transit_parcels']; ?> parcels</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_delivered">Delivered</span>
                        <strong><?php echo $parcel_stats['delivered_parcels']; ?> parcels</strong>
                    </div>
                    <div
                        style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                        <span class="badge badge_cancelled">Cancelled</span>
                        <strong><?php echo $parcel_stats['cancelled_parcels']; ?> parcels</strong>
                    </div>

                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; text-align: center;">
                        <strong style="font-size: 1.2rem; color: #f39c12;">
                            Revenue: LKR <?php echo number_format($parcel_stats['total_parcel_revenue']); ?>
                        </strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabbed Content -->
        <div class="tab_container">
            <div class="tabs mb_2">
                <button class="tab_btn active" onclick="switchTab('bookings')" id="bookings-tab">
                    🚌 Bus Bookings (<?php echo count($bookings); ?>)
                </button>
                <button class="tab_btn" onclick="switchTab('parcels')" id="parcels-tab">
                    📦 Route Parcels (<?php echo count($parcels); ?>)
                </button>
            </div>

            <!-- Bus Bookings Tab -->
            <div id="bookings" class="tab_content active">
                <div class="table_container">
                    <h3 class="p_1 mb_1">My Bus Bookings</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Reference</th>
                                <th>Passenger</th>
                                <th>Route</th>
                                <th>Bus & Seat</th>
                                <th>Travel Date</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td><?php echo $booking['booking_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($booking['booking_reference']); ?></strong></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($booking['passenger_name']); ?></div>
                                        <small
                                            style="color: #666;"><?php echo htmlspecialchars($booking['passenger_email']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($booking['route_name']); ?></strong></div>
                                        <small
                                            style="color: #666;"><?php echo htmlspecialchars($booking['origin'] . ' → ' . $booking['destination']); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($booking['bus_name']); ?>
                                            (<?php echo htmlspecialchars($booking['bus_number']); ?>)</div>
                                        <small style="color: #666;">Seat:
                                            <?php echo htmlspecialchars($booking['seat_number']); ?></small>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($booking['travel_date'])); ?></td>
                                    <td><strong>LKR <?php echo number_format($booking['total_amount']); ?></strong></td>
                                    <td>
                                        <span class="badge badge_<?php echo $booking['booking_status']; ?>">
                                            <?php echo ucfirst($booking['booking_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="update_booking_status">
                                            <input type="hidden" name="booking_id"
                                                value="<?php echo $booking['booking_id']; ?>">
                                            <select name="status" onchange="this.form.submit()"
                                                style="padding: 2px; font-size: 0.8rem;">
                                                <option value="pending" <?php echo $booking['booking_status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="confirmed" <?php echo $booking['booking_status'] === 'confirmed' ? 'selected' : ''; ?>>Confirmed
                                                </option>
                                                <option value="completed" <?php echo $booking['booking_status'] === 'completed' ? 'selected' : ''; ?>>Completed
                                                </option>
                                                <option value="cancelled" <?php echo $booking['booking_status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled
                                                </option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (empty($bookings)): ?>
                        <div style="padding: 2rem; text-align: center; color: #666;">
                            <p>No bookings found for your buses.</p>
                            <p>Bookings will appear here when passengers book seats on your buses.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Parcels Tab -->
            <div id="parcels" class="tab_content">
                <div class="table_container">
                    <h3 class="p_1 mb_1">Route Parcels</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Parcel ID</th>
                                <th>Tracking Number</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Route</th>
                                <th>Weight</th>
                                <th>Cost</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parcels as $parcel): ?>
                                <tr>
                                    <td><?php echo $parcel['parcel_id']; ?></td>
                                    <td><strong><?php echo htmlspecialchars($parcel['tracking_number']); ?></strong></td>
                                    <td>
                                        <div><?php echo htmlspecialchars($parcel['sender_name']); ?></div>
                                        <small
                                            style="color: #666;"><?php echo htmlspecialchars($parcel['sender_email']); ?></small>
                                    </td>
                                    <td>
                                        <div><?php echo htmlspecialchars($parcel['receiver_name']); ?></div>
                                        <small
                                            style="color: #666;"><?php echo htmlspecialchars($parcel['receiver_phone']); ?></small>
                                    </td>
                                    <td>
                                        <div><strong><?php echo htmlspecialchars($parcel['route_name']); ?></strong></div>
                                        <small
                                            style="color: #666;"><?php echo htmlspecialchars($parcel['origin'] . ' → ' . $parcel['destination']); ?></small>
                                    </td>
                                    <td><?php echo $parcel['weight_kg']; ?> kg</td>
                                    <td><strong>LKR <?php echo number_format($parcel['delivery_cost']); ?></strong></td>
                                    <td>
                                        <span class="badge badge_<?php echo $parcel['status']; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $parcel['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="update_parcel_status">
                                            <input type="hidden" name="parcel_id"
                                                value="<?php echo $parcel['parcel_id']; ?>">
                                            <select name="status" onchange="this.form.submit()"
                                                style="padding: 2px; font-size: 0.8rem;">
                                                <option value="pending" <?php echo $parcel['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                                <option value="in_transit" <?php echo $parcel['status'] === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                                <option value="delivered" <?php echo $parcel['status'] === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                                                <option value="cancelled" <?php echo $parcel['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if (empty($parcels)): ?>
                        <div style="padding: 2rem; text-align: center; color: #666;">
                            <p>No parcels found for your routes.</p>
                            <p>Parcels will appear here when customers book parcel delivery on your bus routes.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab_content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all buttons
            document.querySelectorAll('.tab_btn').forEach(button => {
                button.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked button
            document.getElementById(tabName + '-tab').classList.add('active');
        }
    </script>
</body>

</html>