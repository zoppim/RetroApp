<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
if (is_admin_logged_in()) redirect(BASE_URL . '/admin/dashboard.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = input_str('username', 'POST', 80);
    $password = input_str('password', 'POST', 200);
    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $user = db_row("SELECT id, company_id, username, password_hash FROM users WHERE username=? AND status='active'", [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            admin_login((int)$user['id'], $user['username'], (int)$user['company_id']);
            db_exec("UPDATE users SET last_login_at=NOW() WHERE id=?", [(int)$user['id']]);
            redirect(BASE_URL . '/admin/dashboard.php');
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body class="auth-page">
<div class="auth-card">
  <div style="text-align:center;margin-bottom:1.75rem;">
    <div style="font-size:2.5rem;margin-bottom:.5rem;">🔄</div>
    <h1 style="font-size:1.5rem;font-weight:800;letter-spacing:-.03em;margin-bottom:.25rem;"><?= e(APP_NAME) ?></h1>
    <p style="color:var(--text-secondary);font-size:.9rem;">Sign in to your workspace</p>
  </div>
  <?php if ($error): ?>
  <div class="alert alert--error" style="margin-bottom:1rem;"><?= e($error) ?></div>
  <?php endif; ?>
  <form method="POST" action="<?= e(BASE_URL) ?>/admin/login.php">
    <?= csrf_field() ?>
    <div class="form-group">
      <label class="form-label">Username</label>
      <input type="text" name="username" class="form-input"
        value="<?= e($_POST['username'] ?? '') ?>" autocomplete="username" required autofocus>
    </div>
    <div class="form-group" style="margin-bottom:1.5rem;">
      <label class="form-label">Password</label>
      <input type="password" name="password" class="form-input" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn--primary btn--full btn--lg">Sign In</button>
  </form>
  <p style="text-align:center;margin-top:1.25rem;font-size:.775rem;color:var(--text-tertiary);">
    <?= e(APP_NAME) ?> <span class="version-tag">v<?= e(APP_VERSION) ?></span>
  </p>
</div>
</body>
</html>
