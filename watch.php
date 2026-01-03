<?php
session_start();
include "db_connection.php";

// Ensure subscription tables exist for uploader monetization
$conn->query("CREATE TABLE IF NOT EXISTS uploader_plans (
	uploader_id INT PRIMARY KEY,
	price DECIMAL(10,2) NOT NULL DEFAULT 5.00,
	title VARCHAR(120) DEFAULT 'Support this creator',
	description TEXT,
	is_active TINYINT(1) NOT NULL DEFAULT 1,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	CONSTRAINT fk_plan_user_watch FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Reviews table for viewer feedback
$conn->query("CREATE TABLE IF NOT EXISTS movie_reviews (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	movie_id INT NOT NULL,
	review TEXT NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_user_movie (user_id, movie_id),
	INDEX (movie_id),
	CONSTRAINT fk_rev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	CONSTRAINT fk_rev_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$conn->query("CREATE TABLE IF NOT EXISTS uploader_subscriptions (
	id INT AUTO_INCREMENT PRIMARY KEY,
	subscriber_id INT NOT NULL,
	uploader_id INT NOT NULL,
	amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uniq_subscriber (subscriber_id, uploader_id),
	CONSTRAINT fk_sub_subscriber_watch FOREIGN KEY (subscriber_id) REFERENCES users(id) ON DELETE CASCADE,
	CONSTRAINT fk_sub_uploader_watch FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Ensure movie views tracking table/column exists to avoid runtime errors
$conn->query("CREATE TABLE IF NOT EXISTS movie_views (
	user_id INT NOT NULL,
	movie_id INT NOT NULL,
	viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY(user_id, movie_id),
	INDEX (movie_id),
	CONSTRAINT fk_mv_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
	CONSTRAINT fk_mv_movie FOREIGN KEY (movie_id) REFERENCES movies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Add view_count column if missing (older MySQL lacks IF NOT EXISTS)
$hasViewCol = $conn->query("SHOW COLUMNS FROM movies LIKE 'view_count'");
if ($hasViewCol && $hasViewCol->num_rows === 0) {
    $conn->query("ALTER TABLE movies ADD COLUMN view_count INT DEFAULT 0");
}

$id = intval($_GET['id']);
$redirectTarget = 'watch.php?id=' . $id;
if (!isset($_SESSION['user_id'])) {
	header('Location: login.php?redirect=' . urlencode($redirectTarget));
	exit;
}
$stmt = $conn->prepare("SELECT * FROM movies WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
if (!$m) {
	header('Location: index.php');
	exit;
}

// Uploader info and subscription plan
$uploaderId = isset($m['uploaded_by']) ? intval($m['uploaded_by']) : 0;
$uploaderName = null;
$uploaderRole = null;
$planPrice = 5.00;
$planActive = 1;
$hasSubscription = false;
$subscriptionValid = false;
$subscriptionExpires = null;

if ($uploaderId > 0) {
	$up = $conn->prepare("SELECT u.username, u.role, p.price, p.is_active FROM users u LEFT JOIN uploader_plans p ON p.uploader_id=u.id WHERE u.id=? LIMIT 1");
	$up->bind_param('i', $uploaderId);
	$up->execute();
	$up->bind_result($uName, $uRole, $pPrice, $pActive);
	if ($up->fetch()) {
		$uploaderName = $uName;
		$uploaderRole = $uRole;
		$planPrice = ($pPrice !== null) ? floatval($pPrice) : 5.00;
		$planActive = ($pActive !== null) ? intval($pActive) : 1;
	}
	$up->close();

	if (isset($_SESSION['user_id']) && $_SESSION['user_id'] !== $uploaderId) {
		$sub = $conn->prepare("SELECT status, created_at FROM uploader_subscriptions WHERE subscriber_id=? AND uploader_id=? AND status='active' LIMIT 1");
		$sub->bind_param('ii', $_SESSION['user_id'], $uploaderId);
		$sub->execute();
		$subRes = $sub->get_result()->fetch_assoc();
		$sub->close();
		if ($subRes) {
			$hasSubscription = true;
			$subscriptionCreated = strtotime($subRes['created_at']);
			$subscriptionExpires = $subscriptionCreated ? date('Y-m-d', strtotime('+30 days', $subscriptionCreated)) : null;
			$subscriptionValid = $subscriptionCreated && ($subscriptionCreated >= strtotime('-30 days'));
		}
	}
}

// Increment view count (requires movies.view_count INT DEFAULT 0)
// Count a view only once per user
$views = isset($m['view_count']) ? (int)$m['view_count'] : 0;
$insView = $conn->prepare("INSERT IGNORE INTO movie_views (user_id, movie_id, viewed_at) VALUES (?, ?, NOW())");
$insView->bind_param('ii', $_SESSION['user_id'], $id);
$insView->execute();
if ($insView->affected_rows > 0) {
	$inc = $conn->prepare("UPDATE movies SET view_count = IFNULL(view_count,0) + 1 WHERE id=?");
	$inc->bind_param('i', $id);
	$inc->execute();
	$views++;
}

// Access control: uploader content requires active subscription within 30 days unless admin or owner
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
$isOwner = ($_SESSION['user_id'] ?? 0) === $uploaderId;
$requiresSubscription = ($uploaderRole === 'uploader' && $planActive);
$canWatch = true;
$gateMsg = '';
if ($requiresSubscription && !$isAdmin && !$isOwner) {
	$canWatch = $subscriptionValid;
	if (!$canWatch) {
		$gateMsg = 'Subscribe to this uploader to watch. Access lasts 30 days per subscription.';
	}
}

// Ratings summary
$avgRating = null; $ratingCount = 0;
$rStmt = $conn->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as c FROM ratings WHERE movie_id=?");
$rStmt->bind_param('i', $id);
$rStmt->execute();
$rStmt->bind_result($avgRating, $ratingCount);
$rStmt->fetch();
$rStmt->close();
$avgRating = $avgRating ? round($avgRating, 1) : 0;

// Reviews list
$reviews = [];
$revStmt = $conn->prepare("SELECT mr.review, mr.created_at, u.username FROM movie_reviews mr JOIN users u ON u.id=mr.user_id WHERE mr.movie_id=? ORDER BY mr.created_at DESC");
$revStmt->bind_param('i', $id);
$revStmt->execute();
$revRes = $revStmt->get_result();
while ($row = $revRes->fetch_assoc()) { $reviews[] = $row; }
$revStmt->close();
?>
<!DOCTYPE html>
<html>
<head>
<title><?= $m['title'] ?></title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
	<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap">
		<div>
			<h1 style="margin:0"><?= htmlspecialchars($m['title']) ?></h1>
			<div class="help" style="margin-top:6px">Views: <?= $views ?></div>
			<div class="help" style="margin-top:4px">Rating: <?= $ratingCount ? $avgRating . ' / 5 (' . $ratingCount . ' reviews)' : 'No ratings yet' ?></div>
			<?php if(!empty($m['genre'])): ?>
				<div class="help" style="margin-top:8px"><strong>Genre:</strong> <?= htmlspecialchars($m['genre']) ?></div>
			<?php endif; ?>
		</div>
		<a class="btn btn-outline btn-sm" href="index.php">Back</a>
	</div>

	<?php if ($uploaderId && $uploaderRole === 'uploader' && $planActive): ?>
	<div class="card" style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
		<h3>Subscribe to <?= htmlspecialchars($uploaderName) ?></h3>
		<?php if (isset($_GET['subscribed'])): ?>
			<div class="help" style="color:#4ade80">Thank you for subscribing!</div>
		<?php endif; ?>
		<?php if ($hasSubscription && $subscriptionValid): ?>
			<div class="help">You are subscribed. Valid until <?= htmlspecialchars($subscriptionExpires ?? '') ?>.</div>
		<?php elseif ($hasSubscription): ?>
			<div class="help" style="color:#f87171">Your subscription expired. Subscribe again to watch.</div>
		<?php elseif (isset($_SESSION['user_id']) && $_SESSION['user_id'] === $uploaderId): ?>
			<div class="help">This is your own upload.</div>
		<?php else: ?>
			<form method="post" action="subscribe.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
				<input type="hidden" name="uploader_id" value="<?= $uploaderId ?>">
				<input type="hidden" name="movie_id" value="<?= $id ?>">
				<div class="help">Monthly support price: $<?= number_format($planPrice, 2) ?></div>
				<button class="btn btn-sm" type="submit">Subscribe for $<?= number_format($planPrice, 2) ?></button>
			</form>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="card" style="margin-top:16px">
		<h3>Description</h3>
		<div style="color:var(--muted)"><?= nl2br(htmlspecialchars($m['description'])) ?></div>
	</div>

<?php
// Ensure we embed Drive file ID only. Accept either an ID or a full URL stored in DB.
function driveIdFromStored($stored){
	$s = trim($stored);
	if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $s)) return $s;
	if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $s, $mm)) return $mm[1];
	if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $s, $mm)) return $mm[1];
	// fallback: attempt parse_url path last segment
	$p = parse_url($s, PHP_URL_PATH);
	if ($p) {
		$parts = explode('/', trim($p, '/'));
		$last = end($parts);
		if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $last)) return $last;
	}
	return $s;
}
$driveId = driveIdFromStored($m['video_link']);
$downloadUrl = "https://drive.google.com/uc?export=download&id=" . urlencode($driveId);
?>
<?php if ($canWatch): ?>
<div class="video-wrap" style="margin-top:16px">
	<iframe src="https://drive.google.com/file/d/<?= htmlspecialchars($driveId) ?>/preview" allowfullscreen></iframe>
