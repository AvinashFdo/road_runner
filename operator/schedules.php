<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is operator
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'operator') {
    header('Location: ../login.php');
    exit();
}

$operator_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_schedule') {
        // Add new schedule
        $bus_id = $_POST['bus_id'] ?? '';
        $route_id = $_POST['route_id'] ?? '';
        $departure_time = $_POST['departure_time'] ?? '';
        $arrival_time = $_POST['arrival_time'] ?? '';
        $base_price = $_POST['base_price'] ?? '';
        $available_days = $_POST['available_days'] ?? 'Daily';
        
        // Validation
        if (empty($bus_id) || empty($route_id) || empty($departure_time) || empty($base_price)) {
            $error = "Bus, route, departure time, and base price are required.";
        } elseif (!is_numeric($base_price) || $base_price <= 0) {
            $error = "Base price must be a positive number.";
        } else {
            try {
                // Verify bus belongs to this operator
                $stmt = $pdo->prepare("SELECT bus_id FROM buses WHERE bus_id = ? AND operator_id = ?");
                $stmt->execute([$bus_id, $operator_id]);
                if (!$stmt->fetch()) {
                    $error = "Selected bus does not belong to you.";
                } else {
                    // Check for conflicting schedules (same bus, same route, same time)
                    $stmt = $pdo->prepare("SELECT schedule_id FROM schedules WHERE bus_id = ? AND route_id = ? AND departure_time = ? AND status = 'active'");
                    $stmt->execute([$bus_id, $route_id, $departure_time]);
                    if ($stmt->fetch()) {
                        $error = "This bus already has a schedule for this route at this time.";
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO schedules (bus_id, route_id, departure_time, arrival_time, base_price, available_days) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$bus_id, $route_id, $departure_time, $arrival_time ?: null, $base_price, $available_days]);
                        $message = "Schedule added successfully!";
                    }
                }
            } catch (PDOException $e) {
                $error = "Error adding schedule: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_schedule') {
        // Update existing schedule
        $schedule_id = $_POST['schedule_id'] ?? '';
        $departure_time = $_POST['departure_time'] ?? '';
        $arrival_time = $_POST['arrival_time'] ?? '';
        $base_price = $_POST['base_price'] ?? '';
        $available_days = $_POST['available_days'] ?? 'Daily';
        $status = $_POST['status'] ?? 'active';
        
        if (empty($departure_time) || empty($base_price)) {
            $error = "Departure time and base price are required.";
        } elseif (!is_numeric($base_price) || $base_price <= 0) {
            $error = "Base price must be a positive number.";
        } else {
            try {
                // Verify schedule belongs to this operator's bus
                $stmt = $pdo->prepare("SELECT s.schedule_id FROM schedules s JOIN buses b ON s.bus_id = b.bus_id WHERE s.schedule_id = ? AND b.operator_id = ?");
                $stmt->execute([$schedule_id, $operator_id]);
                if (!$stmt->fetch()) {
                    $error = "Schedule not found or you don't have permission to edit it.";
                } else {
                    $stmt = $pdo->prepare("UPDATE schedules SET departure_time = ?, arrival_time = ?, base_price = ?, available_days = ?, status = ? WHERE schedule_id = ?");
                    $stmt->execute([$departure_time, $arrival_time ?: null, $base_price, $available_days, $status, $schedule_id]);
                    $message = "Schedule updated successfully!";
                }
            } catch (PDOException $e) {
                $error = "Error updating schedule: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_schedule') {
        // Delete schedule
        $schedule_id = $_POST['schedule_id'] ?? '';
        try {
            // Check if schedule has active bookings
            $stmt = $pdo->prepare("SELECT COUNT(*) as booking_count FROM bookings WHERE schedule_id = ? AND booking_status IN ('pending', 'confirmed')");
            $stmt->execute([$schedule_id]);
            $result = $stmt->fetch();
            
            if ($result['booking_count'] > 0) {
                $error = "Cannot delete schedule. It has active bookings. Please cancel bookings first.";
            } else {
                // Verify schedule belongs to this operator's bus
                $stmt = $pdo->prepare("SELECT s.schedule_id FROM schedules s JOIN buses b ON s.bus_id = b.bus_id WHERE s.schedule_id = ? AND b.operator_id = ?");
                $stmt->execute([$schedule_id, $operator_id]);
                if (!$stmt->fetch()) {
                    $error = "Schedule not found or you don't have permission to delete it.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM schedules WHERE schedule_id = ?");
                    $stmt->execute([$schedule_id]);
                    $message = "Schedule deleted successfully!";
                }
            }
        } catch (PDOException $e) {
            $error = "Error deleting schedule: " . $e->getMessage();
        }
    }
}

