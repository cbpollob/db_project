<?php
session_start();
include "db_connection.php";
include "password_utils.php";

$cssVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();

// Ensure required tables exist
$conn->query("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY token (token),
    INDEX (user_id),
    INDEX idx_token_expires (token, expires_at),
    CONSTRAINT fk_prt_user_rp FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS password_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (user_id),
    INDEX idx_user_created (user_id, created_at),
    CONSTRAINT fk_ph_user_rp FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    CONSTRAINT fk_ual_user_rp FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$token = $_GET['token'] ?? '';
$success = false;
$error = null;
$tokenValid = false;
$userId = null;


// Validate token
if (!empty($token)) {
    $stmt = $conn->prepare("SELECT user_id, expires_at, used FROM password_reset_tokens WHERE token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->bind_result($tokenUserId, $expiresAt, $used);
    
    if ($stmt->fetch()) {
        $userId = $tokenUserId;
        if ($used) {
            $error = "This reset link has already been used";
        } elseif (strtotime($expiresAt) < time()) {
            $error = "This reset link has expired";
        } else {
            $tokenValid = true;
        }
    } else {
        $error = "Invalid reset link";
    }
    $stmt->close();
}

// Process password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match";
        $tokenValid = true; // Keep form visible
    } else {
        // Validate password strength
        $strengthErrors = validatePasswordStrength($newPassword);
        if (!empty($strengthErrors)) {
            $error = implode(". ", $strengthErrors);
            $tokenValid = true;
        } elseif (isPasswordReused($conn, $userId, $newPassword)) {
            $error = "This password was used recently. Please choose a different password";
            $tokenValid = true;
        } else {
            // Get current password hash to store in history
            $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->bind_result($currentHash);
            $stmt->fetch();
            $stmt->close();
            
            // Store current password in history
            if ($currentHash) {
                storePasswordInHistory($conn, $userId, $currentHash);
            }
            
            // Update password
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param('si', $newHash, $userId);
            $stmt->execute();
            
            // Mark token as used
            $stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE token = ?");
            $stmt->bind_param('s', $token);
            $stmt->execute();
            
            // Log password reset activity
            logUserActivity($conn, $userId, 'password_reset', 'Password reset completed');
            
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Reset Password - CineClick</title>
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
.error-box {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.35);
    padding: 12px 14px;
    border-radius: 12px;
    color: #fecdd3;
    margin-bottom: 12px;
}
</style>
</head>
<body class="auth">
<div class="auth-stack">
    <div class="auth-brand">
        <div class="brand">🎬 CineClick</div>
        <div class="tagline">Create a new password</div>
    </div>

    <div class="card auth-card no-tabs">
        <h2>🔐 Reset Password</h2>
        
        <?php if ($success): ?>
            <div class="success-box">
                ✅ Your password has been reset successfully!
            </div>
            <p class="help" style="text-align:center;margin-top:14px">
                <a href="login.php" class="btn btn-block">🔑 Sign In with New Password</a>
            </p>
        <?php elseif (!$tokenValid && !empty($token)): ?>
            <div class="error-box">
                ❌ <?= htmlspecialchars($error ?? 'Invalid or expired reset link') ?>
            </div>
            <p class="help" style="text-align:center;margin-top:14px">
                <a href="forgot_password.php">Request a new reset link</a>
            </p>
            <div class="back-row">
                <a class="btn btn-outline btn-block" href="login.php">← Back to Login</a>
            </div>
        <?php elseif (empty($token)): ?>
            <div class="error-box">
                ❌ No reset token provided
            </div>
            <p class="help" style="text-align:center;margin-top:14px">
                <a href="forgot_password.php">Request a password reset</a>
            </p>
            <div class="back-row">
                <a class="btn btn-outline btn-block" href="login.php">← Back to Login</a>
            </div>
        <?php else: ?>
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

            <form method="post">
                <input type="password" name="new_password" placeholder="New Password" required minlength="8">
                <input type="password" name="confirm_password" placeholder="Confirm New Password" required minlength="8">
                <button class="btn btn-block" type="submit">🔄 Reset Password</button>
            </form>
            
            <div class="back-row">
                <a class="btn btn-outline btn-block" href="login.php">← Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
    
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
