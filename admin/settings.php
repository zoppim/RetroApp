<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login();
require_company();

$companyId = current_company_id();
$company   = db_row('SELECT * FROM companies WHERE id = ?', [$companyId]);

// ── Handle POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    // Update company profile
    if ($action === 'update_company') {
        $name     = input_str('company_name', 'POST', 200);
        $teamName = input_str('team_name', 'POST', 100);

        if ($name === '') {
            flash_set('error', 'Company name is required.');
        } else {
            // Handle logo upload
            $logoPath = $company['logo_path'] ?? null;
            if (!empty($_FILES['logo']['tmp_name'])) {
                $file = $_FILES['logo'];
                $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) {
                    flash_set('error', 'Invalid logo format. Use PNG, JPG, or WebP.');
                    redirect(BASE_URL . '/admin/settings.php');
                }
                if ($file['size'] > LOGO_MAX_SIZE) {
                    flash_set('error', 'Logo file too large (max 500 KB).');
                    redirect(BASE_URL . '/admin/settings.php');
                }
                $uploadsDir = ROOT_PATH . '/uploads';
                if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
                $newName  = 'logo_' . $companyId . '.' . $ext;
                $destPath = $uploadsDir . '/' . $newName;
                if (move_uploaded_file($file['tmp_name'], $destPath)) {
                    $logoPath = 'uploads/' . $newName;
                }
            }
            db_exec(
                'UPDATE companies SET name=?, team_name=?, logo_path=? WHERE id=?',
                [$name, $teamName ?: null, $logoPath, $companyId]
            );
            flash_set('success', 'Company profile updated.');
        }
        redirect(BASE_URL . '/admin/settings.php');
    }

    // Add new admin
    if ($action === 'add_admin') {
        $username  = input_str('username', 'POST', 80);
        $password  = input_str('password', 'POST', 200);
        $errors = [];
        if (strlen($username) < 3) $errors[] = 'Username must be at least 3 characters.';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (db_row('SELECT id FROM users WHERE username=?', [$username])) {
            $errors[] = 'Username already exists.';
        }
        if (empty($errors)) {
            db_insert(
                "INSERT INTO users (company_id, username, password_hash, role) VALUES (?,?,?,'admin')",
                [$companyId, $username, password_hash($password, PASSWORD_BCRYPT)]
            );
            flash_set('success', "Admin '{$username}' created.");
        } else {
            flash_set('error', implode(' ', $errors));
        }
        redirect(BASE_URL . '/admin/settings.php');
    }

    // Toggle admin status
    if ($action === 'toggle_admin') {
        $userId = input_int('user_id');
        if ($userId === current_admin_id()) {
            flash_set('error', 'You cannot deactivate your own account.');
        } else {
            $user = db_row('SELECT status FROM users WHERE id=? AND company_id=?', [$userId, $companyId]);
            if ($user) {
                $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
                db_exec('UPDATE users SET status=? WHERE id=?', [$newStatus, $userId]);
                flash_set('success', 'Admin status updated.');
            }
        }
        redirect(BASE_URL . '/admin/settings.php');
    }

    // Delete admin
    if ($action === 'delete_admin') {
        $userId = input_int('user_id');
        if ($userId === current_admin_id()) {
            flash_set('error', 'You cannot delete your own account.');
        } else {
            $count = (int)(db_row('SELECT COUNT(*) AS n FROM users WHERE company_id=?', [$companyId])['n'] ?? 0);
            if ($count <= 1) {
                flash_set('error', 'Cannot delete the last admin account.');
            } else {
                db_exec('DELETE FROM users WHERE id=? AND company_id=?', [$userId, $companyId]);
                flash_set('success', 'Admin removed.');
            }
        }
        redirect(BASE_URL . '/admin/settings.php');
    }
}

