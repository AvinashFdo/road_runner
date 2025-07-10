<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is operator
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get operator statistics
try {
    // Get actual bus count for this operator
    $stmt = $pdo->prepare("SELECT COUNT(*) as bus_count FROM buses WHERE operator_id = ?");
    $stmt->execute([$user_id]);
    $total_buses = $stmt->fetch()['bus_count'];

    // Get active bus count
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_buses FROM buses WHERE operator_id = ? AND status = 'active'");
    $stmt->execute([$user_id]);
    $active_buses = $stmt->fetch()['active_buses'];

    // Get active schedules count for this operator
    $stmt = $pdo->prepare("SELECT COUNT(*) as schedule_count FROM schedules s JOIN buses b ON s.bus_id = b.bus_id WHERE b.operator_id = ? AND s.status = 'active'");
    $stmt->execute([$user_id]);
    $active_routes = $stmt->fetch()['schedule_count'];

    // Get actual bookings count for today
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as today_bookings 
        FROM bookings bk 
        JOIN schedules s ON bk.schedule_id = s.schedule_id 
        JOIN buses b ON s.bus_id = b.bus_id 
        WHERE b.operator_id = ? 
        AND DATE(bk.booking_date) = CURDATE()
        AND bk.booking_status IN ('pending', 'confirmed')
    ");
    $stmt->execute([$user_id]);
    $today_bookings = $stmt->fetch()['today_bookings'];

    // UPDATED: Get comprehensive revenue statistics for this operator
    $stmt = $pdo->prepare("
        SELECT 
            SUM(bk.total_amount) as total_booking_revenue,
            COUNT(bk.booking_id) as total_bookings,
            SUM(CASE WHEN bk.booking_status = 'confirmed' THEN bk.total_amount ELSE 0 END) as confirmed_revenue,
            SUM(CASE WHEN bk.booking_status = 'completed' THEN bk.total_amount ELSE 0 END) as completed_revenue
        FROM bookings bk 
        JOIN schedules s ON bk.schedule_id = s.schedule_id 
        JOIN buses b ON s.bus_id = b.bus_id 
        WHERE b.operator_id = ? 
        AND bk.booking_status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$user_id]);
    $booking_revenue_stats = $stmt->fetch();
    $total_booking_revenue = $booking_revenue_stats['total_booking_revenue'] ?? 0;

    // UPDATED: Get comprehensive parcel statistics for this operator's routes
    $stmt = $pdo->prepare("
        SELECT 
            SUM(p.delivery_cost) as total_parcel_revenue,
            COUNT(p.parcel_id) as total_parcels,
            SUM(CASE WHEN p.status = 'delivered' THEN p.delivery_cost ELSE 0 END) as delivered_revenue,
            SUM(CASE WHEN p.status = 'pending' THEN 1 ELSE 0 END) as pending_parcels,
            SUM(CASE WHEN p.status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_parcels,
            SUM(CASE WHEN p.status = 'delivered' THEN 1 ELSE 0 END) as delivered_parcels,
            SUM(CASE WHEN p.status IS NULL OR p.status = '' OR p.status = 'cancelled' OR p.status = 'refunded' THEN 1 ELSE 0 END) as cancelled_parcels
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        WHERE EXISTS (
            SELECT 1 FROM schedules s 
            JOIN buses b ON s.bus_id = b.bus_id 
            WHERE s.route_id = r.route_id 
            AND b.operator_id = ? 
            AND s.status = 'active'
        )
    ");
    $stmt->execute([$user_id]);
    $parcel_revenue_stats = $stmt->fetch();
    $total_parcel_revenue = $parcel_revenue_stats['total_parcel_revenue'] ?? 0;

    // Calculate total revenue
    $total_revenue = $total_booking_revenue + $total_parcel_revenue;

    // Get operator info
    $stmt = $pdo->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $operator_info = $stmt->fetch();

    // Get recent activities (last 10 activities)
    $recent_activities = [];

    // Recent bus additions/updates (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            'Bus Management' as action_type,
            CONCAT('Added bus: ', bus_name, ' (', bus_number, ')') as action_details,
            created_at as action_time,
            'bus_added' as action_category
        FROM buses 
        WHERE operator_id = ?
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $bus_activities = $stmt->fetchAll();

    // Recent bookings (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            'Booking Received' as action_type,
            CONCAT('New booking from ', bk.passenger_name, ' - LKR ', bk.total_amount) as action_details,
            bk.booking_date as action_time,
            'booking_received' as action_category
        FROM bookings bk
        JOIN schedules s ON bk.schedule_id = s.schedule_id
        JOIN buses b ON s.bus_id = b.bus_id
        WHERE b.operator_id = ?
        ORDER BY bk.booking_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $booking_activities = $stmt->fetchAll();

    // Merge and sort activities
    $recent_activities = array_merge($bus_activities, $booking_activities);
    usort($recent_activities, function ($a, $b) {
        return strtotime($b['action_time']) - strtotime($a['action_time']);
    });
    $recent_activities = array_slice($recent_activities, 0, 8);

} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    $total_buses = $active_buses = $active_routes = $today_bookings = $total_revenue = 0;
    $total_booking_revenue = $total_parcel_revenue = 0;
    $operator_info = null;
    $recent_activities = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operator Dashboard - Road Runner</title>
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
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome back, <?php echo htmlspecialchars($_SESSION['user_name']); ?>! Ready to manage your bus operations?
        </div>

        <!-- Display Errors -->
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <h3 class="mb_1">📊 Operations Overview</h3>
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_buses; ?></div>
                <div class="stat_label">Total Buses</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $active_buses; ?></div>
                <div class="stat_label">Active Buses</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $active_routes; ?></div>
                <div class="stat_label">Active Schedules</div>
            </div>

            <div class="stat_card">
                <div class="stat_number" style="color: #3498db;"><?php echo $today_bookings; ?></div>
                <div class="stat_label">Today's Bookings</div>
            </div>
        </div>

        <!-- ADDED: Parcel System Statistics (matching admin dashboard) -->
        <h3 class="mb_1">📦 Parcel System Status</h3>
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_revenue_stats['pending_parcels'] ?? 0; ?></div>
                <div class="stat_label">Pending Parcels</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_revenue_stats['in_transit_parcels'] ?? 0; ?></div>
                <div class="stat_label">In Transit</div>
            </div>

            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_revenue_stats['delivered_parcels'] ?? 0; ?></div>
                <div class="stat_label">Delivered</div>
            </div>

            <div class="stat_card">
                <div class="stat_number" style="color: #e74c3c;">
                    <?php echo $parcel_revenue_stats['cancelled_parcels'] ?? 0; ?></div>
                <div class="stat_label">Cancelled Parcels</div>
            </div>
        </div>

        <!-- ADDED: Revenue Summary Section (similar to admin dashboard) -->
        <div class="alert alert_success mb_2">
            <h4>💰 Revenue Summary</h4>
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #2c3e50;">LKR
                        <?php echo number_format($total_booking_revenue); ?></strong><br>
                    <span style="color: #666;">Bus Booking Revenue</span>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #2c3e50;">LKR
                        <?php echo number_format($total_parcel_revenue); ?></strong><br>
                    <span style="color: #666;">Parcel Delivery Revenue</span>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #27ae60;">LKR
                        <?php echo number_format($total_revenue); ?></strong><br>
                    <span style="color: #666;">Total Revenue</span>
                </div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">

            <!-- Operator Information -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Operator Information</h3>
                <div style="padding: 1rem;">
                    <?php if ($operator_info): ?>
                        <div class="dashboard_metric">
                            <span class="metric_label">Full Name:</span>
                            <span class="metric_value"><?php echo htmlspecialchars($operator_info['full_name']); ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Email:</span>
                            <span class="metric_value"><?php echo htmlspecialchars($operator_info['email']); ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Phone:</span>
                            <span class="metric_value"><?php echo htmlspecialchars($operator_info['phone']); ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Member Since:</span>
                            <span
                                class="metric_value"><?php echo date('M j, Y', strtotime($operator_info['created_at'])); ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Today's Bookings:</span>
                            <span class="metric_value" style="color: #3498db;"><?php echo $today_bookings; ?>
                                booking<?php echo $today_bookings !== 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="p_2">
                            <div style="display: flex; flex-direction: column; gap: 1rem;">
                                <a href="../my_reviews.php" class="btn btn_success"
                                    style="text-align: center; text-decoration: none;">
                                    📊 Bus Reviews
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <p>Unable to load operator information.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Recent Activities</h3>
                <div style="padding: 1rem; max-height: 300px; overflow-y: auto;">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity_item">
                                <div class="activity_icon">
                                    <?php
                                    echo $activity['action_category'] === 'bus_added' ? '🚌' :
                                        ($activity['action_category'] === 'booking_received' ? '🎫' : '📋');
                                    ?>
                                </div>
                                <div class="activity_content">
                                    <div class="activity_type"><?php echo htmlspecialchars($activity['action_type']); ?></div>
                                    <div class="activity_details"><?php echo htmlspecialchars($activity['action_details']); ?>
                                    </div>
                                    <div class="activity_time">
                                        <?php echo date('M j, g:i A', strtotime($activity['action_time'])); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no_activities">
                            <p>No recent activities found.</p>
                            <p><em>Start by adding buses or managing your schedules!</em></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid mb_2">
            <div class="feature_card">
                <h4>📞 Support</h4>
                <p>Get help with your account, report issues, or contact our support team.</p>
                <button class="btn btn_success" onclick="alert('Support: Call +94 11 123 4567')">Get Support</button>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Operator Panel. Manage your bus operations with confidence!</p>
        </div>
    </footer>

    <script>
        // Auto-refresh dashboard statistics every 5 minutes
        setInterval(function () {
            if (document.hasFocus()) {
                location.reload();
            }
        }, 300000); // 5 minutes

        // Add visual feedback for buttons
        document.addEventListener('DOMContentLoaded', function () {
            const buttons = document.querySelectorAll('.btn');
            buttons.forEach(btn => {
                btn.addEventListener('click', function () {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 100);
                });
            });
        });
    </script>
</body>

</html>