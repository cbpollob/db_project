<?php
session_start();
include "db_connection.php";

$cssVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();

// Ensure password management tables exist
$conn->query("CREATE TABLE IF NOT EXISTS password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX idx_user_created (user_id, created_at),
    CONSTRAINT fk_ph_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

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
    CONSTRAINT fk_ual_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Require login
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$success = false;
$error = null;

// Password strength validation function
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return $errors;
}

// Check if password was used before (last 5 passwords)
function isPasswordReused($conn, $userId, $newPassword) {
    $stmt = $conn->prepare("SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        if (password_verify($newPassword, $row['password_hash'])) {
            return true;
        }
    }
    
    // Also check current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($currentHash);
    $stmt->fetch();
    $stmt->close();
    
    if ($currentHash && password_verify($newPassword, $currentHash)) {
        return true;
    }
    
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($currentHash);
    $stmt->fetch();
    $stmt->close();
    
    if (!$currentHash || !password_verify($currentPassword, $currentHash)) {
        $error = "Current password is incorrect";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "New passwords do not match";
    } elseif ($currentPassword === $newPassword) {
        $error = "New password must be different from current password";
    } else {
        // Validate password strength
        $strengthErrors = validatePasswordStrength($newPassword);
        if (!empty($strengthErrors)) {
            $error = implode(". ", $strengthErrors);
        } elseif (isPasswordReused($conn, $userId, $newPassword)) {
            $error = "This password was used recently. Please choose a different password";
        } else {
            // Store current password in history before changing
            $stmt = $conn->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
            $stmt->bind_param('is', $userId, $currentHash);
            $stmt->execute();
            
            // Update password
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $newHash, $userId);
            $stmt->execute();
            
            // Log password change activity
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $conn->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address) VALUES (?, 'password_change', 'Password changed successfully', ?)");
            $stmt->bind_param('is', $userId, $ipAddress);
            $stmt->execute();
            
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Change Password - CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css?v=<?= $cssVersion ?>">
<style>
.password-requirements {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    padding: 12px;
    margin: 12px 0;
    font-size: 13px;
}
.password-requirements h4 {
    margin: 0 0 8px;
    color: var(--muted);
}
.password-requirements ul {
    margin: 0;
    padding-left: 20px;
    color: var(--muted);
}
.password-requirements li {
    margin: 4px 0;
}
.success-box {
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.35);
    padding: 12px 14px;
    border-radius: 12px;
    color: #86efac;
    margin-bottom: 12px;
}
</style>
</head>
<body class="auth">
<div class="auth-stack">
    <div class="auth-brand">
        <div class="brand">🎬 CineClick</div>
        <div class="tagline">Secure your account</div>
    </div>

    <form method="post" class="card auth-card no-tabs">
        <h2>🔐 Change Password</h2>
        
        <?php if ($success): ?>
            <div class="success-box">✅ Password changed successfully!</div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>
        
        <div class="password-requirements">
            <h4>Password Requirements:</h4>
            <ul>
                <li>At least 8 characters long</li>
                <li>Contains uppercase letter (A-Z)</li>
                <li>Contains lowercase letter (a-z)</li>
                <li>Contains a number (0-9)</li>
                <li>Contains special character (!@#$%^&*)</li>
                <li>Cannot be one of your last 5 passwords</li>
            </ul>
        </div>

        <input type="password" name="current_password" placeholder="Current Password" required>
        <input type="password" name="new_password" placeholder="New Password" required minlength="8">
        <input type="password" name="confirm_password" placeholder="Confirm New Password" required minlength="8">

        <button class="btn btn-block" type="submit">🔄 Change Password</button>
        
        <div class="back-row">
            <a class="btn btn-outline btn-block" href="index.php">← Back to Movies</a>
        </div>
    </form>
    
    <div class="auth-decoration">
        <span>🔒 Security</span>
        <span>•</span>
        <span>🛡️ Protected</span>
        <span>•</span>
        <span>✅ Safe</span>
    </div>
</div>
</body>
</html>
