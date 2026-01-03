<?php
include "db_connection.php";
include "password_utils.php";

// Ensure user activity log table exists for registration tracking
$conn->query("CREATE TABLE IF NOT EXISTS user_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    activity_type ENUM('login','logout','password_change','password_reset','registration','role_change') NOT NULL,
    description TEXT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX idx_activity_type (activity_type),
    INDEX idx_created_at (created_at),
    CONSTRAINT fk_ual_user_reg FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name  = $_POST["name"];
    $email = $_POST["email"];
    $pass  = password_hash($_POST["password"], PASSWORD_DEFAULT);

    // Always register new signups as 'user'. Admins/uploaders are created by the preset admin.
    $role = 'user';

    $check = $conn->prepare("SELECT id FROM users WHERE email=?");
    $check->bind_param("s", $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $error = "Email already exists";
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO users (username, email, password, role)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("ssss", $name, $email, $pass, $role);
        $stmt->execute();
        
        // Get the newly inserted user ID and log registration activity
        $newUserId = $conn->insert_id;
        logUserActivity($conn, $newUserId, 'registration', 'New user registered');

        header("Location: login.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Register - CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body class="auth">

<div class="auth-stack">
    <div class="auth-brand">
        <div class="brand">🎬 CineClick</div>
        <div class="tagline">Join the movie community</div>
    </div>

    <form method="post" class="card auth-card no-tabs">
        <h2>🎟️ Create Account</h2>

        <?= isset($error) ? "<p class='error'>".htmlspecialchars($error)."</p>" : "" ?>

        <input name="name" placeholder="Choose a username" required>
        <input type="email" name="email" placeholder="Email address" required>
        <input type="password" name="password" placeholder="Create a password" required>

        <!-- New registrations are users by default -->
        <input type="hidden" name="role" value="user">

        <button class="btn btn-block" type="submit">🚀 Start Watching</button>
        <p class="help" style="margin:14px 0 0;text-align:center">Already have an account? <a href="login.php">Sign In</a></p>
        <div class="back-row">
            <a class="btn btn-outline btn-block" href="index.php">← Back to Movies</a>
        </div>
    </form>
    
    <div class="auth-decoration">
        <span>🎬 Movies</span>
        <span>•</span>
        <span>📽️ Streaming</span>
        <span>•</span>
        <span>⭐ Reviews</span>
    </div>
</div>

</body>
</html>
