<?php

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

// FIXED: Function to handle parcel status display
function getParcelStatusDisplay($status)
{
    // Handle empty/null status as cancelled
    $trimmed_status = trim($status ?? '');
    if (empty($trimmed_status)) {
        $trimmed_status = 'cancelled';
    }

    switch ($trimmed_status) {
        case 'delivered':
            return [
                'class' => 'badge_active',
                'text' => 'Delivered',
                'icon' => '✅'
            ];
        case 'cancelled':
        case 'refunded':
            return [
                'class' => 'badge_inactive',
                'text' => 'Cancelled',
                'icon' => '❌'
            ];
        case 'in_transit':
            return [
                'class' => 'badge_operator',
                'text' => 'In Transit',
                'icon' => '🚌'
            ];
        case 'pending':
        default:
            return [
                'class' => 'badge_passenger',
                'text' => 'Pending',
                'icon' => '⏳'
            ];
    }
}

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
    } elseif ($action === 'delete_parcel') {
        $parcel_id = $_POST['parcel_id'] ?? '';

        try {
            // FIXED: Allow deletion of cancelled parcels (including empty status)
            $stmt = $pdo->prepare("SELECT status FROM parcels WHERE parcel_id = ?");
            $stmt->execute([$parcel_id]);
            $parcel = $stmt->fetch();

            if ($parcel) {
                $status = trim($parcel['status'] ?? '');
                if (empty($status) || $status === 'cancelled' || $status === 'refunded') {
                    $stmt = $pdo->prepare("DELETE FROM parcels WHERE parcel_id = ?");
                    $stmt->execute([$parcel_id]);
                    $message = "Cancelled parcel deleted successfully.";
                } else {
                    $error = "Only cancelled parcels can be deleted.";
                }
            } else {
                $error = "Parcel not found.";
            }
        } catch (PDOException $e) {
            $error = "Error deleting parcel: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$filter_date = $_GET['date'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$filter_route = $_GET['route'] ?? 'all';
$filter_operator = $_GET['operator'] ?? 'all';
$search = $_GET['search'] ?? '';

try {
    // Build dynamic WHERE clause based on filters
    $where_conditions = ["1=1"]; // Always true base condition
    $params = [];

    // Date filter
    if ($filter_date !== 'all') {
        switch ($filter_date) {
            case 'today':
                $where_conditions[] = "DATE(p.travel_date) = CURDATE()";
                break;
            case 'this_week':
                $where_conditions[] = "WEEK(p.travel_date) = WEEK(CURDATE()) AND YEAR(p.travel_date) = YEAR(CURDATE())";
                break;
            case 'this_month':
                $where_conditions[] = "MONTH(p.travel_date) = MONTH(CURDATE()) AND YEAR(p.travel_date) = YEAR(CURDATE())";
                break;
        }
    }

    // Status filter
    if ($filter_status !== 'all') {
        if ($filter_status === 'cancelled') {
            // FIXED: Include empty/null status as cancelled
            $where_conditions[] = "(p.status IS NULL OR p.status = '' OR p.status = 'cancelled' OR p.status = 'refunded')";
        } else {
            $where_conditions[] = "p.status = ?";
            $params[] = $filter_status;
        }
    }

    // Route filter
    if ($filter_route !== 'all') {
        $where_conditions[] = "p.route_id = ?";
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

    // FIXED: Calculate statistics with proper cancelled counting
    $total_parcels = count($parcels);
    $pending_count = count(array_filter($parcels, fn($p) => trim($p['status'] ?? '') === 'pending'));
    $in_transit_count = count(array_filter($parcels, fn($p) => $p['status'] === 'in_transit'));
    $delivered_count = count(array_filter($parcels, fn($p) => $p['status'] === 'delivered'));
    $cancelled_count = count(array_filter($parcels, function ($p) {
        $status = trim($p['status'] ?? '');
        return empty($status) || $status === 'cancelled' || $status === 'refunded';
    }));
    $total_revenue = array_sum(array_column($parcels, 'delivery_cost'));

    // Get system statistics
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total_system_parcels,
            SUM(delivery_cost) as total_system_revenue,
            COUNT(DISTINCT sender_id) as unique_senders,
            COUNT(DISTINCT route_id) as routes_used
        FROM parcels 
        WHERE status != 'cancelled' AND status IS NOT NULL AND status != ''
    ");
    $system_stats = $stmt->fetch();

    // Get available routes for filter
    $stmt = $pdo->query("SELECT DISTINCT r.route_id, r.route_name FROM routes r JOIN parcels p ON r.route_id = p.route_id ORDER BY r.route_name");
    $available_routes = $stmt->fetchAll();

    // Get available operators for filter
    $stmt = $pdo->query("
        SELECT DISTINCT op.user_id, op.full_name 
        FROM users op 
        JOIN buses b ON op.user_id = b.operator_id 
        JOIN schedules s ON b.bus_id = s.bus_id 
        JOIN routes r ON s.route_id = r.route_id 
        JOIN parcels p ON r.route_id = p.route_id 
        WHERE op.user_type = 'operator'
        ORDER BY op.full_name
    ");
    $available_operators = $stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Error loading parcels: " . $e->getMessage();
    $parcels = [];
    $total_parcels = $pending_count = $in_transit_count = $delivered_count = $cancelled_count = $total_revenue = 0;
    $system_stats = ['total_system_parcels' => 0, 'total_system_revenue' => 0, 'unique_senders' => 0, 'routes_used' => 0];
    $available_routes = [];
    $available_operators = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parcel Management - Road Runner Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
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
                <a href="users.php">Manage Users</a>
                <a href="buses.php">View All Buses</a>
                <a href="bookings.php">View All Bookings</a>
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

        <!-- Filters -->
        <div class="table_container mb_2">
            <h3 class="p_1">🔍 Filter Parcels</h3>
            <div class="p_2">
                <form method="GET" action="parcels.php">
                    <div
                        style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; align-items: end;">
                        <div class="form_group">
                            <label for="date">Date Filter:</label>
                            <select name="date" id="date" class="form_control">
                                <option value="all" <?php echo $filter_date === 'all' ? 'selected' : ''; ?>>All Dates
                                </option>
                                <option value="today" <?php echo $filter_date === 'today' ? 'selected' : ''; ?>>Today
                                </option>
                                <option value="this_week" <?php echo $filter_date === 'this_week' ? 'selected' : ''; ?>>
                                    This Week</option>
                                <option value="this_month" <?php echo $filter_date === 'this_month' ? 'selected' : ''; ?>>
                                    This Month</option>
                            </select>
                        </div>

                        <div class="form_group">
                            <label for="status">Status Filter:</label>
                            <select name="status" id="status" class="form_control">
                                <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses
                                </option>
                                <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>
                                    Pending</option>
                                <option value="in_transit" <?php echo $filter_status === 'in_transit' ? 'selected' : ''; ?>>In Transit</option>
                                <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>
                                    Delivered</option>
                                <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>
                                    Cancelled</option>
                            </select>
                        </div>

                        <div class="form_group">
                            <label for="route">Route Filter:</label>
                            <select name="route" id="route" class="form_control">
                                <option value="all">All Routes</option>
                                <?php foreach ($available_routes as $route): ?>
                                    <option value="<?php echo $route['route_id']; ?>" <?php echo $filter_route == $route['route_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route['route_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form_group">
                            <label for="search">Search:</label>
                            <input type="text" name="search" id="search" class="form_control"
                                placeholder="Tracking number, name, phone..."
                                value="<?php echo htmlspecialchars($search); ?>">
                        </div>

                        <div class="form_group">
                            <label>&nbsp;</label>
                            <div style="display: flex; gap: 0.5rem;">
                                <button type="submit" class="btn btn_primary" style="flex: 1;">Apply Filters</button>
                                <a href="parcels.php" class="btn"
                                    style="background: #6c757d; color: white; text-decoration: none; flex: 1; text-align: center; display: flex; align-items: center; justify-content: center;">Clear</a>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Parcels Table -->
        <div class="table_container">
            <h3 class="p_1 mb_1">📦 Parcels (Showing <?php echo count($parcels); ?> of <?php echo $total_parcels; ?>)
            </h3>
            <?php if (empty($parcels)): ?>
                <div class="p_2">
                    <div class="alert alert_info">
                        <p>No parcels found matching your criteria.</p>
                        <p><a href="parcels.php" class="btn btn_primary">View All Parcels</a></p>
                    </div>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Parcel Info</th>
                                <th>Route & Schedule</th>
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
                                            Sender: <?php echo htmlspecialchars($parcel['sender_name']); ?><br>
                                            Phone: <?php echo htmlspecialchars($parcel['sender_phone']); ?><br>
                                            Booked: <?php echo date('M j, g:i A', strtotime($parcel['created_at'])); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($parcel['route_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            <?php echo htmlspecialchars($parcel['origin']); ?> →
                                            <?php echo htmlspecialchars($parcel['destination']); ?><br>
                                            Travel: <?php echo date('M j, Y', strtotime($parcel['travel_date'])); ?><br>
                                            <?php if ($parcel['operator_name']): ?>
                                                Operator: <?php echo htmlspecialchars($parcel['operator_name']); ?>
                                            <?php else: ?>
                                                <span style="color: #e74c3c;">No operator assigned</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>
                                    <td>
                                        <strong>To: <?php echo htmlspecialchars($parcel['receiver_name']); ?></strong><br>
                                        <small style="color: #666;">
                                            Phone: <?php echo htmlspecialchars($parcel['receiver_phone']); ?><br>
                                            Type: <?php echo htmlspecialchars($parcel['parcel_type'] ?: 'General'); ?><br>
                                            Weight: <?php echo $parcel['weight_kg']; ?> kg
                                        </small><br>
                                        <strong style="color: #e74c3c;">LKR
                                            <?php echo number_format($parcel['delivery_cost']); ?></strong>
                                    </td>
                                    <td>
                                        <?php $status_info = getParcelStatusDisplay($parcel['status']); ?>
                                        <span class="badge <?php echo $status_info['class']; ?>">
                                            <?php echo $status_info['icon']; ?>         <?php echo $status_info['text']; ?>
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
                                                <input type="hidden" name="parcel_id"
                                                    value="<?php echo $parcel['parcel_id']; ?>">
                                                <select name="new_status" class="form_control"
                                                    style="font-size: 0.8rem; padding: 0.25rem;"
                                                    onchange="confirmStatusUpdate(this)">
                                                    <option value="">Change Status</option>
                                                    <option value="pending" <?php echo (trim($parcel['status'] ?? '') === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                    <option value="in_transit" <?php echo ($parcel['status'] === 'in_transit') ? 'selected' : ''; ?>>In Transit</option>
                                                    <option value="delivered" <?php echo ($parcel['status'] === 'delivered') ? 'selected' : ''; ?>>Delivered</option>
                                                    <option value="cancelled" <?php echo (empty(trim($parcel['status'] ?? '')) || $parcel['status'] === 'cancelled') ? 'selected' : ''; ?>>Cancelled
                                                    </option>
                                                </select>
                                            </form>

                                            <!-- Action Buttons -->
                                            <div style="display: flex; gap: 0.25rem;">
                                                <a href="../track_parcel.php?tracking=<?php echo urlencode($parcel['tracking_number']); ?>"
                                                    class="btn btn_primary" style="font-size: 0.7rem; padding: 0.2rem 0.4rem;"
                                                    target="_blank">
                                                    Track
                                                </a>

                                                <?php
                                                $status = trim($parcel['status'] ?? '');
                                                if (empty($status) || $status === 'cancelled' || $status === 'refunded'):
                                                    ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="delete_parcel">
                                                        <input type="hidden" name="parcel_id"
                                                            value="<?php echo $parcel['parcel_id']; ?>">
                                                        <button type="submit" class="btn"
                                                            style="background: #dc3545; font-size: 0.7rem; padding: 0.2rem 0.4rem;"
                                                            onclick="return confirm('Permanently delete this cancelled parcel? This action cannot be undone.')">
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
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner Admin Panel. Complete system oversight!</p>
        </div>
    </footer>

    <script>
        function confirmStatusUpdate(selectElement) {
            if (selectElement.value) {
                const newStatus = selectElement.value.replace('_', ' ');
                if (confirm(`Change parcel status to "${newStatus}"?`)) {
                    selectElement.form.submit();
                } else {
                    selectElement.selectedIndex = 0; // Reset to "Change Status"
                }
            }
        }

        // Auto-refresh functionality
        let refreshInterval;

        function startAutoRefresh() {
            refreshInterval = setInterval(function () {
                if (document.hasFocus()) {
                    // Only refresh statistics, not the entire page
                    location.reload();
                }
            }, 300000); // 5 minutes
        }

        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        }

        // Start auto-refresh when page loads
        document.addEventListener('DOMContentLoaded', function () {
            startAutoRefresh();

            // Stop auto-refresh when user is interacting with forms
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('focus', stopAutoRefresh, true);
                form.addEventListener('blur', startAutoRefresh, true);
            });
        });

        // Visual feedback for actions
        document.addEventListener('DOMContentLoaded', function () {
            const actionButtons = document.querySelectorAll('.btn');
            actionButtons.forEach(btn => {
                btn.addEventListener('click', function () {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = 'scale(1)';
                    }, 100);
                });
            });
        });
    </script>
</body>

</html>