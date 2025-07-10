<?php
// Payment Interface
// Save this as: payment.php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get payment data from session
if (!isset($_SESSION['pending_booking'])) {
    header('Location: search_buses.php');
    exit();
}

$booking_data = $_SESSION['pending_booking'];
$total_amount = 0;
foreach ($booking_data['passengers'] as $passenger) {
    $total_amount += $booking_data['base_price'];
}

$error = '';
$success = '';

// Debug: Check if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Form submitted. POST data: " . print_r($_POST, true));
}

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_method = $_POST['payment_method'] ?? '';
    
    // Debug: Log the payment method
    error_log("Payment method selected: " . $payment_method);
    
    if ($payment_method === 'pay_later') {
        // Process booking with pending payment
        try {
            $pdo->beginTransaction();
            
            // Check seat availability again
            foreach ($booking_data['passengers'] as $passenger) {
                $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE seat_id = ? AND travel_date = ? AND booking_status IN ('pending', 'confirmed')");
                $stmt->execute([$passenger['seat_id'], $booking_data['travel_date']]);
                if ($stmt->fetch()) {
                    throw new Exception("One or more seats have been booked. Please try again.");
                }
            }
            
            $booking_references = [];
            
            // Create bookings with pending payment
            foreach ($booking_data['passengers'] as $passenger) {
                // Generate unique booking reference
                do {
                    $booking_ref = 'RR' . date('ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                    $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_reference = ?");
                    $stmt->execute([$booking_ref]);
                } while ($stmt->fetch());
                
                // Create booking
                $stmt = $pdo->prepare("
                    INSERT INTO bookings 
                    (booking_reference, passenger_id, schedule_id, seat_id, passenger_name, passenger_phone, passenger_gender, travel_date, total_amount, booking_status, payment_status) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'pending')
                ");
                $stmt->execute([
                    $booking_ref,
                    $_SESSION['user_id'],
                    $booking_data['schedule_id'],
                    $passenger['seat_id'],
                    $passenger['name'],
                    $booking_data['user_phone'],
                    $passenger['gender'],
                    $booking_data['travel_date'],
                    $booking_data['base_price']
                ]);
                
                $booking_references[] = $booking_ref;
            }
            
            $pdo->commit();
            
            // Clear session
            unset($_SESSION['pending_booking']);
            
            // Redirect to confirmation
            header('Location: booking_confirmation.php?booking_refs=' . implode(',', $booking_references));
            exit();
            
        } catch (Exception $e) {
            $pdo->rollback();
            $error = "Booking failed: " . $e->getMessage();
            error_log("Pay later booking error: " . $e->getMessage());
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
            // Process payment (simplified - in real world, integrate with payment gateway)
            try {
                $pdo->beginTransaction();
                
                // Check seat availability again
                foreach ($booking_data['passengers'] as $passenger) {
                    $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE seat_id = ? AND travel_date = ? AND booking_status IN ('pending', 'confirmed')");
                    $stmt->execute([$passenger['seat_id'], $booking_data['travel_date']]);
                    if ($stmt->fetch()) {
                        throw new Exception("One or more seats have been booked. Please try again.");
                    }
                }
                
                $booking_references = [];
                
                // Create bookings with paid status
                foreach ($booking_data['passengers'] as $passenger) {
                    // Generate unique booking reference
                    do {
                        $booking_ref = 'RR' . date('ymd') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE booking_reference = ?");
                        $stmt->execute([$booking_ref]);
                    } while ($stmt->fetch());
                    
                    // Create booking with paid status
                    $stmt = $pdo->prepare("
                        INSERT INTO bookings 
                        (booking_reference, passenger_id, schedule_id, seat_id, passenger_name, passenger_phone, passenger_gender, travel_date, total_amount, booking_status, payment_status) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', 'paid')
                    ");
                    $stmt->execute([
                        $booking_ref,
                        $_SESSION['user_id'],
                        $booking_data['schedule_id'],
                        $passenger['seat_id'],
                        $passenger['name'],
                        $booking_data['user_phone'],
                        $passenger['gender'],
                        $booking_data['travel_date'],
                        $booking_data['base_price']
                    ]);
                    
                    $booking_references[] = $booking_ref;
                }
                
                // Log payment transaction (optional)
                $transaction_id = 'TXN' . date('ymdHis') . rand(100, 999);
                // In real implementation, save payment details to transactions table
                
                $pdo->commit();
                
                // Clear session
                unset($_SESSION['pending_booking']);
                
                // Redirect to confirmation
                header('Location: booking_confirmation.php?booking_refs=' . implode(',', $booking_references) . '&payment=success');
                exit();
                
            } catch (Exception $e) {
                $pdo->rollback();
                $error = "Payment failed: " . $e->getMessage();
            }
        }
    } else {
        $error = "Please select a valid payment method.";
        error_log("Invalid payment method: " . $payment_method);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment - Road Runner</title>
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
        }
        
        .card-form.show {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        .booking-summary {
            background: #e8f5e8;
            border: 1px solid #d4edda;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .passenger-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid #ddd;
        }
        
        .passenger-item:last-child {
            border-bottom: none;
        }
        
        .total-amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: #2c3e50;
            text-align: center;
            margin: 1rem 0;
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
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="search_buses.php">Search Buses</a></li>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <div class="payment-container">
            <h2 style="text-align: center; margin-bottom: 2rem;">💳 Payment Options</h2>

            <!-- Messages -->
            <?php if ($error): ?>
                <div class="alert alert_error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <!-- Booking Summary -->
            <div class="booking-summary">
                <h3 style="margin-bottom: 1rem;">📋 Booking Summary</h3>
                <div style="margin-bottom: 1rem;">
                    <strong>Route:</strong> <?php echo htmlspecialchars($booking_data['route_name']); ?><br>
                    <strong>From:</strong> <?php echo htmlspecialchars($booking_data['origin']); ?><br>
                    <strong>To:</strong> <?php echo htmlspecialchars($booking_data['destination']); ?><br>
                    <strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($booking_data['travel_date'])); ?><br>
                    <strong>Bus:</strong> <?php echo htmlspecialchars($booking_data['bus_name']); ?> (<?php echo htmlspecialchars($booking_data['bus_number']); ?>)
                </div>
                
                <h4>Passengers:</h4>
                <?php foreach ($booking_data['passengers'] as $index => $passenger): ?>
                    <div class="passenger-item">
                        <span><?php echo htmlspecialchars($passenger['name']); ?> 
                            (Seat <?php echo isset($passenger['seat_number']) ? htmlspecialchars($passenger['seat_number']) : ($index + 1); ?>)</span>
                        <span>LKR <?php echo number_format($booking_data['base_price']); ?></span>
                    </div>
                <?php endforeach; ?>
                
                <div class="total-amount">
                    Total: LKR <?php echo number_format($total_amount); ?>
                </div>
            </div>

            <!-- Payment Form -->
            <form method="POST" id="paymentForm">
                
                <!-- Pay Later Option -->
                <div class="payment-option" onclick="selectPaymentMethod('pay_later')">
                    <input type="radio" name="payment_method" value="pay_later" id="pay_later" style="margin-right: 1rem;">
                    <label for="pay_later" style="cursor: pointer; font-size: 1.1rem;">
                        <strong>💰 Pay Later</strong>
                        <p style="margin: 0.5rem 0; color: #666;">Book your seats now and pay at the bus station before departure.</p>
                        <small style="color: #e74c3c;">Note: Seats will be held for 2 hours. Payment required before travel.</small>
                    </label>
                </div>

                <!-- Pay Now Option -->
                <div class="payment-option" onclick="selectPaymentMethod('pay_now')">
                    <input type="radio" name="payment_method" value="pay_now" id="pay_now" style="margin-right: 1rem;">
                    <label for="pay_now" style="cursor: pointer; font-size: 1.1rem;">
                        <strong>💳 Pay Now</strong>
                        <p style="margin: 0.5rem 0; color: #666;">Complete payment now with your credit/debit card for confirmed booking.</p>
                        <small style="color: #27ae60;">Secure payment • Instant confirmation • No queues</small>
                    </label>
                </div>

                <!-- Card Details Form -->
                <div id="cardForm" class="card-form">
                    <h4 style="margin-bottom: 1rem;">💳 Card Details</h4>
                    
                    <div class="form_group">
                        <label for="cardholder_name">Cardholder Name: *</label>
                        <input type="text" id="cardholder_name" name="cardholder_name" class="form_control" placeholder="John Doe" required>
                    </div>
                    
                    <div class="form_group">
                        <label for="card_number">Card Number: *</label>
                        <input type="text" id="card_number" name="card_number" class="form_control card-number-input" 
                               placeholder="1234 5678 9012 3456" maxlength="19" required>
                    </div>
                    
                    <div class="card-input-group">
                        <div class="form_group">
                            <label for="expiry_month">Expiry Month: *</label>
                            <select id="expiry_month" name="expiry_month" class="form_control" required>
                                <option value="">Month</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo sprintf('%02d', $i); ?>"><?php echo sprintf('%02d', $i); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form_group">
                            <label for="expiry_year">Expiry Year: *</label>
                            <select id="expiry_year" name="expiry_year" class="form_control" required>
                                <option value="">Year</option>
                                <?php for ($i = date('Y'); $i <= date('Y') + 10; $i++): ?>
                                    <option value="<?php echo $i; ?>"><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form_group">
                            <label for="cvv">CVV: *</label>
                            <input type="text" id="cvv" name="cvv" class="form_control" placeholder="123" maxlength="4" required>
                        </div>
                    </div>
                    
                    <div class="secure-notice">
                        🔒 Your payment information is secure and encrypted. We do not store your card details.
                    </div>
                </div>

                <!-- Submit Buttons -->
                <div style="text-align: center; margin-top: 2rem;">
                    <button type="submit" id="submitBtn" class="btn btn_primary" style="padding: 1rem 2rem; font-size: 1.1rem;" disabled>
                        Complete Booking
                    </button>
                    <a href="search_buses.php" class="btn" style="margin-left: 1rem; background: #6c757d;">Cancel</a>
                </div>
            </form>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Secure payments, safe travels!</p>
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
                submitBtn.textContent = 'Pay LKR <?php echo number_format($total_amount); ?>';
                submitBtn.style.background = '#27ae60';
                
                // Make card fields required
                document.getElementById('cardholder_name').required = true;
                document.getElementById('card_number').required = true;
                document.getElementById('expiry_month').required = true;
                document.getElementById('expiry_year').required = true;
                document.getElementById('cvv').required = true;
            } else {
                cardForm.classList.remove('show');
                submitBtn.textContent = 'Book Now (Pay Later)';
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
        document.getElementById('card_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\s+/g, '').replace(/[^0-9]/gi, '');
            let formattedValue = value.match(/.{1,4}/g)?.join(' ') || value;
            e.target.value = formattedValue;
        });
        
        // CVV validation
        document.getElementById('cvv').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });
        
        // Form validation
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
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
            
            // Re-enable after 10 seconds in case of issues
            setTimeout(function() {
                submitBtn.disabled = false;
                if (paymentMethod.value === 'pay_now') {
                    submitBtn.textContent = 'Pay LKR <?php echo number_format($total_amount); ?>';
                } else {
                    submitBtn.textContent = 'Book Now (Pay Later)';
                }
            }, 10000);
        });
        
        // Add click handlers to payment options
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.payment-option').forEach(option => {
                option.addEventListener('click', function() {
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