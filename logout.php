<?php
session_start();
include "db_connection.php";

// Log logout activity before destroying session
if (isset($_SESSION['user_id'])) {
    // Ensure user activity log table exists
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
        CONSTRAINT fk_ual_user_logout FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    $userId = intval($_SESSION['user_id']);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO user_activity_log (user_id, activity_type, description, ip_address) VALUES (?, 'logout', 'User logged out', ?)");
    $stmt->bind_param('is', $userId, $ipAddress);
    $stmt->execute();
}

session_destroy();
header("Location: index.php");
exit;
