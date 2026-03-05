<?php
session_start();
include "db_connection.php";
include "password_utils.php";

$cssVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();

// Demo mode flag - set to false in production to hide reset tokens in browser
// In production, tokens should be sent via email instead
define('DEMO_MODE', true);

// Ensure password reset tokens table exists
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
    CONSTRAINT fk_prt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
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
    CONSTRAINT fk_ual_user_fp FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$success = false;
$error = null;
$resetToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = "Please enter your email address";
    } else {
        // Check if user exists
        $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->bind_result($userId, $username);
        $found = $stmt->fetch();
        $stmt->close();
        
        if ($found) {
            // Invalidate any existing tokens for this user
            $stmt = $conn->prepare("UPDATE password_reset_tokens SET used = 1 WHERE user_id = ? AND used = 0");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            
            // Generate a secure token
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database
            $stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
            $stmt->bind_param('iss', $userId, $token, $expiresAt);
            $stmt->execute();
            
            // Log the password reset request
            logUserActivity($conn, $userId, 'password_reset', 'Password reset requested');
            
            $success = true;
            // DEMO_MODE: Show reset token in browser. In production, send via email
            if (DEMO_MODE) {
                $resetToken = $token;
            }
        } else {
            // For security, don't reveal if email exists
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Forgot Password - CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css?v=<?= $cssVersion ?>">
<style>
.success-box {
    background: rgba(34, 197, 94, 0.15);
    border: 1px solid rgba(34, 197, 94, 0.35);
    padding: 12px 14px;
    border-radius: 12px;
    color: #86efac;
    margin-bottom: 12px;
}
.info-box {
    background: rgba(56, 189, 248, 0.15);
    border: 1px solid rgba(56, 189, 248, 0.35);
    padding: 12px 14px;
    border-radius: 12px;
    color: #7dd3fc;
    margin-bottom: 12px;
}
.reset-link {
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.15);
    border-radius: 8px;
    padding: 12px;
    margin-top: 12px;
    word-break: break-all;
}
.reset-link a {
    color: #22d3ee;
}
</style>
</head>
<body class="auth">
<div class="auth-stack">
    <div class="auth-brand">
        <div class="brand">🎬 CineClick</div>
        <div class="tagline">Reset your password</div>
    </div>

    <form method="post" class="card auth-card no-tabs">
        <h2>🔑 Forgot Password</h2>
        
        <?php if ($success): ?>
            <div class="success-box">
                ✅ If an account exists with this email, you will receive reset instructions.
            </div>
            
            <?php if ($resetToken): ?>
            <div class="info-box">
                <strong>📧 Demo Mode:</strong> In production, this link would be sent via email.
            </div>
            <div class="reset-link">
                <strong>Reset Link:</strong><br>
                <a href="reset_password.php?token=<?= htmlspecialchars($resetToken) ?>">
                    reset_password.php?token=<?= htmlspecialchars($resetToken) ?>
                </a>
            </div>
            <?php endif; ?>
            
            <p class="help" style="margin-top:14px;text-align:center">
                <a href="login.php">← Back to Login</a>
            </p>
        <?php else: ?>
            <?php if ($error): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>
            
            <p class="help" style="color:var(--muted);margin-bottom:14px">
                Enter your email address and we'll send you a link to reset your password.
            </p>

            <input type="email" name="email" placeholder="Email Address" required autocomplete="email">

            <button class="btn btn-block" type="submit">📨 Send Reset Link</button>
            
            <p class="help" style="margin:14px 0 0;text-align:center">
                Remember your password? <a href="login.php">Sign In</a>
            </p>
            
            <div class="back-row">
                <a class="btn btn-outline btn-block" href="index.php">← Back to Movies</a>
            </div>
        <?php endif; ?>
    </form>
    
    <div class="auth-decoration">
        <span>🔒 Security</span>
        <span>•</span>
        <span>📧 Email</span>
        <span>•</span>
        <span>🔑 Reset</span>
    </div>
</div>
</body>
</html>
