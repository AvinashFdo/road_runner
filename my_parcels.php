<?php
// My Parcels Management System
// Save this as: my_parcels.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle parcel cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_parcel'])) {
    $parcel_id = $_POST['parcel_id'] ?? '';
    
    try {
        // Check if parcel belongs to user and can be cancelled
        $stmt = $pdo->prepare("
            SELECT p.*, r.origin, r.destination 
            FROM parcels p 
            JOIN routes r ON p.route_id = r.route_id 
            WHERE p.parcel_id = ? AND p.sender_id = ? AND p.status IN ('pending', 'in_transit')
        ");
        $stmt->execute([$parcel_id, $user_id]);
        $parcel = $stmt->fetch();
        
        if (!$parcel) {
            $error = "Parcel not found or cannot be cancelled.";
        } else {
            // Check if cancellation is allowed (24 hours before travel date)
            $travel_datetime = strtotime($parcel['travel_date']);
            $time_difference = $travel_datetime - time();
            $hours_until_travel = $time_difference / 3600;
            
            if ($hours_until_travel < 24) {
                $error = "Cannot cancel parcel. Cancellation is only allowed up to 24 hours before delivery date.";
            } else {
                // Cancel the parcel
                $stmt = $pdo->prepare("UPDATE parcels SET status = 'cancelled' WHERE parcel_id = ?");
                $stmt->execute([$parcel_id]);
                $message = "Parcel cancelled successfully. Refund will be processed within 3-5 business days.";
            }
        }
        
    } catch (PDOException $e) {
        $error = "Error cancelling parcel: " . $e->getMessage();
    }
}

// Get all parcels for the user
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            r.route_name, r.origin, r.destination, r.distance_km
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        WHERE p.sender_id = ?
        ORDER BY p.travel_date DESC, p.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $all_parcels = $stmt->fetchAll();
    
    // Separate parcels by status and date
    $active_parcels = [];
    $delivered_parcels = [];
    $cancelled_parcels = [];
    
    foreach ($all_parcels as $parcel) {
        $travel_datetime = strtotime($parcel['travel_date']);
        $is_future = $travel_datetime > time();
        
        switch ($parcel['status']) {
            case 'cancelled':
                $cancelled_parcels[] = $parcel;
                break;
            case 'delivered':
                $delivered_parcels[] = $parcel;
                break;
            case 'pending':
            case 'in_transit':
                if ($is_future || $parcel['status'] === 'in_transit') {
                    $active_parcels[] = $parcel;
                } else {
                    // Past delivery date but not marked as delivered - assume delivered
                    $delivered_parcels[] = $parcel;
                }
                break;
            default:
                $active_parcels[] = $parcel;
        }
    }
    
} catch (PDOException $e) {
    $error = "Error loading parcels: " . $e->getMessage();
    $active_parcels = [];
    $delivered_parcels = [];
    $cancelled_parcels = [];
}

