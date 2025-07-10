<?php
// Parcel Delivery Confirmation
// Save this as: parcel_confirmation.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get tracking number from URL
$tracking_number = $_GET['tracking'] ?? '';
if (empty($tracking_number)) {
    header('Location: send_parcel.php');
    exit();
}

$parcel_info = null;
$error = '';

try {
    // Get parcel information
    $stmt = $pdo->prepare("
        SELECT 
            p.parcel_id, p.tracking_number, p.sender_id, p.sender_name, p.sender_phone,
            p.receiver_name, p.receiver_phone, p.receiver_address, p.route_id,
            p.weight_kg, p.parcel_type, p.delivery_cost, p.travel_date, p.status,
            p.payment_status, p.created_at, p.updated_at,
            r.route_name, r.origin, r.destination, r.distance_km, r.estimated_duration,
            u.full_name as sender_full_name, u.email as sender_email
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        JOIN users u ON p.sender_id = u.user_id
        WHERE p.tracking_number = ? AND p.sender_id = ?
    ");
    $stmt->execute([$tracking_number, $_SESSION['user_id']]);
    $parcel_info = $stmt->fetch();

    if (!$parcel_info) {
        header('Location: send_parcel.php');
        exit();
    }

} catch (PDOException $e) {
    $error = "Error retrieving parcel information: " . $e->getMessage();
}

// Generate simple tracking receipt
function generateTrackingReceipt($parcel_info)
{
    $content = "ROAD RUNNER - PARCEL DELIVERY RECEIPT\n\n";
    $content .= "=====================================\n";
    $content .= "TRACKING INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Tracking Number: " . $parcel_info['tracking_number'] . "\n";
    $content .= "Booking Date: " . date('F j, Y \a\t g:i A', strtotime($parcel_info['created_at'])) . "\n";
    $content .= "Status: " . ucfirst($parcel_info['status']) . "\n";
    $content .= "Payment Status: " . ucfirst($parcel_info['payment_status'] ?? 'pending') . "\n";
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
    $content .= "Type: " . $parcel_info['parcel_type'] . "\n";
    $content .= "Weight: " . $parcel_info['weight_kg'] . " kg\n";
    $content .= "Delivery Cost: LKR " . number_format($parcel_info['delivery_cost'], 2) . "\n";

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
if (isset($_GET['download']) && $_GET['download'] === 'receipt') {
    $filename = 'RoadRunner_Parcel_' . $tracking_number . '_' . date('Ymd_His') . '.txt';
    $content = generateTrackingReceipt($parcel_info);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Booked - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">
     <img src="images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="send_parcel.php">Send Parcel</a></li>
                    <?php if ($_SESSION['user_type'] === 'passenger'): ?>
                        <li><a href="passenger/dashboard.php">My Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="my_parcels.php">My Parcels</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <?php if (isset($error) && !empty($error)): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php else: ?>

            <!-- Success Message -->
            <div class="alert alert_success mb_2">
                <h2 style="margin-bottom: 1rem;">📦 Parcel Booked Successfully!</h2>
                <p style="font-size: 1.1rem;">Your parcel has been successfully booked for delivery. Please save your
                    tracking number for future reference.</p>
            </div>

            <!-- Tracking Information -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📱 Tracking Information</h3>
                <div class="p_2">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <div
                            style="background: #2c3e50; color: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                            <h3 style="margin-bottom: 0.5rem;">Tracking Number</h3>
                            <div style="font-size: 2rem; font-weight: bold; letter-spacing: 2px; font-family: monospace;">
                                <?php echo htmlspecialchars($parcel_info['tracking_number']); ?>
                            </div>
                            <p style="margin-top: 0.5rem; font-size: 0.9rem; opacity: 0.8;">
                                Save this number to track your parcel
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>📅 Booking Date:</strong><br>
                                <?php echo date('F j, Y \a\t g:i A', strtotime($parcel_info['created_at'])); ?>
                            </div>
                            <div>
                                <strong>🚛 Delivery Date:</strong><br>
                                <?php echo date('F j, Y', strtotime($parcel_info['travel_date'])); ?>
                            </div>
                            <div>
                                <strong>📊 Status:</strong><br>
                                <span class="badge badge_operator" style="font-size: 0.9rem;">
                                    <?php echo ucfirst($parcel_info['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Route Information -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">🗺️ Route Information</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route Details</h4>
                            <p><strong>Route Name:</strong> <?php echo htmlspecialchars($parcel_info['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($parcel_info['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($parcel_info['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo htmlspecialchars($parcel_info['distance_km']); ?> km
                            </p>
                            <?php if ($parcel_info['estimated_duration']): ?>
                                <p><strong>Duration:</strong>
                                    <?php echo htmlspecialchars($parcel_info['estimated_duration']); ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Delivery Process</h4>
                            <div style="margin-bottom: 1rem;">
                                <strong>1. Pickup</strong><br>
                                <small>Bring parcel to <?php echo htmlspecialchars($parcel_info['origin']); ?> bus
                                    station</small>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <strong>2. Transit</strong><br>
                                <small>Travels with passenger bus to destination</small>
                            </div>
                            <div style="margin-bottom: 1rem;">
                                <strong>3. Arrival</strong><br>
                                <small>Arrives at <?php echo htmlspecialchars($parcel_info['destination']); ?> bus
                                    station</small>
                            </div>
                            <div>
                                <strong>4. Notification</strong><br>
                                <small>Receiver notified for pickup</small>
                            </div>
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
                        <p><strong>Account:</strong>
                            <span class="badge badge_passenger">Verified User</span>
                        </p>
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

            <!-- Parcel Details -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📦 Parcel Details</h3>
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
                            <p><strong>Payment Status:</strong>
                                <?php
                                // Check if we came from payment success
                                $is_payment_success = isset($_GET['payment']) && $_GET['payment'] === 'success';

                                // Get payment status from database or default based on payment flow
                                if (isset($parcel_info['payment_status'])) {
                                    $payment_status = $parcel_info['payment_status'];
                                } else {
                                    // Fallback: if payment=success in URL, assume paid, otherwise pending
                                    $payment_status = $is_payment_success ? 'paid' : 'pending';
                                }

                                $badge_class = ($payment_status === 'paid') ? 'badge_active' : 'badge_operator';
                                $status_text = ucfirst($payment_status);
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>

                                <?php if ($payment_status === 'pending'): ?>
                                    <br><small style="color: #e74c3c;">Payment required when dropping off parcel</small>
                                <?php else: ?>
                                    <br><small style="color: #27ae60;">Payment completed online</small>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div style="text-align: center; margin: 2rem 0;">
                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel_info['tracking_number']); ?>"
                    class="btn btn_primary" style="margin-right: 1rem;">
                    📱 Track Parcel
                </a>
                <a href="?tracking=<?php echo urlencode($parcel_info['tracking_number']); ?>&download=receipt"
                    class="btn btn_success" style="margin-right: 1rem;">
                    📄 Download Receipt
                </a>
                <a href="my_parcels.php" class="btn btn_secondary">
                    📦 My Parcels
                </a>
            </div>


        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Safe and reliable parcel delivery!</p>
        </div>
    </footer>

    <script>
        // Auto-focus on tracking number for easy copying
        document.addEventListener('DOMContentLoaded', function () {
            // Add click-to-copy functionality for tracking number
            const trackingNumber = document.querySelector('div[style*="font-family: monospace"]');
            if (trackingNumber) {
                trackingNumber.style.cursor = 'pointer';
                trackingNumber.title = 'Click to copy tracking number';
                trackingNumber.addEventListener('click', function () {
                    navigator.clipboard.writeText(this.textContent.trim()).then(function () {
                        alert('Tracking number copied to clipboard!');
                    });
                });
            }
        });
    </script>
</body>

</html>