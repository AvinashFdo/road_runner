<?php
// Parcel Delivery System - Send Parcel
// Save this as: send_parcel.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$error = '';
$message = '';

// Handle parcel booking submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_parcel'])) {
    // Get form data
    $route_id = $_POST['route_id'] ?? '';
    $sender_name = trim($_POST['sender_name'] ?? '');
    $sender_phone = trim($_POST['sender_phone'] ?? '');
    $receiver_name = trim($_POST['receiver_name'] ?? '');
    $receiver_phone = trim($_POST['receiver_phone'] ?? '');
    $receiver_address = trim($_POST['receiver_address'] ?? '');
    $travel_date = $_POST['travel_date'] ?? '';
    $weight_kg = $_POST['weight_kg'] ?? '';
    $parcel_type = trim($_POST['parcel_type'] ?? '');
    $parcel_description = trim($_POST['parcel_description'] ?? '');
    
    // Validation
    if (empty($route_id) || empty($sender_name) || empty($sender_phone) || 
        empty($receiver_name) || empty($receiver_phone) || empty($receiver_address) || 
        empty($travel_date) || empty($weight_kg)) {
        $error = "Please fill in all required fields.";
    } elseif (!is_numeric($weight_kg) || $weight_kg <= 0 || $weight_kg > 50) {
        $error = "Weight must be between 0.1 kg and 50 kg.";
    } elseif (strtotime($travel_date) < strtotime('today')) {
        $error = "Travel date cannot be in the past.";
    } elseif (!preg_match('/^[0-9]{10}$/', $sender_phone) || !preg_match('/^[0-9]{10}$/', $receiver_phone)) {
        $error = "Phone numbers must be 10 digits.";
    } else {
        try {
            // Calculate delivery cost based on weight and distance
            $stmt = $pdo->prepare("SELECT distance_km, route_name, origin, destination FROM routes WHERE route_id = ? AND status = 'active'");
            $stmt->execute([$route_id]);
            $route_info = $stmt->fetch();
            
            if (!$route_info) {
                $error = "Selected route is not available.";
            } else {
                // Calculate cost: Base rate + weight rate + distance rate
                $base_rate = 500; // LKR 500 base rate
                $weight_rate = $weight_kg * 50; // LKR 50 per kg
                $distance_rate = $route_info['distance_km'] * 2; // LKR 2 per km
                $delivery_cost = $base_rate + $weight_rate + $distance_rate;
                
                // Generate unique tracking number
                $attempts = 0;
                do {
                    $tracking_number = 'PRR' . date('ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("SELECT parcel_id FROM parcels WHERE tracking_number = ?");
                    $stmt->execute([$tracking_number]);
                    $attempts++;
                    
                    if ($attempts > 10) {
                        throw new Exception("Unable to generate unique tracking number. Please try again.");
                    }
                } while ($stmt->fetch());
                
                // Insert parcel booking
                $stmt = $pdo->prepare("
                    INSERT INTO parcels 
                    (tracking_number, sender_id, sender_name, sender_phone, receiver_name, 
                     receiver_phone, receiver_address, route_id, weight_kg, parcel_type, 
                     delivery_cost, travel_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $tracking_number, $user_id, $sender_name, $sender_phone,
                    $receiver_name, $receiver_phone, $receiver_address,
                    $route_id, $weight_kg, $parcel_type ?: 'General',
                    $delivery_cost, $travel_date
                ]);
                
                // Redirect to confirmation page
                header('Location: parcel_confirmation.php?tracking=' . $tracking_number);
                exit();
            }
        } catch (PDOException $e) {
            $error = "Error processing parcel booking: " . $e->getMessage();
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get active routes for dropdown
try {
    $stmt = $pdo->query("
        SELECT route_id, route_name, origin, destination, distance_km 
        FROM routes 
        WHERE status = 'active' 
        ORDER BY route_name ASC
    ");
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $routes = [];
    $error = "Error loading routes: " . $e->getMessage();
}

// Get user information for pre-filling sender details
try {
    $stmt = $pdo->prepare("SELECT full_name, phone FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch();
} catch (PDOException $e) {
    $user_info = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Send Parcel - Road Runner</title>
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
        <h2 class="mb_2">📦 Send Parcel</h2>

        <!-- Display Messages -->
        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert_success">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Parcel Service Info -->
        <div class="alert alert_info mb_2">
            <h4>📦 Road Runner Parcel Delivery Service</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>🚌 Bus Route Delivery:</strong><br>
                    Your parcel travels along with passenger buses for cost-effective delivery.
                </div>
                <div>
                    <strong>💰 Transparent Pricing:</strong><br>
                    Base rate: LKR 500 + LKR 50/kg + LKR 2/km distance charge.
                </div>
                <div>
                    <strong>📱 Real-time Tracking:</strong><br>
                    Track your parcel with a unique tracking number and get updates.
                </div>
                <div>
                    <strong>⚖️ Weight Limit:</strong><br>
                    Maximum weight: 50kg per parcel. For larger items, contact support.
                </div>
            </div>
        </div>

        <!-- Parcel Booking Form -->
        <div class="form_container">
            <form method="POST" action="send_parcel.php" id="parcel_form">
                <!-- Route Selection -->
                <div class="form_group">
                    <label for="route_id">Select Route: *</label>
                    <select id="route_id" name="route_id" class="form_control" required onchange="calculateCost()">
                        <option value="">Choose delivery route...</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?php echo $route['route_id']; ?>" 
                                    data-distance="<?php echo $route['distance_km']; ?>"
                                    data-route-name="<?php echo htmlspecialchars($route['route_name']); ?>">
                                <?php echo htmlspecialchars($route['route_name']); ?> 
                                (<?php echo htmlspecialchars($route['origin']); ?> → <?php echo htmlspecialchars($route['destination']); ?>) 
                                - <?php echo $route['distance_km']; ?> km
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Travel Date -->
                <div class="form_group">
                    <label for="travel_date">Delivery Date: *</label>
                    <input 
                        type="date" 
                        id="travel_date" 
                        name="travel_date" 
                        class="form_control" 
                        min="<?php echo date('Y-m-d'); ?>"
                        required
                    >
                </div>

                <!-- Sender Information -->
                <fieldset style="border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                    <legend style="padding: 0 0.5rem; font-weight: bold; color: #2c3e50;">Sender Information</legend>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form_group">
                            <label for="sender_name">Sender Name: *</label>
                            <input 
                                type="text" 
                                id="sender_name" 
                                name="sender_name" 
                                class="form_control" 
                                value="<?php echo $user_info ? htmlspecialchars($user_info['full_name']) : ''; ?>"
                                required
                            >
                        </div>
                        
                        <div class="form_group">
                            <label for="sender_phone">Sender Phone: *</label>
                            <input 
                                type="tel" 
                                id="sender_phone" 
                                name="sender_phone" 
                                class="form_control" 
                                value="<?php echo $user_info ? htmlspecialchars($user_info['phone']) : ''; ?>"
                                placeholder="0771234567"
                                pattern="[0-9]{10}"
                                required
                            >
                        </div>
                    </div>
                </fieldset>

                <!-- Receiver Information -->
                <fieldset style="border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                    <legend style="padding: 0 0.5rem; font-weight: bold; color: #2c3e50;">Receiver Information</legend>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form_group">
                            <label for="receiver_name">Receiver Name: *</label>
                            <input 
                                type="text" 
                                id="receiver_name" 
                                name="receiver_name" 
                                class="form_control" 
                                required
                            >
                        </div>
                        
                        <div class="form_group">
                            <label for="receiver_phone">Receiver Phone: *</label>
                            <input 
                                type="tel" 
                                id="receiver_phone" 
                                name="receiver_phone" 
                                class="form_control" 
                                placeholder="0777654321"
                                pattern="[0-9]{10}"
                                required
                            >
                        </div>
                    </div>
                    
                    <div class="form_group">
                        <label for="receiver_address">Receiver Address: *</label>
                        <textarea 
                            id="receiver_address" 
                            name="receiver_address" 
                            class="form_control" 
                            rows="3"
                            placeholder="Complete address including house number, street, city"
                            required
                        ></textarea>
                    </div>
                </fieldset>

                <!-- Parcel Information -->
                <fieldset style="border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                    <legend style="padding: 0 0.5rem; font-weight: bold; color: #2c3e50;">Parcel Information</legend>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form_group">
                            <label for="weight_kg">Weight (kg): *</label>
                            <input 
                                type="number" 
                                id="weight_kg" 
                                name="weight_kg" 
                                class="form_control" 
                                step="0.1" 
                                min="0.1" 
                                max="50"
                                placeholder="e.g., 2.5"
                                required
                                onchange="calculateCost()"
                                oninput="calculateCost()"
                            >
                        </div>
                        
                        <div class="form_group">
                            <label for="parcel_type">Parcel Type:</label>
                            <select id="parcel_type" name="parcel_type" class="form_control">
                                <option value="General">General Items</option>
                                <option value="Documents">Documents</option>
                                <option value="Electronics">Electronics</option>
                                <option value="Clothing">Clothing</option>
                                <option value="Books">Books</option>
                                <option value="Food Items">Food Items</option>
                                <option value="Medical">Medical Supplies</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form_group">
                        <label for="parcel_description">Parcel Description (Optional):</label>
                        <textarea 
                            id="parcel_description" 
                            name="parcel_description" 
                            class="form_control" 
                            rows="2"
                            placeholder="Brief description of parcel contents (for internal tracking)"
                        ></textarea>
                    </div>
                </fieldset>

                <!-- Cost Calculation -->
                <div style="background: #f8f9fa; border-radius: 8px; padding: 1rem; margin: 1rem 0;">
                    <h4 style="margin-bottom: 1rem; color: #2c3e50;">💰 Delivery Cost Calculation</h4>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                        <div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span>Base Rate:</span>
                                <span>LKR 500.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span>Weight Charge (<span id="weight_display">0</span> kg × LKR 50):</span>
                                <span id="weight_cost">LKR 0.00</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                                <span>Distance Charge (<span id="distance_display">0</span> km × LKR 2):</span>
                                <span id="distance_cost">LKR 0.00</span>
                            </div>
                        </div>
                        <div style="text-align: center; border-left: 2px solid #ddd; padding-left: 1rem;">
                            <div style="font-size: 0.9rem; color: #666; margin-bottom: 0.5rem;">Total Delivery Cost</div>
                            <div style="font-size: 2rem; font-weight: bold; color: #e74c3c;" id="total_cost">LKR 500.00</div>
                        </div>
                    </div>
                    
                    <div style="font-size: 0.9rem; color: #666; text-align: center;">
                        💡 Cost includes pickup from bus station and delivery notification to receiver
                    </div>
                </div>

                <!-- Submit Button -->
                <button type="submit" name="send_parcel" class="btn btn_primary" style="width: 100%; padding: 1rem; font-size: 1.1rem;">
                    📦 Send Parcel
                </button>
                
                <p style="text-align: center; margin-top: 1rem; font-size: 0.9rem; color: #666;">
                    <a href="index.php">← Back to Home</a> | 
                    <a href="my_parcels.php">My Parcels</a>
                </p>
            </form>
        </div>

        <!-- Terms and Conditions -->
        <div class="alert alert_info mt_2">
            <h4>📋 Terms and Conditions</h4>
            <ul style="margin: 1rem 0; padding-left: 2rem;">
                <li>Maximum weight limit: 50kg per parcel</li>
                <li>Prohibited items: Hazardous materials, liquids, perishable food items, illegal items</li>
                <li>Delivery time: Same day as bus arrival (subject to bus schedule)</li>
                <li>Insurance: Up to LKR 10,000 coverage included (additional insurance available)</li>
                <li>Pickup: From destination bus station within 24 hours of arrival</li>
                <li>Tracking: Real-time updates via SMS and online tracking</li>
                <li>Payment: Cash on delivery or advance payment accepted</li>
            </ul>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Reliable parcel delivery across Sri Lanka!</p>
        </div>
    </footer>

    <script>
        // Set default delivery date to tomorrow
        document.addEventListener('DOMContentLoaded', function() {
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('travel_date').value = tomorrow.toISOString().split('T')[0];
        });

        // Calculate delivery cost in real-time
        function calculateCost() {
            const routeSelect = document.getElementById('route_id');
            const weightInput = document.getElementById('weight_kg');
            
            const baseRate = 500;
            const weightRate = 50; // per kg
            const distanceRate = 2; // per km
            
            let weight = parseFloat(weightInput.value) || 0;
            let distance = 0;
            
            if (routeSelect.selectedIndex > 0) {
                const selectedOption = routeSelect.options[routeSelect.selectedIndex];
                distance = parseFloat(selectedOption.dataset.distance) || 0;
            }
            
            const weightCost = weight * weightRate;
            const distanceCost = distance * distanceRate;
            const totalCost = baseRate + weightCost + distanceCost;
            
            // Update display
            document.getElementById('weight_display').textContent = weight.toFixed(1);
            document.getElementById('distance_display').textContent = distance.toFixed(1);
            document.getElementById('weight_cost').textContent = 'LKR ' + weightCost.toFixed(2);
            document.getElementById('distance_cost').textContent = 'LKR ' + distanceCost.toFixed(2);
            document.getElementById('total_cost').textContent = 'LKR ' + totalCost.toFixed(2);
        }

        // Form validation
        document.getElementById('parcel_form').addEventListener('submit', function(e) {
            const weight = parseFloat(document.getElementById('weight_kg').value);
            
            if (weight > 50) {
                e.preventDefault();
                alert('Weight cannot exceed 50kg. For larger parcels, please contact our support team.');
                return false;
            }
            
            if (weight <= 0) {
                e.preventDefault();
                alert('Please enter a valid weight.');
                return false;
            }
            
            // Confirm submission
            const routeSelect = document.getElementById('route_id');
            const routeName = routeSelect.options[routeSelect.selectedIndex].dataset.routeName;
            const totalCost = document.getElementById('total_cost').textContent;
            
            const confirmMessage = `Confirm parcel delivery?\n\nRoute: ${routeName}\nWeight: ${weight}kg\nTotal Cost: ${totalCost}\n\nProceed with booking?`;
            
            if (!confirm(confirmMessage)) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>