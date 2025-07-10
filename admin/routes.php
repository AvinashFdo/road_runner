<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_route') {
        // Add new route
        $route_name = trim($_POST['route_name'] ?? '');
        $origin = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $distance_km = $_POST['distance_km'] ?? '';
        $estimated_duration = trim($_POST['estimated_duration'] ?? '');
        $route_description = trim($_POST['route_description'] ?? '');

        // Validation
        if (empty($route_name) || empty($origin) || empty($destination)) {
            $error = "Route name, origin, and destination are required.";
        } elseif (!is_numeric($distance_km) || $distance_km <= 0) {
            $error = "Distance must be a positive number.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO routes (route_name, origin, destination, distance_km, estimated_duration, route_description) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$route_name, $origin, $destination, $distance_km, $estimated_duration, $route_description]);
                $message = "Route added successfully!";
            } catch (PDOException $e) {
                $error = "Error adding route: " . $e->getMessage();
            }
        }
    } elseif ($action === 'update_route') {
        // Update existing route
        $route_id = $_POST['route_id'] ?? '';
        $route_name = trim($_POST['route_name'] ?? '');
        $origin = trim($_POST['origin'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $distance_km = $_POST['distance_km'] ?? '';
        $estimated_duration = trim($_POST['estimated_duration'] ?? '');
        $route_description = trim($_POST['route_description'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (empty($route_name) || empty($origin) || empty($destination)) {
            $error = "Route name, origin, and destination are required.";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE routes SET route_name = ?, origin = ?, destination = ?, distance_km = ?, estimated_duration = ?, route_description = ?, status = ? WHERE route_id = ?");
                $stmt->execute([$route_name, $origin, $destination, $distance_km, $estimated_duration, $route_description, $status, $route_id]);
                $message = "Route updated successfully!";
            } catch (PDOException $e) {
                $error = "Error updating route: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete_route') {
        // Delete route
        $route_id = $_POST['route_id'] ?? '';
        try {
            // Check if route has active schedules
            $stmt = $pdo->prepare("SELECT COUNT(*) as schedule_count FROM schedules WHERE route_id = ? AND status = 'active'");
            $stmt->execute([$route_id]);
            $result = $stmt->fetch();

            if ($result['schedule_count'] > 0) {
                $error = "Cannot delete route. It has active schedules. Please deactivate or remove schedules first.";
            } else {
                $stmt = $pdo->prepare("DELETE FROM routes WHERE route_id = ?");
                $stmt->execute([$route_id]);
                $message = "Route deleted successfully!";
            }
        } catch (PDOException $e) {
            $error = "Error deleting route: " . $e->getMessage();
        }
    }
}

// Get all routes
try {
    $stmt = $pdo->query("SELECT * FROM routes ORDER BY route_name ASC");
    $routes = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = "Error fetching routes: " . $e->getMessage();
    $routes = [];
}

// Get route for editing if edit_id is provided
$edit_route = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM routes WHERE route_id = ?");
        $stmt->execute([$_GET['edit']]);
        $edit_route = $stmt->fetch();
    } catch (PDOException $e) {
        $error = "Error fetching route for editing.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Management - Road Runner Admin</title>
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
        <h2 class="mb_2">Route Management</h2>

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

        <!-- Add/Edit Route Form -->
        <div class="form_container mb_2">
            <h3><?php echo $edit_route ? 'Edit Route' : 'Add New Route'; ?></h3>

            <form method="POST" action="routes.php">
                <input type="hidden" name="action" value="<?php echo $edit_route ? 'update_route' : 'add_route'; ?>">
                <?php if ($edit_route): ?>
                    <input type="hidden" name="route_id" value="<?php echo $edit_route['route_id']; ?>">
                <?php endif; ?>

                <div class="form_group">
                    <label for="route_name">Route Name:</label>
                    <input type="text" id="route_name" name="route_name" class="form_control"
                        value="<?php echo $edit_route ? htmlspecialchars($edit_route['route_name']) : ''; ?>"
                        placeholder="e.g., Colombo to Kandy Express" required>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="origin">Origin:</label>
                        <input type="text" id="origin" name="origin" class="form_control"
                            value="<?php echo $edit_route ? htmlspecialchars($edit_route['origin']) : ''; ?>"
                            placeholder="e.g., Colombo" required>
                    </div>

                    <div class="form_group">
                        <label for="destination">Destination:</label>
                        <input type="text" id="destination" name="destination" class="form_control"
                            value="<?php echo $edit_route ? htmlspecialchars($edit_route['destination']) : ''; ?>"
                            placeholder="e.g., Kandy" required>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form_group">
                        <label for="distance_km">Distance (km):</label>
                        <input type="number" id="distance_km" name="distance_km" class="form_control"
                            value="<?php echo $edit_route ? $edit_route['distance_km'] : ''; ?>" step="0.1" min="0"
                            placeholder="e.g., 115.5" required>
                    </div>

                    <div class="form_group">
                        <label for="estimated_duration">Estimated Duration:</label>
                        <input type="text" id="estimated_duration" name="estimated_duration" class="form_control"
                            value="<?php echo $edit_route ? htmlspecialchars($edit_route['estimated_duration']) : ''; ?>"
                            placeholder="e.g., 3h 30m">
                    </div>
                </div>

                <?php if ($edit_route): ?>
                    <div class="form_group">
                        <label for="status">Status:</label>
                        <select id="status" name="status" class="form_control">
                            <option value="active" <?php echo $edit_route['status'] === 'active' ? 'selected' : ''; ?>>Active
                            </option>
                            <option value="inactive" <?php echo $edit_route['status'] === 'inactive' ? 'selected' : ''; ?>>
                                Inactive</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="form_group">
                    <label for="route_description">Description:</label>
                    <textarea id="route_description" name="route_description" class="form_control" rows="3"
                        placeholder="Additional information about this route..."><?php echo $edit_route ? htmlspecialchars($edit_route['route_description']) : ''; ?></textarea>
                </div>

                <button type="submit" class="btn btn_primary">
                    <?php echo $edit_route ? 'Update Route' : 'Add Route'; ?>
                </button>

                <?php if ($edit_route): ?>
                    <a href="routes.php" class="btn" style="margin-left: 1rem;">Cancel Edit</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Routes List -->
        <div class="table_container">
            <h3 class="p_1 mb_1">All Routes (<?php echo count($routes); ?>)</h3>

            <?php if (empty($routes)): ?>
                <div class="p_2 text_center">
                    <p>No routes found. Add your first route above!</p>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Route Name</th>
                            <th>Origin → Destination</th>
                            <th>Distance</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($routes as $route): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($route['route_name']); ?></strong>
                                    <?php if ($route['route_description']): ?>
                                        <br><small
                                            style="color: #666;"><?php echo htmlspecialchars($route['route_description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($route['origin']); ?> →
                                    <?php echo htmlspecialchars($route['destination']); ?>
                                </td>
                                <td><?php echo $route['distance_km']; ?> km</td>
                                <td><?php echo htmlspecialchars($route['estimated_duration'] ?: 'N/A'); ?></td>
                                <td>
                                    <span
                                        class="badge badge_<?php echo $route['status'] === 'active' ? 'active' : 'inactive'; ?>">
                                        <?php echo ucfirst($route['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="routes.php?edit=<?php echo $route['route_id']; ?>" class="btn btn_primary"
                                        style="font-size: 0.8rem; padding: 0.25rem 0.5rem;">Edit</a>

                                    <form method="POST" style="display: inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this route?');">
                                        <input type="hidden" name="action" value="delete_route">
                                        <input type="hidden" name="route_id" value="<?php echo $route['route_id']; ?>">
                                        <button type="submit" class="btn"
                                            style="background: #e74c3c; font-size: 0.8rem; padding: 0.25rem 0.5rem;">Delete</button>
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
            <p>&copy; 2025 Road Runner Admin Panel. All rights reserved.</p>
        </div>
    </footer>
</body>

</html>