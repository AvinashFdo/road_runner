<?php
// Parcel Tracking System
// Save this as: track_parcel.php

session_start();
require_once 'db_connection.php';

$tracking_number = '';
$parcel_info = null;
$error = '';
$tracking_history = [];

// Handle tracking form submission or URL parameter
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_parcel'])) {
    $tracking_number = trim($_POST['tracking_number'] ?? '');
} elseif (isset($_GET['tracking'])) {
    $tracking_number = trim($_GET['tracking']);
}

if (!empty($tracking_number)) {
    try {
        // Get parcel information
        $stmt = $pdo->prepare("
            SELECT 
                p.*,
                r.route_name, r.origin, r.destination, r.distance_km, r.estimated_duration,
                u.full_name as sender_full_name, u.email as sender_email
            FROM parcels p
            JOIN routes r ON p.route_id = r.route_id
            JOIN users u ON p.sender_id = u.user_id
            WHERE p.tracking_number = ?
        ");
        $stmt->execute([$tracking_number]);
        $parcel_info = $stmt->fetch();
        
        if (!$parcel_info) {
            $error = "No parcel found with tracking number: " . htmlspecialchars($tracking_number);
        } else {
            // Generate tracking history based on status
            $tracking_history = generateTrackingHistory($parcel_info);
        }
        
    } catch (PDOException $e) {
        $error = "Error retrieving parcel information: " . $e->getMessage();
    }
}

// Generate tracking history timeline
function generateTrackingHistory($parcel) {
    $history = [];
    $created_time = strtotime($parcel['created_at']);
    $travel_date = strtotime($parcel['travel_date']);
    $current_time = time();
    
    // Always add booking confirmation
    $history[] = [
        'status' => 'Parcel Booked',
        'description' => 'Parcel booking confirmed and assigned tracking number',
        'timestamp' => date('M j, Y g:i A', $created_time),
        'completed' => true,
        'icon' => '📦'
    ];
    
    if ($parcel['status'] === 'cancelled') {
        $history[] = [
            'status' => 'Parcel Cancelled',
            'description' => 'Parcel delivery has been cancelled by sender',
            'timestamp' => 'Cancelled',
            'completed' => true,
            'icon' => '❌'
        ];
        return $history;
    }
    
    // Add processing step if not yet travel date
    if ($current_time < $travel_date) {
        $history[] = [
            'status' => 'Processing',
            'description' => 'Parcel is being prepared for dispatch',
            'timestamp' => $parcel['status'] === 'pending' ? 'In Progress' : 'Completed',
            'completed' => $parcel['status'] !== 'pending',
            'icon' => '⚙️'
        ];
    }
    
    // Add dispatch step
    $dispatch_time = $travel_date - (2 * 3600); // 2 hours before travel
    if ($current_time >= $dispatch_time || $parcel['status'] === 'in_transit') {
        $history[] = [
            'status' => 'Dispatched',
            'description' => 'Parcel loaded onto bus from ' . $parcel['origin'],
            'timestamp' => $current_time >= $dispatch_time ? date('M j, Y g:i A', $dispatch_time) : 'Scheduled',
            'completed' => $current_time >= $dispatch_time || $parcel['status'] === 'in_transit',
            'icon' => '🚛'
        ];
    }
    
    // Add in transit step
    if ($current_time >= $travel_date || $parcel['status'] === 'in_transit') {
        $history[] = [
            'status' => 'In Transit',
            'description' => 'Parcel is traveling to ' . $parcel['destination'],
            'timestamp' => $current_time >= $travel_date ? date('M j, Y g:i A', $travel_date) : 'Scheduled',
            'completed' => $current_time >= $travel_date || $parcel['status'] === 'in_transit',
            'icon' => '🚌'
        ];
    }
    
    // Add arrival step
    $arrival_time = $travel_date + (4 * 3600); // Estimated 4 hours after departure
    if ($current_time >= $arrival_time || $parcel['status'] === 'delivered') {
        $history[] = [
            'status' => 'Arrived',
            'description' => 'Parcel arrived at ' . $parcel['destination'] . ' bus station',
            'timestamp' => $current_time >= $arrival_time ? date('M j, Y g:i A', $arrival_time) : 'Estimated',
            'completed' => $current_time >= $arrival_time || $parcel['status'] === 'delivered',
            'icon' => '📍'
        ];
        
        // Add notification step
        if ($current_time >= $arrival_time || $parcel['status'] === 'delivered') {
            $notification_time = $arrival_time + (1800); // 30 minutes after arrival
            $history[] = [
                'status' => 'Receiver Notified',
                'description' => 'Pickup notification sent to ' . $parcel['receiver_name'],
                'timestamp' => $current_time >= $notification_time ? date('M j, Y g:i A', $notification_time) : 'Pending',
                'completed' => $current_time >= $notification_time || $parcel['status'] === 'delivered',
                'icon' => '📱'
            ];
        }
    }
    
    // Add delivery step if delivered
    if ($parcel['status'] === 'delivered') {
        $delivery_time = $arrival_time + (6 * 3600); // Assume delivered within 6 hours of arrival
        $history[] = [
            'status' => 'Delivered',
            'description' => 'Parcel successfully delivered to receiver',
            'timestamp' => date('M j, Y g:i A', $delivery_time),
            'completed' => true,
            'icon' => '✅'
        ];
    }
    
    return $history;
}

// Calculate estimated delivery time
function getEstimatedDelivery($parcel) {
    $travel_date = strtotime($parcel['travel_date']);
    $estimated_arrival = $travel_date + (4 * 3600); // 4 hours after departure
    
    if ($parcel['status'] === 'delivered') {
        return ['status' => 'delivered', 'message' => 'Parcel has been delivered'];
    } elseif ($parcel['status'] === 'cancelled') {
        return ['status' => 'cancelled', 'message' => 'Parcel delivery was cancelled'];
    } elseif (time() >= $estimated_arrival) {
        return ['status' => 'ready', 'message' => 'Parcel is ready for pickup at destination'];
    } elseif (time() >= $travel_date) {
        return ['status' => 'transit', 'message' => 'Parcel is in transit to destination'];
    } else {
        return ['status' => 'scheduled', 'message' => 'Parcel is scheduled for delivery on ' . date('M j, Y', $travel_date)];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Parcel - Road Runner</title>
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
                    <li><a href="send_parcel.php">Send Parcel</a></li>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                            <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                        <?php endif; ?>
                        <li><a href="my_parcels.php">My Parcels</a></li>
                        <li><a href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li><a href="login.php">Login</a></li>
                        <li><a href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">📱 Track Your Parcel</h2>

        <!-- Tracking Form -->
        <div class="form_container mb_2">
            <form method="POST" action="track_parcel.php">
                <div style="display: grid; grid-template-columns: 1fr auto; gap: 1rem; align-items: end;">
                    <div class="form_group">
                        <label for="tracking_number">Tracking Number:</label>
                        <input 
                            type="text" 
                            id="tracking_number" 
                            name="tracking_number" 
                            class="form_control" 
                            value="<?php echo htmlspecialchars($tracking_number); ?>"
                            placeholder="Enter tracking number (e.g., PRR250123001)"
                            required
                        >
                    </div>
                    <button type="submit" name="track_parcel" class="btn btn_primary" style="height: 44px;">
                        🔍 Track
                    </button>
                </div>
            </form>
        </div>

        <!-- Display Error -->
        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
                <p style="margin-top: 1rem;">
                    <strong>💡 Tips:</strong><br>
                    • Make sure you entered the tracking number correctly<br>
                    • Tracking numbers are case-sensitive<br>
                    • Contact support if you continue having issues
                </p>
            </div>
        <?php endif; ?>

        <!-- Parcel Information -->
        <?php if ($parcel_info): ?>
            
            <!-- Current Status Banner -->
            <?php $delivery_status = getEstimatedDelivery($parcel_info); ?>
            <div class="alert alert_<?php echo $delivery_status['status'] === 'delivered' ? 'success' : ($delivery_status['status'] === 'cancelled' ? 'error' : 'info'); ?> mb_2">
                <h3 style="margin-bottom: 0.5rem;">
                    <?php 
                    switch($delivery_status['status']) {
                        case 'delivered': echo '✅ Delivered'; break;
                        case 'cancelled': echo '❌ Cancelled'; break;
                        case 'ready': echo '📍 Ready for Pickup'; break;
                        case 'transit': echo '🚌 In Transit'; break;
                        default: echo '📦 Scheduled'; break;
                    }
                    ?>
                </h3>
                <p style="font-size: 1.1rem; margin: 0;"><?php echo $delivery_status['message']; ?></p>
            </div>

            <!-- Parcel Details -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📦 Parcel Details</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Tracking Information</h4>
                            <p><strong>Tracking Number:</strong> 
                                <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 1.1rem;">
                                    <?php echo htmlspecialchars($parcel_info['tracking_number']); ?>
                                </code>
                            </p>
                            <p><strong>Status:</strong> 
                                <span class="badge badge_<?php echo $parcel_info['status'] === 'delivered' ? 'active' : ($parcel_info['status'] === 'cancelled' ? 'inactive' : 'operator'); ?>">
                                    <?php echo ucfirst($parcel_info['status']); ?>
                                </span>
                            </p>
                            <p><strong>Booking Date:</strong> <?php echo date('M j, Y g:i A', strtotime($parcel_info['created_at'])); ?></p>
                            <p><strong>Delivery Date:</strong> <?php echo date('M j, Y', strtotime($parcel_info['travel_date'])); ?></p>
                        </div>
                        
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route Information</h4>
                            <p><strong>Route:</strong> <?php echo htmlspecialchars($parcel_info['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($parcel_info['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($parcel_info['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo $parcel_info['distance_km']; ?> km</p>
                            <?php if ($parcel_info['estimated_duration']): ?>
                                <p><strong>Duration:</strong> <?php echo htmlspecialchars($parcel_info['estimated_duration']); ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Parcel Information</h4>
                            <p><strong>Type:</strong> <?php echo htmlspecialchars($parcel_info['parcel_type']); ?></p>
                            <p><strong>Weight:</strong> <?php echo $parcel_info['weight_kg']; ?> kg</p>
                            <p><strong>Delivery Cost:</strong> LKR <?php echo number_format($parcel_info['delivery_cost']); ?></p>
                            <p><strong>Insurance:</strong> Up to LKR 10,000</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tracking Timeline -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📍 Tracking Timeline</h3>
                <div class="p_2">
                    <div style="position: relative;">
                        <?php foreach ($tracking_history as $index => $event): ?>
                            <div style="display: flex; align-items: flex-start; margin-bottom: 2rem; position: relative;">
                                <!-- Timeline line -->
                                <?php if ($index < count($tracking_history) - 1): ?>
                                    <div style="position: absolute; left: 20px; top: 50px; width: 2px; height: 2rem; background: <?php echo $event['completed'] ? '#27ae60' : '#ddd'; ?>;"></div>
                                <?php endif; ?>
                                
                                <!-- Icon -->
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo $event['completed'] ? '#27ae60' : '#ddd'; ?>; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2rem; margin-right: 1rem; flex-shrink: 0;">
                                    <?php echo $event['completed'] ? '✓' : $event['icon']; ?>
                                </div>
                                
                                <!-- Content -->
                                <div style="flex: 1;">
                                    <h4 style="margin-bottom: 0.5rem; color: <?php echo $event['completed'] ? '#27ae60' : '#666'; ?>;">
                                        <?php echo $event['status']; ?>
                                    </h4>
                                    <p style="margin-bottom: 0.5rem; color: #666;">
                                        <?php echo $event['description']; ?>
                                    </p>
                                    <small style="color: #999;">
                                        <?php echo $event['timestamp']; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Contact Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
                
                <!-- Sender Information -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">📤 Sender Details</h3>
                    <div class="p_2">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($parcel_info['sender_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($parcel_info['sender_phone']); ?></p>
                    </div>
                </div>

                <!-- Receiver Information -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">📥 Receiver Details</h3>
                    <div class="p_2">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($parcel_info['receiver_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($parcel_info['receiver_phone']); ?></p>
                        <p><strong>Address:</strong></p>
                        <div style="background: #f8f9fa; padding: 0.5rem; border-radius: 4px; margin-top: 0.5rem; font-size: 0.9rem;">
                            <?php echo nl2br(htmlspecialchars($parcel_info['receiver_address'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0;">
                <button onclick="window.print()" class="btn btn_primary" style="text-align: center;">
                    🖨️ Print Details
                </button>
                <button onclick="shareTracking()" class="btn btn_success" style="text-align: center;">
                    📤 Share Tracking
                </button>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="my_parcels.php" class="btn" style="text-align: center; background: #34495e;">
                        📋 My Parcels
                    </a>
                <?php endif; ?>
                <a href="send_parcel.php" class="btn" style="text-align: center; background: #7f8c8d;">
                    📦 Send New Parcel
                </a>
            </div>

        <?php endif; ?>

        <!-- Help Section -->
        <div class="alert alert_info mt_2">
            <h4>💡 Tracking Help</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>📱 Real-time Updates:</strong><br>
                    Track your parcel status 24/7 with automatic SMS notifications to sender and receiver.
                </div>
                <div>
                    <strong>📍 Pickup Instructions:</strong><br>
                    Receiver will be notified when parcel arrives. Pickup from destination bus station within 24 hours.
                </div>
                <div>
                    <strong>📞 Customer Support:</strong><br>
                    Need help? Call +94 11 123 4567 or email parcels@roadrunner.lk for assistance.
                </div>
                <div>
                    <strong>🔍 Track Anytime:</strong><br>
                    No login required. Track any parcel using just the tracking number from anywhere.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Track with confidence!</p>
        </div>
    </footer>

    <script>
        function shareTracking() {
            const trackingNumber = '<?php echo htmlspecialchars($tracking_number); ?>';
            const url = window.location.origin + window.location.pathname + '?tracking=' + encodeURIComponent(trackingNumber);
            
            if (navigator.share) {
                navigator.share({
                    title: 'Road Runner Parcel Tracking',
                    text: 'Track parcel: ' + trackingNumber,
                    url: url
                }).catch(console.error);
            } else {
                // Fallback for browsers without Web Share API
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(url).then(function() {
                        alert('Tracking link copied to clipboard!');
                    });
                } else {
                    prompt('Copy this tracking link:', url);
                }
            }
        }
        
        // Auto-focus tracking input when page loads
        document.addEventListener('DOMContentLoaded', function() {
            const trackingInput = document.getElementById('tracking_number');
            if (trackingInput && !trackingInput.value) {
                trackingInput.focus();
            }
        });
        
        // Format tracking number input (optional enhancement)
        document.getElementById('tracking_number').addEventListener('input', function(e) {
            // Remove spaces and convert to uppercase for consistency
            this.value = this.value.replace(/\s/g, '').toUpperCase();
        });
    </script>
</body>
</html>