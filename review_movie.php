<?php
session_start();
include "db_connection.php";

if (!isset($_SESSION['user_id'])) {
    die("Login required");
}

$movieId = intval($_POST['movie_id'] ?? 0);
$review = trim($_POST['review'] ?? '');

if ($movieId <= 0 || $review === '') {
    header('Location: index.php');
    exit;
}

// Ensure table exists (safety)
$conn->query("CREATE TABLE IF NOT EXISTS movie_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    movie_id INT NOT NULL,
    review TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_movie (user_id, movie_id),
    INDEX (movie_id),
    CONSTRAINT fk_rev_user_form FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rev_movie_form FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$stmt = $conn->prepare(
    "INSERT INTO movie_reviews (user_id, movie_id, review)
     VALUES (?, ?, ?)
     ON DUPLICATE KEY UPDATE review=VALUES(review), created_at=CURRENT_TIMESTAMP"
);
$stmt->bind_param('iis', $_SESSION['user_id'], $movieId, $review);
$stmt->execute();

header('Location: watch.php?id=' . $movieId);
exit;
