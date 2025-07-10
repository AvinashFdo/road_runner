<?php
session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_status':
                try {
                    $bus_id = $_POST['bus_id'];
                    $status = $_POST['status'];
                    
                    $stmt = $pdo->prepare("UPDATE buses SET status = ? WHERE bus_id = ?");
                    $stmt->execute([$status, $bus_id]);
                    
                    $message = "Bus status updated successfully!";
                } catch (Exception $e) {
                    $error = "Error updating bus status: " . $e->getMessage();
                }
                break;
                
            case 'delete_bus':
                try {
                    $bus_id = $_POST['bus_id'];
                    
                    // Check if bus has active schedules or bookings
                    $check_stmt = $pdo->prepare("
                        SELECT COUNT(*) as active_count 
                        FROM schedules s 
                        LEFT JOIN bookings b ON s.schedule_id = b.schedule_id 
                        WHERE s.bus_id = ? AND (s.status = 'active' OR b.booking_status IN ('pending', 'confirmed'))
                    ");
                    $check_stmt->execute([$bus_id]);
                    $active_count = $check_stmt->fetchColumn();
                    
                    if ($active_count > 0) {
                        $error = "Cannot delete bus. It has active schedules or bookings.";
                    } else {
                        // Delete seats first, then bus
                        $stmt = $pdo->prepare("DELETE FROM seats WHERE bus_id = ?");
                        $stmt->execute([$bus_id]);
                        
                        $stmt = $pdo->prepare("DELETE FROM buses WHERE bus_id = ?");
                        $stmt->execute([$bus_id]);
                        
                        $message = "Bus deleted successfully!";
                    }
                } catch (Exception $e) {
                    $error = "Error deleting bus: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get bus statistics
try {
    // Get total counts
    $total_stmt = $pdo->query("SELECT COUNT(*) FROM buses");
    $total_buses = $total_stmt->fetchColumn();
    
    // Get status breakdown
    $status_stmt = $pdo->query("SELECT status, COUNT(*) as count FROM buses GROUP BY status");
    $status_stats = $status_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get bus type breakdown
    $type_stmt = $pdo->query("SELECT bus_type, COUNT(*) as count FROM buses GROUP BY bus_type");
    $type_stats = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all buses with operator information and seat count
    $buses_stmt = $pdo->query("
        SELECT 
            b.*,
            u.full_name as operator_name,
            u.email as operator_email,
            COUNT(s.seat_id) as actual_seats,
            COUNT(DISTINCT sch.schedule_id) as total_schedules,
            COUNT(DISTINCT CASE WHEN sch.status = 'active' THEN sch.schedule_id END) as active_schedules
        FROM buses b
        LEFT JOIN users u ON b.operator_id = u.user_id
        LEFT JOIN seats s ON b.bus_id = s.bus_id
        LEFT JOIN schedules sch ON b.bus_id = sch.bus_id
        GROUP BY b.bus_id
        ORDER BY b.created_at DESC
    ");
    $buses = $buses_stmt->fetchAll();
    
} catch (PDOException $e) {
    $error = "Error loading bus data: " . $e->getMessage();
    $total_buses = 0;
    $status_stats = [];
    $type_stats = [];
    $buses = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View All Buses - Road Runner Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">
                    <img src="../images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
                </div>
                <ul class="nav_links">
                    <li><a href="../index.php">Main Site</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Admin Navigation -->
    <div class="admin_nav">
        <div class="container">
            <div class="admin_nav_links">
                <a href="dashboard.php">Dashboard</a>
                <a href="routes.php">Manage Routes</a>
                <a href="parcels.php">Parcel Management</a>
                <a href="users.php">Manage Users</a>
                <a href="buses.php">View All Buses</a>
                <a href="bookings.php">View All Bookings</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">🚌 View All Buses</h2>

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

        <!-- Bus Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_buses; ?></div>
                <div class="stat_label">Total Buses</div>
            </div>
            <?php foreach ($status_stats as $stat): ?>
                <div class="stat_card">
                    <div class="stat_number"><?php echo $stat['count']; ?></div>
                    <div class="stat_label"><?php echo ucfirst($stat['status']); ?> Buses</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">
            
            <!-- Bus Status Overview -->
            <div class="table_container">
                <h3 class="p_1 mb_1">📊 Bus Status Overview</h3>
                <div style="padding: 1rem;">
                    <?php foreach ($status_stats as $stat): ?>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                            <span class="badge badge_<?php echo $stat['status']; ?>">
                                <?php echo ucfirst($stat['status']); ?>
                            </span>
                            <strong><?php echo $stat['count']; ?> buses</strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Bus Type Breakdown -->
            <div class="table_container">
                <h3 class="p_1 mb_1">🏷️ Bus Types</h3>
                <div style="padding: 1rem;">
                    <?php foreach ($type_stats as $stat): ?>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                            <span><?php echo $stat['bus_type']; ?></span>
                            <strong><?php echo $stat['count']; ?> buses</strong>
                        </div>
                    <?php endforeach; ?>
                    
                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                        <div style="text-align: center;">
                            <strong style="font-size: 1.2rem; color: #3498db;">
                                Total: <?php echo $total_buses; ?> Buses
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Buses Table -->
        <div class="table_container">
            <h3 class="p_1 mb_1">All Buses</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>Bus ID</th>
                        <th>Bus Number</th>
                        <th>Bus Name</th>
                        <th>Type</th>
                        <th>Operator</th>
                        <th>Seats</th>
                        <th>Schedules</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($buses as $bus): ?>
                        <tr>
                            <td><?php echo $bus['bus_id']; ?></td>
                            <td><strong><?php echo htmlspecialchars($bus['bus_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($bus['bus_name']); ?></td>
                            <td>
                                <span class="badge" style="background: #e3f2fd; color: #1976d2;">
                                    <?php echo $bus['bus_type']; ?>
                                </span>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($bus['operator_name'] ?? 'Unknown'); ?></div>
                                <small style="color: #666;"><?php echo htmlspecialchars($bus['operator_email'] ?? ''); ?></small>
                            </td>
                            <td>
                                <div><?php echo $bus['actual_seats']; ?> / <?php echo $bus['total_seats']; ?></div>
                                <small style="color: #666;"><?php echo $bus['seat_configuration']; ?></small>
                            </td>
                            <td>
                                <div><?php echo $bus['active_schedules']; ?> active</div>
                                <small style="color: #666;"><?php echo $bus['total_schedules']; ?> total</small>
                            </td>
                            <td>
                                <span class="badge badge_<?php echo $bus['status']; ?>">
                                    <?php echo ucfirst($bus['status']); ?>
                                </span>
                            </td>
                            <td>
                                <!-- Status Update Form -->
                                <form method="POST" style="display: inline; margin-right: 5px;">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="bus_id" value="<?php echo $bus['bus_id']; ?>">
                                    <select name="status" onchange="this.form.submit()" style="padding: 2px; font-size: 0.8rem;">
                                        <option value="active" <?php echo $bus['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="maintenance" <?php echo $bus['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                        <option value="inactive" <?php echo $bus['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </form>
                                
                                <!-- Delete Button -->
                                <?php if ($bus['active_schedules'] == 0): ?>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this bus? This will also delete all its seats.')">
                                        <input type="hidden" name="action" value="delete_bus">
                                        <input type="hidden" name="bus_id" value="<?php echo $bus['bus_id']; ?>">
                                        <button type="submit" class="btn btn_danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 0.8rem;">Has Active Schedules</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (empty($buses)): ?>
                <div style="padding: 2rem; text-align: center; color: #666;">
                    <p>No buses found in the system.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Bus Details Summary -->
        <?php if (!empty($buses)): ?>
            <div class="table_container mt_2">
                <h3 class="p_1 mb_1">📋 Additional Information</h3>
                <div style="padding: 1rem;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                        <div style="text-align: center; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
                            <strong style="font-size: 1.5rem; color: #2c3e50;">
                                <?php echo array_sum(array_column($buses, 'actual_seats')); ?>
                            </strong><br>
                            <span style="color: #666;">Total Seats in System</span>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
                            <strong style="font-size: 1.5rem; color: #2c3e50;">
                                <?php echo array_sum(array_column($buses, 'active_schedules')); ?>
                            </strong><br>
                            <span style="color: #666;">Active Schedules</span>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
                            <strong style="font-size: 1.5rem; color: #2c3e50;">
                                <?php echo count(array_unique(array_column($buses, 'operator_id'))); ?>
                            </strong><br>
                            <span style="color: #666;">Active Operators</span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>