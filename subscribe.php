<?php
session_start();
include "db_connection.php";

// Require login
if (!isset($_SESSION['user_id'])) {
    $back = isset($_POST['movie_id']) ? 'watch.php?id=' . intval($_POST['movie_id']) : 'index.php';
    header('Location: login.php?redirect=' . urlencode($back));
    exit;
}

$userId = intval($_SESSION['user_id']);
$uploaderId = intval($_POST['uploader_id'] ?? 0);
$movieId = intval($_POST['movie_id'] ?? 0);

// Safety: prevent self-subscription
if ($uploaderId === 0 || $uploaderId === $userId) {
    header('Location: ' . ($movieId ? 'watch.php?id=' . $movieId : 'index.php'));
    exit;
}

// Ensure tables exist
$conn->query("CREATE TABLE IF NOT EXISTS uploader_plans (
    uploader_id INT PRIMARY KEY,
    price DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    title VARCHAR(120) DEFAULT 'Support this creator',
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plan_user_sub FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS uploader_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    subscriber_id INT NOT NULL,
    uploader_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_subscriber (subscriber_id, uploader_id),
    CONSTRAINT fk_sub_subscriber_sub FOREIGN KEY (subscriber_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_uploader_sub FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Validate uploader role and get plan price
$planPrice = 5.00;
$planActive = 1;
$roleCheck = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$roleCheck->bind_param('i', $uploaderId);
$roleCheck->execute();
$roleCheck->bind_result($role);
$found = $roleCheck->fetch();
$roleCheck->close();

if (!$found || $role !== 'uploader') {
    header('Location: ' . ($movieId ? 'watch.php?id=' . $movieId : 'index.php'));
    exit;
}

$pStmt = $conn->prepare("SELECT price, is_active FROM uploader_plans WHERE uploader_id=? LIMIT 1");
$pStmt->bind_param('i', $uploaderId);
$pStmt->execute();
$pStmt->bind_result($planPrice, $planActive);
$pStmt->fetch();
$pStmt->close();

if (!$planActive) {
    // If plan disabled, redirect silently
    header('Location: ' . ($movieId ? 'watch.php?id=' . $movieId : 'index.php'));
    exit;
}

$planPrice = $planPrice !== null ? floatval($planPrice) : 5.00;

// Upsert subscription
$sub = $conn->prepare("INSERT INTO uploader_subscriptions (subscriber_id, uploader_id, amount, status)
    VALUES (?, ?, ?, 'active')
    ON DUPLICATE KEY UPDATE status='active', amount=VALUES(amount), created_at=CURRENT_TIMESTAMP");
$sub->bind_param('iid', $userId, $uploaderId, $planPrice);
$sub->execute();

$dest = $movieId ? 'watch.php?id=' . $movieId . '&subscribed=1' : 'index.php';
header('Location: ' . $dest);
exit;
