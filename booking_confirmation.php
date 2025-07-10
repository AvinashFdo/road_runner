<?php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get booking references
$booking_refs = $_GET['booking_refs'] ?? '';
if (empty($booking_refs)) {
    header('Location: search_buses.php');
    exit();
}

$booking_references = explode(',', $booking_refs);
$bookings = [];
$total_amount = 0;
$trip_info = null;

try {
    // Get all booking details
    foreach ($booking_references as $booking_ref) {
        $stmt = $pdo->prepare("
            SELECT 
                b.booking_id, b.booking_reference, b.passenger_name, b.passenger_gender, 
                b.travel_date, b.total_amount, b.booking_status, b.payment_status, b.booking_date,
                s.departure_time, s.arrival_time,
                bus.bus_name, bus.bus_number, bus.bus_type, bus.amenities,
                r.route_name, r.origin, r.destination, r.distance_km,
                seat.seat_number, seat.seat_type,
                u.full_name as operator_name, u.phone as operator_phone
            FROM bookings b
            JOIN schedules s ON b.schedule_id = s.schedule_id
            JOIN buses bus ON s.bus_id = bus.bus_id
            JOIN routes r ON s.route_id = r.route_id
            JOIN seats seat ON b.seat_id = seat.seat_id
            JOIN users u ON bus.operator_id = u.user_id
            WHERE b.booking_reference = ? AND b.passenger_id = ?
        ");
        $stmt->execute([$booking_ref, $_SESSION['user_id']]);
        $booking = $stmt->fetch();

        if ($booking) {
            $bookings[] = $booking;
            $total_amount += $booking['total_amount'];

            if (!$trip_info) {
                $trip_info = $booking;
            }
        }
    }

    if (empty($bookings)) {
        header('Location: search_buses.php');
        exit();
    }

} catch (PDOException $e) {
    $error = "Error loading booking details: " . $e->getMessage();
}

// Generate ticket content for download
function generateTicketContent($bookings, $trip_info)
{
    $content = "ROAD RUNNER - BUS TICKETS\n\n";
    $content .= "========================================\n";
    $content .= "TRIP INFORMATION\n";
    $content .= "========================================\n";
    $content .= "Route: " . $trip_info['route_name'] . "\n";
    $content .= "From: " . $trip_info['origin'] . "\n";
    $content .= "To: " . $trip_info['destination'] . "\n";
    $content .= "Date: " . date('D, M j, Y', strtotime($trip_info['travel_date'])) . "\n";
    $content .= "Departure: " . date('g:i A', strtotime($trip_info['departure_time'])) . "\n";

    if ($trip_info['arrival_time']) {
        $content .= "Arrival: " . date('g:i A', strtotime($trip_info['arrival_time'])) . "\n";
    }

    $content .= "\n========================================\n";
    $content .= "BUS DETAILS\n";
    $content .= "========================================\n";
    $content .= "Bus: " . $trip_info['bus_name'] . " (" . $trip_info['bus_number'] . ")\n";
    $content .= "Type: " . $trip_info['bus_type'] . "\n";
    $content .= "Operator: " . $trip_info['operator_name'] . "\n";
    $content .= "Contact: " . $trip_info['operator_phone'] . "\n";

    if ($trip_info['amenities']) {
        $content .= "Amenities: " . $trip_info['amenities'] . "\n";
    }

    $content .= "\n========================================\n";
    $content .= "PASSENGERS & SEATS\n";
    $content .= "========================================\n";

    $total = 0;
    foreach ($bookings as $index => $booking) {
        $content .= "\nPassenger " . ($index + 1) . ":\n";
        $content .= "  Name: " . $booking['passenger_name'] . "\n";
        $content .= "  Seat: " . $booking['seat_number'] . "\n";
        $content .= "  Gender: " . ucfirst($booking['passenger_gender']) . "\n";
        $content .= "  Booking ID: " . $booking['booking_reference'] . "\n";
        $content .= "  Amount: LKR " . number_format($booking['total_amount']) . "\n";
        $total += $booking['total_amount'];
    }

    $content .= "\n========================================\n";
    $content .= "PAYMENT SUMMARY\n";
    $content .= "========================================\n";
    $content .= "Total Passengers: " . count($bookings) . "\n";
    $content .= "Total Amount: LKR " . number_format($total) . "\n";
    $content .= "Payment Status: " . ucfirst($bookings[0]['payment_status']) . "\n";
    $content .= "Booking Date: " . date('M j, Y g:i A', strtotime($bookings[0]['booking_date'])) . "\n";

    $content .= "\n========================================\n";
    $content .= "IMPORTANT NOTES\n";
    $content .= "========================================\n";
    $content .= "• Arrive 15 minutes before departure\n";
    $content .= "• Bring valid photo ID\n";
    $content .= "• Keep booking references safe\n";
    $content .= "• Contact operator for any changes\n";
    $content .= "\nThank you for choosing Road Runner!\n";
    $content .= "========================================\n";

    return $content;
}

// Handle download
if (isset($_GET['download']) && $_GET['download'] === 'ticket') {
    $filename = 'RoadRunner_Tickets_' . date('Ymd_His') . '.txt';
    $content = generateTicketContent($bookings, $trip_info);

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
    <title>Booking Confirmed - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner</div>
                <ul class="nav_links">
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="dashboard.php">My Dashboard</a></li>
                    <li><a href="../search_buses.php">Search Buses</a></li>
                    <li><a href="send_parcel.php">Parcel</a></li>
                    <li><a href="../my_bookings.php">My Bookings</a></li>
                    <li><a href="../my_parcels.php">My Parcels</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if (isset($error)): ?>
            <div class="alert alert_error"><?php echo htmlspecialchars($error); ?></div>
        <?php else: ?>

            <!-- Success Message -->
            <div class="alert alert_success mb_2">
                <h2>🎉 Booking Confirmed!</h2>
                <p>Your bus tickets have been successfully booked. Details below:</p>
            </div>

            <!-- Trip Information -->
            <div class="table_container mb_2">
                <h3 class="p_1">Trip Information</h3>
                <div class="p_2">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 2rem;">
                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Route</h4>
                            <p><strong>Route:</strong> <?php echo htmlspecialchars($trip_info['route_name']); ?></p>
                            <p><strong>From:</strong> <?php echo htmlspecialchars($trip_info['origin']); ?></p>
                            <p><strong>To:</strong> <?php echo htmlspecialchars($trip_info['destination']); ?></p>
                            <p><strong>Distance:</strong> <?php echo $trip_info['distance_km']; ?> km</p>
                        </div>

                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Schedule</h4>
                            <p><strong>Date:</strong> <?php echo date('D, M j, Y', strtotime($trip_info['travel_date'])); ?>
                            </p>
                            <p><strong>Departure:</strong>
                                <?php echo date('g:i A', strtotime($trip_info['departure_time'])); ?></p>
                            <?php if ($trip_info['arrival_time']): ?>
                                <p><strong>Arrival:</strong> <?php echo date('g:i A', strtotime($trip_info['arrival_time'])); ?>
                                </p>
                            <?php endif; ?>
                            <p><strong>Booked:</strong>
                                <?php echo date('M j, Y g:i A', strtotime($trip_info['booking_date'])); ?></p>
                        </div>

                        <div>
                            <h4 style="color: #2c3e50; margin-bottom: 1rem;">Bus</h4>
                            <p><strong>Bus:</strong> <?php echo htmlspecialchars($trip_info['bus_name']); ?></p>
                            <p><strong>Number:</strong> <?php echo htmlspecialchars($trip_info['bus_number']); ?></p>
                            <p><strong>Type:</strong> <?php echo $trip_info['bus_type']; ?></p>
                            <p><strong>Operator:</strong> <?php echo htmlspecialchars($trip_info['operator_name']); ?></p>
                            <p><strong>Contact:</strong> <?php echo htmlspecialchars($trip_info['operator_phone']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Passenger Details -->
            <div class="table_container mb_2">
                <h3 class="p_1">Passengers (<?php echo count($bookings); ?>)</h3>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Passenger</th>
                            <th>Seat</th>
                            <th>Gender</th>
                            <th>Booking ID</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($booking['passenger_name']); ?></strong></td>
                                <td>
                                    <span
                                        style="background: #4caf50; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-weight: bold;">
                                        <?php echo htmlspecialchars($booking['seat_number']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge_<?php echo $booking['passenger_gender']; ?>">
                                        <?php echo ucfirst($booking['passenger_gender']); ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="background: #f5f5f5; padding: 0.25rem 0.5rem; border-radius: 4px;">
                                                <?php echo $booking['booking_reference']; ?>
                                            </code>
                                </td>
                                <td><strong>LKR <?php echo number_format($booking['total_amount']); ?></strong></td>
                                <td>
                                    <span class="badge badge_active">
                                        <?php echo ucfirst($booking['booking_status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Payment Summary -->
            <div class="table_container mb_2">
                <h3 class="p_1">Payment Summary</h3>
                <div class="p_2">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <span>Number of passengers:</span>
                        <strong><?php echo count($bookings); ?></strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                        <span>Price per seat:</span>
                        <strong>LKR <?php echo number_format($bookings[0]['total_amount']); ?></strong>
                    </div>
                    <div style="border-top: 2px solid #eee; padding-top: 1rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 1.2rem;">
                            <span><strong>Total Amount:</strong></span>
                            <span style="color: #e74c3c; font-weight: bold; font-size: 1.4rem;">
                                LKR <?php echo number_format($total_amount); ?>
                            </span>
                        </div>
                        <p style="color: #666; margin-top: 0.5rem;">
                            Payment Status: <span
                                class="badge badge_operator"><?php echo ucfirst($bookings[0]['payment_status']); ?></span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div
                style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0;">
                <a href="?booking_refs=<?php echo urlencode(implode(',', $booking_references)); ?>&download=ticket"
                    class="btn btn_primary" style="text-align: center;">
                    📄 Download Tickets
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

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Thank you for your booking!</p>
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