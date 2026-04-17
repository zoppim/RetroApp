<?php /** Admin header — Liquid Glass v1.2 */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title><?= e($pageTitle ?? APP_NAME) ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body>
<?php
$_co = db_row('SELECT name, team_name FROM companies WHERE id=?', [current_company_id()])
     ?? ['name' => APP_NAME, 'team_name' => ''];
$_ap = $activePage ?? '';
?>

<!-- Sidebar / mobile bottom nav -->
<a href="#main-content" class="skip-link">Skip to main content</a>
<nav class="sidebar" aria-label="Main navigation">
  <div class="sidebar__logo">
    <a href="<?= e(BASE_URL) ?>/admin/dashboard.php">
      🔄 <?= e($_co['name'] ?: APP_NAME) ?>
    </a>
    <?php if ($_co['team_name']): ?>
    <span class="team-name"><?= e($_co['team_name']) ?></span>
    <?php endif; ?>
  </div>

  <ul class="sidebar__nav">
    <li class="sidebar__group" role="presentation">Workspace</li>
    <li class="<?= $_ap === 'dashboard' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/dashboard.php" aria-current="<?= $_ap === 'dashboard' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">□</span><span>Dashboard</span>
      </a>
    </li>
    <li class="<?= in_array($_ap, ['rooms','new-room']) ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/rooms.php" aria-current="<?= in_array($_ap, ['rooms','new-room']) ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">≡</span><span>Sessions</span>
      </a>
    </li>
    <li class="<?= $_ap === 'actions' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/actions.php" aria-current="<?= $_ap === 'actions' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">✓</span><span>Actions</span>
      </a>
    </li>
    <li class="<?= $_ap === 'templates' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/templates.php" aria-current="<?= $_ap === 'templates' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">⊞</span><span>Templates</span>
      </a>
    </li>
    <li class="sidebar__group" role="presentation">Tools</li>
    <li class="<?= $_ap === 'poker' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/poker.php" aria-current="<?= $_ap === 'poker' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">🃏</span><span>Poker</span>
      </a>
    </li>
    <li class="sidebar__group" role="presentation">Account</li>
    <li class="<?= $_ap === 'data' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/data.php" aria-current="<?= $_ap === 'data' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">🗄</span><span>Data</span>
      </a>
    </li>
    <li class="<?= $_ap === 'settings' ? 'active' : '' ?>">
      <a href="<?= e(BASE_URL) ?>/admin/settings.php" aria-current="<?= $_ap === 'settings' ? 'page' : 'false' ?>">
        <span class="nav-icon" aria-hidden="true">⚙</span><span>Settings</span>
      </a>
    </li>
  </ul>

  <div class="sidebar__footer">
    <span class="sidebar__user">👤 <?= e($_SESSION['admin_username'] ?? '') ?></span>
    <form method="POST" action="<?= e(BASE_URL) ?>/admin/logout.php">
      <?= csrf_field() ?>
      <button type="submit" class="sidebar__logout">Sign out</button>
    </form>
  </div>
</nav>

<!-- Main -->
<div class="main-content" id="main-content">
  <div class="topbar">
    <h1 class="page-title"><?= e($pageTitle ?? '') ?></h1>
    <div class="topbar__actions">
      <?php if (!empty($topbarActions)): ?><?= $topbarActions ?><?php endif; ?>
      <a href="<?= e(BASE_URL) ?>/admin/room-create.php" class="btn btn--primary btn--sm">+ New</a>
    </div>
  </div>
  <div class="content-body">
    <?= flash_html() ?>
