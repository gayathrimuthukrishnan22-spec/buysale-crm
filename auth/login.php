<?php
require_once __DIR__ . '/../includes/auth.php';

if (isset($_SESSION['user_id'])) {
    redirectTo(usesAdminLayout() ? '/admin/dashboard.php' : '/employee/dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Session expired. Please try again.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } else {
            $user = attemptLogin($username, $password);
            if ($user) {
                $goesToAdminSection = in_array($user['role'], ['super_admin', 'admin', 'manager', 'accountant'], true);
                redirectTo($goesToAdminSection ? '/admin/dashboard.php' : '/employee/dashboard.php');
            } elseif (($GLOBALS['login_error'] ?? '') === 'disabled') {
                $error = 'Your account has been disabled. Please contact admin for more info.';
            } else {
                $error = 'Invalid username or password.';
            }
        }
    }
}
$timeout = isset($_GET['timeout']);
$disabled = isset($_GET['disabled']);
if ($disabled) {
    $error = 'Your account has been disabled. Please contact admin for more info.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login | <?= APP_NAME ?></title>
<link rel="icon" href="<?= BASE_URL ?>/assets/img/favicon.ico" sizes="any">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-card">
    <div class="auth-logo"><i class="bi bi-buildings-fill"></i></div>
    <h4 class="text-center fw-800 mb-1">Property CRM</h4>
    <p class="text-center text-muted-sm mb-4">Sign in to manage leads &amp; properties</p>

    <?php if ($timeout): ?>
      <div class="alert alert-warning py-2 fs-13">Your session expired. Please log in again.</div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger py-2 fs-13"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
      <div class="mb-3">
        <label class="form-label fw-600">Username</label>
        <input type="text" name="username" class="form-control form-control-lg" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-600">Password</label>
        <div class="input-group">
          <input type="password" name="password" id="pwd" class="form-control form-control-lg" required>
          <button class="btn btn-outline-secondary" type="button" id="togglePwd"><i class="bi bi-eye"></i></button>
        </div>
      </div>
      <button type="submit" class="btn btn-accent btn-lg w-100 mt-2">Sign In</button>
    </form>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePwd').addEventListener('click', function () {
  const pwd = document.getElementById('pwd');
  const icon = this.querySelector('i');
  if (pwd.type === 'password') { pwd.type = 'text'; icon.className = 'bi bi-eye-slash'; }
  else { pwd.type = 'password'; icon.className = 'bi bi-eye'; }
});
</script>
</body>
</html>