</div>

<div class="card" style="margin-top:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
	<h3 style="margin:0;flex:1">Download</h3>
	<a class="btn btn-outline btn-sm" href="<?= htmlspecialchars($downloadUrl) ?>" target="_blank" rel="noopener">⬇ Download Video</a>
</div>
<?php else: ?>
<div class="card" style="margin-top:16px">
	<h3>Subscription required</h3>
	<p class="help" style="margin-top:6px; color:#f87171;"><?= htmlspecialchars($gateMsg) ?></p>
	<form method="post" action="subscribe.php" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:8px">
		<input type="hidden" name="uploader_id" value="<?= $uploaderId ?>">
		<input type="hidden" name="movie_id" value="<?= $id ?>">
		<div class="help">Price: $<?= number_format($planPrice, 2) ?> / 30 days</div>
		<button class="btn btn-sm" type="submit">Subscribe to watch</button>
	</form>
</div>
<?php endif; ?>

<?php if(isset($_SESSION['user_id'])): ?>
<form method="post" action="rate_movie.php" class="card" style="margin-top:16px">
	<h3>Rate this movie</h3>
	<input type="hidden" name="movie_id" value="<?= $id ?>">
	<select name="rating">
		<option value="1">★</option>
		<option value="2">★★</option>
		<option value="3">★★★</option>
		<option value="4">★★★★</option>
		<option value="5">★★★★★</option>
	</select>
	<button class="btn btn-block" type="submit">Rate</button>
