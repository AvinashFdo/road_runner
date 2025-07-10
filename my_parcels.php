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

    // Debug: Add temporary status check
    if (isset($_GET['debug'])) {
        echo "<pre>";
        echo "=== PARCEL STATUS DEBUG ===\n";
        foreach ($all_parcels as $parcel) {
            $status = $parcel['status'] ?? 'NULL';
            $status_length = strlen($status);
            echo "Tracking: " . $parcel['tracking_number'] . "\n";
            echo "  Status: '" . $status . "' (length: $status_length)\n";
            echo "  Travel Date: " . $parcel['travel_date'] . "\n";
            echo "  Will be categorized as: ";

            // Test the categorization logic
            $trimmed_status = trim($status);
            if (empty($trimmed_status)) {
                echo "ACTIVE (empty status - defaulted to pending)\n";
            } elseif ($trimmed_status === 'refunded' || $trimmed_status === 'cancelled') {
                echo "CANCELLED\n";
            } elseif ($trimmed_status === 'delivered') {
                echo "DELIVERED\n";
            } elseif ($trimmed_status === 'pending' || $trimmed_status === 'in_transit') {
                echo "ACTIVE\n";
            } else {
                echo "ACTIVE (unknown status: '$trimmed_status')\n";
            }
            echo "\n";
        }
        echo "=========================\n";
        echo "</pre>";
    }

    // Separate parcels by status and date
    $active_parcels = [];
    $delivered_parcels = [];
    $cancelled_parcels = [];

    foreach ($all_parcels as $parcel) {
        $travel_datetime = strtotime($parcel['travel_date']);
        $is_future = $travel_datetime > time();

        // Handle empty/null status
        $status = trim($parcel['status'] ?? '');
        if (empty($status)) {
            // For demo purposes, assume empty status means cancelled
            $status = 'cancelled';
        }

        // Show all cancelled and refunded parcels in cancelled section
        if (in_array($status, ['cancelled', 'refunded']) || empty(trim($parcel['status'] ?? ''))) {
            $cancelled_parcels[] = $parcel;
        } elseif ($status === 'delivered') {
            $delivered_parcels[] = $parcel;
        } elseif ($status === 'pending' || $status === 'in_transit') {
            if ($is_future || $status === 'in_transit') {
                $active_parcels[] = $parcel;
            } else {
                // Past delivery date but not marked as delivered - assume delivered
                $delivered_parcels[] = $parcel;
            }
        } else {
            // For any other unknown status, put in cancelled for safety
            $cancelled_parcels[] = $parcel;
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

// Function to get cancellation status display (simplified)
function getCancellationStatusDisplay($status)
{
    $trimmed_status = trim($status ?? '');

    if (empty($trimmed_status) || $trimmed_status === 'cancelled') {
        return [
            'text' => 'Cancelled',
            'class' => 'badge_inactive',
            'icon' => '❌',
            'description' => 'This parcel delivery was cancelled.'
        ];
    } elseif ($trimmed_status === 'refunded') {
        return [
            'text' => 'Cancelled & Refunded',
            'class' => 'badge_active',
            'icon' => '✅',
            'description' => 'This parcel was cancelled and refund has been processed.'
        ];
    } else {
        return [
            'text' => 'Cancelled',
            'class' => 'badge_inactive',
            'icon' => '❌',
            'description' => 'This parcel delivery was cancelled.'
        ];
    }
}
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
                <div class="logo">
     <img src="images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
</div>
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
        <div class="tabs mb_2">
            <button class="tab_btn active" onclick="showTab('active')" id="active-tab">
                🚚 Active Deliveries (<?php echo count($active_parcels); ?>)
            </button>
            <button class="tab_btn" onclick="showTab('delivered')" id="delivered-tab">
                ✅ Delivered (<?php echo count($delivered_parcels); ?>)
            </button>
            <button class="tab_btn" onclick="showTab('cancelled')" id="cancelled-tab">
                ❌ Cancelled (<?php echo count($cancelled_parcels); ?>)
            </button>
        </div>

        <!-- Active Parcels -->
        <div id="active-content" class="tab_content active">
            <h3 class="mb_1">Active Deliveries</h3>
            <?php if (empty($active_parcels)): ?>
                <div class="alert alert_info">
                    <p>No active deliveries found. Send a parcel to get started!</p>
                </div>
            <?php else: ?>
                <?php foreach ($active_parcels as $parcel): ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    📦 <?php echo htmlspecialchars($parcel['tracking_number']); ?>
                                    <span
                                        class="badge badge_<?php echo $parcel['status'] === 'in_transit' ? 'operator' : 'active'; ?>"
                                        style="margin-left: 1rem;">
                                        <?php echo ucfirst($parcel['status']); ?>
                                    </span>
                                </h4>

                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($parcel['route_name']); ?><br>
                                    <strong>From:</strong> <?php echo htmlspecialchars($parcel['origin']); ?>
                                    <strong>To:</strong> <?php echo htmlspecialchars($parcel['destination']); ?><br>
                                    <strong>Delivery Date:</strong>
                                    <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel['receiver_name']); ?><br>
                                    <strong>Weight:</strong> <?php echo $parcel['weight_kg']; ?> kg
                                </div>
                            </div>

                            <div class="trip-actions">
                                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>"
                                    class="btn btn_primary" style="margin-bottom: 0.5rem;">
                                    📱 Track Parcel
                                </a>

                                <?php if ($parcel['status'] === 'pending'): ?>
                                    <?php
                                    $travel_datetime = strtotime($parcel['travel_date']);
                                    $time_difference = $travel_datetime - time();
                                    $hours_until_travel = $time_difference / 3600;
                                    ?>
                                    <?php if ($hours_until_travel >= 24): ?>
                                        <form method="POST" style="display: inline;"
                                            onsubmit="return confirm('Are you sure you want to cancel this parcel? This action cannot be undone.');">
                                            <input type="hidden" name="parcel_id" value="<?php echo $parcel['parcel_id']; ?>">
                                            <button type="submit" name="cancel_parcel" class="btn"
                                                style="background: #dc3545; font-size: 0.9rem;">
                                                ❌ Cancel Parcel
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <button class="btn" style="background: #6c757d; font-size: 0.9rem;" disabled
                                            title="Cannot cancel within 24 hours of delivery">
                                            ❌ Too Late to Cancel
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="total-summary">
                            <div>Delivery Cost:</div>
                            <div>LKR <?php echo number_format($parcel['delivery_cost']); ?></div>
                        </div>

                        <div
                            style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                            <strong>📍 Status:</strong>
                            <?php if ($parcel['status'] === 'pending'): ?>
                                Scheduled for delivery | Distance: <?php echo $parcel['distance_km']; ?> km
                            <?php else: ?>
                                In transit to destination | Distance: <?php echo $parcel['distance_km']; ?> km
                            <?php endif; ?>
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
                    <p>Your completed deliveries will appear here.</p>
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
                                    <strong>Delivered:</strong>
                                    <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel['receiver_name']); ?><br>
                                    <strong>Weight:</strong> <?php echo $parcel['weight_kg']; ?> kg
                                </div>
                            </div>

                            <div class="trip-actions">
                                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>"
                                    class="btn btn_primary" style="font-size: 0.9rem;">
                                    📱 View Details
                                </a>
                                <div style="margin-top: 0.5rem;">
                                    <button class="btn btn_success" onclick="alert('Feedback feature coming soon!')"
                                        style="font-size: 0.9rem;">
                                        ⭐ Rate Service
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="total-summary">
                            <div>Delivery Cost:</div>
                            <div>LKR <?php echo number_format($parcel['delivery_cost']); ?></div>
                        </div>

                        <div
                            style="margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; font-size: 0.9rem; color: #666;">
                            <strong>✅ Delivery Completed</strong> | Distance: <?php echo $parcel['distance_km']; ?> km
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Cancelled Parcels -->
        <div id="cancelled-content" class="tab_content">
            <h3 class="mb_1">Cancelled Parcels</h3>
            <?php if (empty($cancelled_parcels)): ?>
                <div class="alert alert_info">
                    <p>No cancelled parcels found.</p>
                </div>
            <?php else: ?>
                <?php foreach ($cancelled_parcels as $parcel): ?>
                    <?php $cancellation_status = getCancellationStatusDisplay($parcel['status']); ?>
                    <div class="booking-group">
                        <div class="trip-header">
                            <div class="trip-title">
                                <h4 style="color: #2c3e50; margin-bottom: 0.5rem;">
                                    📦 <?php echo htmlspecialchars($parcel['tracking_number']); ?>
                                    <span class="badge <?php echo $cancellation_status['class']; ?>" style="margin-left: 1rem;">
                                        <?php echo $cancellation_status['icon']; ?>         <?php echo $cancellation_status['text']; ?>
                                    </span>
                                </h4>

                                <div style="color: #666; margin-bottom: 1rem;">
                                    <strong>Route:</strong> <?php echo htmlspecialchars($parcel['route_name']); ?><br>
                                    <strong>Was scheduled for:</strong>
                                    <?php echo date('D, M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                    <strong>From:</strong> <?php echo htmlspecialchars($parcel['origin']); ?>
                                    <strong>To:</strong> <?php echo htmlspecialchars($parcel['destination']); ?><br>
                                    <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel['receiver_name']); ?><br>
                                    <strong>Weight:</strong> <?php echo $parcel['weight_kg']; ?> kg<br>
                                    <strong>Cancelled:</strong>
                                    <?php echo date('M j, Y', strtotime($parcel['updated_at'] ?? $parcel['created_at'])); ?>
                                </div>
                            </div>

                            <div class="trip-actions">
                                <a href="track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>"
                                    class="btn btn_primary" style="font-size: 0.9rem;">
                                    📱 View Details
                                </a>

                                <div style="margin-top: 0.5rem;">
                                    <button class="btn"
                                        style="background: #6c757d; color: white; font-size: 0.8rem; padding: 0.4rem 0.8rem;"
                                        onclick="alert('This parcel has been cancelled. Contact support at +94 11 123 4567 for assistance.')">
                                        📞 Contact Support
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="total-summary">
                            <div>Amount:</div>
                            <div>LKR <?php echo number_format($parcel['delivery_cost']); ?></div>
                        </div>

                        <!-- Cancellation Information -->
                        <div
                            style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; font-size: 0.9rem;">
                            <div style="display: flex; align-items: center; margin-bottom: 0.5rem;">
                                <span
                                    style="font-size: 1.2rem; margin-right: 0.5rem;"><?php echo $cancellation_status['icon']; ?></span>
                                <strong><?php echo $cancellation_status['text']; ?></strong>
                            </div>
                            <p style="margin: 0; color: #666;">
                                <?php echo $cancellation_status['description']; ?>
                            </p>

                            <div style="margin-top: 0.5rem; font-size: 0.8rem; color: #666;">
                                <strong>💡 Note:</strong> For refund inquiries or assistance, please contact our customer
                                support team.
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid mt_2">
            <div class="feature_card">
                <h4>📱 Track Parcel</h4>
                <p>Track any parcel using the tracking number and get real-time delivery updates.</p>
                <button class="btn btn_success" onclick="showTrackingModal()">Track Parcel</button>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your parcels, delivered with care!</p>
        </div>
    </footer>

    <!-- Quick Track Modal -->
    <div id="trackingModal"
        style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div
            style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 2rem; border-radius: 8px; width: 90%; max-width: 400px;">
            <h3 style="margin-bottom: 1rem;">📱 Track Parcel</h3>
            <form onsubmit="trackParcel(event)">
                <div class="form_group">
                    <label for="tracking_input">Enter Tracking Number:</label>
                    <input type="text" id="tracking_input" class="form_control" placeholder="e.g., PRR250123001"
                        required>
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
        }

        function trackParcel(event) {
            event.preventDefault();
            const trackingNumber = document.getElementById('tracking_input').value.trim();
            if (trackingNumber) {
                window.open('track_parcel.php?tracking=' + encodeURIComponent(trackingNumber), '_blank');
                hideTrackingModal();
            }
        }

        // Close modal when clicking outside
        document.getElementById('trackingModal').addEventListener('click', function (e) {
            if (e.target === this) {
                hideTrackingModal();
            }
        });
    </script>

</body>

</html>