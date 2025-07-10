<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is operator
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$operator_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add_bus') {
        // Get form data
        $bus_number = trim($_POST['bus_number'] ?? '');
        $bus_name = trim($_POST['bus_name'] ?? '');
        $bus_type = $_POST['bus_type'] ?? 'Non-AC';
        $total_seats = (int)($_POST['total_seats'] ?? 0);
        $seat_configuration = $_POST['seat_configuration'] ?? '2x2';
        $amenities = trim($_POST['amenities'] ?? '');
        
        // Validation
        if (empty($bus_number) || empty($bus_name)) {
            $error = "Bus number and bus name are required.";
        } elseif ($total_seats < 10 || $total_seats > 60) {
            $error = "Total seats must be between 10 and 60.";
        } else {
            try {
                // Check if bus number already exists
                $stmt = $pdo->prepare("SELECT bus_id FROM buses WHERE bus_number = ?");
                $stmt->execute([$bus_number]);
                if ($stmt->fetch()) {
                    $error = "Bus number already exists. Please use a different number.";
                } else {
                    // Insert new bus
                    $stmt = $pdo->prepare("INSERT INTO buses (operator_id, bus_number, bus_name, bus_type, total_seats, seat_configuration, amenities) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$operator_id, $bus_number, $bus_name, $bus_type, $total_seats, $seat_configuration, $amenities]);
                    
                    $bus_id = $pdo->lastInsertId();
                    
                    // CLEAN SEAT GENERATION - Simple sequential numbering
                    for ($seat_num = 1; $seat_num <= $total_seats; $seat_num++) {
                        // Determine seat type based on position
                        $config = explode('x', $seat_configuration);
                        $seats_per_row = (int)$config[0] + (int)$config[1];
                        $position_in_row = (($seat_num - 1) % $seats_per_row) + 1;
                        
                        if ($position_in_row == 1 || $position_in_row == $seats_per_row) {
                            $seat_type = 'window';
                        } elseif ($position_in_row == (int)$config[0] || $position_in_row == ((int)$config[0] + 1)) {
                            $seat_type = 'aisle';
                        } else {
                            $seat_type = 'middle';
                        }
                        
                        $stmt = $pdo->prepare("INSERT INTO seats (bus_id, seat_number, seat_type) VALUES (?, ?, ?)");
                        $stmt->execute([$bus_id, (string)$seat_num, $seat_type]);
                    }
                    
                    // Verify seat creation
                    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM seats WHERE bus_id = ?");
                    $stmt->execute([$bus_id]);
                    $actual_seats = $stmt->fetch()['count'];
                    
                    $message = "Bus added successfully! Created $actual_seats seats (numbered 1 to $total_seats).";
                }
            } catch (PDOException $e) {
                $error = "Error adding bus: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'update_bus') {
        $bus_id = $_POST['bus_id'] ?? '';
        $bus_number = trim($_POST['bus_number'] ?? '');
        $bus_name = trim($_POST['bus_name'] ?? '');
        $bus_type = $_POST['bus_type'] ?? 'Non-AC';
        $amenities = trim($_POST['amenities'] ?? '');
        $status = $_POST['status'] ?? 'active';
        
        if (empty($bus_number) || empty($bus_name)) {
            $error = "Bus number and bus name are required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE buses SET bus_number = ?, bus_name = ?, bus_type = ?, amenities = ?, status = ? WHERE bus_id = ? AND operator_id = ?");
                $stmt->execute([$bus_number, $bus_name, $bus_type, $amenities, $status, $bus_id, $operator_id]);
                $message = "Bus updated successfully!";
            } catch (PDOException $e) {
                $error = "Error updating bus: " . $e->getMessage();
            }
        }
    }
    
    elseif ($action === 'delete_bus') {
        $bus_id = $_POST['bus_id'] ?? '';
        try {
            // Check if bus has active schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM schedules WHERE bus_id = ? AND status = 'active'");
            $stmt->execute([$bus_id]);
            $active_schedules = $stmt->fetch()['count'];
            
            if ($active_schedules > 0) {
                $error = "Cannot delete bus. It has $active_schedules active schedules.";
            } else {
                // Delete seats first, then bus
                $stmt = $pdo->prepare("DELETE FROM seats WHERE bus_id = ?");
                $stmt->execute([$bus_id]);
                
                $stmt = $pdo->prepare("DELETE FROM buses WHERE bus_id = ? AND operator_id = ?");
                $stmt->execute([$bus_id, $operator_id]);
                
                $message = "Bus deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting bus: " . $e->getMessage();
        }
    }
}

