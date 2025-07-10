<?php

session_start();
require_once 'db_connection.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get parameters
$schedule_id = $_GET['schedule_id'] ?? '';
$travel_date = $_GET['travel_date'] ?? '';

if (empty($schedule_id) || empty($travel_date)) {
    header('Location: search_buses.php');
    exit();
}

// Validate date
if (strtotime($travel_date) < strtotime('today')) {
    header('Location: search_buses.php');
    exit();
}

$error = '';
$bus_info = null;
$seats = [];

try {
    // Get trip information
    $stmt = $pdo->prepare("
        SELECT 
            s.schedule_id, s.departure_time, s.arrival_time, s.base_price,
            b.bus_id, b.bus_name, b.bus_number, b.bus_type, b.total_seats, b.seat_configuration, b.amenities,
            r.route_name, r.origin, r.destination, r.distance_km,
            u.full_name as operator_name
        FROM schedules s
        JOIN buses b ON s.bus_id = b.bus_id
        JOIN routes r ON s.route_id = r.route_id
        JOIN users u ON b.operator_id = u.user_id
        WHERE s.schedule_id = ? AND s.status = 'active'
    ");
    $stmt->execute([$schedule_id]);
    $bus_info = $stmt->fetch();
    
    if (!$bus_info) {
        header('Location: search_buses.php');
        exit();
    }
    
    // Get all seats with booking status
    $stmt = $pdo->prepare("
        SELECT 
            s.seat_id, s.seat_number, s.seat_type,
            b.booking_id, b.passenger_gender, b.booking_status
        FROM seats s
        LEFT JOIN bookings b ON s.seat_id = b.seat_id 
            AND b.travel_date = ? 
            AND b.booking_status IN ('pending', 'confirmed')
        WHERE s.bus_id = ?
        ORDER BY CAST(s.seat_number AS UNSIGNED) ASC
    ");
    $stmt->execute([$travel_date, $bus_info['bus_id']]);
    $seats = $stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error loading seat information: " . $e->getMessage();
}

// Handle booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_seats'])) {
    $passengers = $_POST['passengers'] ?? [];
    
    if (empty($passengers)) {
        $error = "Please add passengers and select seats.";
    } else {
        $valid = true;
        $selected_seats = [];
        
        // Get user phone
        $stmt = $pdo->prepare("SELECT phone FROM users WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_phone = $stmt->fetch()['phone'] ?? '';
        
        // Validate passengers
        foreach ($passengers as $index => $passenger) {
            $seat_id = $passenger['seat_id'] ?? '';
            $name = trim($passenger['name'] ?? '');
            $gender = $passenger['gender'] ?? '';
            
            if (empty($seat_id) || empty($name) || empty($gender)) {
                $error = "Please fill all details for passenger " . ($index + 1);
                $valid = false;
                break;
            }
            
            if (!preg_match('/^[a-zA-Z\s\.\-\']+$/', $name)) {
                $error = "Invalid name for passenger " . ($index + 1);
                $valid = false;
                break;
            }
            
            if (!in_array($gender, ['male', 'female'])) {
                $error = "Invalid gender for passenger " . ($index + 1);
                $valid = false;
                break;
            }
            
            if (in_array($seat_id, $selected_seats)) {
                $error = "Cannot select same seat for multiple passengers.";
                $valid = false;
                break;
            }
            
            $selected_seats[] = $seat_id;
        }
        
        if ($valid) {
            try {
                // Check seat availability one more time
                foreach ($selected_seats as $seat_id) {
                    $stmt = $pdo->prepare("SELECT booking_id FROM bookings WHERE seat_id = ? AND travel_date = ? AND booking_status IN ('pending', 'confirmed')");
                    $stmt->execute([$seat_id, $travel_date]);
                    if ($stmt->fetch()) {
                        throw new Exception("One or more seats have been booked. Please refresh and try again.");
                    }
                }
                
                // Get seat numbers for the selected seats
                foreach ($passengers as &$passenger) {
                    $stmt = $pdo->prepare("SELECT seat_number FROM seats WHERE seat_id = ?");
                    $stmt->execute([$passenger['seat_id']]);
                    $seat_info = $stmt->fetch();
                    $passenger['seat_number'] = $seat_info ? $seat_info['seat_number'] : 'Unknown';
                }
                
                // Store booking data in session for payment processing
                $_SESSION['pending_booking'] = [
                    'schedule_id' => $schedule_id,
                    'travel_date' => $travel_date,
                    'passengers' => $passengers,
                    'user_phone' => $user_phone,
                    'base_price' => $bus_info['base_price'],
                    'bus_name' => $bus_info['bus_name'],
                    'bus_number' => $bus_info['bus_number'],
                    'route_name' => $bus_info['route_name'],
                    'origin' => $bus_info['origin'],
                    'destination' => $bus_info['destination']
                ];
                
                // Redirect to payment page
                header('Location: payment.php');
                exit();
                
            } catch (Exception $e) {
                $error = "Validation failed: " . $e->getMessage();
            }
        }
    }
}


// Calculate seat layout
$config = explode('x', $bus_info['seat_configuration'] ?? '2x2');
$left_seats = (int)$config[0];
$right_seats = (int)$config[1];
$seats_per_row = $left_seats + $right_seats;
$total_rows = ceil(count($seats) / $seats_per_row);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Seats - Road Runner</title>
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
                    <li><a href="search_buses.php">Search Buses</a></li>
                    <li><a href="send_parcel.php">Parcel</a></li>
                    <li><a href="my_bookings.php">My Bookings</a></li>
                    <li><a href="my_parcels.php">My Parcels</a></li>
                    <li><a href="logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
        <?php if ($error): ?>
            <div class="alert alert_error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($bus_info): ?>
        <!-- Trip Info -->
        <div class="alert alert_info">
            <h3><?php echo htmlspecialchars($bus_info['route_name']); ?> - <?php echo date('D, M j, Y', strtotime($travel_date)); ?></h3>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>Bus:</strong> <?php echo htmlspecialchars($bus_info['bus_name']); ?> (<?php echo htmlspecialchars($bus_info['bus_number']); ?>)<br>
                    <strong>Type:</strong> <?php echo htmlspecialchars($bus_info['bus_type']); ?>
                </div>
                <div>
                    <strong>Route:</strong> <?php echo htmlspecialchars($bus_info['origin']); ?> → <?php echo htmlspecialchars($bus_info['destination']); ?><br>
                    <strong>Operator:</strong> <?php echo htmlspecialchars($bus_info['operator_name']); ?>
                </div>
                <div>
                    <strong>Departure:</strong> <?php echo date('g:i A', strtotime($bus_info['departure_time'])); ?><br>
                    <strong>Price:</strong> LKR <?php echo number_format($bus_info['base_price']); ?> per seat
                </div>
            </div>
        </div>

        <!-- Seat Selection -->
        <div class="seat_selection_container">
            <!-- Bus Layout -->
            <div>
                <div class="bus_layout">
                    <div class="bus_header">
                        <h3>Select Your Seats</h3>
                        <p style="color: #666; margin: 0;">Total Seats: <?php echo count($seats); ?></p>
                    </div>
                    
                    <div class="driver_area">🚗 Driver</div>
                    
                    <div id="seat_map">
                        <?php for ($row = 1; $row <= $total_rows; $row++): ?>
                            <div class="seat_row">
                                <!-- Left seats -->
                                <div class="seat_group_left">
                                    <?php for ($left_pos = 1; $left_pos <= $left_seats; $left_pos++): ?>
                                        <?php 
                                        $seat_index = (($row - 1) * $seats_per_row) + $left_pos - 1;
                                        if (isset($seats[$seat_index])):
                                            $seat = $seats[$seat_index];
                                        ?>
                                            <div 
                                                class="seat <?php echo $seat['booking_id'] ? 'booked' : 'available'; ?> <?php echo $seat['booking_id'] ? strtolower($seat['passenger_gender'] ?? 'neutral') : 'neutral'; ?>"
                                                data-seat-id="<?php echo $seat['seat_id']; ?>"
                                                data-seat-number="<?php echo htmlspecialchars($seat['seat_number']); ?>"
                                                <?php echo $seat['booking_id'] ? 'title="Seat taken"' : 'onclick="selectSeat(this)" title="Click to select seat ' . $seat['seat_number'] . '"'; ?>
                                            >
                                                <?php echo $seat['seat_number']; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                
                                <!-- Aisle -->
                                <div class="aisle">AISLE</div>
                                
                                <!-- Right seats -->
                                <div class="seat_group_right">
                                    <?php for ($right_pos = 1; $right_pos <= $right_seats; $right_pos++): ?>
                                        <?php 
                                        $seat_index = (($row - 1) * $seats_per_row) + $left_seats + $right_pos - 1;
                                        if (isset($seats[$seat_index])):
                                            $seat = $seats[$seat_index];
                                        ?>
                                            <div 
                                                class="seat <?php echo $seat['booking_id'] ? 'booked' : 'available'; ?> <?php echo $seat['booking_id'] ? strtolower($seat['passenger_gender'] ?? 'neutral') : 'neutral'; ?>"
                                                data-seat-id="<?php echo $seat['seat_id']; ?>"
                                                data-seat-number="<?php echo htmlspecialchars($seat['seat_number']); ?>"
                                                <?php echo $seat['booking_id'] ? 'title="Seat taken"' : 'onclick="selectSeat(this)" title="Click to select seat ' . $seat['seat_number'] . '"'; ?>
                                            >
                                                <?php echo $seat['seat_number']; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    
                    <!-- Legend -->
                    <div class="seat_legend">
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #f5f5f5; border-color: #999;"></div>
                            <span>Available</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #1976d2; border-color: #1976d2;"></div>
                            <span>Male Passenger</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #c2185b; border-color: #c2185b;"></div>
                            <span>Female Passenger</span>
                        </div>
                        <div class="legend_item">
                            <div class="legend_seat" style="background: #4caf50; border-color: #4caf50;"></div>
                            <span>Selected</span>
                        </div>
                    </div>

                    <div class="alert alert_info" style="margin: 2rem 0;">
                        <h4>💳 Payment Options Available</h4>
                        <p>After selecting your seats, you can choose to:</p>
                        <ul style="margin: 0.5rem 0; padding-left: 2rem;">
                            <li><strong>Pay Now:</strong> Complete payment online with your card for instant confirmation</li>
                            <li><strong>Pay Later:</strong> Reserve seats and pay at the bus station (seats held for 2 hours)</li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- Booking Form -->
            <div class="booking_form">
                <h3>Passenger Details</h3>
                
                <button type="button" class="add_passenger_btn" onclick="addPassenger()">+ Add Passenger</button>
                
                <form method="POST" id="booking_form">
                    <div id="passengers_container" class="passengers_container">
                        <!-- Passengers will be added here -->
                    </div>
                    
                    <div style="border-top: 1px solid #eee; padding-top: 1rem; margin-top: 1rem;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                            <span>Price per seat:</span>
                            <span>LKR <?php echo number_format($bus_info['base_price']); ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem;">
                            <span>Passengers:</span>
                            <span id="passenger_count">0</span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 1.1rem; font-weight: bold;">
                            <span>Total Amount:</span>
                            <span style="color: #e74c3c;" id="total_amount">LKR 0</span>
                        </div>
                    </div>
                    
                    <button type="submit" name="book_seats" class="btn btn_primary" style="width: 100%; margin-top: 1rem;" disabled id="book_button">
                        Add Passengers First
                    </button>
                </form>
                
                <p style="text-align: center; margin-top: 1rem;">
                    <a href="search_buses.php">← Back to Search</a>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Simple seat selection!</p>
        </div>
    </footer>

    <script>
        // Simple seat selection JavaScript
        let selectedSeats = [];
        let passengers = [];
        let passengerCounter = 0;
        const basePrice = <?php echo $bus_info['base_price'] ?? 0; ?>;
        
        function selectSeat(seatElement) {
            const seatId = seatElement.getAttribute('data-seat-id');
            const seatNumber = seatElement.getAttribute('data-seat-number');
            
            if (seatElement.classList.contains('selected')) {
                // Deselect
                seatElement.classList.remove('selected');
                selectedSeats = selectedSeats.filter(s => s.id !== seatId);
                
                // Remove from passenger
                passengers.forEach(p => {
                    if (p.seatId === seatId) {
                        p.seatId = null;
                        p.seatNumber = null;
                        updatePassengerDisplay(p.id);
                    }
                });
            } else {
                // Find passenger without seat
                const passenger = passengers.find(p => !p.seatId);
                if (!passenger) {
                    alert('Add more passengers or deselect a seat first.');
                    return;
                }
                
                // Select
                seatElement.classList.add('selected');
                selectedSeats.push({id: seatId, number: seatNumber});
                
                // Assign to passenger
                passenger.seatId = seatId;
                passenger.seatNumber = seatNumber;
                updatePassengerDisplay(passenger.id);
            }
            
            updateBookingButton();
        }
        
        function addPassenger() {
            passengerCounter++;
            const passengerId = 'passenger_' + passengerCounter;
            
            const passenger = {
                id: passengerId,
                name: passengerCounter === 1 ? '<?php echo htmlspecialchars($_SESSION['user_name']); ?>' : '',
                gender: '',
                seatId: null,
                seatNumber: null
            };
            
            passengers.push(passenger);
            
            const html = `
                <div class="passenger_form" id="${passengerId}">
                    <div class="passenger_header">
                        <span class="passenger_number">Passenger ${passengers.length}</span>
                        <span class="seat_status"></span>
                        ${passengers.length > 1 ? `<button type="button" class="remove_passenger" onclick="removePassenger('${passengerId}')">&times;</button>` : ''}
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                        <input type="text" name="passengers[${passengers.length - 1}][name]" placeholder="Full Name" 
                               class="form_control" value="${passenger.name}" required 
                               onchange="updatePassengerName('${passengerId}', this.value)">
                        <select name="passengers[${passengers.length - 1}][gender]" class="form_control" required 
                                onchange="updatePassengerGender('${passengerId}', this.value)">
                            <option value="">Select Gender</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                        </select>
                    </div>
                    
                    <input type="hidden" name="passengers[${passengers.length - 1}][seat_id]" value="">
                </div>
            `;
            
            document.getElementById('passengers_container').insertAdjacentHTML('beforeend', html);
            updateCounts();
            updateBookingButton();
        }
        
        function removePassenger(passengerId) {
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger && passenger.seatId) {
                // Deselect seat
                const seatElement = document.querySelector(`[data-seat-id="${passenger.seatId}"]`);
                if (seatElement) {
                    seatElement.classList.remove('selected');
                }
                selectedSeats = selectedSeats.filter(s => s.id !== passenger.seatId);
            }
            
            // Remove passenger
            passengers = passengers.filter(p => p.id !== passengerId);
            document.getElementById(passengerId).remove();
            
            // Reindex form
            const forms = document.querySelectorAll('.passenger_form');
            forms.forEach((form, index) => {
                const inputs = form.querySelectorAll('input, select');
                inputs.forEach(input => {
                    if (input.name.includes('passengers[')) {
                        const field = input.name.match(/\[(\w+)\]$/)[1];
                        input.name = `passengers[${index}][${field}]`;
                    }
                });
                
                form.querySelector('.passenger_number').textContent = `Passenger ${index + 1}`;
            });
            
            updateCounts();
            updateBookingButton();
        }
        
        function updatePassengerName(passengerId, name) {
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger) passenger.name = name;
            updateBookingButton();
        }
        
        function updatePassengerGender(passengerId, gender) {
            const passenger = passengers.find(p => p.id === passengerId);
            if (passenger) passenger.gender = gender;
            updateBookingButton();
        }
        
        function updatePassengerDisplay(passengerId) {
            const passenger = passengers.find(p => p.id === passengerId);
            const element = document.getElementById(passengerId);
            const seatStatus = element.querySelector('.seat_status');
            const hiddenInput = element.querySelector('input[type="hidden"]');
            
            if (passenger.seatId) {
                seatStatus.innerHTML = `<span style="background: #4caf50; color: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8rem;">Seat ${passenger.seatNumber}</span>`;
                hiddenInput.value = passenger.seatId;
                element.classList.add('has_seat');
            } else {
                seatStatus.innerHTML = '';
                hiddenInput.value = '';
                element.classList.remove('has_seat');
            }
        }
        
        function updateCounts() {
            document.getElementById('passenger_count').textContent = passengers.length;
            document.getElementById('total_amount').textContent = 'LKR ' + (passengers.length * basePrice).toLocaleString();
        }
        
        function updateBookingButton() {
            const button = document.getElementById('book_button');
            const allHaveNames = passengers.every(p => p.name.trim() !== '');
            const allHaveGenders = passengers.every(p => p.gender !== '');
            const allHaveSeats = passengers.every(p => p.seatId !== null);
            
            if (passengers.length === 0) {
                button.disabled = true;
                button.textContent = 'Add Passengers First';
            } else if (!allHaveNames) {
                button.disabled = true;
                button.textContent = 'Enter All Names';
            } else if (!allHaveGenders) {
                button.disabled = true;
                button.textContent = 'Select All Genders';
            } else if (!allHaveSeats) {
                button.disabled = true;
                button.textContent = `Select Seats (${selectedSeats.length}/${passengers.length})`;
            } else {
                button.disabled = false;
                button.textContent = `Book ${passengers.length} Seat${passengers.length > 1 ? 's' : ''} - LKR ${(passengers.length * basePrice).toLocaleString()}`;
            }
        }
        
        // Initialize with one passenger
        document.addEventListener('DOMContentLoaded', function() {
            addPassenger();
        });
        
        // Form validation
        document.getElementById('booking_form').addEventListener('submit', function(e) {
            const totalAmount = passengers.length * basePrice;
            const seatNumbers = passengers.map(p => p.seatNumber).join(', ');
            
            if (!confirm(`Confirm booking for ${passengers.length} passenger(s)?\n\nSeats: ${seatNumbers}\nTotal: LKR ${totalAmount.toLocaleString()}`)) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>