// Get statistics
$total_parcels = count($all_parcels);
$total_spent = array_sum(array_column($all_parcels, 'delivery_cost'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Parcels - Road Runner</title>
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
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="my_parcels.php">My Parcels</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">📦 My Parcels</h2>

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

        <!-- Parcel Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo count($active_parcels); ?></div>
                <div class="stat_label">Active Deliveries</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($delivered_parcels); ?></div>
                <div class="stat_label">Delivered Parcels</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo count($cancelled_parcels); ?></div>
                <div class="stat_label">Cancelled</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($total_spent); ?></div>
                <div class="stat_label">Total Spent</div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div style="border-bottom: 2px solid #eee; margin-bottom: 2rem;">
            <div style="display: flex; gap: 2rem;">
                <button class="tab_btn active" onclick="showTab('active')" id="active-tab">
                    Active Deliveries (<?php echo count($active_parcels); ?>)
                </button>
                <button class="tab_btn" onclick="showTab('delivered')" id="delivered-tab">
                    Delivered (<?php echo count($delivered_parcels); ?>)
                </button>
                <button class="tab_btn" onclick="showTab('cancelled')" id="cancelled-tab">
                    Cancelled (<?php echo count($cancelled_parcels); ?>)
                </button>
            </div>
        </div>

        <!-- Active Parcels -->
        <div id="active-content" class="tab_content active">
            <h3 class="mb_1">Active Deliveries</h3>
            <?php if (empty($active_parcels)): ?>
                <div class="alert alert_info">
                    <h4>No active deliveries</h4>
                    <p>You don't have any parcels currently in transit. Ready to send a parcel?</p>
                    <a href="send_parcel.php" class="btn btn_primary mt_1">Send Parcel</a>
                </div>
            <?php else: ?>
                <?php foreach ($active_parcels as $parcel): ?>
                    <?php 
                    $travel_datetime = strtotime($parcel['travel_date']);
                    $time_difference = $travel_datetime - time();
                    $hours_until_travel = $time_difference / 3600;
                    $can_cancel = $hours_until_travel >= 24;
                    ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    📦 <?php echo htmlspecialchars($parcel['tracking_number']); ?>
                                    <span class="badge badge_<?php echo $parcel['status'] === 'pending' ? 'operator' : 'active'; ?>" style="margin-left: 1rem;">
                                        <?php echo ucfirst($parcel['status']); ?>
                                    </span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($parcel['route_name']); ?><br>
                                    <strong>From:</strong> <?php echo htmlspecialchars($parcel['origin']); ?> 
                                    <strong>To:</strong> <?php echo htmlspecialchars($parcel['destination']); ?><br>
                                    <strong>Delivery Date:</strong> <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>Weight:</strong> <?php echo $parcel['weight_kg']; ?> kg | 
                                    <strong>Type:</strong> <?php echo htmlspecialchars($parcel['parcel_type']); ?><br>
                                    <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel['receiver_name']); ?> (<?php echo htmlspecialchars($parcel['receiver_phone']); ?>)
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>" class="btn btn_primary" style="font-size: 0.9rem; margin-bottom: 0.5rem;">
                                    📱 Track Parcel
                                </a>
                                
                                <?php if ($can_cancel && $parcel['status'] === 'pending'): ?>
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to cancel this parcel delivery?');" style="margin-bottom: 0.5rem;">
                                        <input type="hidden" name="parcel_id" value="<?php echo $parcel['parcel_id']; ?>">
                                        <button type="submit" name="cancel_parcel" class="btn" style="background: #e74c3c; font-size: 0.9rem;">
                                            Cancel Delivery
                                        </button>
                                    </form>
                                    <small style="color: #27ae60;">✓ Free cancellation</small>
                                <?php else: ?>
                                    <button class="btn" disabled style="background: #95a5a6; font-size: 0.9rem;">
                                        Cannot Cancel
                                    </button>
                                    <small style="color: #e74c3c;">
                                        <?php echo $parcel['status'] === 'in_transit' ? 'Already in transit' : 'Cancellation deadline passed'; ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Delivery Progress -->
                        <div style="background: #f8f9fa; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                            <h5 style="margin-bottom: 1rem; color: #2c3e50;">Delivery Progress</h5>
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; text-align: center;">
                                <div style="<?php echo $parcel['status'] === 'pending' ? 'color: #3498db; font-weight: bold;' : 'color: #27ae60;'; ?>">
                                    <?php echo $parcel['status'] !== 'pending' ? '✅' : '📦'; ?> Pending
                                </div>
                                <div style="<?php echo $parcel['status'] === 'in_transit' ? 'color: #3498db; font-weight: bold;' : ($parcel['status'] === 'delivered' ? 'color: #27ae60;' : 'color: #999;'); ?>">
                                    <?php echo $parcel['status'] === 'delivered' ? '✅' : ($parcel['status'] === 'in_transit' ? '🚛' : '⏳'); ?> In Transit
                                </div>
                                <div style="<?php echo $parcel['status'] === 'delivered' ? 'color: #27ae60; font-weight: bold;' : 'color: #999;'; ?>">
                                    <?php echo $parcel['status'] === 'delivered' ? '✅' : '📍'; ?> Arrived
                                </div>
                                <div style="<?php echo $parcel['status'] === 'delivered' ? 'color: #27ae60; font-weight: bold;' : 'color: #999;'; ?>">
                                    <?php echo $parcel['status'] === 'delivered' ? '✅' : '📥'; ?> Delivered
                                </div>
                            </div>
                        </div>
                        
                        <!-- Cost and Details -->
                        <div class="total-summary">
                            <div>
                                <span>Delivery Cost:</span>
                            </div>
                            <div>
                                <span style="color: #e74c3c; font-size: 1.2rem;">
                                    LKR <?php echo number_format($parcel['delivery_cost']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                            <strong>Booked:</strong> <?php echo date('M j, Y \a\t g:i A', strtotime($parcel['created_at'])); ?>
                            | <strong>Distance:</strong> <?php echo $parcel['distance_km']; ?> km
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Delivered Parcels -->
        <div id="delivered-content" class="tab_content">
            <h3 class="mb_1">Delivered Parcels</h3>
            <?php if (empty($delivered_parcels)): ?>
                <div class="alert alert_info">
                    <p>No delivered parcels found. Your completed deliveries will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($delivered_parcels as $parcel): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    📦 <?php echo htmlspecialchars($parcel['tracking_number']); ?>
                                    <span class="badge badge_active" style="margin-left: 1rem;">Delivered</span>
                                </h4>
                                
                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($parcel['route_name']); ?><br>
                                    <strong>Delivered:</strong> <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel['receiver_name']); ?><br>
                                    <strong>Weight:</strong> <?php echo $parcel['weight_kg']; ?> kg
                                </div>
                            </div>
                            
                            <div class="trip-actions">
                                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>" class="btn btn_primary" style="font-size: 0.9rem;">
                                    📱 View Details
                                </a>
                                <div style="margin-top: 0.5rem;">
                                    <button class="btn btn_success" onclick="alert('Feedback feature coming soon!')" style="font-size: 0.9rem;">
                                        ⭐ Rate Service
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="total-summary">
                            <div>Delivery Cost:</div>
                            <div>LKR <?php echo number_format($parcel['delivery_cost']); ?></div>
                        </div>
                        
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                            <strong>✅ Delivery Completed</strong> | Distance: <?php echo $parcel['distance_km']; ?> km
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Cancelled Parcels -->
        <div id="cancelled-content" class="tab_content">
            <h3 class="mb_1">Cancelled Deliveries</h3>
            <?php if (empty($cancelled_parcels)): ?>
                <div class="alert alert_info">
                    <p>No cancelled parcels found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cancelled_parcels as $parcel): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    📦 <?php echo htmlspecialchars($parcel['tracking_number']); ?>
                                    <span class="badge badge_inactive" style="margin-left: 1rem;">Cancelled</span>
                                </h4>
                                
                                <div style="color: #666;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($parcel['route_name']); ?><br>
                                    <strong>Was scheduled for:</strong> <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>Refund Status:</strong> Processing
                                </div>
                            </div>
                        </div>
                        
                        <div class="total-summary">
                            <div>Refund Amount:</div>
                            <div>LKR <?php echo number_format($parcel['delivery_cost']); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid mt_2">
            <div class="feature_card">
                <h4>📦 Send New Parcel</h4>
                <p>Send parcels along with our bus routes for affordable, reliable delivery across Sri Lanka.</p>
                <a href="send_parcel.php" class="btn btn_primary">Send Parcel</a>
            </div>
            <div class="feature_card">
                <h4>📱 Track Parcel</h4>
                <p>Track any parcel using the tracking number and get real-time delivery updates.</p>
                <button class="btn btn_success" onclick="showTrackingModal()">Track Parcel</button>
            </div>
            <div class="feature_card">
                <h4>📞 Need Help?</h4>
                <p>Contact our support team for assistance with your parcel deliveries or account.</p>
                <button class="btn btn_success" onclick="alert('Support: +94 11 123 4567 | Email: parcels@roadrunner.lk')">Get Support</button>
            </div>
        </div>

        <!-- Parcel Service Information -->
        <div class="alert alert_info mt_2">
            <h4>📦 About Road Runner Parcel Service</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>🚌 Bus Route Delivery:</strong><br>
                    Your parcels travel safely along with our passenger buses, ensuring reliable delivery.
                </div>
                <div>
                    <strong>💰 Affordable Rates:</strong><br>
                    Transparent pricing: Base rate + weight charge + distance charge. No hidden fees.
                </div>
                <div>
                    <strong>📱 Real-time Tracking:</strong><br>
                    Track your parcel journey with SMS updates and online tracking system.
                </div>
                <div>
                    <strong>🛡️ Insurance Included:</strong><br>
                    Up to LKR 10,000 coverage included with every parcel delivery.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your parcels, delivered with care!</p>
        </div>
    </footer>

    <!-- Quick Track Modal (Simple) -->
    <div id="trackingModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 1rem;">📱 Track Parcel</h3>
            <form onsubmit="trackParcel(event)">
                <div class="form_group">
                    <label for="tracking_input">Enter Tracking Number:</label>
                    <input type="text" id="tracking_input" class="form_control" placeholder="e.g., PRR250123001" required>
                </div>
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn_primary" style="flex: 1;">Track</button>
                    <button type="button" class="btn" onclick="hideTrackingModal()" style="flex: 1;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

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

        function showTrackingModal() {
            document.getElementById('trackingModal').style.display = 'block';
            document.getElementById('tracking_input').focus();
        }

        function hideTrackingModal() {
            document.getElementById('trackingModal').style.display = 'none';
            document.getElementById('tracking_input').value = '';
        }

        function trackParcel(event) {
            event.preventDefault();
            const trackingNumber = document.getElementById('tracking_input').value.trim();
            if (trackingNumber) {
                window.location.href = 'track_parcel.php?tracking=' + encodeURIComponent(trackingNumber);
            }
        }

        // Close modal when clicking outside
        document.getElementById('trackingModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideTrackingModal();
            }
        });

        // Escape key to close modal
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                hideTrackingModal();
            }
        });
    </script>
</body>
</html>