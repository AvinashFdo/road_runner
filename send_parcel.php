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
    if (
        empty($route_id) || empty($sender_name) || empty($sender_phone) ||
        empty($receiver_name) || empty($receiver_phone) || empty($receiver_address) ||
        empty($travel_date) || empty($weight_kg)
    ) {
        $error = "Please fill in all required fields.";
    } elseif (!is_numeric($weight_kg) || $weight_kg <= 0 || $weight_kg > 50) {
        $error = "Weight must be between 0.1 kg and 50 kg.";
    } elseif (strtotime($travel_date) < strtotime('today')) {
        $error = "Travel date cannot be in the past.";
    } elseif (!preg_match('/^[0-9]{10}$/', $sender_phone) || !preg_match('/^[0-9]{10}$/', $receiver_phone)) {
        $error = "Phone numbers must be 10 digits.";
    } else {
        try {
            // Get route information for cost calculation
            $stmt = $pdo->prepare("SELECT distance_km, route_name, origin, destination FROM routes WHERE route_id = ? AND status = 'active'");
            $stmt->execute([$route_id]);
            $route_info = $stmt->fetch();

            if (!$route_info) {
                $error = "Selected route is not available.";
            } else {
                // Calculate delivery cost
                $base_cost = 500; // Base cost in LKR
                $per_kg_cost = 50; // Cost per kg
                $per_km_cost = 2; // Cost per km
                $distance = $route_info['distance_km'];
                $weight = floatval($weight_kg);

                $delivery_cost = $base_cost + ($per_kg_cost * $weight) + ($per_km_cost * $distance);

                // Store parcel data in session for payment processing
                $_SESSION['pending_parcel'] = [
                    'route_id' => $route_id,
                    'route_name' => $route_info['route_name'],
                    'origin' => $route_info['origin'],
                    'destination' => $route_info['destination'],
                    'distance_km' => $distance,
                    'sender_name' => $sender_name,
                    'sender_phone' => $sender_phone,
                    'receiver_name' => $receiver_name,
                    'receiver_phone' => $receiver_phone,
                    'receiver_address' => $receiver_address,
                    'travel_date' => $travel_date,
                    'weight_kg' => $weight,
                    'parcel_type' => $parcel_type,
                    'parcel_description' => $parcel_description,
                    'delivery_cost' => $delivery_cost
                ];

                // Redirect to payment page
                header('Location: parcel_payment.php');
                exit();
            }
        } catch (PDOException $e) {
            $error = "Error processing parcel booking: " . $e->getMessage();
        }
    }
}

