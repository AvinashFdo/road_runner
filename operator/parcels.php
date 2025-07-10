<?php
// FIXED VERSION - Operator Parcel Management
// Save this as: operator/parcels.php

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

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $parcel_id = $_POST['parcel_id'] ?? '';
    $new_status = $_POST['new_status'] ?? '';
    
    try {
        // Verify parcel is on operator's route
        $stmt = $pdo->prepare("
            SELECT p.tracking_number, r.route_name 
            FROM parcels p 
            JOIN routes r ON p.route_id = r.route_id 
            WHERE p.parcel_id = ? 
            AND EXISTS (
                SELECT 1 FROM schedules s 
                JOIN buses b ON s.bus_id = b.bus_id 
                WHERE s.route_id = r.route_id AND b.operator_id = ?
            )
        ");
        $stmt->execute([$parcel_id, $operator_id]);
        $parcel = $stmt->fetch();
        
        if (!$parcel) {
            $error = "Parcel not found or not assigned to your routes.";
        } else {
            $stmt = $pdo->prepare("UPDATE parcels SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE parcel_id = ?");
            $stmt->execute([$new_status, $parcel_id]);
            $message = "Parcel {$parcel['tracking_number']} status updated to " . ucfirst($new_status);
        }
    } catch (PDOException $e) {
        $error = "Error updating parcel status: " . $e->getMessage();
    }
}

// Get filter parameters - DEFAULT TO SHOW ALL DATES
$filter_date = $_GET['date'] ?? 'all';  // Changed from date('Y-m-d') to 'all'
$filter_status = $_GET['status'] ?? 'all';
$filter_route = $_GET['route'] ?? 'all';

// Get operator's routes
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT r.route_id, r.route_name, r.origin, r.destination
        FROM routes r
        JOIN schedules s ON r.route_id = s.route_id
        JOIN buses b ON s.bus_id = b.bus_id
        WHERE b.operator_id = ? AND s.status = 'active'
        ORDER BY r.route_name ASC
    ");
    $stmt->execute([$operator_id]);
    $operator_routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $operator_routes = [];
}

