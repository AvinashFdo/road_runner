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
    
    // Get total revenue from confirmed bookings
    $stmt = $pdo->prepare("
        SELECT SUM(bk.total_amount) as total_revenue 
        FROM bookings bk 
        JOIN schedules s ON bk.schedule_id = s.schedule_id 
        JOIN buses b ON s.bus_id = b.bus_id 
        WHERE b.operator_id = ? 
        AND bk.booking_status IN ('confirmed', 'completed')
    ");
    $stmt->execute([$user_id]);
    $total_revenue = $stmt->fetch()['total_revenue'] ?? 0;
    
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
    
    // Recent schedule additions/updates (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            'Schedule Management' as action_type,
            CONCAT('Schedule created for ', r.route_name, ' - ', TIME_FORMAT(s.departure_time, '%h:%i %p')) as action_details,
            s.created_at as action_time,
            'schedule_added' as action_category
        FROM schedules s
        JOIN buses b ON s.bus_id = b.bus_id
        JOIN routes r ON s.route_id = r.route_id
        WHERE b.operator_id = ? 
        ORDER BY s.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $schedule_activities = $stmt->fetchAll();
    
    // Recent bookings (last 5)
    $stmt = $pdo->prepare("
        SELECT 
            'New Booking' as action_type,
            CONCAT('Booking ', bk.booking_reference, ' - Seat ', st.seat_number, ' for ', r.origin, ' to ', r.destination) as action_details,
            bk.booking_date as action_time,
            'booking_received' as action_category
        FROM bookings bk
        JOIN schedules s ON bk.schedule_id = s.schedule_id
        JOIN buses b ON s.bus_id = b.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats st ON bk.seat_id = st.seat_id
        WHERE b.operator_id = ? 
        AND bk.booking_status IN ('pending', 'confirmed')
        ORDER BY bk.booking_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$user_id]);
    $booking_activities = $stmt->fetchAll();
    
    // Combine all activities and sort by time
    $all_activities = array_merge($bus_activities, $schedule_activities, $booking_activities);
    
    // Sort by action_time descending
    usort($all_activities, function($a, $b) {
        return strtotime($b['action_time']) - strtotime($a['action_time']);
    });
    
    // Take only the most recent 8 activities
    $recent_activities = array_slice($all_activities, 0, 8);
    
    // If no activities, show welcome message
    if (empty($recent_activities)) {
        $recent_activities = [
            [
                'action_type' => 'Welcome',
                'action_details' => 'Welcome to Road Runner! Start by adding your first bus.',
                'action_time' => date('Y-m-d H:i:s'),
                'action_category' => 'welcome'
            ]
        ];
    }
    
} catch (PDOException $e) {
    $error = "Error loading dashboard data: " . $e->getMessage();
    $total_buses = 0;
    $active_buses = 0;
    $active_routes = 0;
    $today_bookings = 0;
    $total_revenue = 0;
    $recent_activities = [];
}

// Helper function to get time ago
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'Just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    return date('M j, Y', strtotime($datetime));
}

