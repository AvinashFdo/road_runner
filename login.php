<?php

session_start();
require_once 'db_connection.php';

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$errors = [];
$email = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize input data
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Basic validation
    if (empty($email)) {
        $errors[] = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    // If no basic errors, attempt login
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT user_id, full_name, email, password, user_type FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Login successful - create session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['user_name'] = $user['full_name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_type'] = $user['user_type'];

                // Redirect based on user type
                switch ($user['user_type']) {
                    case 'admin':
                        header('Location: index.php');
                        break;
                    case 'operator':
                        header('Location: index.php');
                        break;
                    case 'passenger':
                    default:
                        header('Location: index.php');
                        break;
                }
                exit();
            } else {
                $errors[] = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $errors[] = "Login failed. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Road Runner</title>
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
    <main class="container" style="padding: 2rem 0;">
        <div class="form_container">
            <h2 class="text_center mb_2">Login to Your Account</h2>

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

            <!-- Login Form -->
            <form method="POST" action="login.php">
                <div class="form_group">
                    <label for="email">Email Address:</label>
                    <input type="email" id="email" name="email" class="form_control"
                        value="<?php echo htmlspecialchars($email); ?>" required autofocus>
                </div>

                <div class="form_group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" class="form_control" required>
                </div>

                <button type="submit" class="btn btn_primary" style="width: 100%;">
                    Login
                </button>
            </form>

            <div class="text_center mt_2">
                <p>Don't have an account? <a href="register.php">Register here</a></p>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer">
        <div class="container">
            <p>&copy; 2025 Road Runner. All rights reserved.</p>
    </footer>
</body>

</html>