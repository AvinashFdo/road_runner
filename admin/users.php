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
            case 'add_user':
                try {
                    $name = trim($_POST['name']);
                    $email = trim($_POST['email']);
                    $phone = trim($_POST['phone']);
                    $user_type = $_POST['user_type'];
                    $password = $_POST['password'];

                    if (empty($name) || empty($email) || empty($password)) {
                        throw new Exception("Name, email, and password are required.");
                    }

                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception("Invalid email format.");
                    }

                    // Check if email already exists
                    $check_stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
                    $check_stmt->execute([$email]);
                    if ($check_stmt->fetch()) {
                        throw new Exception("Email already exists.");
                    }

                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, user_type, password) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $email, $phone, $user_type, $hashed_password]);

                    $message = "User added successfully!";
                } catch (Exception $e) {
                    $error = "Error adding user: " . $e->getMessage();
                }
                break;

            case 'delete_user':
                try {
                    $user_id = $_POST['user_id'];

                    if ($user_id == $_SESSION['user_id']) {
                        throw new Exception("You cannot delete your own account.");
                    }

                    $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
                    $stmt->execute([$user_id]);
                    $message = "User deleted successfully!";
                } catch (Exception $e) {
                    $error = "Error deleting user: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get user statistics
try {
    $stats_sql = "SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type";
    $stats_stmt = $pdo->query($stats_sql);
    $user_stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users = $total_stmt->fetchColumn();

    // Get all users
    $users_stmt = $pdo->query("SELECT * FROM users ORDER BY created_at DESC");
    $users = $users_stmt->fetchAll();

} catch (PDOException $e) {
    $error = "Error loading data: " . $e->getMessage();
    $user_stats = [];
    $total_users = 0;
    $users = [];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Road Runner Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">🚌 Road Runner - Admin</div>
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
        <h2 class="mb_2">👥 User Management</h2>

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

        <!-- User Statistics -->
        <div class="dashboard_grid mb_2">
            <div class="stat_card">
                <div class="stat_number"><?php echo $total_users; ?></div>
                <div class="stat_label">Total Users</div>
            </div>
            <?php foreach ($user_stats as $stat): ?>
                <div class="stat_card">
                    <div class="stat_number"><?php echo $stat['count']; ?></div>
                    <div class="stat_label"><?php echo ucfirst($stat['user_type']); ?>s</div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Two Column Layout -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin: 2rem 0;">

            <!-- Add New User Form -->
            <div class="table_container">
                <h3 class="p_1 mb_1">➕ Add New User</h3>
                <form method="POST" action="" style="padding: 1rem;">
                    <input type="hidden" name="action" value="add_user">

                    <div class="form_group mb_1">
                        <label>Full Name:</label>
                        <input type="text" name="name" class="form_control" required>
                    </div>

                    <div class="form_group mb_1">
                        <label>Email:</label>
                        <input type="email" name="email" class="form_control" required>
                    </div>

                    <div class="form_group mb_1">
                        <label>Phone:</label>
                        <input type="tel" name="phone" class="form_control">
                    </div>

                    <div class="form_group mb_1">
                        <label>User Type:</label>
                        <select name="user_type" class="form_control" required>
                            <option value="passenger">Passenger</option>
                            <option value="operator">Operator</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>

                    <div class="form_group mb_1">
                        <label>Password:</label>
                        <input type="password" name="password" class="form_control" required minlength="6">
                    </div>

                    <button type="submit" class="btn btn_primary">Add User</button>
                </form>
            </div>

            <!-- User Summary -->
            <div class="table_container">
                <h3 class="p_1 mb_1">📊 Quick Stats</h3>
                <div style="padding: 1rem;">
                    <?php foreach ($user_stats as $stat): ?>
                        <div
                            style="display: flex; justify-content: space-between; margin-bottom: 1rem; padding: 0.5rem; background: #f8f9fa; border-radius: 4px;">
                            <span class="badge badge_<?php echo $stat['user_type']; ?>">
                                <?php echo ucfirst($stat['user_type']); ?>
                            </span>
                            <strong><?php echo $stat['count']; ?> users</strong>
                        </div>
                    <?php endforeach; ?>

                    <div style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                        <div style="text-align: center;">
                            <strong style="font-size: 1.2rem; color: #3498db;">
                                Total: <?php echo $total_users; ?> Users
                            </strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- All Users Table -->
        <div class="table_container">
            <h3 class="p_1 mb_1">All Users</h3>
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Type</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['user_id']; ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                            <td>
                                <span class="badge badge_<?php echo $user['user_type']; ?>">
                                    <?php echo ucfirst($user['user_type']); ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                    <form method="POST" style="display: inline;"
                                        onsubmit="return confirm('Are you sure you want to delete this user?')">
                                        <input type="hidden" name="action" value="delete_user">
                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                        <button type="submit" class="btn btn_danger"
                                            style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            Delete
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color: #999; font-size: 0.8rem;">Current User</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </main>
</body>

</html>