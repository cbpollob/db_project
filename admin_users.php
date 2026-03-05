<?php
session_start();
include "db_connection.php";

$cssVersion = file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : time();

// Config: one-time fee to become uploader
$uploaderFee = 20.00;

// Ensure payment table exists (shared with request_uploader.php)
$conn->query("CREATE TABLE IF NOT EXISTS uploader_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  reference VARCHAR(120) NOT NULL,
  method ENUM('bkash','nagad','upay','cash') NOT NULL DEFAULT 'bkash',
  status ENUM('pending','paid','denied') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  CONSTRAINT fk_pay_user_admin FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
// Backfill payment method column for older MySQL (no IF NOT EXISTS support)
$hasMethod = $conn->query("SHOW COLUMNS FROM uploader_payments LIKE 'method'");
if ($hasMethod && $hasMethod->num_rows === 0) {
    $conn->query("ALTER TABLE uploader_payments ADD COLUMN method ENUM('bkash','nagad','upay','cash') NOT NULL DEFAULT 'bkash'");
}

// Table: uploader subscription plans (one plan per uploader)
$conn->query("CREATE TABLE IF NOT EXISTS uploader_plans (
  uploader_id INT PRIMARY KEY,
  price DECIMAL(10,2) NOT NULL DEFAULT 5.00,
  title VARCHAR(120) DEFAULT 'Support this creator',
  description TEXT,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_plan_user FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Table: user subscriptions to uploaders
$conn->query("CREATE TABLE IF NOT EXISTS uploader_subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  subscriber_id INT NOT NULL,
  uploader_id INT NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_subscriber (subscriber_id, uploader_id),
  CONSTRAINT fk_sub_subscriber FOREIGN KEY (subscriber_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_sub_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// --- enforce pollob admin presence/credentials (password: pollob123) ---
$adminUser = 'pollob';
$adminEmail = 'pollob@example.com';
// regenerate the hash each load to keep the credential in sync
$adminPassHash = password_hash('pollob123', PASSWORD_DEFAULT);

$stmt = $conn->prepare(
    "INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, 'admin')
     ON DUPLICATE KEY UPDATE password=VALUES(password), role='admin', username=VALUES(username), email=VALUES(email)"
);
$stmt->bind_param('sss', $adminUser, $adminEmail, $adminPassHash);
$stmt->execute();
// --- end enforce pollob admin ---

if(!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin'){
    header('Location: login.php?type=admin');
    exit;
}

// handle deletion or role change
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    // Payment status controls
    if (isset($_POST['mark_payment_paid'])){
      $payId = intval($_POST['mark_payment_paid']);
      $stmt = $conn->prepare("UPDATE uploader_payments SET status='paid' WHERE id=?");
      $stmt->bind_param('i', $payId);
      $stmt->execute();
    }
    if (isset($_POST['deny_payment'])){
      $payId = intval($_POST['deny_payment']);
      $stmt = $conn->prepare("UPDATE uploader_payments SET status='denied' WHERE id=?");
      $stmt->bind_param('i', $payId);
      $stmt->execute();
    }

    // approve/deny uploader requests (stored in requests table)
    if (isset($_POST['approve_request_user_id'])){
      $id = intval($_POST['approve_request_user_id']);
      // Require a paid payment before approval
      $payChk = $conn->prepare("SELECT status FROM uploader_payments WHERE user_id=? ORDER BY created_at DESC LIMIT 1");
      $payChk->bind_param('i', $id);
      $payChk->execute();
      $payChk->bind_result($payStatus);
      $hasPay = $payChk->fetch();
      $payChk->close();

      if (!$hasPay || $payStatus !== 'paid') {
        $error = "Payment not marked as paid for this user.";
      } else {
        $role = 'uploader';
        $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
        $stmt->bind_param('si', $role, $id);
        $stmt->execute();

        $delReq = $conn->prepare("DELETE FROM requests WHERE user_id=?");
        $delReq->bind_param('i', $id);
        $delReq->execute();
      }
    }
    if (isset($_POST['deny_request_user_id'])){
      $id = intval($_POST['deny_request_user_id']);
      $delReq = $conn->prepare("DELETE FROM requests WHERE user_id=?");
      $delReq->bind_param('i', $id);
      $delReq->execute();
    }

    // Set/adjust uploader subscription plan price
    if (isset($_POST['set_plan_uploader_id'])) {
        $uploaderId = intval($_POST['set_plan_uploader_id']);
        $price = max(0.50, floatval($_POST['plan_price'] ?? 0));
        $isActive = isset($_POST['plan_active']) ? 1 : 0;
        $stmt = $conn->prepare(
            "INSERT INTO uploader_plans (uploader_id, price, is_active)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE price=VALUES(price), is_active=VALUES(is_active)"
        );
        $stmt->bind_param('idi', $uploaderId, $price, $isActive);
        $stmt->execute();
    }

    // create uploader (producer)
    if (isset($_POST['create_uploader'])){
      $username = trim($_POST['username'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $passwordPlain = $_POST['password'] ?? '';

      if ($username === '' || $email === '' || $passwordPlain === ''){
        $error = "All fields are required to create an uploader.";
      } else {
        $check = $conn->prepare("SELECT id FROM users WHERE email=? OR username=?");
        $check->bind_param("ss", $email, $username);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0){
          $error = "Username or email already exists.";
        } else {
          $hash = password_hash($passwordPlain, PASSWORD_DEFAULT);
          $role = 'uploader';
          $stmt = $conn->prepare("INSERT INTO users (username, email, password, role) VALUES (?, ?, ?, ?)");
          $stmt->bind_param("ssss", $username, $email, $hash, $role);
          $stmt->execute();
        }
      }
    }

    if (isset($_POST['delete_id'])){
        $id = intval($_POST['delete_id']);
        $chk = $conn->prepare("SELECT username, email, role FROM users WHERE id=?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $chk->store_result();
        $chk->bind_result($existingName, $existingEmail, $existingRole);
        $hasRole = ($chk->num_rows > 0) && $chk->fetch();
        // Prevent deleting pollob
        if ($hasRole && ($existingRole === 'admin' && ($existingName === $adminUser || $existingEmail === $adminEmail))) {
            $error = "Cannot delete the default admin account.";
        } else if ($hasRole && $existingRole === 'admin') {
            $error = "Cannot delete an admin account.";
        } else {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }
    }
    if (isset($_POST['promote_id']) && in_array($_POST['new_role'], ['user','uploader','admin'])){
        $id = intval($_POST['promote_id']);
        $role = $_POST['new_role'];
        $chk = $conn->prepare("SELECT username, email FROM users WHERE id=?");
        $chk->bind_param("i", $id);
        $chk->execute();
        $chk->bind_result($cName, $cEmail);
        $chk->fetch();
        $chk->close();
        // Block admin promotion for any non-pollob
        if ($role === 'admin' && ($cName !== $adminUser && $cEmail !== $adminEmail)) {
            $error = "Only {$adminUser} can be admin.";
        } elseif ($role === 'admin' && ($cName === $adminUser || $cEmail === $adminEmail)) {
            $stmt = $conn->prepare("UPDATE users SET role='admin' WHERE id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
        } else {
            // Forbid demoting pollob's admin
            if (($cName === $adminUser || $cEmail === $adminEmail)) {
                $error = "Cannot change role of default admin.";
            } else {
                $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=?");
                $stmt->bind_param("si", $role, $id);
                $stmt->execute();
            }
        }
    }
    if (!isset($error)) {
        header('Location: admin_users.php');
        exit;
    }
}

// Pending uploader requests (requests table)
$pending = $conn->query(
    "SELECT r.user_id, u.username, u.email, r.message, r.created_at,
      p.id as pay_id, p.amount as pay_amount, p.reference as pay_ref, p.method as pay_method, p.status as pay_status, p.created_at as pay_created_at
   FROM requests r 
   JOIN users u ON u.id=r.user_id
   LEFT JOIN uploader_payments p ON p.id = (
       SELECT p2.id FROM uploader_payments p2 WHERE p2.user_id = r.user_id ORDER BY p2.created_at DESC LIMIT 1
   )
   ORDER BY r.created_at DESC"
);

// Your DB schema doesn't include created_at, so sort by id desc
$res = $conn->query("SELECT u.id, u.username, u.email, u.role, p.price as plan_price, p.is_active as plan_active
                     FROM users u
                     LEFT JOIN uploader_plans p ON p.uploader_id = u.id
                     ORDER BY u.id DESC");
$loadError = ($res === false) ? "Failed to load users list." : null;

// Get stats for dashboard
$statsQuery = $conn->query("SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) as users,
    SUM(CASE WHEN role = 'uploader' THEN 1 ELSE 0 END) as uploaders,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins
    FROM users");
$stats = $statsQuery ? $statsQuery->fetch_assoc() : ['total_users' => 0, 'users' => 0, 'uploaders' => 0, 'admins' => 0];

$movieStats = $conn->query("SELECT COUNT(*) as total_movies FROM movies");
$movieCount = $movieStats ? $movieStats->fetch_assoc()['total_movies'] : 0;

$pendingCount = $pending ? $pending->num_rows : 0;
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Dashboard - CineClick</title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css?v=<?= $cssVersion ?>">
<style>
/* Admin palette + layout (kept local to this page) */
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Manrope:wght@500;600&display=swap');

:root {
  --admin-bg: radial-gradient(circle at 10% 20%, rgba(74, 222, 128, 0.09), transparent 26%),
              radial-gradient(circle at 80% 0%, rgba(56, 189, 248, 0.12), transparent 30%),
              radial-gradient(circle at 50% 80%, rgba(244, 114, 182, 0.08), transparent 32%),
              linear-gradient(160deg, #0b1220 0%, #0f172a 45%, #0b1220 100%);
  --admin-card: rgba(255, 255, 255, 0.05);
  --admin-card-strong: rgba(255, 255, 255, 0.08);
  --admin-border: rgba(255, 255, 255, 0.14);
  --admin-text: #eef2ff;
  --admin-muted: #cbd5e1;
  --admin-accent: #7c3aed;
  --admin-accent-2: #22d3ee;
  --admin-accent-3: #fbbf24;
  --admin-danger: #ef4444;
}

body.admin-page {
  background: var(--admin-bg);
  min-height: 100vh;
  color: var(--admin-text);
  font-family: 'Space Grotesk', 'Manrope', system-ui, -apple-system, 'Segoe UI', sans-serif;
  padding-bottom: 40px;
}

.admin-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 18px 20px;
  background: linear-gradient(120deg, rgba(124,58,237,0.18), rgba(34,211,238,0.18));
  border: 1px solid var(--admin-border);
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(15,23,42,0.45);
  margin-bottom: 24px;
}
.admin-title h1 {
  margin: 0;
  font-size: 28px;
  letter-spacing: 0.3px;
}
.admin-title small {color: var(--admin-muted);display:block;margin-top:6px;}

.admin-nav {display:flex;align-items:center;gap:10px;flex-wrap:wrap;}

.admin-badge {
  margin-top: 6px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: linear-gradient(135deg, #22d3ee, #7c3aed);
  padding: 6px 10px;
  border-radius: 999px;
  font-weight: 700;
  color: #0b1220;
  box-shadow: 0 10px 30px rgba(124,58,237,0.35);
}

.stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
.stat-card {
  background: var(--admin-card);
  border: 1px solid var(--admin-border);
  padding: 16px;
  border-radius: 14px;
  backdrop-filter: blur(8px);
  position: relative;
  overflow: hidden;
}
.stat-card h4 {margin:0;color:var(--admin-muted);font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}
.stat-card .stat-value {font-size:28px;font-weight:700;margin-top:6px;}
.stat-card .pill {position:absolute;right:12px;top:12px;font-size:12px;padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.06);}

.admin-panels {display:grid;grid-template-columns:2fr 1fr;gap:16px;margin-bottom:18px;}
.glass-card {
  background: var(--admin-card-strong);
  border: 1px solid var(--admin-border);
  border-radius: 16px;
  padding: 18px;
  backdrop-filter: blur(10px);
  box-shadow: 0 18px 50px rgba(0,0,0,0.35);
}
.glass-card h3 {margin:0 0 12px;font-size:18px;}
.section-head {display:flex;align-items:center;gap:10px;justify-content:space-between;margin-bottom:12px;}
.section-head .muted {color:var(--admin-muted);font-size:13px;}

.requests {display:flex;flex-direction:column;gap:12px;}
.request-item {
  border:1px solid rgba(255,255,255,0.08);
  border-radius:12px;
  padding:12px;
  background: rgba(255,255,255,0.03);
}
.request-item .meta {display:flex;align-items:center;gap:10px;font-size:13px;color:var(--admin-muted);}
.request-actions {display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;}

.form-grid {display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}

.users-table {width:100%;border-collapse:collapse;font-size:14px;}
.users-table th,.users-table td {padding:10px;border-bottom:1px solid rgba(255,255,255,0.08);text-align:left;}
.users-table th {color:var(--admin-muted);font-weight:600;}
.role-select {min-width:130px;padding:8px 10px;border-radius:10px;border:1px solid rgba(255,255,255,0.12);background:rgba(255,255,255,0.04);color:var(--admin-text);}

.tag {display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:700;}
.tag.user {background:rgba(34,211,238,0.16);color:#67e8f9;border:1px solid rgba(34,211,238,0.35);}
.tag.uploader {background:rgba(251,191,36,0.16);color:#fcd34d;border:1px solid rgba(251,191,36,0.4);}
.tag.admin {background:rgba(124,58,237,0.16);color:#c4b5fd;border:1px solid rgba(124,58,237,0.4);}

.pill-btn {border:1px solid rgba(255,255,255,0.18);background:rgba(255,255,255,0.05);color:var(--admin-text);padding:8px 12px;border-radius:10px;cursor:pointer;font-weight:600;}
.pill-btn.danger {border-color:rgba(239,68,68,0.4);color:#fecdd3;}
.pill-btn:hover {filter:brightness(1.05);}

.error-box {background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.35);padding:12px 14px;border-radius:12px;color:#fecdd3;margin-bottom:12px;}

@media(max-width: 900px){
  .admin-panels {grid-template-columns:1fr;}
  .admin-header {flex-direction:column;align-items:flex-start;}
}
</style>
</head>
<body class="admin-page">
<div class="container">
  <div class="admin-header">
    <div class="admin-title">
      <h1>🎬 Admin Dashboard</h1>
      <small>Control center for uploads, users, and access.</small>
      <span class="admin-badge">🛡️ <?= htmlspecialchars($_SESSION['name']) ?></span>
    </div>
    <nav class="admin-nav">
      <a class="btn btn-sm" href="upload_movie.php">📤 Upload Movie</a>
      <a class="btn btn-outline btn-sm" href="change_password.php">🔐 Change Password</a>
      <a class="btn btn-outline btn-sm" href="index.php">🏠 View Site</a>
      <a class="btn btn-outline btn-sm" href="logout.php">🚪 Logout</a>
    </nav>
  </div>

  <?php if (isset($error)): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="stat-grid">
    <div class="stat-card">
      <div class="pill">Users</div>
      <h4>Total Accounts</h4>
      <div class="stat-value"><?= (int)$stats['total_users'] ?></div>
    </div>
    <div class="stat-card">
      <div class="pill">Admins</div>
      <h4>Admin Seats</h4>
      <div class="stat-value"><?= (int)$stats['admins'] ?></div>
    </div>
    <div class="stat-card">
      <div class="pill">Uploaders</div>
      <h4>Creators</h4>
      <div class="stat-value"><?= (int)$stats['uploaders'] ?></div>
    </div>
    <div class="stat-card">
      <div class="pill">Movies</div>
      <h4>Published Titles</h4>
      <div class="stat-value"><?= (int)$movieCount ?></div>
    </div>
  </div>

  <div class="admin-panels">
    <div class="glass-card">
      <div class="section-head">
        <h3>Pending Uploader Requests (<?= $pendingCount ?>)</h3>
        <span class="muted">Approve or decline access requests.</span>
      </div>
      <div class="requests">
        <?php if ($pending && $pending->num_rows > 0): ?>
          <?php while($row = $pending->fetch_assoc()): ?>
            <div class="request-item">
              <strong><?= htmlspecialchars($row['username']) ?></strong>
              <div class="meta">
                <span>📧 <?= htmlspecialchars($row['email']) ?></span>
                <?php if (!empty($row['message'])): ?>
                  <span>💬 <?= htmlspecialchars($row['message']) ?></span>
                <?php endif; ?>
                <span>💳 Payment: <?= $row['pay_amount'] !== null ? '$'.number_format($row['pay_amount'], 2) : 'not submitted' ?></span>
                <?php if (!empty($row['pay_method'])): ?>
                  <span>Method: <?= htmlspecialchars(strtoupper($row['pay_method'])) ?></span>
                <?php endif; ?>
                <span>Status: <?= htmlspecialchars($row['pay_status'] ?? 'pending') ?></span>
                <?php if (!empty($row['pay_ref'])): ?>
                  <span>Ref: <?= htmlspecialchars($row['pay_ref']) ?></span>
                <?php endif; ?>
              </div>
              <div class="request-actions">
                <?php if (!empty($row['pay_id']) && $row['pay_status'] !== 'paid'): ?>
                  <form method="post">
                    <input type="hidden" name="mark_payment_paid" value="<?= (int)$row['pay_id'] ?>">
                    <button class="btn btn-sm" type="submit">💰 Mark Paid</button>
                  </form>
                <?php endif; ?>

                <form method="post">
                  <input type="hidden" name="approve_request_user_id" value="<?= (int)$row['user_id'] ?>">
                  <button class="btn btn-sm" type="submit">✅ Approve</button>
                </form>
                <form method="post">
                  <input type="hidden" name="deny_request_user_id" value="<?= (int)$row['user_id'] ?>">
                  <button class="pill-btn danger" type="submit">✖ Deny</button>
                </form>
                <?php if (!empty($row['pay_id'])): ?>
                  <form method="post">
                    <input type="hidden" name="deny_payment" value="<?= (int)$row['pay_id'] ?>">
                    <button class="pill-btn danger" type="submit">Refund / Deny</button>
                  </form>
                <?php endif; ?>
              </div>
              <?php if (($row['pay_status'] ?? 'pending') !== 'paid'): ?>
                <div class="muted">Mark payment as paid before approving.</div>
              <?php endif; ?>
            </div>
          <?php endwhile; ?>
        <?php else: ?>
          <div class="muted">No pending requests.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="glass-card">
      <div class="section-head">
        <h3>Create Uploader</h3>
        <span class="muted">Invite a producer quickly.</span>
      </div>
      <form method="post">
        <div class="form-grid">
          <input type="text" name="username" placeholder="Username" required>
          <input type="email" name="email" placeholder="Email" required>
          <input type="password" name="password" placeholder="Temporary password" required>
        </div>
        <button class="btn btn-block" type="submit" name="create_uploader" value="1">➕ Create Uploader</button>
      </form>
    </div>
  </div>

  <div class="glass-card">
    <div class="section-head">
      <h3>All Users</h3>
      <span class="muted">Promote, demote, or remove accounts.</span>
    </div>
    <?php if ($loadError): ?>
      <div class="error-box"><?= htmlspecialchars($loadError) ?></div>
    <?php endif; ?>
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="users-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Email</th>
            <th>Role</th>
            <th>Sub Price</th>
            <th style="width:180px">Change Role</th>
            <th style="width:120px">Delete</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($res && $res->num_rows > 0): ?>
            <?php while($u = $res->fetch_assoc()): ?>
              <tr>
                <td><?= (int)$u['id'] ?></td>
                <td><?= htmlspecialchars($u['username']) ?></td>
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td>
                  <span class="tag <?= htmlspecialchars($u['role']) ?>"><?= htmlspecialchars(ucfirst($u['role'])) ?></span>
                </td>
                <td>
                  <?php if ($u['role'] === 'uploader'): ?>
                    <form method="post" class="actions" style="gap:6px;align-items:center;">
                      <input type="hidden" name="set_plan_uploader_id" value="<?= (int)$u['id'] ?>">
                      <input type="number" name="plan_price" step="0.01" min="0.50" value="<?= htmlspecialchars($u['plan_price'] !== null ? number_format($u['plan_price'],2,'.','') : '5.00') ?>" style="width:90px;">
                      <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:var(--admin-muted)">
                        <input type="checkbox" name="plan_active" value="1" <?= ($u['plan_active'] ?? 1) ? 'checked' : '' ?>> Active
                      </label>
                      <button class="pill-btn" type="submit">Save</button>
                    </form>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td>
                  <form method="post" class="actions" style="gap:6px;align-items:center;">
                    <input type="hidden" name="promote_id" value="<?= (int)$u['id'] ?>">
                    <select name="new_role" class="role-select">
                      <option value="user" <?= $u['role']==='user' ? 'selected' : '' ?>>User</option>
                      <option value="uploader" <?= $u['role']==='uploader' ? 'selected' : '' ?>>Uploader</option>
                      <option value="admin" <?= $u['role']==='admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                    <button class="pill-btn" type="submit">Update</button>
                  </form>
                </td>
                <td>
                  <form method="post" onsubmit="return confirm('Delete this user?');">
                    <input type="hidden" name="delete_id" value="<?= (int)$u['id'] ?>">
                    <button class="pill-btn danger" type="submit">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php else: ?>
            <tr><td colspan="6" class="muted">No users found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>