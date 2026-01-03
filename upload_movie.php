<?php
session_start();
if(!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','uploader'])){
    header("Location: login.php");
    exit;
}
include "db_connection.php";

// Ensure plan table exists (for uploader-controlled subscription pricing)
$conn->query("CREATE TABLE IF NOT EXISTS uploader_plans (
    uploader_id INT PRIMARY KEY,
    price DECIMAL(10,2) NOT NULL DEFAULT 5.00,
    title VARCHAR(120) DEFAULT 'Support this creator',
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plan_user_upload FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$uploaderId = intval($_SESSION['user_id']);

// Handle plan save from uploader
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    $price = floatval($_POST['plan_price'] ?? 0);
    $active = isset($_POST['plan_active']) ? 1 : 0;
    if ($price < 0) { $price = 0; }
    if ($price == 0) { $active = 0; }
    $stmt = $conn->prepare(
        "INSERT INTO uploader_plans (uploader_id, price, is_active)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE price=VALUES(price), is_active=VALUES(is_active)"
    );
    $stmt->bind_param('idi', $uploaderId, $price, $active);
    $stmt->execute();
    $planSaved = true;
}

// Load current plan
$planPrice = 5.00; $planActive = 1;
$p = $conn->prepare("SELECT price, is_active FROM uploader_plans WHERE uploader_id=? LIMIT 1");
$p->bind_param('i', $uploaderId);
$p->execute();
$p->bind_result($pp, $pa);
if ($p->fetch()) {
    $planPrice = $pp !== null ? floatval($pp) : 5.00;
    $planActive = $pa !== null ? intval($pa) : 1;
}
$p->close();
?>

<!DOCTYPE html>
<html>
<head>
<title>Add Movie | CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <h2 style="margin:0">Add New Movie</h2>
        <a class="btn btn-outline btn-sm" href="index.php">Back</a>
    </div>

    <div class="card" style="margin-top:16px;display:flex;flex-direction:column;gap:10px">
        <h3>Subscription settings</h3>
        <?php if (!empty($planSaved)): ?>
            <div class="help" style="color:#4ade80">Saved</div>
        <?php endif; ?>
        <form method="post" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
            <input type="hidden" name="save_plan" value="1">
            <label style="display:flex;align-items:center;gap:6px">Enable subscription
                <input type="checkbox" name="plan_active" value="1" <?= $planActive ? 'checked' : '' ?>></label>
            <label style="display:flex;align-items:center;gap:6px">Price ($)
                <input type="number" step="0.01" min="0" name="plan_price" value="<?= htmlspecialchars(number_format($planPrice,2,'.','')) ?>" style="width:110px;">
            </label>
            <div class="help">Set price to 0 or uncheck to make videos free (no subscription required).</div>
            <button class="btn btn-sm" type="submit">Save plan</button>
        </form>
    </div>

    <form class="card" action="save_movie.php" method="POST" enctype="multipart/form-data" style="margin-top:16px">
            <label>Movie Title</label>
            <input type="text" name="title" required>

            <label>Description</label>
            <textarea name="description" required></textarea>

            <label>Genre</label>
            <input type="text" name="genre" placeholder="e.g. Action, Comedy" required>

            <label>Thumbnail (choose one)</label>
            <input type="file" name="thumbnail" accept="image/*">
            <div class="help">Or paste a Google Drive image link/ID</div>
            <input type="url" name="thumbnail_drive" placeholder="Google Drive link or file ID">

            <label>Movie Video Link</label>
            <input type="url" name="video_link" required>

            <button class="btn btn-block" type="submit">Add Movie</button>
    </form>
</div>

</body>
</html>
