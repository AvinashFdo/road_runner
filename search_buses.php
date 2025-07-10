<?php

session_start();
require_once 'db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$search_results = [];
$search_performed = false;
$origin = '';
$destination = '';
$travel_date = '';
$error = '';

// Handle search form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_buses'])) {
    $origin = trim($_POST['origin'] ?? '');
    $destination = trim($_POST['destination'] ?? '');
    $travel_date = $_POST['travel_date'] ?? '';
    
    // Validation
    if (empty($origin) || empty($destination) || empty($travel_date)) {
        $error = "Please fill in all search fields.";
    } elseif ($origin === $destination) {
        $error = "Origin and destination cannot be the same.";
    } elseif (strtotime($travel_date) < strtotime('today')) {
        $error = "Travel date cannot be in the past.";
    } else {
        $search_performed = true;
        
        try {
            // Search for buses/schedules matching the criteria
            $stmt = $pdo->prepare("
                SELECT 
                    s.schedule_id,
                    s.departure_time,
                    s.arrival_time,
                    s.base_price,
                    s.available_days,
                    b.bus_id,
                    b.bus_name,
                    b.bus_number,
                    b.bus_type,
                    b.total_seats,
                    b.amenities,
                    r.route_id,
                    r.route_name,
                    r.origin,
                    r.destination,
                    r.distance_km,
                    r.estimated_duration,
                    u.full_name as operator_name,
                    -- Count available seats (total seats minus booked seats for this date)
                    (b.total_seats - COALESCE(booked_seats.count, 0)) as available_seats
                FROM schedules s
                JOIN buses b ON s.bus_id = b.bus_id
                JOIN routes r ON s.route_id = r.route_id
                JOIN users u ON b.operator_id = u.user_id
                LEFT JOIN (
                    SELECT 
                        schedule_id, 
                        COUNT(*) as count 
                    FROM bookings 
                    WHERE travel_date = ? 
                    AND booking_status IN ('pending', 'confirmed')
                    GROUP BY schedule_id
                ) booked_seats ON s.schedule_id = booked_seats.schedule_id
                WHERE r.origin LIKE ? 
                AND r.destination LIKE ?
                AND s.status = 'active'
                AND b.status = 'active'
                AND r.status = 'active'
                ORDER BY s.departure_time ASC
            ");
            
            $origin_like = '%' . $origin . '%';
            $destination_like = '%' . $destination . '%';
            $stmt->execute([$travel_date, $origin_like, $destination_like]);
            $search_results = $stmt->fetchAll();
            
        } catch (PDOException $e) {
            $error = "Error searching buses: " . $e->getMessage();
        }
    }
}