$admins    = db_query('SELECT * FROM users WHERE company_id=? ORDER BY created_at', [$companyId]);
$company   = db_row('SELECT * FROM companies WHERE id = ?', [$companyId]);
$pageTitle  = 'Settings';
$activePage = 'settings';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">

  <!-- Left: Company Profile -->
  <div style="flex:1;min-width:280px;">
    <div class="card">
      <div class="card__header"><span class="card__title">🏢 Company Profile</span></div>
      <div class="card__body">
        <?php if ($company['logo_path'] && file_exists(ROOT_PATH . '/' . $company['logo_path'])): ?>
        <div style="margin-bottom:1rem;">
          <img src="<?= e(BASE_URL . '/' . $company['logo_path']) ?>" alt="Logo"
            style="max-height:60px;max-width:180px;border-radius:6px;border:1px solid rgba(255,255,255,.3);">
        </div>
        <?php endif; ?>
        <form method="POST" enctype="multipart/form-data" action="<?= e(BASE_URL) ?>/admin/settings.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_company">
          <div class="form-group">
            <label class="form-label">Company / App Name *</label>
            <input type="text" name="company_name" class="form-input"
              value="<?= e($company['name'] ?? '') ?>" required maxlength="200">
          </div>
          <div class="form-group">
            <label class="form-label">Team Name <span style="font-weight:400;color:var(--text-secondary)">(shown in sidebar)</span></label>
            <input type="text" name="team_name" class="form-input"
              value="<?= e($company['team_name'] ?? '') ?>" maxlength="100" placeholder="e.g. Engineering Team">
          </div>
          <div class="form-group">
            <label class="form-label">Logo <span style="font-weight:400;color:var(--text-secondary)">(PNG/JPG/WebP, max 500 KB)</span></label>
            <input type="file" name="logo" class="form-input" accept=".png,.jpg,.jpeg,.webp,.gif"
              style="padding:.3rem .5rem;">
          </div>
          <button type="submit" class="btn btn--primary">Save Profile</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right: Admin Users -->
  <div style="flex:1;min-width:300px;">
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card__header"><span class="card__title">👥 Admin Users (<?= count($admins) ?>)</span></div>
      <div style="padding:.25rem 0;">
        <?php foreach ($admins as $u): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.25rem;border-bottom:1px solid rgba(255,255,255,.3);">
          <div>
            <strong style="font-size:.875rem;"><?= e($u['username']) ?></strong>
            <?php if ($u['id'] == current_admin_id()): ?>
              <span style="font-size:.7rem;background:var(--brand-lt);color:var(--brand-mid);padding:.1rem .4rem;border-radius:4px;margin-left:.35rem;">You</span>
            <?php endif; ?>
            <br>
            <span style="font-size:.75rem;color:var(--muted);">
              <?= $u['status'] === 'active'
                ? '<span style="color:var(--accent-green);">● Active</span>'
                : '<span style="color:var(--muted);">○ Inactive</span>' ?>
              <?php if ($u['last_login_at']): ?>
                · Last login <?= e(date('M j, Y', strtotime($u['last_login_at']))) ?>
              <?php endif; ?>
            </span>
          </div>
          <?php if ($u['id'] != current_admin_id()): ?>
          <div class="btn-group">
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="toggle_admin">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn--outline btn--sm">
                <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
            <form method="POST" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_admin">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button class="btn btn--ghost btn--sm" style="color:var(--color-danger);"
                data-confirm="Remove admin <?= e($u['username']) ?>? They will lose access." data-confirm-title="Remove Admin" data-confirm-btn="Remove">✕</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <div class="card__header"><span class="card__title">➕ Add Admin</span></div>
      <div class="card__body">
        <form method="POST" action="<?= e(BASE_URL) ?>/admin/settings.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="add_admin">
          <div class="form-group">
            <label class="form-label">Username *</label>
            <input type="text" name="username" class="form-input" required minlength="3" maxlength="80">
          </div>
          <div class="form-group">
            <label class="form-label">Password * <span style="font-weight:400;color:var(--text-secondary)">(min 8 chars)</span></label>
            <input type="password" name="password" class="form-input" required minlength="8" autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn--success btn--sm">Create Admin</button>
        </form>
      </div>
    </div>

  </div>
</div>

<!-- App info -->
<div class="card" style="margin-top:1.25rem;">
  <div class="card__body" style="display:flex;gap:2rem;flex-wrap:wrap;font-size:.875rem;color:var(--muted);">
    <span><strong>Version:</strong> <?= e(APP_VERSION) ?></span>
    <span><strong>Environment:</strong> <?= e(APP_ENV) ?></span>
    <span><strong>Base URL:</strong> <?= e(BASE_URL) ?></span>
    <a href="<?= e(BASE_URL) ?>/upgrade.php" style="margin-left:auto;">Run upgrade wizard →</a>
  </div>
</div>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