// Get available routes
try {
    $stmt = $pdo->prepare("SELECT route_id, route_name, origin, destination, distance_km FROM routes WHERE status = 'active' ORDER BY route_name");
    $stmt->execute();
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
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
    <style>
        /* Two-column parcel form styles */
        .parcel-form-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            margin: 2rem auto;
            max-width: 1200px;
        }

        .form-header {
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            padding: 2rem;
            text-align: center;
        }

        .form-header h2 {
            margin: 0;
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }

        .form-content {
            padding: 2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
        }

        .form-section {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            padding: 1.5rem;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #3498db;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .cost-display {
            background: #e8f5e8;
            border: 2px solid #27ae60;
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
            margin: 1.5rem 0;
        }

        .cost-amount {
            font-size: 1.8rem;
            font-weight: bold;
            color: #27ae60;
            margin: 0.5rem 0;
        }

        .submit-section {
            grid-column: 1 / -1;
            text-align: center;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 2px solid #eee;
        }

        .btn-submit {
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 15px 40px;
            font-size: 1.1rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 250px;
        }

        .btn-submit:hover {
            background: #219a52;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(39, 174, 96, 0.3);
        }

        .btn-submit:disabled {
            background: #95a5a6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .subsection-title {
            color: #2c3e50;
            margin: 1.5rem 0 1rem 0;
            font-size: 1rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .required {
            color: #e74c3c;
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
                gap: 2rem;
            }

            .form-row {
                grid-template-columns: 1fr;
            }

            .form-content {
                padding: 1rem;
            }

            .form-section {
                padding: 1rem;
            }
        }
    </style>
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
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
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
                    Maximum weight: 50kg per parcel.
                </div>
            </div>
        </div>

        <!-- Two-Column Parcel Form -->
        <div class="parcel-form-container">
            <!-- Form Header -->
            <div class="form-header">
                <h2>📦 Send Parcel</h2>
                <p>Fast, reliable parcel delivery across routes</p>
            </div>

            <!-- Form Content -->
            <div class="form-content">
                <form method="POST" action="send_parcel.php" id="parcelForm">
                    <div class="form-grid">
                        <!-- Left Column: Route & Delivery Info -->
                        <div class="form-section">
                            <div class="section-title">
                                <span>🚌</span>
                                Route & Delivery Information
                            </div>

                            <div class="form_group">
                                <label for="route_id">Select Route <span class="required">*</span></label>
                                <select id="route_id" name="route_id" class="form_control" required
                                    onchange="calculateCost()">
                                    <option value="">Choose a route...</option>
                                    <?php foreach ($routes as $route): ?>
                                        <option value="<?php echo $route['route_id']; ?>"
                                            data-distance="<?php echo $route['distance_km']; ?>">
                                            <?php echo htmlspecialchars($route['route_name'] . ' (' . $route['distance_km'] . ' km)'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form_group">
                                <label for="travel_date">Delivery Date <span class="required">*</span></label>
                                <input type="date" id="travel_date" name="travel_date" class="form_control"
                                    min="<?php echo date('Y-m-d'); ?>" required onchange="calculateCost()">
                            </div>

                            <div class="form-row">
                                <div class="form_group">
                                    <label for="weight_kg">Weight (kg) <span class="required">*</span></label>
                                    <input type="number" id="weight_kg" name="weight_kg" class="form_control" step="0.1"
                                        min="0.1" max="50" placeholder="e.g., 2.5" required onchange="calculateCost()"
                                        oninput="calculateCost()">
                                </div>

                                <div class="form_group">
                                    <label for="parcel_type">Parcel Type</label>
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
                                <label for="parcel_description">Parcel Description (Optional)</label>
                                <textarea id="parcel_description" name="parcel_description" class="form_control"
                                    rows="3" placeholder="Brief description of parcel contents..."></textarea>
                            </div>

                            <!-- Cost Display -->
                            <div class="cost-display" id="costDisplay" style="display: none;">
                                <div style="color: #666; margin-bottom: 0.5rem;">Estimated Delivery Cost</div>
                                <div class="cost-amount" id="costAmount">LKR 0</div>
                                <small style="color: #666;">Based on weight and distance</small>
                            </div>
                        </div>

                        <!-- Right Column: Sender & Receiver Info -->
                        <div class="form-section">
                            <div class="section-title">
                                <span>👥</span>
                                Sender & Receiver Information
                            </div>

                            <!-- Sender Info -->
                            <div class="subsection-title">
                                📤 Sender Details
                            </div>

                            <div class="form-row">
                                <div class="form_group">
                                    <label for="sender_name">Sender Name <span class="required">*</span></label>
                                    <input type="text" id="sender_name" name="sender_name" class="form_control"
                                        value="<?php echo $user_info ? htmlspecialchars($user_info['full_name']) : ''; ?>"
                                        required>
                                </div>

                                <div class="form_group">
                                    <label for="sender_phone">Sender Phone <span class="required">*</span></label>
                                    <input type="tel" id="sender_phone" name="sender_phone" class="form_control"
                                        value="<?php echo $user_info ? htmlspecialchars($user_info['phone']) : ''; ?>"
                                        placeholder="0771234567" pattern="[0-9]{10}" required>
                                </div>
                            </div>

                            <!-- Receiver Info -->
                            <div class="subsection-title">
                                📥 Receiver Details
                            </div>

                            <div class="form-row">
                                <div class="form_group">
                                    <label for="receiver_name">Receiver Name <span class="required">*</span></label>
                                    <input type="text" id="receiver_name" name="receiver_name" class="form_control"
                                        required>
                                </div>

                                <div class="form_group">
                                    <label for="receiver_phone">Receiver Phone <span class="required">*</span></label>
                                    <input type="tel" id="receiver_phone" name="receiver_phone" class="form_control"
                                        placeholder="0777654321" pattern="[0-9]{10}" required>
                                </div>
                            </div>

                            <div class="form_group">
                                <label for="receiver_address">Receiver Address <span class="required">*</span></label>
                                <textarea id="receiver_address" name="receiver_address" class="form_control" rows="4"
                                    placeholder="Complete address including house number, street, city"
                                    required></textarea>
                            </div>
                        </div>

                        <!-- Submit Section (spans both columns) -->
                        <div class="submit-section">
                            <button type="submit" name="send_parcel" class="btn-submit" id="submitBtn" disabled>
                                📦 Book Parcel Delivery
                            </button>
                            <p style="margin-top: 1rem; color: #666; font-size: 0.9rem;">
                                By booking, you agree to our terms and conditions
                            </p>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Payment Options Info -->
        <div class="alert alert_info" style="margin: 2rem 0;">
            <h4>💳 Payment Options Available</h4>
            <p>After filling the parcel details, you can choose to:</p>
            <ul style="margin: 0.5rem 0; padding-left: 2rem;">
                <li><strong>Pay Now:</strong> Complete payment online with your card for instant confirmation</li>
                <li><strong>Pay at Pickup:</strong> Book now and pay when you drop off the parcel at the station</li>
            </ul>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Safe and reliable parcel delivery!</p>
        </div>
    </footer>

    <script>
        // Calculate delivery cost based on weight and route
        function calculateCost() {
            const routeSelect = document.getElementById('route_id');
            const weightInput = document.getElementById('weight_kg');
            const dateInput = document.getElementById('travel_date');
            const costDisplay = document.getElementById('costDisplay');
            const costAmount = document.getElementById('costAmount');
            const submitBtn = document.getElementById('submitBtn');

            if (routeSelect.value && weightInput.value && dateInput.value) {
                const selectedOption = routeSelect.options[routeSelect.selectedIndex];
                const distance = parseFloat(selectedOption.getAttribute('data-distance')) || 0;
                const weight = parseFloat(weightInput.value);

                // Cost calculation: Base 500 + 50/kg + 2/km
                const baseCost = 500;
                const perKgCost = 50;
                const perKmCost = 2;

                const totalCost = Math.round(baseCost + (perKgCost * weight) + (perKmCost * distance));

                costAmount.textContent = `LKR ${totalCost}`;
                costDisplay.style.display = 'block';
                submitBtn.disabled = false;
            } else {
                costDisplay.style.display = 'none';
                submitBtn.disabled = true;
            }
        }

        // Phone number validation
        document.getElementById('sender_phone').addEventListener('input', function (e) {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });

        document.getElementById('receiver_phone').addEventListener('input', function (e) {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });

        // Form validation
        document.getElementById('parcelForm').addEventListener('submit', function (e) {
            const requiredFields = document.querySelectorAll('[required]');
            let isValid = true;

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.style.borderColor = '#e74c3c';
                } else {
                    field.style.borderColor = '#ddd';
                }
            });

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
                window.scrollTo(0, 0);
            }
        });

        // Set minimum date to today
        document.getElementById('travel_date').min = new Date().toISOString().split('T')[0];

        // Initial check for submit button state
        document.addEventListener('DOMContentLoaded', function () {
            calculateCost();
        });
    </script>
</body>

</html>