</form>
<form method="post" action="review_movie.php" class="card" style="margin-top:16px">
	<h3>Write a review</h3>
	<input type="hidden" name="movie_id" value="<?= $id ?>">
	<textarea name="review" placeholder="Share your thoughts" required></textarea>
	<button class="btn btn-block" type="submit">Post review</button>
</form>
<?php endif; ?>

<?php
// Show delete button if admin or uploader
if (isset($_SESSION['user_id'])){
	$isAdmin = ($_SESSION['role'] === 'admin');
	$isOwner = ($_SESSION['user_id'] == $m['uploaded_by']);
	if ($isAdmin || $isOwner){
		echo '<form method="get" action="delete_movie.php" class="card" style="margin-top:16px" onsubmit="return confirm(\'Delete this movie?\')">'.
			 '<h3>Danger zone</h3>'.
			 '<input type="hidden" name="id" value="'.intval($m['id']).'">'.
			 '<button class="btn btn-outline btn-block" type="submit">Delete Movie</button>'.
			 '</form>';
	}
}
?>

<div class="card" style="margin-top:16px">
	<h3>Reviews</h3>
	<?php if (count($reviews) === 0): ?>
		<div class="help">No reviews yet.</div>
	<?php else: ?>
		<?php foreach ($reviews as $rev): ?>
			<div style="padding:10px 0;border-bottom:1px solid rgba(255,255,255,0.06)">
				<strong><?= htmlspecialchars($rev['username']) ?></strong>
				<div class="help" style="margin-top:4px;"><?= htmlspecialchars($rev['created_at']) ?></div>
				<div style="margin-top:6px;white-space:pre-wrap;"><?= nl2br(htmlspecialchars($rev['review'])) ?></div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

</div>

</body>
</html>
