<?php
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user_id'])) {
    redirectTo(usesAdminLayout() ? '/admin/dashboard.php' : '/employee/dashboard.php');
} else {
    redirectTo('/auth/login.php');
}
