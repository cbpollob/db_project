<?php
session_start();
include "db_connection.php";
include "password_utils.php";

$cssVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();

// Ensure default admin exists and password stays in sync (pollob / pollob123)
$adminUser = 'pollob';
$adminEmail = 'pollob@example.com';
$adminPassHash = password_hash('pollob123', PASSWORD_DEFAULT);
$seed = $conn->prepare(
    "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password=VALUES(password), role='admin', username=VALUES(username), email=VALUES(email)"
);
$seed->bind_param('sss', $adminUser, $adminEmail, $adminPassHash);
$seed->execute();

$loginType = isset($_POST['login_type']) ? $_POST['login_type'] : (isset($_GET['type']) ? $_GET['type'] : 'user');

// Ensure user activity log table exists for login tracking
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
    CONSTRAINT fk_ual_user_login FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Optional redirect target (only allow local paths)
    $redirect = trim($_POST["redirect"] ?? 'index.php');
    if (preg_match('/^https?:\/\//i', $redirect) || strpos($redirect, '//') === 0) {
        $redirect = 'index.php';
    }

    // Accept either email or username in the same field
    $login = trim($_POST["login"] ?? ($_POST["email"] ?? ''));
    $pass  = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE email=? OR username=? LIMIT 1");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $stmt->store_result();

    $id = null;
    $username = null;
    $hash = null;
    $role = null;
    $stmt->bind_result($id, $username, $hash, $role);

    if ($stmt->num_rows !== 1) {
        $error = "User not found.";
    } elseif (!$stmt->fetch()) {
        $error = "Login failed. Please try again.";
    } elseif (!password_verify($pass, $hash)) {
        $error = "Wrong password.";
    } elseif ($loginType === 'admin' && $role !== 'admin') {
        $error = "Access denied. Admin credentials required.";
    } else {
        $_SESSION["user_id"] = $id;
        $_SESSION["name"]    = $username;
        $_SESSION["role"]    = $role;
        
        // Log successful login activity
        logUserActivity($conn, $id, 'login', 'User logged in successfully');
        
        // Redirect admin to admin panel, users to their destination
        if ($role === 'admin' && $loginType === 'admin') {
            header("Location: admin_users.php");
        } else {
            header("Location: " . $redirect);
        }
        exit;
    }
}

// Support redirect param on GET as well
$redirect = $_GET['redirect'] ?? 'index.php';
if (preg_match('/^https?:\/\//i', $redirect) || strpos($redirect, '//') === 0) {
    $redirect = 'index.php';
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Login - CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css?v=<?= $cssVersion ?>">
</head>
<body class="auth">
<div class="auth-stack">
    <div class="auth-brand">
        <div class="brand">🎬 CineClick</div>
        <div class="tagline">Your gateway to endless entertainment</div>
    </div>
    
    <div class="auth-tabs">
        <button type="button" class="auth-tab <?= $loginType !== 'admin' ? 'active' : '' ?>" onclick="switchTab('user')">
            <span class="auth-tab-icon">👤</span>User Login
        </button>
        <button type="button" class="auth-tab <?= $loginType === 'admin' ? 'active' : '' ?>" onclick="switchTab('admin')">
            <span class="auth-tab-icon">🔐</span>Admin Login
        </button>
    </div>
    
    <form method="post" class="card auth-card" id="loginForm">
        <h2><?= $loginType === 'admin' ? '🛡️ Admin Access' : '🎥 Welcome Back' ?></h2>
        <?= isset($error) ? "<p class='error'>".htmlspecialchars($error)."</p>" : "" ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect) ?>">
        <input type="hidden" name="login_type" id="loginType" value="<?= htmlspecialchars($loginType) ?>">
        <input type="text" name="login" placeholder="<?= $loginType === 'admin' ? 'Admin Username or Email' : 'Email or Username' ?>" autocomplete="username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button class="btn btn-block" type="submit"><?= $loginType === 'admin' ? 'Access Admin Panel' : 'Sign In' ?></button>
        
        <?php if($loginType !== 'admin'): ?>
        <p class="help" style="margin:14px 0 0;text-align:center">New to CineClick? <a href="register.php">Create Account</a></p>
        <p class="help" style="margin:8px 0 0;text-align:center"><a href="forgot_password.php">Forgot Password?</a></p>
        <?php else: ?>
        <p class="help" style="margin:14px 0 0;text-align:center;font-size:12px">🔒 Authorized personnel only</p>
        <?php endif; ?>
        
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

<script>
function switchTab(type) {
    // Update active tab styling
    document.querySelectorAll('.auth-tab').forEach(tab => tab.classList.remove('active'));
    event.target.closest('.auth-tab').classList.add('active');
    
    // Update form elements
    document.getElementById('loginType').value = type;
    
    const form = document.getElementById('loginForm');
    const h2 = form.querySelector('h2');
    const loginInput = form.querySelector('input[name="login"]');
    const submitBtn = form.querySelector('button[type="submit"]');
    const helpText = form.querySelector('.help');
    
    if (type === 'admin') {
        h2.textContent = '🛡️ Admin Access';
        loginInput.placeholder = 'Admin Username or Email';
        submitBtn.textContent = 'Access Admin Panel';
        helpText.innerHTML = '🔒 Authorized personnel only';
        helpText.style.fontSize = '12px';
    } else {
        h2.textContent = '🎥 Welcome Back';
        loginInput.placeholder = 'Email or Username';
        submitBtn.textContent = 'Sign In';
        helpText.innerHTML = 'New to CineClick? <a href="register.php">Create Account</a>';
        helpText.style.fontSize = '14px';
    }
}

// Initialize the correct tab on page load
document.addEventListener('DOMContentLoaded', function() {
    const loginType = document.getElementById('loginType').value;
    if (loginType === 'admin') {
        document.querySelectorAll('.auth-tab')[1].classList.add('active');
        document.querySelectorAll('.auth-tab')[0].classList.remove('active');
    }
});
</script>
</body>
</html>