// FIXED: Get parcels for operator's routes (avoiding duplicates)
try {
    $where_conditions = [];
    $params = [$operator_id];
    
    // Add date filter
    if ($filter_date !== 'all') {
        $where_conditions[] = "p.travel_date = ?";
        $params[] = $filter_date;
    }
    
    // Add status filter
    if ($filter_status !== 'all') {
        $where_conditions[] = "p.status = ?";
        $params[] = $filter_status;
    }
    
    // Add route filter
    if ($filter_route !== 'all') {
        $where_conditions[] = "r.route_id = ?";
        $params[] = $filter_route;
    }
    
    $additional_where = !empty($where_conditions) ? " AND " . implode(" AND ", $where_conditions) : "";
    
    // FIXED QUERY: Use DISTINCT to avoid duplicates and EXISTS to check operator access
    $stmt = $pdo->prepare("
        SELECT DISTINCT
            p.parcel_id, p.tracking_number, p.sender_name, p.sender_phone,
            p.receiver_name, p.receiver_phone, p.receiver_address,
            p.weight_kg, p.parcel_type, p.delivery_cost, p.status, 
            p.travel_date, p.created_at,
            r.route_name, r.origin, r.destination, r.distance_km,
            u.full_name as sender_full_name
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        JOIN users u ON p.sender_id = u.user_id
        WHERE EXISTS (
            SELECT 1 FROM schedules s 
            JOIN buses b ON s.bus_id = b.bus_id 
            WHERE s.route_id = r.route_id 
            AND b.operator_id = ? 
            AND s.status = 'active'
        )
        {$additional_where}
        ORDER BY p.travel_date DESC, p.created_at DESC
    ");
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();
    
    // Get summary statistics
    $total_parcels = count($parcels);
    $pending_count = count(array_filter($parcels, fn($p) => $p['status'] === 'pending'));
    $in_transit_count = count(array_filter($parcels, fn($p) => $p['status'] === 'in_transit'));
    $delivered_count = count(array_filter($parcels, fn($p) => $p['status'] === 'delivered'));
    $total_revenue = array_sum(array_column($parcels, 'delivery_cost'));
    
} catch (PDOException $e) {
    $error = "Error loading parcels: " . $e->getMessage();
    $parcels = [];
    $total_parcels = $pending_count = $in_transit_count = $delivered_count = $total_revenue = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Management - Road Runner Operator</title>
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
                <a href="buses.php">My Buses</a>
                <a href="schedules.php">Routes & Schedules</a>
                <a href="parcels.php">Parcel Management</a>
                <a href="#" onclick="alert('Coming soon!')">Revenue Reports</a>
                <a href="#" onclick="alert('Coming soon!')">Profile Settings</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">📦 Parcel Management</h2>

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

        <!-- Statistics Cards -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $pending_count; ?></div>
                <div class="stat_label">Pending Parcels</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo $in_transit_count; ?></div>
                <div class="stat_label">In Transit</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo $delivered_count; ?></div>
                <div class="stat_label">Delivered</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($total_revenue); ?></div>
                <div class="stat_label">Revenue (Filtered)</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="form_container mb_2">
            <h3>📋 Filter Parcels</h3>
            <form method="GET" action="parcels.php">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; align-items: end;">
                    <div class="form_group">
                        <label for="date">Travel Date:</label>
                        <select id="date" name="date" class="form_control">
                            <option value="all" <?php echo $filter_date === 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <option value="<?php echo date('Y-m-d'); ?>" <?php echo $filter_date === date('Y-m-d') ? 'selected' : ''; ?>>Today</option>
                            <option value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" <?php echo $filter_date === date('Y-m-d', strtotime('+1 day')) ? 'selected' : ''; ?>>Tomorrow</option>
                            <option value="custom">Custom Date...</option>
                        </select>
                        <input 
                            type="date" 
                            id="custom_date" 
                            name="custom_date" 
                            class="form_control" 
                            style="display: none; margin-top: 0.5rem;"
                            onchange="document.getElementById('date').value = this.value;"
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form_control">
                            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="in_transit" <?php echo $filter_status === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                            <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form_group">
                        <label for="route">Route:</label>
                        <select id="route" name="route" class="form_control">
                            <option value="all" <?php echo $filter_route === 'all' ? 'selected' : ''; ?>>All Routes</option>
                            <?php foreach ($operator_routes as $route): ?>
                                <option value="<?php echo $route['route_id']; ?>" <?php echo $filter_route == $route['route_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($route['route_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn_primary">Filter</button>
                    <a href="parcels.php" class="btn">Clear Filters</a>
                </div>
            </form>
        </div>

        <!-- Parcels List -->
        <div class="table_container">
            <h3 class="p_1 mb_1">
                Parcels for Your Routes (<?php echo $total_parcels; ?> found)
                <?php if ($filter_date !== 'all'): ?>
                    <small style="font-weight: normal; color: #666;">
                        - <?php echo $filter_date === date('Y-m-d') ? 'Today' : ($filter_date === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : date('M j, Y', strtotime($filter_date))); ?>
                    </small>
                <?php else: ?>
                    <small style="font-weight: normal; color: #666;">- All Dates</small>
                <?php endif; ?>
            </h3>
            
            <?php if (empty($parcels)): ?>
                <div class="p_2 text_center">
                    <h4>No parcels found for your routes</h4>
                    <p>No parcels have been booked on your scheduled routes yet.</p>
                    <div style="margin: 1rem 0;">
                        <a href="parcels.php" class="btn btn_primary">Refresh View</a>
                        <a href="../send_parcel.php" class="btn btn_success" target="_blank">Test Send Parcel</a>
                    </div>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Tracking Number</th>
                                <th>Route & Date</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Parcel Details</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parcels as $parcel): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['tracking_number']); ?></strong><br>
                                        <small style="color: #666;">
                                            Booked: <?php echo date('M j, g:i A', strtotime($parcel['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['route_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($parcel['origin']); ?> → <?php echo htmlspecialchars($parcel['destination']); ?>
                                        </small><br>
                                        <small style="color: #666;">
                                            <strong><?php echo date('M j, Y', strtotime($parcel['travel_date'])); ?></strong>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['sender_name']); ?></strong><br>
                                        <small style="color: #666;"><?php echo htmlspecialchars($parcel['sender_phone']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['receiver_name']); ?></strong><br>
                                        <small style="color: #666;"><?php echo htmlspecialchars($parcel['receiver_phone']); ?></small><br>
                                        <small style="color: #888; font-size: 0.8rem;">
                                            <?php echo htmlspecialchars(substr($parcel['receiver_address'], 0, 50)); ?>
                                            <?php echo strlen($parcel['receiver_address']) > 50 ? '...' : ''; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['parcel_type']); ?></strong><br>
                                        <small style="color: #666;">Weight: <?php echo $parcel['weight_kg']; ?> kg</small><br>
                                        <small style="color: #e74c3c; font-weight: bold;">LKR <?php echo number_format($parcel['delivery_cost']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge badge_<?php 
                                            echo $parcel['status'] === 'delivered' ? 'active' : 
                                                ($parcel['status'] === 'cancelled' ? 'inactive' : 'operator'); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $parcel['status'])); ?>
                                        </span>
                                        <br><small style="color: #666;">
                                            <?php 
                                            $travel_time = strtotime($parcel['travel_date']);
                                            $now = time();
                                            if ($travel_time > $now) {
                                                $days_until = ceil(($travel_time - $now) / 86400);
                                                echo "In {$days_until} day" . ($days_until > 1 ? 's' : '');
                                            } elseif ($travel_time < $now - 86400) {
                                                echo "Past due";
                                            } else {
                                                echo "Today";
                                            }
                                            ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?php if ($parcel['status'] !== 'delivered' && $parcel['status'] !== 'cancelled'): ?>
                                            <form method="POST" style="display: inline-block; margin-bottom: 0.5rem;">
                                                <input type="hidden" name="parcel_id" value="<?php echo $parcel['parcel_id']; ?>">
                                                <select name="new_status" class="form_control" style="font-size: 0.8rem; padding: 0.25rem;" onchange="confirmStatusUpdate(this)">
                                                    <option value="">Update Status</option>
                                                    <?php if ($parcel['status'] === 'pending'): ?>
                                                        <option value="in_transit">Mark In Transit</option>
                                                    <?php endif; ?>
                                                    <?php if ($parcel['status'] === 'in_transit'): ?>
                                                        <option value="delivered">Mark Delivered</option>
                                                    <?php endif; ?>
                                                </select>
                                                <input type="hidden" name="update_status" value="1">
                                            </form>
                                        <?php endif; ?>
                                        
                                        <a href="../track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>" 
                                           class="btn btn_primary" 
                                           style="font-size: 0.8rem; padding: 0.25rem 0.5rem;" 
                                           target="_blank">
                                            View Details
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bulk Actions Info -->
                <div class="p_1" style="border-top: 1px solid #eee; background: #f8f9fa;">
                    <small style="color: #666;">
                        💡 <strong>Tip:</strong> Update parcel status as they progress through your route. 
                        Customers receive automatic notifications for status changes.
                    </small>
                </div>
            <?php endif; ?>
        </div>

        <!-- Today's Parcels Quick View -->
        <?php
        $today_parcels = array_filter($parcels, fn($p) => $p['travel_date'] === date('Y-m-d'));
        $tomorrow_parcels = array_filter($parcels, fn($p) => $p['travel_date'] === date('Y-m-d', strtotime('+1 day')));
        ?>
        
        <div class="features_grid mt_2">
            <div class="feature_card">
                <h4>📅 Today's Parcels</h4>
                <p><strong><?php echo count($today_parcels); ?></strong> parcels scheduled for today</p>
                <p><strong>LKR <?php echo number_format(array_sum(array_column($today_parcels, 'delivery_cost'))); ?></strong> potential revenue</p>
                <a href="?date=<?php echo date('Y-m-d'); ?>" class="btn btn_primary">View Today's Parcels</a>
            </div>
            
            <div class="feature_card">
                <h4>📅 Tomorrow's Parcels</h4>
                <p><strong><?php echo count($tomorrow_parcels); ?></strong> parcels scheduled for tomorrow</p>
                <p>Start preparing for pickup and transport</p>
                <a href="?date=<?php echo date('Y-m-d', strtotime('+1 day')); ?>" class="btn btn_success">View Tomorrow's Parcels</a>
            </div>
            
            <div class="feature_card">
                <h4>📞 Need Support?</h4>
                <p>Contact our support team for assistance with parcel management or system issues.</p>
                <button class="btn btn_success" onclick="alert('Support: +94 11 123 4567 | Email: operator@roadrunner.lk')">
                    Contact Support
                </button>
            </div>
        </div>

        <!-- Parcel Management Guide -->
        <div class="alert alert_info mt_2">
            <h4>📦 Parcel Management Guide</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>📋 Status Updates:</strong><br>
                    Keep parcel status current. Mark "In Transit" when loading and "Delivered" when passengers pick up.
                </div>
                <div>
                    <strong>📱 Customer Communication:</strong><br>
                    Status updates automatically notify both sender and receiver via SMS.
                </div>
                <div>
                    <strong>🚌 Loading Process:</strong><br>
                    Load parcels securely in designated cargo area. Verify tracking numbers during loading.
                </div>
                <div>
                    <strong>📍 Delivery Process:</strong><br>
                    Notify passengers about parcel pickups at destination station. Verify receiver identity.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Operator Panel. Reliable parcel delivery!</p>
        </div>
    </footer>

    <script>
        // Improved status update with confirmation
        function confirmStatusUpdate(selectElement) {
            if (selectElement.value) {
                const trackingNumber = selectElement.closest('tr').querySelector('strong').textContent;
                const newStatus = selectElement.value.replace('_', ' ');
                
                if (confirm(`Update parcel ${trackingNumber} status to "${newStatus}"?\n\nThis will notify both sender and receiver.`)) {
                    selectElement.form.submit();
                } else {
                    selectElement.value = ''; // Reset selection
                }
            }
        }
        
        // Auto-set date filter behavior
        document.addEventListener('DOMContentLoaded', function() {
            const dateSelect = document.getElementById('date');
            const customDateInput = document.getElementById('custom_date');
            
            // Handle date filter changes
            dateSelect.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customDateInput.style.display = 'block';
                    customDateInput.focus();
                } else {
                    customDateInput.style.display = 'none';
                }
            });
            
            // If custom date was selected, show the input
            const urlParams = new URLSearchParams(window.location.search);
            const currentDate = urlParams.get('date');
            if (currentDate && !['all', '<?php echo date('Y-m-d'); ?>', '<?php echo date('Y-m-d', strtotime('+1 day')); ?>'].includes(currentDate)) {
                dateSelect.value = currentDate;
                customDateInput.style.display = 'block';
                customDateInput.value = currentDate;
            }
        });
    </script>
</body>
</html>