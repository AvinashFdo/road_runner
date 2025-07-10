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

// Generate simple tracking receipt (same as parcel_confirmation.php)
function generateTrackingReceipt($parcel_info) {
    $content = "ROAD RUNNER - PARCEL DELIVERY RECEIPT\n\n";
    $content .= "=====================================\n";
    $content .= "TRACKING INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Tracking Number: " . $parcel_info['tracking_number'] . "\n";
    $content .= "Booking Date: " . date('F j, Y \a\t g:i A', strtotime($parcel_info['created_at'])) . "\n";
    $content .= "Status: " . ucfirst($parcel_info['status']) . "\n";
    $content .= "Delivery Date: " . date('F j, Y', strtotime($parcel_info['travel_date'])) . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "ROUTE INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Route: " . $parcel_info['route_name'] . "\n";
    $content .= "From: " . $parcel_info['origin'] . "\n";
    $content .= "To: " . $parcel_info['destination'] . "\n";
    $content .= "Distance: " . $parcel_info['distance_km'] . " km\n";
    if ($parcel_info['estimated_duration']) {
        $content .= "Estimated Duration: " . $parcel_info['estimated_duration'] . "\n";
    }
    
    $content .= "\n=====================================\n";
    $content .= "SENDER DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Name: " . $parcel_info['sender_name'] . "\n";
    $content .= "Phone: " . $parcel_info['sender_phone'] . "\n";
    $content .= "Email: " . $parcel_info['sender_email'] . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "RECEIVER DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Name: " . $parcel_info['receiver_name'] . "\n";
    $content .= "Phone: " . $parcel_info['receiver_phone'] . "\n";
    $content .= "Address: " . $parcel_info['receiver_address'] . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "PARCEL DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Type: " . ($parcel_info['parcel_type'] ?: 'General') . "\n";
    $content .= "Weight: " . $parcel_info['weight_kg'] . " kg\n";
    $content .= "Delivery Cost: LKR " . number_format($parcel_info['delivery_cost'], 2) . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "TRACKING HISTORY\n";
    $content .= "=====================================\n";
    
    // Add current status info
    $status_text = ucfirst($parcel_info['status']);
    switch($parcel_info['status']) {
        case 'pending':
            $content .= "Current Status: Parcel ready for dispatch\n";
            break;
        case 'in_transit':
            $content .= "Current Status: Parcel is traveling to destination\n";
            break;
        case 'delivered':
            $content .= "Current Status: Parcel has been delivered\n";
            break;
        case 'cancelled':
            $content .= "Current Status: Parcel delivery was cancelled\n";
            break;
        default:
            $content .= "Current Status: " . $status_text . "\n";
    }
    
    $content .= "Last Updated: " . date('F j, Y \a\t g:i A') . "\n";
    
    $content .= "\n=====================================\n";
    $content .= "IMPORTANT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "• Track your parcel online using tracking number\n";
    $content .= "• Receiver will be notified upon arrival\n";
    $content .= "• Pickup from destination bus station within 24 hours\n";
    $content .= "• Contact us for any queries or support\n";
    $content .= "• Insurance coverage up to LKR 10,000 included\n";
    
    $content .= "\n=====================================\n";
    $content .= "CONTACT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Road Runner Customer Support\n";
    $content .= "Phone: +94 11 123 4567\n";
    $content .= "Email: parcels@roadrunner.lk\n";
    $content .= "Website: www.roadrunner.lk\n";
    $content .= "Tracking: www.roadrunner.lk/track\n";
    
    $content .= "\n=====================================\n";
    $content .= "Thank you for choosing Road Runner!\n";
    $content .= "Your parcel is in safe hands!\n";
    $content .= "=====================================\n";
    
    return $content;
}

