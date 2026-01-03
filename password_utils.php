<?php
/**
 * Password utility functions for CineClick
 * Shared between change_password.php and reset_password.php
 */

/**
 * Validate password strength against security requirements
 * 
 * @param string $password The password to validate
 * @return array Array of error messages (empty if password is strong enough)
 */
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

/**
 * Check if password was used before (last 5 passwords)
 * 
 * @param mysqli $conn Database connection
 * @param int $userId The user ID to check
 * @param string $newPassword The new password to check against history
 * @return bool True if password was used recently, false otherwise
 */
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
    $stmt->close();
    
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

/**
 * Store old password hash in history before changing
 * 
 * @param mysqli $conn Database connection
 * @param int $userId The user ID
 * @param string $passwordHash The password hash to store
 * @return bool True on success
 */
function storePasswordInHistory($conn, $userId, $passwordHash) {
    $stmt = $conn->prepare("INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)");
    $stmt->bind_param('is', $userId, $passwordHash);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Log user activity
 * 
 * @param mysqli $conn Database connection
 * @param int $userId The user ID
 * @param string $activityType Type of activity (login, logout, password_change, password_reset, registration, role_change)
 * @param string $description Description of the activity
 * @param string|null $ipAddress IP address (optional, will use $_SERVER['REMOTE_ADDR'] if not provided)
 * @return bool True on success
 */
function logUserActivity($conn, $userId, $activityType, $description, $ipAddress = null) {
    if ($ipAddress === null) {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    $stmt = $conn->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address) VALUES (?, ?, ?, ?)");
    $stmt->bind_param('isss', $userId, $activityType, $description, $ipAddress);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}
