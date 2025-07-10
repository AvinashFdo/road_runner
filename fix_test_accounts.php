<?php

require_once 'db_connection.php';

try {
    // Hash the passwords properly
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    $passenger_password = password_hash('pass123', PASSWORD_DEFAULT);

    // Update admin account
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = 'admin@roadrunner.com'");
    $stmt->execute([$admin_password]);

    // Update passenger account  
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = 'john@test.com'");
    $stmt->execute([$passenger_password]);

    echo "<h2>Test Accounts Fixed Successfully!</h2>";
    echo "<p>Admin account: admin@roadrunner.com / admin123</p>";
    echo "<p>Passenger account: john@test.com / pass123</p>";
    echo "<p><strong>You can now delete this file (fix_test_accounts.php)</strong></p>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>