// Get all buses for this operator
try {
    $stmt = $pdo->prepare("
        SELECT b.*, COUNT(s.seat_id) as actual_seats 
        FROM buses b 
        LEFT JOIN seats s ON b.bus_id = s.bus_id 
        WHERE b.operator_id = ? 
        GROUP BY b.bus_id 
        ORDER BY b.bus_name ASC
    ");
    $stmt->execute([$operator_id]);
    $buses = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching buses: " . $e->getMessage();
    $buses = [];
}

// Get bus for editing
$edit_bus = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM buses WHERE bus_id = ? AND operator_id = ?");
        $stmt->execute([$_GET['edit'], $operator_id]);
        $edit_bus = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error fetching bus for editing.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bus Management - Road Runner</title>
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
        <h2 class="mb_2">Bus Fleet Management</h2>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="alert alert_success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert_error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Add/Edit Bus Form -->
        <div class="form_container mb_2">
            <h3><?php echo $edit_bus ? 'Edit Bus' : 'Add New Bus'; ?></h3>
            
            <form method="POST" action="buses.php">
                <input type="hidden" name="action" value="<?php echo $edit_bus ? 'update_bus' : 'add_bus'; ?>">
                <?php if ($edit_bus): ?>
                    <input type="hidden" name="bus_id" value="<?php echo $edit_bus['bus_id']; ?>">
                <?php endif; ?>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="bus_number">Bus Number: *</label>
                        <input type="text" id="bus_number" name="bus_number" class="form_control" 
                               value="<?php echo $edit_bus ? htmlspecialchars($edit_bus['bus_number']) : ''; ?>" 
                               placeholder="e.g., NB-1234" required>
                    </div>
                    
                    <div class="form_group">
                        <label for="bus_name">Bus Name: *</label>
                        <input type="text" id="bus_name" name="bus_name" class="form_control" 
                               value="<?php echo $edit_bus ? htmlspecialchars($edit_bus['bus_name']) : ''; ?>" 
                               placeholder="e.g., Express Cruiser" required>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="bus_type">Bus Type:</label>
                        <select id="bus_type" name="bus_type" class="form_control">
                            <option value="Non-AC" <?php echo ($edit_bus && $edit_bus['bus_type'] === 'Non-AC') ? 'selected' : ''; ?>>Non-AC</option>
                            <option value="AC" <?php echo ($edit_bus && $edit_bus['bus_type'] === 'AC') ? 'selected' : ''; ?>>AC</option>
                            <option value="Semi-Luxury" <?php echo ($edit_bus && $edit_bus['bus_type'] === 'Semi-Luxury') ? 'selected' : ''; ?>>Semi-Luxury</option>
                            <option value="Luxury" <?php echo ($edit_bus && $edit_bus['bus_type'] === 'Luxury') ? 'selected' : ''; ?>>Luxury</option>
                        </select>
                    </div>
                    
                    <?php if (!$edit_bus): ?>
                        <div class="form_group">
                            <label for="total_seats">Total Seats: *</label>
                            <input type="number" id="total_seats" name="total_seats" class="form_control" 
                                   min="10" max="60" placeholder="e.g., 50" required>
                        </div>
                        
                        <div class="form_group">
                            <label for="seat_configuration">Seat Config:</label>
                            <select id="seat_configuration" name="seat_configuration" class="form_control">
                                <option value="2x2">2x2 (4 per row)</option>
                                <option value="2x3">2x3 (5 per row)</option>
                                <option value="3x2">3x2 (5 per row)</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="form_group">
                            <label>Total Seats:</label>
                            <input type="text" class="form_control" value="<?php echo $edit_bus['total_seats']; ?>" readonly style="background: #f5f5f5;">
                        </div>
                        
                        <div class="form_group">
                            <label for="status">Status:</label>
                            <select id="status" name="status" class="form_control">
                                <option value="active" <?php echo ($edit_bus && $edit_bus['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="maintenance" <?php echo ($edit_bus && $edit_bus['status'] === 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                                <option value="inactive" <?php echo ($edit_bus && $edit_bus['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form_group">
                    <label for="amenities">Amenities:</label>
                    <textarea id="amenities" name="amenities" class="form_control" rows="2" 
                              placeholder="e.g., WiFi, USB Charging, AC, Reclining Seats"><?php echo $edit_bus ? htmlspecialchars($edit_bus['amenities']) : ''; ?></textarea>
                </div>
                
                <button type="submit" class="btn btn_primary">
                    <?php echo $edit_bus ? 'Update Bus' : 'Add Bus'; ?>
                </button>
                
                <?php if ($edit_bus): ?>
                    <a href="buses.php" class="btn" style="margin-left: 1rem;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Bus List -->
        <div class="table_container">
            <h3 class="p_1 mb_1">My Buses (<?php echo count($buses); ?>)</h3>
            
            <?php if (empty($buses)): ?>
                <div class="p_2 text_center">
                    <p>No buses in your fleet yet. Add your first bus above!</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Bus Details</th>
                            <th>Type & Seats</th>
                            <th>Amenities</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($buses as $bus): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($bus['bus_name']); ?></strong><br>
                                    <small style="color: #666;">Bus #: <?php echo htmlspecialchars($bus['bus_number']); ?></small>
                                </td>
                                <td>
                                    <span class="badge badge_<?php echo strtolower(str_replace('-', '', $bus['bus_type'])); ?>"><?php echo $bus['bus_type']; ?></span><br>
                                    <small><?php echo $bus['actual_seats']; ?>/<?php echo $bus['total_seats']; ?> seats (<?php echo $bus['seat_configuration']; ?>)</small>
                                </td>
                                <td>
                                    <?php if ($bus['amenities']): ?>
                                        <small><?php echo htmlspecialchars($bus['amenities']); ?></small>
                                    <?php else: ?>
                                        <small style="color: #999;">No amenities</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge_<?php echo $bus['status'] === 'active' ? 'active' : ($bus['status'] === 'maintenance' ? 'operator' : 'inactive'); ?>">
                                        <?php echo ucfirst($bus['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="buses.php?edit=<?php echo $bus['bus_id']; ?>" class="btn btn_primary" style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">Edit</a>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this bus and all its seats?');">
                                        <input type="hidden" name="action" value="delete_bus">
                                        <input type="hidden" name="bus_id" value="<?php echo $bus['bus_id']; ?>">
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
            <p>&copy; 2025 Road Runner. Clean and simple bus management.</p>
        </div>
    </footer>
</body>
</html>