// Helper function to get activity icon
function getActivityIcon($category) {
    switch ($category) {
        case 'bus_added': return '🚌';
        case 'schedule_added': return '📅';
        case 'booking_received': return '🎫';
        case 'welcome': return '👋';
        default: return '📋';
    }
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
                <div class="logo">🚌 Road Runner - Operator</div>
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
                <a href="buses.php">My Buses</a>
                <a href="schedules.php">Routes & Schedules</a>
                <a href="parcels.php">Parcel Management</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <!-- Welcome Message -->
        <div class="user_info mb_2">
            Welcome to Operator Dashboard, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!
        </div>

        <!-- Display Errors -->
        <?php if (isset($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="dashboard_grid">
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
                <div class="stat_number">LKR <?php echo number_format($total_revenue); ?></div>
                <div class="stat_label">Total Revenue</div>
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
                            <span class="metric_value"><?php echo date('M j, Y', strtotime($operator_info['created_at'])); ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Today's Bookings:</span>
                            <span class="metric_value" style="color: #3498db;"><?php echo $today_bookings; ?> booking<?php echo $today_bookings !== 1 ? 's' : ''; ?></span>
                        </div>
                        <div class="dashboard_metric">
                            <span class="metric_label">Account Status:</span>
                            <span class="metric_value"><span class="badge badge_active">Active</span></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="table_container">
                <h3 class="p_1 mb_1">Recent Activities</h3>
                <div style="max-height: 400px; overflow-y: auto;">
                    <?php if (!empty($recent_activities)): ?>
                        <?php foreach ($recent_activities as $activity): ?>
                            <div class="activity_item">
                                <div class="activity_icon">
                                    <?php echo getActivityIcon($activity['action_category']); ?>
                                </div>
                                <div class="activity_content">
                                    <div class="activity_type"><?php echo htmlspecialchars($activity['action_type']); ?></div>
                                    <div class="activity_details"><?php echo htmlspecialchars($activity['action_details']); ?></div>
                                    <div class="activity_time"><?php echo timeAgo($activity['action_time']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="no_activities">
                            <p>No recent activities found.</p>
                            <p><small>Your activities will appear here as you manage your buses and schedules.</small></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid">
            <div class="feature_card">
                <h4>🚌 Manage Buses</h4>
                <p>Add new buses to your fleet, update bus information, and manage seating configurations.</p>
                <button class="btn btn_primary" onclick="window.location.href='buses.php'">Manage Buses</button>
            </div>
            
            <div class="feature_card">
                <h4>🗺️ Routes & Schedules</h4>
                <p>Create and manage your bus routes, set departure times, and update schedules.</p>
                <button class="btn btn_primary" onclick="window.location.href='schedules.php'">Manage Routes</button>
            </div>
            
            <div class="feature_card">
                <h4>📊 View Bookings</h4>
                <p>Monitor current bookings, check passenger details, and manage seat assignments.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">View Bookings</button>
            </div>
            
            <div class="feature_card">
                <h4>💰 Revenue Reports</h4>
                <p>Track your earnings, view payment history, and generate financial reports.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">View Reports</button>
            </div>
            
            <div class="feature_card">
                <h4>⚙️ Settings</h4>
                <p>Update your profile information, manage payment details, and configure preferences.</p>
                <button class="btn btn_primary" onclick="alert('Coming soon!')">Settings</button>
            </div>
            
            <div class="feature_card">
                <h4>📞 Support</h4>
                <p>Get help with your account, report issues, or contact our support team.</p>
                <button class="btn btn_success" onclick="alert('Support: Call +94 11 123 4567')">Get Support</button>
            </div>
        </div>

        <!-- Getting Started Guide -->
        <?php if ($total_buses === 0): ?>
        <div class="alert alert_info mt_2">
            <h4>Getting Started as a Bus Operator</h4>
            <p><strong>Welcome to Road Runner!</strong> Here's how to get started:</p>
            <ol style="margin: 1rem 0; padding-left: 2rem;">
                <li><strong>Add Your Buses:</strong> Register your buses with seating configuration</li>
                <li><strong>Create Routes:</strong> Set up your travel routes with pickup/drop points</li>
                <li><strong>Set Schedules:</strong> Define departure times and pricing</li>
                <li><strong>Go Live:</strong> Start accepting bookings from passengers</li>
            </ol>
            <p><em>Need help? Contact our support team for assistance with setting up your account.</em></p>
        </div>
        <?php else: ?>
        <div class="alert alert_success mt_2">
            <h4>🎉 Your Bus Operation is Active!</h4>
            <p>Great job! You have <strong><?php echo $total_buses; ?> bus(es)</strong> and <strong><?php echo $active_routes; ?> active schedule(s)</strong>. Keep monitoring your dashboard for new bookings and activity updates.</p>
            <div style="margin-top: 1rem;">
                <strong>Quick Tips:</strong>
                <ul style="margin: 0.5rem 0; padding-left: 1.5rem;">
                    <li>Check recent activities to stay updated on bookings and system changes</li>
                    <li>Monitor today's bookings and total revenue regularly</li>
                    <li>Update schedules and pricing based on demand patterns</li>
                    <li>Keep your bus information and contact details current</li>
                </ul>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Operator Panel. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Auto-refresh dashboard every 5 minutes to show latest activities
        setTimeout(function() {
            location.reload();
        }, 300000); // 5 minutes

        // Add smooth animations to stat cards
        document.addEventListener('DOMContentLoaded', function() {
            const statCards = document.querySelectorAll('.stat_card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                
                setTimeout(() => {
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>