// Handle receipt download
if (isset($_GET['download']) && $_GET['download'] === 'receipt' && $parcel_info) {
    $filename = 'RoadRunner_Tracking_' . $tracking_number . '_' . date('Ymd_His') . '.txt';
    $content = generateTrackingReceipt($parcel_info);
    
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit();
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
    if ($current_time >= $dispatch_time || $parcel['status'] === 'in_transit' || $parcel['status'] === 'delivered') {
        $history[] = [
            'status' => 'Dispatched',
            'description' => 'Parcel loaded onto bus from ' . $parcel['origin'],
            'timestamp' => $current_time >= $dispatch_time ? date('M j, Y g:i A', $dispatch_time) : 'Scheduled',
            'completed' => $current_time >= $dispatch_time || $parcel['status'] === 'in_transit' || $parcel['status'] === 'delivered',
            'icon' => '🚛'
        ];
    }
    
    // Add in transit step
    if ($current_time >= $travel_date || $parcel['status'] === 'in_transit' || $parcel['status'] === 'delivered') {
        $history[] = [
            'status' => 'In Transit',
            'description' => 'Parcel is traveling to ' . $parcel['destination'],
            'timestamp' => $current_time >= $travel_date ? date('M j, Y g:i A', $travel_date) : 'Scheduled',
            'completed' => $current_time >= $travel_date || $parcel['status'] === 'in_transit' || $parcel['status'] === 'delivered',
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
        
        // Skip receiver notification step - not needed in timeline
    }
    
    // Add delivery step if delivered
    if ($parcel['status'] === 'delivered') {
        $history[] = [
            'status' => 'Delivered',
            'description' => 'Parcel successfully delivered and picked up',
            'timestamp' => date('M j, Y', strtotime($parcel['travel_date'])), // Just show date
            'completed' => true,
            'icon' => '✅'
        ];
    }
    
    return $history;
}

// Get estimated delivery status
function getEstimatedDelivery($parcel) {
    $travel_date = strtotime($parcel['travel_date']);
    $current_time = time();
    $status = trim($parcel['status'] ?? '');
    
    // Handle empty status as cancelled for demo purposes
    if (empty($status)) {
        $status = 'cancelled';
    }
    
    switch ($status) {
        case 'delivered':
            return [
                'status' => 'delivered',
                'message' => 'Your parcel has been successfully delivered and picked up.'
            ];
        case 'cancelled':
        case 'refunded':
            return [
                'status' => 'cancelled',
                'message' => 'This parcel delivery has been cancelled.'
            ];
        case 'in_transit':
            $arrival_time = $travel_date + (4 * 3600);
            return [
                'status' => 'transit',
                'message' => 'Your parcel is currently in transit. Estimated arrival: ' . date('M j, Y \a\t g:i A', $arrival_time)
            ];
        case 'pending':
            if ($current_time < $travel_date) {
                $days_until = ceil(($travel_date - $current_time) / 86400);
                return [
                    'status' => 'scheduled',
                    'message' => 'Your parcel is scheduled for delivery in ' . $days_until . ' day' . ($days_until != 1 ? 's' : '') . ' on ' . date('F j, Y', $travel_date) . '.'
                ];
            } else {
                return [
                    'status' => 'ready',
                    'message' => 'Your parcel is ready for pickup at ' . $parcel['destination'] . ' bus station.'
                ];
            }
        default:
            return [
                'status' => 'info',
                'message' => 'Parcel status: ' . ucfirst($status)
            ];
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
                        <li><a href="my_parcels.php">My Parcels</a></li>
                        <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                            <li><a href="passenger/dashboard.php">Dashboard</a></li>
                        <?php endif; ?>
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
                                <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 1.1rem; cursor: pointer;" 
                                      onclick="copyToClipboard('<?php echo htmlspecialchars($parcel_info['tracking_number']); ?>')" 
                                      title="Click to copy">
                                    <?php echo htmlspecialchars($parcel_info['tracking_number']); ?>
                                </code>
                            </p>
                            <p><strong>Status:</strong> 
                                <span class="badge badge_<?php echo $parcel_info['status'] === 'delivered' ? 'active' : ($parcel_info['status'] === 'cancelled' ? 'inactive' : 'operator'); ?>">
                                    <?php echo ucfirst($parcel_info['status']); ?>
                                </span>
                            </p>
                            <p><strong>Booking Date:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($parcel_info['created_at'])); ?></p>
                            <p><strong>Travel Date:</strong> <?php echo date('F j, Y', strtotime($parcel_info['travel_date'])); ?></p>
                        </div>
                        
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route Details</h4>
                            <p><strong>Route:</strong> <?php echo htmlspecialchars($parcel_info['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($parcel_info['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($parcel_info['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo $parcel_info['distance_km']; ?> km</p>
                            <?php if ($parcel_info['estimated_duration']): ?>
                                <p><strong>Duration:</strong> <?php echo htmlspecialchars($parcel_info['estimated_duration']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sender and Receiver Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
                
                <!-- Sender Information -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">📤 Sender Details</h3>
                    <div class="p_2">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($parcel_info['sender_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($parcel_info['sender_phone']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($parcel_info['sender_email']); ?></p>
                    </div>
                </div>

                <!-- Receiver Information -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">📥 Receiver Details</h3>
                    <div class="p_2">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($parcel_info['receiver_name']); ?></p>
                        <p><strong>Phone:</strong> <?php echo htmlspecialchars($parcel_info['receiver_phone']); ?></p>
                        <p><strong>Address:</strong></p>
                        <div style="background: #f8f9fa; padding: 0.5rem; border-radius: 4px; margin-top: 0.5rem;">
                            <?php echo nl2br(htmlspecialchars($parcel_info['receiver_address'])); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Parcel Specifications -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📋 Parcel Specifications</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div>
                            <strong>Type:</strong><br>
                            <?php echo htmlspecialchars($parcel_info['parcel_type'] ?: 'General'); ?>
                        </div>
                        <div>
                            <strong>Weight:</strong><br>
                            <?php echo htmlspecialchars($parcel_info['weight_kg']); ?> kg
                        </div>
                        <div>
                            <strong>Delivery Cost:</strong><br>
                            LKR <?php echo number_format($parcel_info['delivery_cost'], 2); ?>
                        </div>
                        <div>
                            <strong>Insurance:</strong><br>
                            Up to LKR 10,000
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
                                    <div style="position: absolute; left: 20px; top: 50px; width: 2px; height: 2rem; background: <?php echo $event['completed'] ? '#27ae60' : '#e0e0e0'; ?>;"></div>
                                <?php endif; ?>
                                
                                <!-- Timeline marker -->
                                <div style="width: 40px; height: 40px; border-radius: 50%; background: <?php echo $event['completed'] ? '#27ae60' : '#e0e0e0'; ?>; display: flex; align-items: center; justify-content: center; margin-right: 1rem; flex-shrink: 0; color: white; font-size: 1.2rem;">
                                    <?php echo $event['icon']; ?>
                                </div>
                                
                                <!-- Timeline content -->
                                <div style="flex: 1;">
                                    <h4 style="margin-bottom: 0.5rem; color: <?php echo $event['completed'] ? '#27ae60' : '#95a5a6'; ?>;">
                                        <?php echo htmlspecialchars($event['status']); ?>
                                    </h4>
                                    <p style="margin-bottom: 0.5rem; color: #666;">
                                        <?php echo htmlspecialchars($event['description']); ?>
                                    </p>
                                    <small style="color: #888; font-style: italic;">
                                        <?php echo htmlspecialchars($event['timestamp']); ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0;">
                <a href="?tracking=<?php echo urlencode($parcel_info['tracking_number']); ?>&download=receipt" 
                   class="btn btn_primary" style="text-align: center;">
                    📄 Download Receipt
                </a>
                <button onclick="window.print()" class="btn btn_success" style="text-align: center;">
                    🖨️ Print Details
                </button>
                <a href="send_parcel.php" class="btn" style="text-align: center; background: #34495e;">
                    📦 Send Another Parcel
                </a>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <a href="my_parcels.php" class="btn" style="text-align: center; background: #7f8c8d;">
                        📋 My Parcels
                    </a>
                <?php endif; ?>
            </div>

            <!-- Support Information -->
            <div class="alert alert_info">
                <h4>📞 Need Help?</h4>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                    <div>
                        <strong>📱 Customer Support:</strong><br>
                        Call +94 11 123 4567 for immediate assistance with your parcel delivery.
                    </div>
                    <div>
                        <strong>✉️ Email Support:</strong><br>
                        Send queries to parcels@roadrunner.lk for detailed support.
                    </div>
                    <div>
                        <strong>🌐 Online Help:</strong><br>
                        Visit www.roadrunner.lk/help for FAQs and troubleshooting guides.
                    </div>
                    <div>
                        <strong>⏰ Business Hours:</strong><br>
                        Mon-Fri: 8AM-6PM, Sat: 8AM-4PM, Sun: 9AM-2PM
                    </div>
                </div>
            </div>

        <?php endif; ?>

        <!-- General Information (shown when no tracking) -->
        <?php if (!$parcel_info && empty($error)): ?>
            <div class="features_grid">
                <div class="feature_card">
                    <h4>📦 Track Any Parcel</h4>
                    <p>Enter any valid Road Runner tracking number to see real-time delivery status and timeline.</p>
                </div>
                <div class="feature_card">
                    <h4>📱 Real-time Updates</h4>
                    <p>Get live updates on your parcel's journey from pickup to final delivery.</p>
                </div>
                <div class="feature_card">
                    <h4>📄 Download Receipts</h4>
                    <p>Access and download detailed delivery receipts for your records and proof of service.</p>
                </div>
            </div>

            <div class="alert alert_info mt_2">
                <h4>📋 How to Track Your Parcel</h4>
                <div style="margin-top: 1rem;">
                    <p><strong>1. Find Your Tracking Number:</strong> Look for the tracking number in your booking confirmation or receipt</p>
                    <p><strong>2. Enter Tracking Number:</strong> Type or paste the tracking number in the field above</p>
                    <p><strong>3. View Details:</strong> See real-time status, timeline, and delivery information</p>
                    <p><strong>4. Download Receipt:</strong> Get a detailed receipt for your records</p>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Track with confidence!</p>
        </div>
    </footer>

    <script>
        // Copy tracking number to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Tracking number copied to clipboard!');
            }).catch(function(err) {
                console.error('Could not copy text: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Tracking number copied to clipboard!');
            });
        }

        // Auto-focus tracking number input if no parcel info is displayed
        document.addEventListener('DOMContentLoaded', function() {
            const trackingInput = document.getElementById('tracking_number');
            const hasParcelInfo = <?php echo $parcel_info ? 'true' : 'false'; ?>;
            
            if (!hasParcelInfo && trackingInput) {
                trackingInput.focus();
            }
        });

        // Copy tracking number to clipboard
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function() {
                alert('Tracking number copied to clipboard!');
            }).catch(function(err) {
                console.error('Could not copy text: ', err);
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                alert('Tracking number copied to clipboard!');
            });
        }

        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const trackingNumber = document.getElementById('tracking_number').value.trim();
            if (!trackingNumber) {
                e.preventDefault();
                alert('Please enter a tracking number to track your parcel.');
                return false;
            }
            
            // Basic tracking number format validation (optional)
            if (trackingNumber.length < 8) {
                e.preventDefault();
                alert('Please enter a valid tracking number. Tracking numbers are usually 8 or more characters long.');
                return false;
            }
        });

        // Print functionality enhancement
        window.addEventListener('beforeprint', function() {
            document.body.classList.add('printing');
        });

        window.addEventListener('afterprint', function() {
            document.body.classList.remove('printing');
        });
    </script>

    <style>
        /* Print styles */
        @media print {
            .header, .footer, .btn, button, .alert:not(.alert_success):not(.alert_info) {
                display: none !important;
            }
            
            .container {
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .table_container {
                border: 1px solid #333 !important;
                margin-bottom: 1rem !important;
                page-break-inside: avoid;
            }
            
            .timeline-item {
                page-break-inside: avoid;
            }
            
            body {
                font-size: 12pt !important;
                line-height: 1.4 !important;
            }
            
            h2, h3, h4 {
                color: #000 !important;
            }
        }
    </style>
</body>
</html>