// Get operator's buses
try {
    $stmt = $pdo->prepare("SELECT bus_id, bus_name, bus_number, bus_type, status FROM buses WHERE operator_id = ? AND status = 'active' ORDER BY bus_name ASC");
    $stmt->execute([$operator_id]);
    $operator_buses = $stmt->fetchAll();
} catch (PDOException $e) {
    $operator_buses = [];
}

// Get all active routes
try {
    $stmt = $pdo->query("SELECT route_id, route_name, origin, destination, distance_km FROM routes WHERE status = 'active' ORDER BY route_name ASC");
    $all_routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_routes = [];
}

// Get all schedules for this operator
try {
    $stmt = $pdo->prepare("
        SELECT s.*, 
               b.bus_name, b.bus_number, b.bus_type,
               r.route_name, r.origin, r.destination, r.distance_km
        FROM schedules s
        JOIN buses b ON s.bus_id = b.bus_id
        JOIN routes r ON s.route_id = r.route_id
        WHERE b.operator_id = ?
        ORDER BY r.route_name ASC, s.departure_time ASC
    ");
    $stmt->execute([$operator_id]);
    $schedules = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching schedules: " . $e->getMessage();
    $schedules = [];
}

// Get schedule for editing if edit_id is provided
$edit_schedule = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, b.bus_name, r.route_name
            FROM schedules s
            JOIN buses b ON s.bus_id = b.bus_id
            JOIN routes r ON s.route_id = r.route_id
            WHERE s.schedule_id = ? AND b.operator_id = ?
        ");
        $stmt->execute([$_GET['edit'], $operator_id]);
        $edit_schedule = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error fetching schedule for editing.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Management - Road Runner Operator</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Operator</div>
                <ul class="nav_links">
                    <li><a href="dashboard.php">Dashboard</a></li>
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Operator Navigation -->
    <div class="operator_nav">
        <div class="container">
            <div class="operator_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="buses.php">Buses</a>
                <a href="schedules.php">Schedules</a>
                <a href="parcels.php">Parcel Management</a>
                <a href="#" onclick="alert('Coming soon!')">View All Bookings</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">Schedule Management</h2>

        <!-- Check if operator has buses -->
        <?php if (empty($operator_buses)): ?>
            <div class="alert alert_error">
                <h4>No Active Buses Found</h4>
                <p>You need to add buses to your fleet before creating schedules.</p>
                <a href="buses.php" class="btn btn_primary mt_1">Add Buses</a>
            </div>
        <?php elseif (empty($all_routes)): ?>
            <div class="alert alert_error">
                <h4>No Routes Available</h4>
                <p>The admin hasn't added any routes yet. Contact support for route additions.</p>
            </div>
        <?php else: ?>

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

        <!-- Add/Edit Schedule Form -->
        <div class="form_container mb_2">
            <h3><?php echo $edit_schedule ? 'Edit Schedule' : 'Add New Schedule'; ?></h3>
            
            <form method="POST" action="schedules.php">
                <input type="hidden" name="action" value="<?php echo $edit_schedule ? 'update_schedule' : 'add_schedule'; ?>">
                <?php if ($edit_schedule): ?>
                    <input type="hidden" name="schedule_id" value="<?php echo $edit_schedule['schedule_id']; ?>">
                <?php endif; ?>
                
                <?php if (!$edit_schedule): ?>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="bus_id">Select Bus: *</label>
                        <select id="bus_id" name="bus_id" class="form_control" required>
                            <option value="">Choose a bus...</option>
                            <?php foreach ($operator_buses as $bus): ?>
                                <option value="<?php echo $bus['bus_id']; ?>">
                                    <?php echo htmlspecialchars($bus['bus_name'] . ' (' . $bus['bus_number'] . ') - ' . $bus['bus_type']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form_group">
                        <label for="route_id">Select Route: *</label>
                        <select id="route_id" name="route_id" class="form_control" required>
                            <option value="">Choose a route...</option>
                            <?php foreach ($all_routes as $route): ?>
                                <option value="<?php echo $route['route_id']; ?>">
                                    <?php echo htmlspecialchars($route['route_name'] . ' (' . $route['origin'] . ' → ' . $route['destination'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php else: ?>
                <div class="alert alert_info">
                    <strong>Bus:</strong> <?php echo htmlspecialchars($edit_schedule['bus_name']); ?><br>
                    <strong>Route:</strong> <?php echo htmlspecialchars($edit_schedule['route_name']); ?>
                </div>
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="departure_time">Departure Time: *</label>
                        <input 
                            type="time" 
                            id="departure_time" 
                            name="departure_time" 
                            class="form_control" 
                            value="<?php echo $edit_schedule ? $edit_schedule['departure_time'] : ''; ?>"
                            required
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="arrival_time">Arrival Time:</label>
                        <input 
                            type="time" 
                            id="arrival_time" 
                            name="arrival_time" 
                            class="form_control" 
                            value="<?php echo $edit_schedule ? $edit_schedule['arrival_time'] : ''; ?>"
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="base_price">Base Price (LKR): *</label>
                        <input 
                            type="number" 
                            id="base_price" 
                            name="base_price" 
                            class="form_control" 
                            value="<?php echo $edit_schedule ? $edit_schedule['base_price'] : ''; ?>"
                            step="0.01"
                            min="0"
                            placeholder="e.g., 1500.00"
                            required
                        >
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="available_days">Available Days:</label>
                        <select id="available_days" name="available_days" class="form_control">
                            <option value="Daily" <?php echo ($edit_schedule && $edit_schedule['available_days'] === 'Daily') ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekdays" <?php echo ($edit_schedule && $edit_schedule['available_days'] === 'Weekdays') ? 'selected' : ''; ?>>Weekdays Only</option>
                            <option value="Weekends" <?php echo ($edit_schedule && $edit_schedule['available_days'] === 'Weekends') ? 'selected' : ''; ?>>Weekends Only</option>
                        </select>
                    </div>
                    
                    <?php if ($edit_schedule): ?>
                    <div class="form_group">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form_control">
                            <option value="active" <?php echo $edit_schedule['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $edit_schedule['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                
                <button type="submit" class="btn btn_primary">
                    <?php echo $edit_schedule ? 'Update Schedule' : 'Add Schedule'; ?>
                </button>
                
                <?php if ($edit_schedule): ?>
                    <a href="schedules.php" class="btn" style="margin-left: 1rem;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <?php endif; ?>

        <!-- Schedules List -->
        <div class="table_container">
            <h3 class="p_1 mb_1">My Schedules (<?php echo count($schedules); ?>)</h3>
            
            <?php if (empty($schedules)): ?>
                <div class="p_2 text_center">
                    <p>No schedules created yet. Add your first schedule above!</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Route</th>
                            <th>Bus</th>
                            <th>Time</th>
                            <th>Price</th>
                            <th>Availability</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($schedule['route_name']); ?></strong><br>
                                    <small style="color: #666;">
                                        <?php echo htmlspecialchars($schedule['origin']); ?> → 
                                        <?php echo htmlspecialchars($schedule['destination']); ?>
                                        (<?php echo $schedule['distance_km']; ?> km)
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($schedule['bus_name']); ?></strong><br>
                                    <small style="color: #666;">
                                        <?php echo htmlspecialchars($schedule['bus_number']); ?> - 
                                        <span class="badge badge_<?php echo strtolower(str_replace('-', '', $schedule['bus_type'])); ?>" style="font-size: 0.7rem;">
                                            <?php echo $schedule['bus_type']; ?>
                                        </span>
                                    </small>
                                </td>
                                <td>
                                    <strong><?php echo date('g:i A', strtotime($schedule['departure_time'])); ?></strong><br>
                                    <?php if ($schedule['arrival_time']): ?>
                                        <small style="color: #666;">to <?php echo date('g:i A', strtotime($schedule['arrival_time'])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong>LKR <?php echo number_format($schedule['base_price'], 2); ?></strong>
                                </td>
                                <td>
                                    <span class="badge badge_<?php echo $schedule['available_days'] === 'Daily' ? 'active' : 'operator'; ?>">
                                        <?php echo $schedule['available_days']; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge_<?php echo $schedule['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($schedule['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="schedules.php?edit=<?php echo $schedule['schedule_id']; ?>" class="btn btn_primary" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">Edit</a>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="schedule_id" value="<?php echo $schedule['schedule_id']; ?>">
                                        <button type="submit" class="btn" style="background: #e74c3c; font-size: 0.8rem; padding: 0.25rem 0.5rem;">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Operator Panel. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>