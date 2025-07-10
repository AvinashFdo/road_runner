<?php
// Admin Parcel Management System
// Save this as: admin/parcels.php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle admin actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $parcel_id = $_POST['parcel_id'] ?? '';
        $new_status = $_POST['new_status'] ?? '';
        
        try {
            $stmt = $pdo->prepare("UPDATE parcels SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE parcel_id = ?");
            $stmt->execute([$new_status, $parcel_id]);
            $message = "Parcel status updated successfully.";
        } catch (PDOException $e) {
            $error = "Error updating parcel status: " . $e->getMessage();
        }
    }
    
    elseif ($action === 'delete_parcel') {
        $parcel_id = $_POST['parcel_id'] ?? '';
        
        try {
            // Only allow deletion of cancelled parcels
            $stmt = $pdo->prepare("SELECT status FROM parcels WHERE parcel_id = ?");
            $stmt->execute([$parcel_id]);
            $parcel = $stmt->fetch();
            
            if ($parcel && $parcel['status'] === 'cancelled') {
                $stmt = $pdo->prepare("DELETE FROM parcels WHERE parcel_id = ?");
                $stmt->execute([$parcel_id]);
                $message = "Cancelled parcel deleted successfully.";
            } else {
                $error = "Only cancelled parcels can be deleted.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting parcel: " . $e->getMessage();
        }
    }
    
    elseif ($action === 'bulk_status_update') {
        $parcel_ids = $_POST['parcel_ids'] ?? [];
        $bulk_status = $_POST['bulk_status'] ?? '';
        
        if (!empty($parcel_ids) && !empty($bulk_status)) {
            try {
                $placeholders = str_repeat('?,', count($parcel_ids) - 1) . '?';
                $stmt = $pdo->prepare("UPDATE parcels SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE parcel_id IN ($placeholders)");
                $params = array_merge([$bulk_status], $parcel_ids);
                $stmt->execute($params);
                $message = count($parcel_ids) . " parcel(s) updated to " . ucfirst($bulk_status) . ".";
            } catch (PDOException $e) {
                $error = "Error updating parcels: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$filter_date = $_GET['date'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_route = $_GET['route'] ?? 'all';
$filter_operator = $_GET['operator'] ?? 'all';
$search = $_GET['search'] ?? '';

// Get all routes for filter
try {
    $stmt = $pdo->query("SELECT route_id, route_name, origin, destination FROM routes WHERE status = 'active' ORDER BY route_name ASC");
    $all_routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_routes = [];
}

// Get all operators for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT u.user_id, u.full_name FROM users u JOIN buses b ON u.user_id = b.operator_id WHERE u.user_type = 'operator' ORDER BY u.full_name ASC");
    $all_operators = $stmt->fetchAll();
} catch (PDOException $e) {
    $all_operators = [];
}

// Build comprehensive parcel query
try {
    $where_conditions = ["1=1"];
    $params = [];
    
    // Date filter
    if ($filter_date !== 'all') {
        $where_conditions[] = "p.travel_date = ?";
        $params[] = $filter_date;
    }
    
    // Status filter
    if ($filter_status !== 'all') {
        $where_conditions[] = "p.status = ?";
        $params[] = $filter_status;
    }
    
    // Route filter
    if ($filter_route !== 'all') {
        $where_conditions[] = "r.route_id = ?";
        $params[] = $filter_route;
    }
    
    // Operator filter
    if ($filter_operator !== 'all') {
        $where_conditions[] = "b.operator_id = ?";
        $params[] = $filter_operator;
    }
    
    // Search filter
    if (!empty($search)) {
        $where_conditions[] = "(p.tracking_number LIKE ? OR p.sender_name LIKE ? OR p.receiver_name LIKE ? OR p.receiver_phone LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    // Main query with all parcel information
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            r.route_name, r.origin, r.destination, r.distance_km,
            u.full_name as sender_full_name, u.email as sender_email,
            op.full_name as operator_name,
            b.bus_name, b.bus_number
        FROM parcels p
        JOIN routes r ON p.route_id = r.route_id
        JOIN users u ON p.sender_id = u.user_id
        LEFT JOIN schedules s ON r.route_id = s.route_id AND s.status = 'active'
        LEFT JOIN buses b ON s.bus_id = b.bus_id
        LEFT JOIN users op ON b.operator_id = op.user_id AND op.user_type = 'operator'
        WHERE {$where_clause}
        GROUP BY p.parcel_id
        ORDER BY p.travel_date DESC, p.created_at DESC
    ");
    $stmt->execute($params);
    $parcels = $stmt->fetchAll();
    
    // Calculate statistics
    $total_parcels = count($parcels);
    $pending_count = count(array_filter($parcels, fn($p) => $p['status'] === 'pending'));
    $in_transit_count = count(array_filter($parcels, fn($p) => $p['status'] === 'in_transit'));
    $delivered_count = count(array_filter($parcels, fn($p) => $p['status'] === 'delivered'));
    $cancelled_count = count(array_filter($parcels, fn($p) => $p['status'] === 'cancelled'));
    $total_revenue = array_sum(array_column($parcels, 'delivery_cost'));
    
    // Get system statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_system_parcels,
            SUM(delivery_cost) as total_system_revenue,
            COUNT(DISTINCT sender_id) as unique_senders,
            COUNT(DISTINCT route_id) as routes_used
        FROM parcels 
        WHERE status != 'cancelled'
    ");
    $system_stats = $stmt->fetch();
    
} catch (PDOException $e) {
    $error = "Error loading parcels: " . $e->getMessage();
    $parcels = [];
    $total_parcels = $pending_count = $in_transit_count = $delivered_count = $cancelled_count = $total_revenue = 0;
    $system_stats = ['total_system_parcels' => 0, 'total_system_revenue' => 0, 'unique_senders' => 0, 'routes_used' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Management - Road Runner Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        .parcel-card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; background: white; }
        .parcel-header { display: flex; justify-content: between; align-items: center; margin-bottom: 1rem; }
        .parcel-details { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
        .status-update-form { display: inline-block; margin-right: 0.5rem; }
        .bulk-actions { background: #f8f9fa; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Admin</div>
                <ul class="nav_links">
                    <li><a href="dashboard.php">Dashboard</a></li>
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
                <a href="#" onclick="alert('Coming soon!')">Manage Users</a>
                <a href="#" onclick="alert('Coming soon!')">View All Buses</a>
                <a href="#" onclick="alert('Coming soon!')">System Reports</a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">📦 Admin Parcel Management</h2>

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

        <!-- System Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $system_stats['total_system_parcels']; ?></div>
                <div class="stat_label">Total System Parcels</div>
            </div>
            <div class="stat_card">
                <div class="stat_number">LKR <?php echo number_format($system_stats['total_system_revenue']); ?></div>
                <div class="stat_label">Total System Revenue</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo $system_stats['unique_senders']; ?></div>
                <div class="stat_label">Unique Customers</div>
            </div>
            <div class="stat_card">
                <div class="stat_number"><?php echo $system_stats['routes_used']; ?></div>
                <div class="stat_label">Routes Used</div>
            </div>
        </div>

        <!-- Filtered Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $pending_count; ?></div>
                <div class="stat_label">Pending</div>
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
                <div class="stat_number"><?php echo $cancelled_count; ?></div>
                <div class="stat_label">Cancelled</div>
            </div>
        </div>

        <!-- Filters and Search -->
        <div class="form_container mb_2">
            <h3>🔍 Filter & Search Parcels</h3>
            <form method="GET" action="parcels.php">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1rem;">
                    <div class="form_group">
                        <label for="search">Search:</label>
                        <input 
                            type="text" 
                            id="search" 
                            name="search" 
                            class="form_control" 
                            value="<?php echo htmlspecialchars($search); ?>"
                            placeholder="Tracking, name, phone..."
                        >
                    </div>
                    
                    <div class="form_group">
                        <label for="date">Travel Date:</label>
                        <select id="date" name="date" class="form_control">
                            <option value="all" <?php echo $filter_date === 'all' ? 'selected' : ''; ?>>All Dates</option>
                            <option value="<?php echo date('Y-m-d'); ?>" <?php echo $filter_date === date('Y-m-d') ? 'selected' : ''; ?>>Today</option>
                            <option value="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" <?php echo $filter_date === date('Y-m-d', strtotime('+1 day')) ? 'selected' : ''; ?>>Tomorrow</option>
                            <option value="<?php echo date('Y-m-d', strtotime('-1 day')); ?>" <?php echo $filter_date === date('Y-m-d', strtotime('-1 day')) ? 'selected' : ''; ?>>Yesterday</option>
                        </select>
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
                            <?php foreach ($all_routes as $route): ?>
                                <option value="<?php echo $route['route_id']; ?>" <?php echo $filter_route == $route['route_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($route['route_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form_group">
                        <label for="operator">Operator:</label>
                        <select id="operator" name="operator" class="form_control">
                            <option value="all" <?php echo $filter_operator === 'all' ? 'selected' : ''; ?>>All Operators</option>
                            <?php foreach ($all_operators as $operator): ?>
                                <option value="<?php echo $operator['user_id']; ?>" <?php echo $filter_operator == $operator['user_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($operator['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn btn_primary">🔍 Filter</button>
                    <a href="parcels.php" class="btn">Clear All</a>
                </div>
            </form>
        </div>

        <!-- Bulk Actions -->
        <?php if (!empty($parcels)): ?>
        <div class="bulk-actions">
            <h4>📋 Bulk Actions</h4>
            <form method="POST" id="bulk-form">
                <input type="hidden" name="action" value="bulk_status_update">
                <div style="display: flex; gap: 1rem; align-items: end;">
                    <div class="form_group">
                        <label>Selected Parcels:</label>
                        <div style="background: #e9ecef; padding: 0.5rem; border-radius: 4px; min-height: 2rem;">
                            <span id="selected-count">0 selected</span>
                        </div>
                    </div>
                    <div class="form_group">
                        <label for="bulk_status">Update Status To:</label>
                        <select name="bulk_status" id="bulk_status" class="form_control">
                            <option value="">Choose status...</option>
                            <option value="pending">Pending</option>
                            <option value="in_transit">In Transit</option>
                            <option value="delivered">Delivered</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn_primary" onclick="return confirmBulkUpdate()">Update Selected</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Parcels List -->
        <div class="table_container">
            <h3 class="p_1 mb_1">
                All Parcels (<?php echo $total_parcels; ?> found)
                <?php if ($total_revenue > 0): ?>
                    <small style="font-weight: normal; color: #666;">
                        - Total Value: LKR <?php echo number_format($total_revenue); ?>
                    </small>
                <?php endif; ?>
            </h3>
            
            <?php if (empty($parcels)): ?>
                <div class="p_2 text_center">
                    <h4>No parcels found</h4>
                    <p>No parcels match your current filters. Try adjusting the search criteria.</p>
                    <a href="parcels.php" class="btn btn_primary mt_1">View All Parcels</a>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>
                                    <input type="checkbox" id="select-all" onchange="toggleAllCheckboxes()"> Select
                                </th>
                                <th>Tracking & Date</th>
                                <th>Sender</th>
                                <th>Receiver</th>
                                <th>Route & Operator</th>
                                <th>Parcel Details</th>
                                <th>Status</th>
                                <th>Admin Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($parcels as $parcel): ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="parcel_ids[]" value="<?php echo $parcel['parcel_id']; ?>" class="parcel-checkbox" onchange="updateSelectedCount()">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['tracking_number']); ?></strong><br>
                                        <small style="color: #666;">
                                            Travel: <?php echo date('M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                            Booked: <?php echo date('M j, g:i A', strtotime($parcel['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['sender_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($parcel['sender_phone']); ?><br>
                                            <?php echo htmlspecialchars($parcel['sender_email']); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['receiver_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($parcel['receiver_phone']); ?><br>
                                            <?php echo htmlspecialchars(substr($parcel['receiver_address'], 0, 30)); ?>...
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['route_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($parcel['origin']); ?> → <?php echo htmlspecialchars($parcel['destination']); ?><br>
                                            <?php if ($parcel['operator_name']): ?>
                                                Operator: <?php echo htmlspecialchars($parcel['operator_name']); ?>
                                            <?php else: ?>
                                                <span style="color: #e74c3c;">No operator assigned</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['parcel_type']); ?></strong><br>
                                        <small style="color: #666;">
                                            Weight: <?php echo $parcel['weight_kg']; ?> kg<br>
                                            Distance: <?php echo $parcel['distance_km']; ?> km
                                        </small><br>
                                        <strong style="color: #e74c3c;">LKR <?php echo number_format($parcel['delivery_cost']); ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge_<?php 
                                            echo $parcel['status'] === 'delivered' ? 'active' : 
                                                ($parcel['status'] === 'cancelled' ? 'inactive' : 'operator'); 
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $parcel['status'])); ?>
                                        </span>
                                        <br><small style="color: #666;">
                                            Updated: <?php echo date('M j, g:i A', strtotime($parcel['updated_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                            <!-- Status Update -->
                                            <form method="POST" class="status-update-form">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="parcel_id" value="<?php echo $parcel['parcel_id']; ?>">
                                                <select name="new_status" class="form_control" style="font-size: 0.8rem; padding: 0.25rem;" onchange="confirmStatusUpdate(this)">
                                                    <option value="">Change Status</option>
                                                    <option value="pending">Pending</option>
                                                    <option value="in_transit">In Transit</option>
                                                    <option value="delivered">Delivered</option>
                                                    <option value="cancelled">Cancelled</option>
                                                </select>
                                            </form>
                                            
                                            <!-- Action Buttons -->
                                            <div style="display: flex; gap: 0.25rem;">
                                                <a href="../track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>" 
                                                   class="btn btn_primary" 
                                                   style="font-size: 0.7rem; padding: 0.2rem 0.4rem;" 
                                                   target="_blank">
                                                    Track
                                                </a>
                                                
                                                <?php if ($parcel['status'] === 'cancelled'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_parcel">
                                                        <input type="hidden" name="parcel_id" value="<?php echo $parcel['parcel_id']; ?>">
                                                        <button type="submit" 
                                                                class="btn" 
                                                                style="background: #dc3545; font-size: 0.7rem; padding: 0.2rem 0.4rem;"
                                                                onclick="return confirm('Permanently delete this cancelled parcel?')">
                                                            Delete
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Quick Actions -->
        <div class="features_grid mt_2">
            <div class="feature_card">
                <h4>📊 System Analytics</h4>
                <p>View detailed reports and analytics about the parcel delivery system performance.</p>
                <button class="btn btn_primary" onclick="alert('Analytics feature coming soon!')">View Analytics</button>
            </div>
            
            <div class="feature_card">
                <h4>🔧 System Settings</h4>
                <p>Configure parcel delivery settings, pricing rules, and system parameters.</p>
                <button class="btn btn_success" onclick="alert('Settings feature coming soon!')">Manage Settings</button>
            </div>
            
            <div class="feature_card">
                <h4>📞 Support Tools</h4>
                <p>Access customer support tools and parcel dispute resolution features.</p>
                <button class="btn btn_success" onclick="alert('Support tools coming soon!')">Support Tools</button>
            </div>
        </div>

        <!-- Admin Guidelines -->
        <div class="alert alert_info mt_2">
            <h4>👑 Admin Parcel Management Guidelines</h4>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-top: 1rem;">
                <div>
                    <strong>🔍 Monitor System Health:</strong><br>
                    Regularly check for stuck parcels, delayed deliveries, and system anomalies.
                </div>
                <div>
                    <strong>🚨 Handle Disputes:</strong><br>
                    Resolve customer complaints, missing parcels, and delivery issues promptly.
                </div>
                <div>
                    <strong>📊 Track Performance:</strong><br>
                    Monitor delivery success rates, operator performance, and customer satisfaction.
                </div>
                <div>
                    <strong>⚙️ System Maintenance:</strong><br>
                    Regularly clean up cancelled parcels and maintain data integrity.
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Admin Panel. Complete system oversight!</p>
        </div>
    </footer>

    <script>
        // Bulk selection functionality
        function toggleAllCheckboxes() {
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.parcel-checkbox');
            
            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAll.checked;
            });
            
            updateSelectedCount();
        }
        
        function updateSelectedCount() {
            const checkedBoxes = document.querySelectorAll('.parcel-checkbox:checked');
            const count = checkedBoxes.length;
            const selectedCount = document.getElementById('selected-count');
            
            selectedCount.textContent = count + ' selected';
            
            // Update bulk form
            const bulkForm = document.getElementById('bulk-form');
            const existingInputs = bulkForm.querySelectorAll('input[name="parcel_ids[]"]');
            existingInputs.forEach(input => input.remove());
            
            checkedBoxes.forEach(checkbox => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'parcel_ids[]';
                hiddenInput.value = checkbox.value;
                bulkForm.appendChild(hiddenInput);
            });
        }
        
        function confirmBulkUpdate() {
            const selectedCount = document.querySelectorAll('.parcel-checkbox:checked').length;
            const status = document.getElementById('bulk_status').value;
            
            if (selectedCount === 0) {
                alert('Please select at least one parcel.');
                return false;
            }
            
            if (!status) {
                alert('Please select a status to update to.');
                return false;
            }
            
            return confirm(`Update ${selectedCount} parcel(s) to "${status}"?`);
        }
        
        function confirmStatusUpdate(selectElement) {
            if (selectElement.value) {
                const trackingNumber = selectElement.closest('tr').querySelector('strong').textContent;
                const newStatus = selectElement.value.replace('_', ' ');
                
                if (confirm(`Update parcel ${trackingNumber} status to "${newStatus}"?`)) {
                    selectElement.form.submit();
                } else {
                    selectElement.value = ''; // Reset selection
                }
            }
        }
        
        // Auto-refresh functionality
        let autoRefresh = false;
        function toggleAutoRefresh() {
            autoRefresh = !autoRefresh;
            const button = document.getElementById('auto-refresh-btn');
            
            if (autoRefresh) {
                button.textContent = 'Stop Auto-Refresh';
                button.className = 'btn';
                button.style.background = '#e74c3c';
                setTimeout(function refreshPage() {
                    if (autoRefresh) {
                        location.reload();
                    }
                }, 30000); // Refresh every 30 seconds
            } else {
                button.textContent = 'Auto-Refresh (30s)';
                button.className = 'btn btn_success';
                button.style.background = '';
            }
        }
        
        // Search functionality enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search');
            let searchTimeout;
            
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    if (this.value.length >= 3 || this.value.length === 0) {
                        // Auto-submit search after 1 second of no typing
                        // this.form.submit();
                    }
                }, 1000);
            });
        });
    </script>
</body>
</html>