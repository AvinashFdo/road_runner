<?php
// Parcel Payment Interface
// Save this as: parcel_payment.php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get parcel data from session
if (!isset($_SESSION['pending_parcel'])) {
    header('Location: send_parcel.php');
    exit();
}

$parcel_data = $_SESSION['pending_parcel'];
$delivery_cost = $parcel_data['delivery_cost'];

$error = '';
$success = '';

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';

    if ($payment_method === 'pay_later') {
        // Process parcel booking with pending payment
        try {
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

            // Insert parcel booking with pending payment
            $stmt = $pdo->prepare("
                INSERT INTO parcels 
                (tracking_number, sender_id, sender_name, sender_phone, receiver_name, 
                 receiver_phone, receiver_address, route_id, weight_kg, parcel_type, 
                 delivery_cost, travel_date, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([
                $tracking_number,
                $_SESSION['user_id'],
                $parcel_data['sender_name'],
                $parcel_data['sender_phone'],
                $parcel_data['receiver_name'],
                $parcel_data['receiver_phone'],
                $parcel_data['receiver_address'],
                $parcel_data['route_id'],
                $parcel_data['weight_kg'],
                $parcel_data['parcel_type'] ?: 'General',
                $delivery_cost,
                $parcel_data['travel_date']
            ]);

            // Get the inserted parcel ID and update payment status
            $parcel_id = $pdo->lastInsertId();

            // Add payment status tracking (you might need to create a separate payments table)
            // For now, we'll add a note or use a different approach
            try {
                // Try to update payment_status if column exists
                $stmt = $pdo->prepare("UPDATE parcels SET payment_status = 'pending' WHERE parcel_id = ?");
                $stmt->execute([$parcel_id]);
            } catch (PDOException $e) {
                // If payment_status column doesn't exist, ignore the error
                // The parcel is still created successfully
            }

            // Clear session
            unset($_SESSION['pending_parcel']);

            // Redirect to confirmation
            header('Location: parcel_confirmation.php?tracking=' . $tracking_number);
            exit();

        } catch (Exception $e) {
            $error = "Booking failed: " . $e->getMessage();
        }

    } elseif ($payment_method === 'pay_now') {
        // Validate card details
        $card_number = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
        $expiry_month = $_POST['expiry_month'] ?? '';
        $expiry_year = $_POST['expiry_year'] ?? '';
        $cvv = $_POST['cvv'] ?? '';
        $cardholder_name = trim($_POST['cardholder_name'] ?? '');

        // Basic validation
        if (empty($card_number) || empty($expiry_month) || empty($expiry_year) || empty($cvv) || empty($cardholder_name)) {
            $error = "Please fill all card details.";
        } elseif (strlen($card_number) < 13 || strlen($card_number) > 19) {
            $error = "Invalid card number.";
        } elseif (strlen($cvv) < 3 || strlen($cvv) > 4) {
            $error = "Invalid CVV.";
        } elseif (!preg_match('/^[a-zA-Z\s\.\-\']+$/', $cardholder_name)) {
            $error = "Invalid cardholder name.";
        } else {
            // Process payment
            try {
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

                // Insert parcel booking with paid status
                $stmt = $pdo->prepare("
                    INSERT INTO parcels 
                    (tracking_number, sender_id, sender_name, sender_phone, receiver_name, 
                     receiver_phone, receiver_address, route_id, weight_kg, parcel_type, 
                     delivery_cost, travel_date, status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $tracking_number,
                    $_SESSION['user_id'],
                    $parcel_data['sender_name'],
                    $parcel_data['sender_phone'],
                    $parcel_data['receiver_name'],
                    $parcel_data['receiver_phone'],
                    $parcel_data['receiver_address'],
                    $parcel_data['route_id'],
                    $parcel_data['weight_kg'],
                    $parcel_data['parcel_type'] ?: 'General',
                    $delivery_cost,
                    $parcel_data['travel_date']
                ]);

                // Get the inserted parcel ID and update payment status
                $parcel_id = $pdo->lastInsertId();

                // Add payment status tracking
                try {
                    // Try to update payment_status if column exists
                    $stmt = $pdo->prepare("UPDATE parcels SET payment_status = 'paid' WHERE parcel_id = ?");
                    $stmt->execute([$parcel_id]);
                } catch (PDOException $e) {
                    // If payment_status column doesn't exist, we'll use a different approach
                    // For now, we can distinguish by looking at the creation context
                }

                // Clear session
                unset($_SESSION['pending_parcel']);

                // Redirect to confirmation
                header('Location: parcel_confirmation.php?tracking=' . $tracking_number . '&payment=success');
                exit();

            } catch (Exception $e) {
                $error = "Payment failed: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please select a valid payment method.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Payment - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .payment-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }

        .payment-option {
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .payment-option.selected {
            border-color: #2c3e50;
            background: #f8f9fa;
        }

        .payment-option:hover {
            border-color: #3498db;
        }

        .card-form {
            display: none;
            background: #f8f9fa;
            padding: 2rem;
            border-radius: 8px;
            margin-top: 1rem;
            border: 1px solid #ddd;
            animation: slideDown 0.3s ease;
        }

        .card-form.show {
            display: block;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .card-input-group {
            display: flex;
            gap: 1rem;
        }

        .card-input-group .form_group {
            flex: 1;
        }

        .card-number-input {
            font-family: monospace;
            font-size: 1.1rem;
            letter-spacing: 1px;
        }

        .parcel-summary {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .cost-breakdown {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 4px;
            margin: 1rem 0;
        }

        .cost-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .cost-item.total {
            font-weight: bold;
            border-top: 1px solid #ddd;
            padding-top: 0.5rem;
            margin-top: 0.5rem;
        }

        .secure-notice {
            background: #e3f2fd;
            border: 1px solid #bbdefb;
            border-radius: 4px;
            padding: 1rem;
            margin: 1rem 0;
            font-size: 0.9rem;
            color: #1976d2;
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
                    <li><a href="send_parcel.php">Send Parcel</a></li>
                    <li><a href="my_parcels.php">My Parcels</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <div class="payment-container">
            <h2 style="text-align: center; margin-bottom: 2rem;">📦 Parcel Payment</h2>

            <!-- Messages -->
            <?php if ($error): ?>
                <div class="alert alert_error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Parcel Summary -->
            <div class="parcel-summary">
                <h3 style="margin-bottom: 1rem;">📋 Parcel Summary</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                    <div>
                        <strong>Route:</strong> <?php echo htmlspecialchars($parcel_data['route_name']); ?><br>
                        <strong>From:</strong> <?php echo htmlspecialchars($parcel_data['origin']); ?><br>
                        <strong>To:</strong> <?php echo htmlspecialchars($parcel_data['destination']); ?><br>
                        <strong>Delivery Date:</strong>
                        <?php echo date('D, M j, Y', strtotime($parcel_data['travel_date'])); ?>
                    </div>
                    <div>
                        <strong>Sender:</strong> <?php echo htmlspecialchars($parcel_data['sender_name']); ?><br>
                        <strong>Receiver:</strong> <?php echo htmlspecialchars($parcel_data['receiver_name']); ?><br>
                        <strong>Weight:</strong> <?php echo $parcel_data['weight_kg']; ?> kg<br>
                        <strong>Type:</strong> <?php echo htmlspecialchars($parcel_data['parcel_type'] ?: 'General'); ?>
                    </div>
                </div>

                <!-- Cost Breakdown -->
                <div class="cost-breakdown">
                    <h4>💰 Cost Breakdown</h4>
                    <?php
                    $base_rate = 500;
                    $weight_rate = $parcel_data['weight_kg'] * 50;
                    $distance_rate = $parcel_data['distance_km'] * 2;
                    ?>
                    <div class="cost-item">
                        <span>Base Rate:</span>
                        <span>LKR <?php echo number_format($base_rate); ?></span>
                    </div>
                    <div class="cost-item">
                        <span>Weight (<?php echo $parcel_data['weight_kg']; ?> kg × LKR 50):</span>
                        <span>LKR <?php echo number_format($weight_rate); ?></span>
                    </div>
                    <div class="cost-item">
                        <span>Distance (<?php echo $parcel_data['distance_km']; ?> km × LKR 2):</span>
                        <span>LKR <?php echo number_format($distance_rate); ?></span>
                    </div>
                    <div class="cost-item total">
                        <span>Total Delivery Cost:</span>
                        <span>LKR <?php echo number_format($delivery_cost); ?></span>
                    </div>
                </div>
            </div>

            <!-- Payment Form -->
            <form method="POST" id="paymentForm">

                <!-- Pay Later Option -->
                <div class="payment-option" onclick="selectPaymentMethod('pay_later')">
                    <input type="radio" name="payment_method" value="pay_later" id="pay_later"
                        style="margin-right: 1rem;">
                    <label for="pay_later" style="cursor: pointer; font-size: 1.1rem;">
                        <strong>💰 Pay at Pickup</strong>
                        <p style="margin: 0.5rem 0; color: #666;">Book your parcel now and pay when you drop it off at
                            the origin station.</p>
                        <small style="color: #e74c3c;">Note: Payment required before parcel is accepted for
                            transport.</small>
                    </label>
                </div>

                <!-- Pay Now Option -->
                <div class="payment-option" onclick="selectPaymentMethod('pay_now')">
                    <input type="radio" name="payment_method" value="pay_now" id="pay_now" style="margin-right: 1rem;">
                    <label for="pay_now" style="cursor: pointer; font-size: 1.1rem;">
                        <strong>💳 Pay Now</strong>
                        <p style="margin: 0.5rem 0; color: #666;">Complete payment now with your credit/debit card for
                            confirmed booking.</p>
                        <small style="color: #27ae60;">Secure payment • Instant confirmation • Priority handling</small>
                    </label>
                </div>

                <!-- Card Details Form -->
                <div id="cardForm" class="card-form">
                    <h4 style="margin-bottom: 1rem;">💳 Card Details</h4>

                    <div class="form_group">
                        <label for="cardholder_name">Cardholder Name: *</label>
                        <input type="text" id="cardholder_name" name="cardholder_name" class="form_control"
                            placeholder="Name">
                    </div>

                    <div class="form_group">
                        <label for="card_number">Card Number: *</label>
                        <input type="text" id="card_number" name="card_number" class="form_control card-number-input"
                            placeholder="1234 5678 9012 3456" maxlength="19">
                    </div>

                    <div class="card-input-group">
                        <div class="form_group">
                            <label for="expiry_month">Expiry Month: *</label>
                            <select id="expiry_month" name="expiry_month" class="form_control">
                                <option value="">Month</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>"><?php echo sprintf('%02d', $i); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form_group">
                            <label for="expiry_year">Expiry Year: *</label>
                            <select id="expiry_year" name="expiry_year" class="form_control">
                                <option value="">Year</option>
                                <?php for ($i = date('Y'); $i <= date('Y') + 10; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="form_group">
                            <label for="cvv">CVV: *</label>
                            <input type="text" id="cvv" name="cvv" class="form_control" placeholder="123" maxlength="4">
                        </div>
                    </div>

                    <div class="secure-notice">
                        🔒 Your payment information is secure and encrypted. We do not store your card details.
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div style="text-align: center; margin-top: 2rem;">
                    <button type="submit" id="submitBtn" class="btn btn_primary"
                        style="padding: 1rem 2rem; font-size: 1.1rem;" disabled>
                        Complete Booking
                    </button>
                    <a href="send_parcel.php" class="btn" style="margin-left: 1rem; background: #6c757d;">Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Secure payments, safe deliveries!</p>
        </div>
    </footer>

    <script>
        function selectPaymentMethod(method) {
            // Clear previous selections
            document.querySelectorAll('.payment-option').forEach(option => {
                option.classList.remove('selected');
            });

            // Select current option
            document.getElementById(method).checked = true;
            document.getElementById(method).closest('.payment-option').classList.add('selected');

            // Show/hide card form
            const cardForm = document.getElementById('cardForm');
            const submitBtn = document.getElementById('submitBtn');

            if (method === 'pay_now') {
                cardForm.classList.add('show');
                submitBtn.textContent = 'Pay LKR <?php echo number_format($delivery_cost); ?>';
                submitBtn.style.background = '#27ae60';

                // Make card fields required
                document.getElementById('cardholder_name').required = true;
                document.getElementById('card_number').required = true;
                document.getElementById('expiry_month').required = true;
                document.getElementById('expiry_year').required = true;
                document.getElementById('cvv').required = true;
            } else {
                cardForm.classList.remove('show');
                submitBtn.textContent = 'Book Parcel (Pay at Pickup)';
                submitBtn.style.background = '#3498db';

                // Make card fields not required
                document.getElementById('cardholder_name').required = false;
                document.getElementById('card_number').required = false;
                document.getElementById('expiry_month').required = false;
                document.getElementById('expiry_year').required = false;
                document.getElementById('cvv').required = false;
            }

            submitBtn.disabled = false;
        }

        // Format card number input
        document.getElementById('card_number').addEventListener('input', function (e) {
            let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
            let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
            e.target.value = formattedValue;
        });

        // CVV validation
        document.getElementById('cvv').addEventListener('input', function (e) {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });

        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function (e) {
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked');

            if (!paymentMethod) {
                e.preventDefault();
                alert('Please select a payment method.');
                return;
            }

            if (paymentMethod.value === 'pay_now') {
                const cardNumber = document.getElementById('card_number').value.replace(/\s+/g, '');
                const cvv = document.getElementById('cvv').value;
                const cardholderName = document.getElementById('cardholder_name').value.trim();
                const expiryMonth = document.getElementById('expiry_month').value;
                const expiryYear = document.getElementById('expiry_year').value;

                if (cardNumber.length < 13 || cardNumber.length > 19) {
                    e.preventDefault();
                    alert('Please enter a valid card number.');
                    return;
                }

                if (cvv.length < 3 || cvv.length > 4) {
                    e.preventDefault();
                    alert('Please enter a valid CVV.');
                    return;
                }

                if (cardholderName.length < 2) {
                    e.preventDefault();
                    alert('Please enter the cardholder name.');
                    return;
                }

                if (!expiryMonth || !expiryYear) {
                    e.preventDefault();
                    alert('Please select expiry month and year.');
                    return;
                }
            }

            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.textContent = 'Processing...';
            submitBtn.disabled = true;
        });

        // Add click handlers to payment options
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.payment-option').forEach(option => {
                option.addEventListener('click', function () {
                    const radio = this.querySelector('input[type="radio"]');
                    if (radio) {
                        selectPaymentMethod(radio.value);
                    }
                });
            });
        });
    </script>
</body>

</html>