<?php

session_start();
require_once '../db_connection.php';

// Check if user is logged in and is passenger
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'passenger') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        // Update basic profile information
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        // Validation
        if (empty($full_name)) {
            $error = "Full name is required.";
        } elseif (empty($email)) {
            $error = "Email is required.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (empty($phone)) {
            $error = "Phone number is required.";
        } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
            $error = "Phone number must be 10 digits.";
        } else {
            try {
                // Check if email is already taken by another user
                $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
                $stmt->execute([$email, $user_id]);
                if ($stmt->fetch()) {
                    $error = "This email is already registered to another account.";
                } else {
                    // Update profile
                    $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, phone = ? WHERE user_id = ?");
                    $stmt->execute([$full_name, $email, $phone, $user_id]);

                    // Update session data
                    $_SESSION['user_name'] = $full_name;
                    $_SESSION['user_email'] = $email;

                    $message = "Profile updated successfully!";
                }
            } catch (PDOException $e) {
                $error = "Error updating profile: " . $e->getMessage();
            }
        }
    } elseif ($action === 'change_password') {
        // Change password
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($current_password)) {
            $error = "Current password is required.";
        } elseif (empty($new_password)) {
            $error = "New password is required.";
        } elseif (strlen($new_password) < 6) {
            $error = "New password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } else {
            try {
                // Verify current password
                $stmt = $pdo->prepare("SELECT password FROM users WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();

                if (!$user || !password_verify($current_password, $user['password'])) {
                    $error = "Current password is incorrect.";
                } else {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                    $stmt->execute([$hashed_password, $user_id]);

                    $message = "Password changed successfully!";
                }
            } catch (PDOException $e) {
                $error = "Error changing password: " . $e->getMessage();
            }
        }
    }
}

// Get current user information
try {
    $stmt = $pdo->prepare("SELECT full_name, email, phone, created_at FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user_info = $stmt->fetch();

    if (!$user_info) {
        header('Location: ../logout.php');
        exit();
    }
} catch (PDOException $e) {
    $error = "Error loading profile information: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile - Road Runner</title>
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
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="dashboard.php">My Dashboard</a></li>
                    <li><a href="../search_buses.php">Search Buses</a></li>
                    <li><a href="../send_parcel.php">Parcel</a></li>
                    <li><a href="../my_bookings.php">My Bookings</a></li>
                    <li><a href="../my_parcels.php">My Parcels</a></li>
                    <li><a href="../logout.php">Logout</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container">
        <h2 class="mb_2">Edit Profile</h2>

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

        <!-- Tab Navigation -->
        <div style="border-bottom: 2px solid #eee; margin-bottom: 2rem;">
            <div style="display: flex; gap: 2rem;">
                <button class="tab_btn active" onclick="showTab('profile')" id="profile-tab">
                    Personal Information
                </button>
                <button class="tab_btn" onclick="showTab('password')" id="password-tab">
                    Change Password
                </button>
            </div>
        </div>

        <!-- Profile Information Tab -->
        <div id="profile-content" class="tab_content active">
            <div class="form_container">
                <h3>Personal Information</h3>
                <p style="color: #666; margin-bottom: 2rem;">Update your personal details below. Changes will be
                    reflected across all your bookings.</p>

                <?php if ($user_info): ?>
                    <form method="POST" action="edit_profile.php">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="form_group">
                            <label for="full_name">Full Name:</label>
                            <input type="text" id="full_name" name="full_name" class="form_control"
                                value="<?php echo htmlspecialchars($user_info['full_name']); ?>" required>
                        </div>

                        <div class="form_group">
                            <label for="email">Email Address:</label>
                            <input type="email" id="email" name="email" class="form_control"
                                value="<?php echo htmlspecialchars($user_info['email']); ?>" required>
                            <small style="color: #666;">This email is used for booking confirmations and important
                                updates.</small>
                        </div>

                        <div class="form_group">
                            <label for="phone">Phone Number:</label>
                            <input type="tel" id="phone" name="phone" class="form_control"
                                value="<?php echo htmlspecialchars($user_info['phone']); ?>" placeholder="0771234567"
                                pattern="[0-9]{10}" required>
                            <small style="color: #666;">10-digit phone number for booking confirmations and operator
                                contact.</small>
                        </div>

                        <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1.5rem;">
                            <button type="submit" class="btn btn_primary">Update Profile</button>
                            <a href="dashboard.php" class="btn" style="background: #95a5a6;">Cancel</a>
                        </div>
                    </form>

                    <!-- Account Information -->
                    <div style="background: #f8f9fa; padding: 1.5rem; border-radius: 8px; margin-top: 2rem;">
                        <h4 style="color: #2c3e50; margin-bottom: 1rem;">Account Information</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <strong>Account Type:</strong><br>
                                <span class="badge badge_passenger">Passenger</span>
                            </div>
                            <div>
                                <strong>Member Since:</strong><br>
                                <?php echo date('F j, Y', strtotime($user_info['created_at'])); ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Change Password Tab -->
        <div id="password-content" class="tab_content">
            <div class="form_container">
                <h3>Change Password</h3>
                <p style="color: #666; margin-bottom: 2rem;">Choose a strong password to keep your account secure.</p>

                <form method="POST" action="edit_profile.php">
                    <input type="hidden" name="action" value="change_password">

                    <div class="form_group">
                        <label for="current_password">Current Password:</label>
                        <input type="password" id="current_password" name="current_password" class="form_control"
                            required>
                    </div>

                    <div class="form_group">
                        <label for="new_password">New Password:</label>
                        <input type="password" id="new_password" name="new_password" class="form_control" minlength="6"
                            required>
                        <small style="color: #666;">Must be at least 6 characters long.</small>
                    </div>

                    <div class="form_group">
                        <label for="confirm_password">Confirm New Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form_control"
                            minlength="6" required>
                    </div>

                    <div style="display: flex; gap: 1rem; align-items: center; margin-top: 1.5rem;">
                        <button type="submit" class="btn btn_primary">Change Password</button>
                        <button type="reset" class="btn" style="background: #95a5a6;">Clear Form</button>
                    </div>
                </form>

                <!-- Security Tips -->
                <div style="background: #e8f4fd; padding: 1.5rem; border-radius: 8px; margin-top: 2rem;">
                    <h4 style="color: #2c3e50; margin-bottom: 1rem;">🔒 Password Security Tips</h4>
                    <ul style="margin: 0; padding-left: 1.5rem; color: #666;">
                        <li>Use a mix of letters, numbers, and special characters</li>
                        <li>Avoid using personal information like your name or birthday</li>
                        <li>Don't reuse passwords from other accounts</li>
                        <li>Consider using a password manager</li>
                        <li>Change your password if you suspect it has been compromised</li>
                    </ul>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. Your journey, our priority!</p>
        </div>
    </footer>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const contents = document.querySelectorAll('.tab_content');
            contents.forEach(content => content.classList.remove('active'));

            // Remove active class from all tab buttons
            const buttons = document.querySelectorAll('.tab_btn');
            buttons.forEach(button => button.classList.remove('active'));

            // Show selected tab content
            document.getElementById(tabName + '-content').classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');
        }

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function () {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;

            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Real-time password validation
        document.getElementById('new_password').addEventListener('input', function () {
            const confirmPassword = document.getElementById('confirm_password');
            if (confirmPassword.value && this.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        });
    </script>
</body>

</html>