<?php
// Expects $pageTitle to be set by including page
if (!isset($pageTitle)) { $pageTitle = 'Dashboard'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>

<!-- PWA -->
<meta name="theme-color" content="#4338ca">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
<link rel="icon" href="<?= BASE_URL ?>/assets/img/favicon.ico" sizes="any">
<link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/icon-192.png">

<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?>">
<script>
  window.CSRF_TOKEN = <?= json_encode(csrfToken()) ?>;
  window.IS_ADMIN = <?= isAdmin() ? 'true' : 'false' ?>;
  window.CAN_DELETE_LEADS = <?= userCan('leads.delete') ? 'true' : 'false' ?>;
  window.CAN_MANAGE_PROPERTIES = <?= userCan('properties.manage') ? 'true' : 'false' ?>;
  window.CAN_ASSIGN_LEADS = <?= userCan('leads.assign') ? 'true' : 'false' ?>;
  window.CAN_CLOSE_DEALS = <?= userCan('deals.close') ? 'true' : 'false' ?>;
  window.CAN_VIEW_REVENUE = <?= userCan('revenue.view') ? 'true' : 'false' ?>;
</script>
</head>
<body data-base-url="<?= BASE_URL ?>">

<nav class="navbar navbar-expand-lg app-navbar sticky-top">
  <div class="container-fluid px-3 flex-nowrap">
    <button class="btn btn-icon d-lg-none me-2" id="sidebarToggle"><i class="bi bi-list fs-4"></i></button>
    <a class="navbar-brand fw-800" href="<?= BASE_URL ?>/<?= usesAdminLayout() ? 'admin' : 'employee' ?>/dashboard.php">
      <i class="bi bi-buildings-fill me-1"></i> Property<span class="text-accent">CRM</span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <span class="badge role-badge text-uppercase"><?= e($_SESSION['role']) ?></span>
      <div class="dropdown">
        <button class="btn btn-user dropdown-toggle" data-bs-toggle="dropdown">
          <i class="bi bi-person-circle me-1"></i><span class="user-name-text"><?= e($_SESSION['name']) ?></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
        </ul>
      </div>
    </div>
  </div>
</nav>

<div class="app-shell">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="app-main">
