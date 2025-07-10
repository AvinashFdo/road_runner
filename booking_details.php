<?php
// Booking Details Page
// Save this as: booking_details.php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$booking_reference = $_GET['ref'] ?? '';
$error = '';
$booking_details = null;

if (empty($booking_reference)) {
    header('Location: my_bookings.php');
    exit();
}

// Get booking details - Get ALL bookings for the same trip
try {
    // First, get the basic booking info
    $stmt = $pdo->prepare("
        SELECT 
            b.*, 
            s.departure_time, s.arrival_time, s.schedule_id, s.bus_id,
            bus.bus_name, bus.bus_number, bus.bus_type, bus.amenities,
            r.route_name, r.origin, r.destination, r.distance_km,
            seat.seat_number,
            u.full_name as operator_name, u.phone as operator_phone, u.email as operator_email
        FROM bookings b
        JOIN schedules s ON b.schedule_id = s.schedule_id
        JOIN buses bus ON s.bus_id = bus.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN seats seat ON b.seat_id = seat.seat_id
        JOIN users u ON bus.operator_id = u.user_id
        WHERE b.booking_reference = ? AND b.passenger_id = ?
    ");
    $stmt->execute([$booking_reference, $user_id]);
    $booking_details = $stmt->fetch();

    if (!$booking_details) {
        $error = "Booking not found or you don't have permission to view it.";
    } else {
        // Get ALL bookings made at the same time (within 5 minutes) for the same trip
        // This groups bookings that were made together in one booking session
        $stmt = $pdo->prepare("
            SELECT 
                b.*, 
                seat.seat_number
            FROM bookings b
            JOIN seats seat ON b.seat_id = seat.seat_id
            WHERE b.passenger_id = ? 
            AND b.schedule_id = ? 
            AND b.travel_date = ?
            AND ABS(TIMESTAMPDIFF(MINUTE, b.booking_date, ?)) <= 5
            ORDER BY b.booking_reference ASC
        ");
        $stmt->execute([
            $user_id,
            $booking_details['schedule_id'],
            $booking_details['travel_date'],
            $booking_details['booking_date']
        ]);
        $all_trip_bookings = $stmt->fetchAll();

        // Calculate total amount for the trip
        $total_trip_amount = array_sum(array_column($all_trip_bookings, 'total_amount'));
    }

} catch (PDOException $e) {
    $error = "Error retrieving booking details: " . $e->getMessage();
}

// Function to get horizontal seat number
function getHorizontalSeatNumber($seatNumber, $busId, $pdo)
{
    try {
        // If seat number is already a simple number, just return it
        if (is_numeric($seatNumber)) {
            return (int) $seatNumber;
        }

        // If it's in letter format (A1, B2, etc.), convert it
        if (preg_match('/^([A-Z])(\d+)$/', $seatNumber, $matches)) {
            $seatLetter = $matches[1];
            $seatRowNum = (int) $matches[2];

            // Get bus seat configuration
            $stmt = $pdo->prepare("SELECT seat_configuration FROM buses WHERE bus_id = ?");
            $stmt->execute([$busId]);
            $seatConfig = $stmt->fetch()['seat_configuration'] ?? '2x2';

            // Parse configuration
            $config = explode('x', $seatConfig);
            $leftSeats = (int) $config[0];
            $rightSeats = (int) $config[1];
            $seatsPerRow = $leftSeats + $rightSeats;

            $positionInRow = ord($seatLetter) - ord('A');
            $horizontalNumber = (($seatRowNum - 1) * $seatsPerRow) + $positionInRow + 1;

            return $horizontalNumber;
        }

        // If it's neither format, just return as-is
        return $seatNumber;

    } catch (PDOException $e) {
        return $seatNumber;
    }
}

// Function to get booking status display
function getBookingStatusInfo($status)
{
    switch ($status) {
        case 'pending':
            return ['text' => 'Pending Confirmation', 'class' => 'badge_operator', 'icon' => '⏳'];
        case 'confirmed':
            return ['text' => 'Confirmed', 'class' => 'badge_active', 'icon' => '✅'];
        case 'completed':
            return ['text' => 'Trip Completed', 'class' => 'badge_active', 'icon' => '🎯'];
        case 'cancelled':
            return ['text' => 'Cancelled', 'class' => 'badge_inactive', 'icon' => '❌'];
        case 'refunded':
            return ['text' => 'Cancelled', 'class' => 'badge_inactive', 'icon' => '❌'];
        default:
            return ['text' => ucfirst($status), 'class' => 'badge_operator', 'icon' => '📋'];
    }
}

// Generate booking receipt text
function generateBookingReceipt($booking)
{
    $content = "ROAD RUNNER - BUS BOOKING RECEIPT\n\n";
    $content .= "=====================================\n";
    $content .= "BOOKING INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Booking Reference: " . $booking['booking_reference'] . "\n";
    $content .= "Booking Date: " . date('F j, Y \a\t g:i A', strtotime($booking['booking_date'])) . "\n";
    $content .= "Status: " . ucfirst($booking['booking_status']) . "\n";
    $content .= "Payment Status: " . ucfirst($booking['payment_status']) . "\n";

    $content .= "\n=====================================\n";
    $content .= "TRIP DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Route: " . $booking['route_name'] . "\n";
    $content .= "From: " . $booking['origin'] . "\n";
    $content .= "To: " . $booking['destination'] . "\n";
    $content .= "Travel Date: " . date('F j, Y', strtotime($booking['travel_date'])) . "\n";
    $content .= "Departure Time: " . date('g:i A', strtotime($booking['departure_time'])) . "\n";
    if ($booking['arrival_time']) {
        $content .= "Arrival Time: " . date('g:i A', strtotime($booking['arrival_time'])) . "\n";
    }
    $content .= "Distance: " . $booking['distance_km'] . " km\n";

    $content .= "\n=====================================\n";
    $content .= "BUS INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Bus Name: " . $booking['bus_name'] . "\n";
    $content .= "Bus Number: " . $booking['bus_number'] . "\n";
    $content .= "Bus Type: " . $booking['bus_type'] . "\n";
    if ($booking['amenities']) {
        $content .= "Amenities: " . $booking['amenities'] . "\n";
    }

    $content .= "\n=====================================\n";
    $content .= "PASSENGER DETAILS\n";
    $content .= "=====================================\n";
    $content .= "Name: " . $booking['passenger_name'] . "\n";
    $content .= "Gender: " . ucfirst($booking['passenger_gender']) . "\n";
    $content .= "Seat Number: " . $booking['seat_number'] . "\n";

    $content .= "\n=====================================\n";
    $content .= "OPERATOR CONTACT\n";
    $content .= "=====================================\n";
    $content .= "Operator: " . $booking['operator_name'] . "\n";
    $content .= "Phone: " . $booking['operator_phone'] . "\n";
    $content .= "Email: " . $booking['operator_email'] . "\n";

    $content .= "\n=====================================\n";
    $content .= "PAYMENT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Total Amount: LKR " . number_format($booking['total_amount'], 2) . "\n";
    $content .= "Payment Status: " . ucfirst($booking['payment_status']) . "\n";

    $content .= "\n=====================================\n";
    $content .= "IMPORTANT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "• Arrive at departure location 15 minutes early\n";
    $content .= "• Bring a valid ID for verification\n";
    $content .= "• Contact operator for any schedule changes\n";
    $content .= "• Cancellation allowed up to 2 hours before departure\n";
    $content .= "• Keep this receipt for your records\n";

    $content .= "\n=====================================\n";
    $content .= "CONTACT INFORMATION\n";
    $content .= "=====================================\n";
    $content .= "Road Runner Customer Support\n";
    $content .= "Phone: +94 11 123 4567\n";
    $content .= "Email: support@roadrunner.lk\n";
    $content .= "Website: www.roadrunner.lk\n";

    $content .= "\n=====================================\n";
    $content .= "Thank you for choosing Road Runner!\n";
    $content .= "Have a safe journey!\n";
    $content .= "=====================================\n";

    return $content;
}

// Handle receipt download
if (isset($_GET['download']) && $_GET['download'] === 'receipt' && $booking_details) {
    $filename = 'RoadRunner_Booking_' . $booking_reference . '_' . date('Ymd_His') . '.txt';
    $content = generateBookingReceipt($booking_details);

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
    <title>Booking Details - Road Runner</title>
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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
            <h2>📋 Booking Details</h2>
            <a href="my_bookings.php" class="btn" style="background: #6c757d;">← Back to Bookings</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert_error">
                <?php echo htmlspecialchars($error); ?>
                <p style="margin-top: 1rem;">
                    <a href="my_bookings.php" class="btn btn_primary">← Back to My Bookings</a>
                </p>
            </div>
        <?php else: ?>
            <?php $status_info = getBookingStatusInfo($booking_details['booking_status']); ?>

            <!-- Booking Status Banner -->
            <div
                class="alert alert_<?php echo $booking_details['booking_status'] === 'confirmed' || $booking_details['booking_status'] === 'completed' ? 'success' : ($booking_details['booking_status'] === 'cancelled' ? 'error' : 'info'); ?> mb_2">
                <h3 style="margin-bottom: 0.5rem;">
                    <?php echo $status_info['icon']; ?>     <?php echo $status_info['text']; ?>
                </h3>
                <p style="font-size: 1.1rem; margin: 0;">
                    <?php
                    switch ($booking_details['booking_status']) {
                        case 'confirmed':
                            echo 'Your booking is confirmed. Have a safe journey!';
                            break;
                        case 'pending':
                            echo 'Your booking is pending confirmation from the operator.';
                            break;
                        case 'completed':
                            echo 'Your trip has been completed. Thank you for traveling with us!';
                            break;
                        case 'cancelled':
                        case 'refunded':
                            echo 'This booking has been cancelled. Contact support for assistance.';
                            break;
                        default:
                            echo 'Booking Reference: ' . htmlspecialchars($booking_reference);
                    }
                    ?>
                </p>
            </div>

            <!-- Booking Information -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">🎫 Booking Information</h3>
                <div class="p_2">
                    <div style="text-align: center; margin-bottom: 2rem;">
                        <div
                            style="background: #2c3e50; color: white; padding: 1rem; border-radius: 8px; margin-bottom: 1rem;">
                            <h3 style="margin-bottom: 0.5rem;">Booking Reference</h3>
                            <div style="font-size: 2rem; font-weight: bold; letter-spacing: 2px; font-family: monospace;">
                                <?php echo htmlspecialchars($booking_details['booking_reference']); ?>
                            </div>
                            <p style="margin-top: 0.5rem; font-size: 0.9rem; opacity: 0.8;">
                                Keep this reference for your records
                            </p>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                            <div>
                                <strong>📅 Booking Date:</strong><br>
                                <?php echo date('F j, Y \a\t g:i A', strtotime($booking_details['booking_date'])); ?>
                            </div>
                            <div>
                                <strong>🚌 Travel Date:</strong><br>
                                <?php echo date('F j, Y', strtotime($booking_details['travel_date'])); ?>
                            </div>
                            <div>
                                <strong>📊 Status:</strong><br>
                                <span class="badge <?php echo $status_info['class']; ?>" style="font-size: 0.9rem;">
                                    <?php echo $status_info['text']; ?>
                                </span>
                            </div>
                            <div>
                                <strong>💳 Payment:</strong><br>
                                <span
                                    class="badge <?php echo $booking_details['payment_status'] === 'paid' ? 'badge_active' : 'badge_operator'; ?>"
                                    style="font-size: 0.9rem;">
                                    <?php echo ucfirst($booking_details['payment_status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trip Details -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">🗺️ Trip Details</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route Information</h4>
                            <p><strong>Route:</strong> <?php echo htmlspecialchars($booking_details['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($booking_details['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($booking_details['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo htmlspecialchars($booking_details['distance_km']); ?>
                                km</p>
                        </div>

                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Schedule</h4>
                            <p><strong>Departure:</strong>
                                <?php echo date('g:i A', strtotime($booking_details['departure_time'])); ?></p>
                            <?php if ($booking_details['arrival_time']): ?>
                                <p><strong>Arrival:</strong>
                                    <?php echo date('g:i A', strtotime($booking_details['arrival_time'])); ?></p>
                            <?php endif; ?>
                            <p><strong>Travel Date:</strong>
                                <?php echo date('D, M j, Y', strtotime($booking_details['travel_date'])); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Passenger Information -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">

                <!-- Bus Information -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">🚌 Bus Information</h3>
                    <div class="p_2">
                        <p><strong>Bus Name:</strong> <?php echo htmlspecialchars($booking_details['bus_name']); ?></p>
                        <p><strong>Bus Number:</strong> <?php echo htmlspecialchars($booking_details['bus_number']); ?></p>
                        <p><strong>Bus Type:</strong> <?php echo htmlspecialchars($booking_details['bus_type']); ?></p>
                        <?php if ($booking_details['amenities']): ?>
                            <p><strong>Amenities:</strong> <?php echo htmlspecialchars($booking_details['amenities']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Trip Summary -->
                <div class="table_container">
                    <h3 class="p_1 mb_1">🎫 Trip Summary</h3>
                    <div class="p_2">
                        <p><strong>Total Passengers:</strong> <?php echo count($all_trip_bookings); ?></p>
                        <p><strong>Seats:</strong>
                            <?php
                            $seats = [];
                            foreach ($all_trip_bookings as $trip_booking) {
                                $seats[] = $trip_booking['seat_number'];
                            }
                            echo implode(', ', $seats);
                            ?>
                        </p>
                        <p><strong>Total Amount:</strong>
                            <span style="font-size: 1.2rem; color: #2c3e50; font-weight: bold;">
                                LKR <?php echo number_format($total_trip_amount); ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- All Passengers/Bookings -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">👥 Passenger Details (<?php echo count($all_trip_bookings); ?> passengers)</h3>
                <div class="p_2">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Booking Reference</th>
                                <th>Passenger Name</th>
                                <th>Gender</th>
                                <th>Seat Number</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_trip_bookings as $trip_booking): ?>
                                <?php $status = getBookingStatusInfo($trip_booking['booking_status']); ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($trip_booking['booking_reference']); ?></strong>
                                    </td>
                                    <td><?php echo htmlspecialchars($trip_booking['passenger_name']); ?></td>
                                    <td>
                                        <span class="badge"
                                            style="background: <?php echo $trip_booking['passenger_gender'] === 'male' ? '#007bff' : '#dc3545'; ?>; color: white;">
                                            <?php echo ucfirst($trip_booking['passenger_gender']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span
                                            style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: bold;">
                                            <?php echo htmlspecialchars($trip_booking['seat_number']); ?>
                                        </span>
                                    </td>
                                    <td><strong>LKR <?php echo number_format($trip_booking['total_amount']); ?></strong></td>
                                    <td>
                                        <span class="badge <?php echo $status['class']; ?>">
                                            <?php echo $status['text']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Operator Contact -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">📞 Operator Contact</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div>
                            <strong>Operator:</strong><br>
                            <?php echo htmlspecialchars($booking_details['operator_name']); ?>
                        </div>
                        <div>
                            <strong>Phone:</strong><br>
                            <a href="tel:<?php echo htmlspecialchars($booking_details['operator_phone']); ?>">
                                <?php echo htmlspecialchars($booking_details['operator_phone']); ?>
                            </a>
                        </div>
                        <div>
                            <strong>Email:</strong><br>
                            <a href="mailto:<?php echo htmlspecialchars($booking_details['operator_email']); ?>">
                                <?php echo htmlspecialchars($booking_details['operator_email']); ?>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Information -->
            <div class="table_container mb_2">
                <h3 class="p_1 mb_1">💳 Payment Details</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: 1fr auto; gap: 2rem; align-items: center;">
                        <div>
                            <p><strong>Trip Total Amount:</strong>
                                <span style="font-size: 1.3rem; color: #2c3e50; font-weight: bold;">
                                    LKR <?php echo number_format($total_trip_amount); ?>
                                </span>
                                <small style="display: block; color: #666; margin-top: 0.25rem;">
                                    (<?php echo count($all_trip_bookings); ?>
                                    passenger<?php echo count($all_trip_bookings) > 1 ? 's' : ''; ?> × LKR
                                    <?php echo number_format($booking_details['total_amount']); ?> each)
                                </small>
                            </p>
                            <p><strong>Payment Status:</strong>
                                <span
                                    class="badge <?php echo $booking_details['payment_status'] === 'paid' ? 'badge_active' : 'badge_operator'; ?>">
                                    <?php echo ucfirst($booking_details['payment_status']); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0;">
                <a href="?ref=<?php echo urlencode($booking_reference); ?>&download=receipt" class="btn btn_primary"
                    style="text-align: center;">
                    📄 Download Receipt
                </a>
                <button onclick="window.print()" class="btn btn_success" style="text-align: center;">
                    🖨️ Print Details
                </button>
                <a href="my_bookings.php" class="btn" style="text-align: center; background: #6c757d;">
                    📋 My Bookings
                </a>
                <a href="search_buses.php" class="btn" style="text-align: center; background: #34495e;">
                    🔍 Book Another Trip
                </a>
            </div>



        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your journey, our priority!</p>
        </div>
    </footer>

    <script>
        // Copy booking reference to clipboard
        document.addEventListener('DOMContentLoaded', function () {
            const bookingRef = document.querySelector('div[style*="font-family: monospace"]');
            if (bookingRef) {
                bookingRef.style.cursor = 'pointer';
                bookingRef.title = 'Click to copy booking reference';
                bookingRef.addEventListener('click', function () {
                    navigator.clipboard.writeText(this.textContent.trim()).then(function () {
                        alert('Booking reference copied to clipboard!');
                    });
                });
            }
        });
    </script>

    <style>
        /* Print styles */
        @media print {

            .header,
            .footer,
            .btn,
            button,
            .alert:not(.alert_success):not(.alert_info) {
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

            body {
                font-size: 12pt !important;
                line-height: 1.4 !important;
            }

            h2,
            h3,
            h4 {
                color: #000 !important;
            }
        }
    </style>
</body>

</html>