// Get popular routes for suggestions
try {
    $stmt = $pdo->query("
        SELECT DISTINCT r.origin, r.destination, r.route_name, COUNT(s.schedule_id) as schedule_count
        FROM routes r 
        LEFT JOIN schedules s ON r.route_id = s.route_id AND s.status = 'active'
        WHERE r.status = 'active'
        GROUP BY r.route_id, r.origin, r.destination, r.route_name
        HAVING schedule_count > 0
        ORDER BY schedule_count DESC, r.route_name ASC
        LIMIT 6
    ");
    $popular_routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $popular_routes = [];
}

// Check day of week for available_days filtering
function isAvailableOnDate($available_days, $date) {
    $day_of_week = date('N', strtotime($date)); // 1 = Monday, 7 = Sunday
    
    switch ($available_days) {
        case 'Weekdays':
            return $day_of_week >= 1 && $day_of_week <= 5;
        case 'Weekends':
            return $day_of_week >= 6 && $day_of_week <= 7;
        case 'Daily':
        default:
            return true;
    }
}

// Filter results by availability days
if ($search_performed && !empty($search_results)) {
    $search_results = array_filter($search_results, function($result) use ($travel_date) {
        return isAvailableOnDate($result['available_days'], $travel_date);
    });
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Buses - Road Runner</title>
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
        <!-- Search Form -->
        <div class="form_container mb_2">
            <h2 class="text_center mb_2">Search Buses</h2>
            
            <?php if ($error): ?>
                <div class="alert alert_error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="search_buses.php">
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                    <div class="form_group">
                        <label for="origin">From:</label>
                        <input 
                            type="text" 
                            id="origin" 
                            name="origin" 
                            class="form_control" 
                            value="<?php echo htmlspecialchars($origin); ?>"
                            placeholder="e.g., Colombo"
                            required
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="destination">To:</label>
                        <input 
                            type="text" 
                            id="destination" 
                            name="destination" 
                            class="form_control" 
                            value="<?php echo htmlspecialchars($destination); ?>"
                            placeholder="e.g., Kandy"
                            required
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="travel_date">Travel Date:</label>
                        <input 
                            type="date" 
                            id="travel_date" 
                            name="travel_date" 
                            class="form_control" 
                            value="<?php echo htmlspecialchars($travel_date); ?>"
                            min="<?php echo date('Y-m-d'); ?>"
                            required
                        >
                    </div>
                    
                    <button type="submit" name="search_buses" class="btn btn_primary" style="height: 44px;">
                        🔍 Search
                    </button>
                </div>
            </form>
        </div>

        <!-- Popular Routes -->
        <?php if (!$search_performed && !empty($popular_routes)): ?>
        <div class="mb_2">
            <h3 class="mb_1">Popular Routes</h3>
            <div class="features_grid">
                <?php foreach ($popular_routes as $route): ?>
                    <div class="feature_card" style="padding: 1rem;">
                        <h4 style="margin-bottom: 0.5rem;"><?php echo htmlspecialchars($route['route_name']); ?></h4>
                        <p style="color: #666; margin-bottom: 1rem;">
                            <?php echo htmlspecialchars($route['origin']); ?> → 
                            <?php echo htmlspecialchars($route['destination']); ?>
                        </p>
                        <button 
                            class="btn btn_primary" 
                            style="font-size: 0.9rem; padding: 0.5rem 1rem;"
                            onclick="fillRoute('<?php echo htmlspecialchars($route['origin']); ?>', '<?php echo htmlspecialchars($route['destination']); ?>')"
                        >
                            Select Route
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Search Results -->
        <?php if ($search_performed): ?>
        <div class="mb_2">
            <h3 class="mb_1">
                Search Results: <?php echo htmlspecialchars($origin); ?> → <?php echo htmlspecialchars($destination); ?>
                <small style="color: #666; font-weight: normal;">(<?php echo date('D, M j, Y', strtotime($travel_date)); ?>)</small>
            </h3>
            
            <?php if (empty($search_results)): ?>
                <div class="alert alert_info">
                    <h4>No buses found</h4>
                    <p>Sorry, no buses are available for this route on the selected date. Try:</p>
                    <ul style="margin: 1rem 0; padding-left: 2rem;">
                        <li>Different date</li>
                        <li>Nearby cities (e.g., if searching "Colombo", try "Colombo Fort")</li>
                        <li>Reverse route (destination to origin)</li>
                    </ul>
                    <button class="btn btn_primary" onclick="document.getElementById('origin').focus();">
                        Try Another Search
                    </button>
                </div>
            <?php else: ?>
                <div class="table_container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Bus Details</th>
                                <th>Route & Timing</th>
                                <th>Price & Seats</th>
                                <th>Amenities</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($search_results as $bus): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bus['bus_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($bus['bus_number']); ?> - 
                                            <span class="badge badge_<?php echo strtolower(str_replace('-', '', $bus['bus_type'])); ?>" style="font-size: 0.7rem;">
                                                <?php echo $bus['bus_type']; ?>
                                            </span>
                                        </small><br>
                                        <small style="color: #888;">by <?php echo htmlspecialchars($bus['operator_name']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($bus['route_name']); ?></strong><br>
                                        <div style="margin: 0.5rem 0;">
                                            <strong style="color: #27ae60;">
                                                🕐 <?php echo date('g:i A', strtotime($bus['departure_time'])); ?>
                                            </strong>
                                            <?php if ($bus['arrival_time']): ?>
                                                <span style="color: #666;"> → </span>
                                                <strong style="color: #e74c3c;">
                                                    🕐 <?php echo date('g:i A', strtotime($bus['arrival_time'])); ?>
                                                </strong>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($bus['estimated_duration']): ?>
                                            <small style="color: #666;">Duration: <?php echo htmlspecialchars($bus['estimated_duration']); ?></small><br>
                                        <?php endif; ?>
                                        <small style="color: #666;">Distance: <?php echo $bus['distance_km']; ?> km</small>
                                    </td>
                                    <td>
                                        <div style="font-size: 1.2rem; font-weight: bold; color: #e74c3c; margin-bottom: 0.5rem;">
                                            LKR <?php echo number_format($bus['base_price']); ?>
                                        </div>
                                        <div>
                                            <?php if ($bus['available_seats'] > 0): ?>
                                                <span class="badge badge_active">
                                                    <?php echo $bus['available_seats']; ?> seats available
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge_inactive">Fully Booked</span>
                                            <?php endif; ?>
                                        </div>
                                        <small style="color: #666;">Total: <?php echo $bus['total_seats']; ?> seats</small>
                                    </td>
                                    <td>
                                        <?php if ($bus['amenities']): ?>
                                            <small><?php echo htmlspecialchars($bus['amenities']); ?></small>
                                        <?php else: ?>
                                            <small style="color: #999;">No amenities listed</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($bus['available_seats'] > 0): ?>
                                            <a 
                                                href="seat_selection.php?schedule_id=<?php echo $bus['schedule_id']; ?>&travel_date=<?php echo urlencode($travel_date); ?>" 
                                                class="btn btn_primary" 
                                                style="font-size: 0.9rem; padding: 0.5rem 1rem;"
                                            >
                                                Select Seats
                                            </a>
                                        <?php else: ?>
                                            <button class="btn" style="background: #95a5a6; cursor: not-allowed;" disabled>
                                                Fully Booked
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Find your perfect journey!</p>
        </div>
    </footer>

    <script>
        // Fill route suggestion
        function fillRoute(origin, destination) {
            document.getElementById('origin').value = origin;
            document.getElementById('destination').value = destination;
            document.getElementById('travel_date').focus();
        }
        
        // Set minimum date to today
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('travel_date').setAttribute('min', today);
            
            // Set default date to tomorrow if no date selected
            if (!document.getElementById('travel_date').value) {
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                document.getElementById('travel_date').value = tomorrow.toISOString().split('T')[0];
            }
        });
    </script>
</body>
</html>