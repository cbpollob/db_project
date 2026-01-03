<?php
session_start();
include "db_connection.php";

// Fee required to become an uploader
$uploaderFee = 20.00;

// Ensure payment table exists (stores proof of payment to upgrade)
$conn->query("CREATE TABLE IF NOT EXISTS uploader_payments (
	id INT AUTO_INCREMENT PRIMARY KEY,
	user_id INT NOT NULL,
	amount DECIMAL(10,2) NOT NULL,
	reference VARCHAR(120) NOT NULL,
	method ENUM('bkash','nagad','upay','cash') NOT NULL DEFAULT 'bkash',
	status ENUM('pending','paid','denied') NOT NULL DEFAULT 'pending',
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	INDEX (user_id),
	CONSTRAINT fk_pay_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Backfill column if table already existed (older MySQL lacks IF NOT EXISTS)
$hasMethod = $conn->query("SHOW COLUMNS FROM uploader_payments LIKE 'method'");
if ($hasMethod && $hasMethod->num_rows === 0) {
	$conn->query("ALTER TABLE uploader_payments ADD COLUMN method ENUM('bkash','nagad','upay','cash') NOT NULL DEFAULT 'bkash'");
}

if (!isset($_SESSION['user_id'])) {
		header('Location: login.php');
		exit;
}

// Admins are already effectively uploaders; uploaders don't need to request.
if (in_array($_SESSION['role'], ['admin', 'uploader'])) {
		header('Location: index.php');
		exit;
}

$userId = intval($_SESSION['user_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$message = trim($_POST['message'] ?? '');
	$amount  = floatval($_POST['amount'] ?? 0);
	$reference = trim($_POST['reference'] ?? '');
	$method = $_POST['method'] ?? 'bkash';

	if ($amount < $uploaderFee) {
		$error = "Minimum payment is $" . number_format($uploaderFee, 2);
	} elseif ($reference === '') {
		$error = "Payment reference is required.";
	}

	if (!isset($error)) {
		// Log payment as pending
		$pay = $conn->prepare("INSERT INTO uploader_payments (user_id, amount, reference, method, status) VALUES (?, ?, ?, ?, 'pending')");
		$pay->bind_param('idss', $userId, $amount, $reference, $method);
		$pay->execute();

		// Store request in requests table (one active request per user)
		$delOld = $conn->prepare("DELETE FROM requests WHERE user_id=?");
		$delOld->bind_param('i', $userId);
		$delOld->execute();

		$ins = $conn->prepare("INSERT INTO requests (user_id, message) VALUES (?, ?)");
		$ins->bind_param('is', $userId, $message);
		$ins->execute();

		header('Location: index.php?requested=1');
		exit;
	}
}
?>

<!DOCTYPE html>
<html>
<head>
<title>Request Uploader</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
	<h2>Request Uploader Access</h2>
	<p style="color:var(--muted)">A one-time payment is required to become an uploader.</p>

	<?php if (isset($error)): ?>
		<p class="error" style="margin-top:10px"><?= htmlspecialchars($error) ?></p>
	<?php endif; ?>

	<form method="post" class="card">
		<label>Payment amount (USD)</label>
		<input type="number" name="amount" step="0.01" min="<?= number_format($uploaderFee, 2, '.', '') ?>" value="<?= number_format($uploaderFee, 2, '.', '') ?>" required>

		<label>Payment method</label>
		<select name="method" required>
			<option value="bkash">bKash</option>
			<option value="nagad">Nagad</option>
			<option value="upay">Upay</option>
			<option value="cash">Cash / Hand</option>
		</select>

		<label>Payment reference / transaction ID</label>
		<input type="text" name="reference" placeholder="e.g. invoice ID or receipt code" required>

		<label>Message to admin (optional)</label>
		<textarea name="message" placeholder="Why do you want to become an uploader?"></textarea>
		<button class="btn btn-block" type="submit">Send Request</button>
	</form>

	<div class="back-row"><a class="btn btn-outline btn-sm" href="index.php">Back</a></div>
</div>
</body>
</html>

