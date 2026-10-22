<?php
/**
 * ONE-TIME SETUP SCRIPT
 * Run this once via browser (e.g. https://yourdomain.com/property-crm/setup/setup_admin.php)
 * right after importing schema.sql, to set a real admin password.
 * DELETE THIS FILE FROM THE SERVER AFTER USE.
 */
require_once __DIR__ . '/../config/database.php';

$message = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? 'admin');
    $password = $_POST['password'] ?? '';
    if (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE username = ? AND role IN ('super_admin','admin')");
        $stmt->execute([$hash, $username]);
        if ($stmt->rowCount() > 0) {
            $message = "Password updated successfully for '$username'. Please delete this file now.";
            $done = true;
        } else {
            $message = "No admin user found with username '$username'.";
        }
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Admin Setup</title>
<style>body{font-family:sans-serif;max-width:420px;margin:60px auto;padding:0 20px;}
input{width:100%;padding:10px;margin:6px 0 14px;box-sizing:border-box;}
button{padding:10px 20px;background:#4338ca;color:#fff;border:none;border-radius:6px;cursor:pointer;}
.msg{padding:10px;border-radius:6px;margin-bottom:14px;background:<?= $done ? '#dcfce7' : '#fee2e2' ?>;}
</style></head><body>
<h2>Admin Password Setup</h2>
<?php if ($message): ?><div class="msg"><?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if (!$done): ?>
<form method="POST">
  <label>Admin Username</label>
  <input type="text" name="username" value="admin" required>
  <label>New Password (min 8 chars)</label>
  <input type="password" name="password" required>
  <button type="submit">Set Password</button>
</form>
<?php endif; ?>
</body></html>
