<?php
// Updated Admin Dashboard with Cancelled Bookings
// Save this as: admin/dashboard.php (UPDATED VERSION)

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

// Get some basic statistics
try {
    // Count total users
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $total_users = $stmt->fetch()['total_users'];
    
    // Count users by type
    $stmt = $pdo->query("SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type");
    $user_stats = [];
    while ($row = $stmt->fetch()) {
        $user_stats[$row['user_type']] = $row['count'];
    }
    
    // Get parcel statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_parcels,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_parcels,
            SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_parcels,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_parcels,
            SUM(CASE WHEN status IS NULL OR status = '' OR status = 'cancelled' OR status = 'refunded' THEN 1 ELSE 0 END) as cancelled_parcels,
            SUM(delivery_cost) as total_revenue
        FROM parcels
    ");
    $parcel_stats = $stmt->fetch();
    
    // UPDATED: Get booking statistics including cancelled bookings
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_bookings,
            SUM(CASE WHEN booking_status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_bookings,
            SUM(CASE WHEN booking_status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
            SUM(CASE WHEN booking_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END) as cancelled_bookings,
            SUM(total_amount) as total_booking_revenue
        FROM bookings
    ");
    $booking_stats = $stmt->fetch();
    
    // Get recent activity
    $stmt = $pdo->query("
        SELECT 'user' as type, full_name as name, email, created_at FROM users 
        UNION ALL
        SELECT 'parcel' as type, tracking_number as name, CONCAT('From: ', sender_name) as email, created_at FROM parcels
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $recent_activity = $stmt->fetchAll();
    
    // Get route statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_routes FROM routes WHERE status = 'active'");
    $total_routes = $stmt->fetch()['total_routes'];
    
    // Get bus statistics
    $stmt = $pdo->query("SELECT COUNT(*) as total_buses FROM buses WHERE status = 'active'");
    $total_buses = $stmt->fetch()['total_buses'];
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    $total_users = 0;
    $user_stats = [];
    $parcel_stats = ['total_parcels' => 0, 'pending_parcels' => 0, 'in_transit_parcels' => 0, 'delivered_parcels' => 0, 'cancelled_parcels' => 0, 'total_revenue' => 0];
    $booking_stats = ['total_bookings' => 0, 'confirmed_bookings' => 0, 'completed_bookings' => 0, 'cancelled_bookings' => 0, 'total_booking_revenue' => 0];
    $recent_activity = [];
    $total_routes = 0;
    $total_buses = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Road Runner</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Admin</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Admin Navigation -->
    <div class="admin_nav">
        <div class="container">
            <div class="admin_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="routes.php">Manage Routes</a>
                <a href="parcels.php">Parcel Management</a>
                <a href="#" onclick="alert('Coming soon!')">Manage Users</a>
                <a href="#" onclick="alert('Coming soon!')">View All Buses</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome to Admin Dashboard, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!
        </div>

        <!-- Display Errors -->
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- System Overview Statistics -->
        <h3 class="mb_1">📊 System Overview</h3>
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_users ?? 0; ?></div>
                <div class="stat_label">Total Users</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_routes; ?></div>
                <div class="stat_label">Active Routes</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_buses; ?></div>
                <div class="stat_label">Active Buses</div>
            </div>
            
        </div>

        <!-- Revenue Summary -->
        <div class="alert alert_success mt_2">
            <h4>💰 Revenue Summary</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #2c3e50;">LKR <?php echo number_format($booking_stats['total_booking_revenue']); ?></strong><br>
                    <span style="color: #666;">Bus Booking Revenue</span>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #2c3e50;">LKR <?php echo number_format($parcel_stats['total_revenue']); ?></strong><br>
                    <span style="color: #666;">Parcel Delivery Revenue</span>
                </div>
                <div style="text-align: center;">
                    <strong style="font-size: 1.5rem; color: #27ae60;">LKR <?php echo number_format($booking_stats['total_booking_revenue'] + $parcel_stats['total_revenue']); ?></strong><br>
                    <span style="color: #666;">Total System Revenue</span>
                </div>
            </div>
        </div>

        <!-- UPDATED: Booking System Statistics -->
        <h3 class="mb_1">🎫 Booking System Status</h3>
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $booking_stats['total_bookings']; ?></div>
                <div class="stat_label">Total Bookings</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $booking_stats['confirmed_bookings']; ?></div>
                <div class="stat_label">Confirmed Bookings</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $booking_stats['completed_bookings']; ?></div>
                <div class="stat_label">Completed Trips</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number" style="color: #e74c3c;"><?php echo $booking_stats['cancelled_bookings']; ?></div>
                <div class="stat_label">Cancelled Bookings</div>
            </div>
        </div>

        <!-- Parcel System Statistics -->
        <h3 class="mb_1">📦 Parcel System Status</h3>
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_stats['pending_parcels']; ?></div>
                <div class="stat_label">Pending Parcels</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_stats['in_transit_parcels']; ?></div>
                <div class="stat_label">In Transit</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number"><?php echo $parcel_stats['delivered_parcels']; ?></div>
                <div class="stat_label">Delivered</div>
            </div>
            
            <div class="stat_card">
                <div class="stat_number" style="color: #e74c3c;" ><?php echo $parcel_stats['cancelled_parcels']; ?></div>
                <div class="stat_label">Cancelled Parcels</div>
            </div>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
            
            <!-- Recent Activity -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Recent System Activity</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Details</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($recent_activity)): ?>
                            <?php foreach ($recent_activity as $activity): ?>
                                <tr>
                                    <td>
                                        <span class="badge badge_<?php echo $activity['type'] === 'user' ? 'passenger' : 'operator'; ?>">
                                            <?php echo $activity['type'] === 'user' ? 'User' : 'Parcel'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($activity['name']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($activity['email']); ?></small>
                                    </td>
                                    <td><?php echo date('M j, g:i A', strtotime($activity['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="text_center">No recent activity</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Quick Admin Actions -->
            <div class="table_container">
                <h3 class="p_1 mb_1">🚀 Quick Actions</h3>
                <div class="p_2">
                    <div style="display: flex; flex-direction: column; gap: 1rem;">
                        <a href="parcels.php" class="btn btn_primary" style="text-align: center;">
                            📦 Manage Parcels
                        </a>
                        <a href="routes.php" class="btn btn_success" style="text-align: center;">
                            🛣️ Manage Routes
                        </a>
                        <a href="parcels.php?status=cancelled" class="btn" style="background: #e74c3c; color: white; text-align: center; text-decoration: none;">
                            ❌ View Cancelled Parcels
                        </a>
                        
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Admin Panel. Complete system control!</p>
        </div>
    </footer>

    <script>
        // Auto-refresh dashboard statistics every 2 minutes
        setInterval(function() {
            // Only refresh if user is still on the page and active
            if (document.hasFocus()) {
                location.reload();
            }
        }, 120000); // 2 minutes
        
        // Add visual feedback for quick actions
        document.addEventListener('DOMContentLoaded', function() {
            const quickActionBtns = document.querySelectorAll('.table_container .btn');
            quickActionBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    if (this.href) {
                        this.style.transform = 'scale(0.95)';
                        setTimeout(() => {
                            this.style.transform = 'scale(1)';
                        }, 100);
                    }
                });
            });
        });
    </script>
</body>
</html>