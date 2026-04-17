<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

if (is_admin_logged_in()) {
    redirect(BASE_URL . '/admin/dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-card" style="text-align:center;max-width:460px;">
  <div style="font-size:3rem;margin-bottom:.75rem;">🔄</div>
  <h1 style="font-size:1.75rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.5rem;">
    <?= e(APP_NAME) ?>
  </h1>
  <p style="color:var(--text-secondary);margin-bottom:1.75rem;line-height:1.6;">
    A lightweight retrospective tool for agile teams.
  </p>
  <p style="color:var(--text-secondary);font-size:.875rem;margin-bottom:1.5rem;">
    Have a session link? Use it to join your retrospective.
  </p>
  <a href="<?= e(BASE_URL) ?>/admin/login.php" class="btn btn--outline">
    Admin Login →
  </a>
  <p style="margin-top:1.5rem;font-size:.75rem;color:var(--text-secondary);">
    <?= e(APP_NAME) ?> <span class="version-tag">v<?= e(APP_VERSION) ?></span>
  </p>
</div>
<script src="<?= e(BASE_URL) ?>/assets/js/app.js"></script>
</body>
</html>
