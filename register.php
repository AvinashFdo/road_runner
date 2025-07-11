<?php

session_start();
require_once 'db_connection.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$errors = [];
$success = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input data
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $user_type = $_POST['user_type'] ?? 'passenger';

    // Validation
    if (empty($full_name)) {
        $errors[] = "Full name is required.";
    }

    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (empty($phone)) {
        $errors[] = "Phone number is required.";
    } elseif (!preg_match('/^[0-9]{10}$/', $phone)) {
        $errors[] = "Phone number must be 10 digits.";
    }

    if (empty($password)) {
        $errors[] = "Password is required.";
    } elseif (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    if (!in_array($user_type, ['passenger', 'operator'])) {
        $errors[] = "Invalid user type selected.";
    }

    // Check if email already exists
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "An account with this email already exists.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error. Please try again.";
        }
    }

    //create account
    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("INSERT INTO users (full_name, email, phone, password, user_type) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $email, $phone, $hashed_password, $user_type]);

            $success = "Account created successfully! You can now login.";

            // Clear form data after successful registration
            $full_name = $email = $phone = '';

        } catch (PDOException $e) {
            $errors[] = "Registration failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Road Runner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <!-- Header -->
    <header class="header">
        <div class="container">
            <nav class="nav">
                <div class="logo">
                    <img src="images/logo.jpg" alt="Road Runner Logo" style="height: 50px; width: auto;">
                </div>
                <ul class="nav_links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php">Register</a></li>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main>
        <div class="register-page-wrapper">
            <div class="form_container">
                <h2 class="text_center mb_2">Create Your Account</h2>

                <!-- Display Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert_error">
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Display Success Message -->
                <?php if ($success): ?>
                    <div class="alert alert_success">
                        <?php echo htmlspecialchars($success); ?>
                        <p class="mt_1"><a href="login.php">Click here to login</a></p>
                    </div>
                <?php endif; ?>

                <!-- Registration Form -->
                <form method="POST" action="register.php">
                    <div class="form_group">
                        <label for="full_name">Full Name:</label>
                        <input type="text" id="full_name" name="full_name" class="form_control"
                            value="<?php echo htmlspecialchars($full_name ?? ''); ?>" required>
                    </div>

                    <div class="form_group">
                        <label for="email">Email Address:</label>
                        <input type="email" id="email" name="email" class="form_control"
                            value="<?php echo htmlspecialchars($email ?? ''); ?>" required>
                    </div>

                    <div class="form_group">
                        <label for="phone">Phone Number:</label>
                        <input type="tel" id="phone" name="phone" class="form_control" placeholder="0771234567"
                            value="<?php echo htmlspecialchars($phone ?? ''); ?>" required>
                    </div>

                    <div class="form_group">
                        <label for="user_type">Account Type:</label>
                        <select id="user_type" name="user_type" class="form_control" required>
                            <option value="passenger" <?php echo (($user_type ?? '') === 'passenger') ? 'selected' : ''; ?>>
                                Passenger
                            </option>
                            <option value="operator" <?php echo (($user_type ?? '') === 'operator') ? 'selected' : ''; ?>>
                                Bus Operator
                            </option>
                        </select>
                    </div>

                    <div class="form_group">
                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" class="form_control" minlength="6"
                            required>
                    </div>

                    <div class="form_group">
                        <label for="confirm_password">Confirm Password:</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form_control"
                            minlength="6" required>
                    </div>

                    <button type="submit" class="btn btn_primary">
                        Create Account
                    </button>
                </form>

                <p class="text_center mt_2">
                    Already have an account? <a href="login.php">Login here</a>
                </p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. All rights reserved.</p>
        </div>
    </footer>
</body>

</html>