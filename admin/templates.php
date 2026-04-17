<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login();
require_company();

// ── Handle POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    if ($action === 'create' || $action === 'edit') {
        $tplId    = input_int('tpl_id');
        $name     = input_str('name', 'POST', 100);
        $guidance = trim($_POST['guidance'] ?? '');
        $guidance = $guidance !== '' ? $guidance : null;
        $titles   = array_map('trim', $_POST['col_title'] ?? []);
        $colors   = $_POST['col_color'] ?? [];

        $cols = [];
        foreach ($titles as $i => $title) {
            if ($title === '') continue;
            $color  = preg_match('/^#[0-9a-fA-F]{6}$/', $colors[$i] ?? '') ? $colors[$i] : '#5856D6';
            $cols[] = ['title' => $title, 'color' => $color];
        }

        if ($name && count($cols) >= 1) {
            $json = json_encode($cols);
            if ($action === 'create') {
                db_insert(
                    'INSERT INTO board_templates (name, columns_json, guidance) VALUES (?,?,?)',
                    [$name, $json, $guidance]
                );
                flash_set('success', 'Template created.');
            } else {
                db_exec(
                    'UPDATE board_templates SET name=?, columns_json=?, guidance=? WHERE id=?',
                    [$name, $json, $guidance, $tplId]
                );
                flash_set('success', 'Template updated.');
            }
        }
    }

    if ($action === 'delete') {
        $tplId = input_int('tpl_id');
        db_exec('DELETE FROM board_templates WHERE id=? AND is_default=0', [$tplId]);
        flash_set('success', 'Template deleted.');
    }

    redirect(BASE_URL . '/admin/templates.php');
}

$templates  = db_query('SELECT * FROM board_templates ORDER BY is_default DESC, name');
$pageTitle  = 'Board Templates';
$activePage = 'templates';

require_once ROOT_PATH . '/templates/admin-header.php';
?>

<div style="display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap;">

  <!-- ── Template list ──────────────────────────────────────────────────── -->
  <div style="flex:1;min-width:280px;">
    <div class="card">
      <div class="card__header">
        <h2 class="card__title">Saved Templates</h2>
        <span class="text-sm" style="color:var(--muted);"><?= count($templates) ?> template<?= count($templates) !== 1 ? 's' : '' ?></span>
      </div>
      <?php if (empty($templates)): ?>
        <div class="empty-state"><p>No templates yet. Create one →</p></div>
      <?php else: ?>
      <div style="padding:.35rem 0;">
        <?php foreach ($templates as $tpl):
            $cols = json_decode($tpl['columns_json'], true) ?? [];
            $hasGuidance = !empty($tpl['guidance'] ?? '');
        ?>
        <div style="padding:.9rem 1.25rem;border-bottom:1px solid var(--divider);">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem;">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:.4rem;">
                <strong style="font-size:.9rem;"><?= e($tpl['name']) ?></strong>
                <?php if ($tpl['is_default']): ?>
                  <span style="font-size:.65rem;background:var(--brand-lt);color:var(--brand-mid);padding:.1rem .45rem;border-radius:999px;font-weight:700;">Default</span>
                <?php endif; ?>
                <?php if ($hasGuidance): ?>
                  <span style="font-size:.65rem;background:var(--color-success-bg,.10);color:#166534;padding:.1rem .45rem;border-radius:999px;font-weight:600;" title="Has session guidance">📋 Guidance</span>
                <?php endif; ?>
              </div>
              <!-- Column pills -->
              <div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:<?= $hasGuidance ? '.5rem' : '0' ?>;">
                <?php foreach ($cols as $col): ?>
                <span style="background:<?= e($col['color']) ?>22;border:1px solid <?= e($col['color']) ?>;color:<?= e($col['color']) ?>;font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:4px;">
                  <?= e($col['title']) ?>
                </span>
                <?php endforeach; ?>
              </div>
              <!-- Guidance preview -->
              <?php if ($hasGuidance): ?>
              <div style="font-size:.78rem;color:var(--muted);line-height:1.5;background:var(--brand-lt);border-left:2.5px solid var(--brand-mid);padding:.4rem .65rem;border-radius:0 var(--r-xs) var(--r-xs) 0;max-height:3.6rem;overflow:hidden;position:relative;">
                <?= e(mb_substr($tpl['guidance'], 0, 160)) ?><?= mb_strlen($tpl['guidance']) > 160 ? '…' : '' ?>
              </div>
              <?php endif; ?>
            </div>
            <!-- Action buttons -->
            <div style="display:flex;gap:.4rem;align-items:center;flex-shrink:0;">
              <button class="btn btn--sm btn--outline edit-tpl-btn"
                data-id="<?= (int)$tpl['id'] ?>"
                data-name="<?= e($tpl['name']) ?>"
                data-guidance="<?= e($tpl['guidance'] ?? '') ?>"
                data-cols="<?= e($tpl['columns_json']) ?>">Edit</button>
              <?php if (!$tpl['is_default']): ?>
              <form method="POST" style="display:inline" id="del-tpl-<?= (int)$tpl['id'] ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="tpl_id" value="<?= (int)$tpl['id'] ?>">
                <button type="button" class="btn btn--sm btn--ghost" style="color:var(--accent-red);"
                  data-confirm="Delete template &quot;<?= e($tpl['name']) ?>&quot;? Sessions using it will keep their columns."
                  data-confirm-title="Delete Template" data-confirm-btn="Delete"
                  data-confirm-form="del-tpl-<?= (int)$tpl['id'] ?>">✕</button>
              </form>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Create / Edit form ─────────────────────────────────────────────── -->
  <div style="flex:1;min-width:320px;">
    <div class="card" id="tpl-form-card">
      <div class="card__header">
        <h2 class="card__title" id="tpl-form-title">New Template</h2>
      </div>
      <div class="card__body">
        <form method="POST" action="<?= e(BASE_URL) ?>/admin/templates.php" id="tpl-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" id="tpl-action" value="create">
          <input type="hidden" name="tpl_id"  id="tpl-id"   value="">

          <!-- Name -->
          <div class="form-group">
            <label class="form-label">Template Name <span class="required">*</span></label>
            <input type="text" name="name" id="tpl-name" class="form-input"
              required maxlength="100" placeholder="e.g. 4Ls Retrospective">
          </div>

          <!-- Session Guidance -->
          <div class="form-group">
            <label class="form-label">Session Guidance
              <span style="font-size:.75rem;color:var(--muted);font-weight:400;"> — optional</span>
            </label>
            <textarea name="guidance" id="tpl-guidance" class="form-input" rows="4"
              maxlength="2000"
              placeholder="Explain how to run this session. E.g. 'Each participant silently adds notes for 5 minutes, then we group and discuss. Vote on the most impactful items.' Shown to participants during the session."
            ></textarea>
            <p class="form-hint">Shown to participants as a collapsible guide during the session. Max 2000 chars. Updates here reflect immediately in all sessions using this template.</p>
          </div>

          <!-- Columns -->
          <label class="form-label">Columns <span class="required">*</span></label>
          <div id="tpl-cols" style="display:flex;flex-direction:column;gap:.5rem;margin-bottom:.75rem;"></div>
          <button type="button" id="tpl-add-col" class="btn btn--outline btn--sm" style="width:100%;margin-bottom:1.1rem;">+ Add Column</button>

          <div class="form-actions" style="margin-top:0;">
            <button type="button" id="tpl-reset-btn" class="btn btn--ghost">Reset</button>
            <button type="submit" class="btn btn--primary">Save Template</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
const DEFAULT_COLS = [
    {title:'Start',   color:'#22c55e'},
    {title:'Stop',    color:'#ef4444'},
    {title:'Continue',color:'#3b82f6'},
];

function renderCols(cols) {
    const container = document.getElementById('tpl-cols');
    container.innerHTML = '';
    (cols || DEFAULT_COLS).forEach(col => addColRow(col.title, col.color));
}

function addColRow(title = '', color = '#5856D6') {
    const container = document.getElementById('tpl-cols');
    const row = document.createElement('div');
    row.className = 'col-builder-row';
    row.innerHTML = `
        <input type="text" name="col_title[]" class="form-input form-input--sm"
            value="${title.replace(/"/g,'&quot;')}" placeholder="Column title" required maxlength="100">
        <input type="color" name="col_color[]" class="col-color-picker" value="${color}">
        <button type="button" class="btn btn--ghost btn--sm" onclick="this.closest('.col-builder-row').remove()">✕</button>
    `;
    container.appendChild(row);
}

renderCols(DEFAULT_COLS);

document.getElementById('tpl-add-col').addEventListener('click', () => addColRow());

document.getElementById('tpl-reset-btn').addEventListener('click', () => {
    document.getElementById('tpl-action').value    = 'create';
    document.getElementById('tpl-id').value        = '';
    document.getElementById('tpl-name').value      = '';
    document.getElementById('tpl-guidance').value  = '';
    document.getElementById('tpl-form-title').textContent = 'New Template';
    renderCols(DEFAULT_COLS);
    document.getElementById('tpl-form-card').scrollIntoView({behavior:'smooth'});
});

document.querySelectorAll('.edit-tpl-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const cols = JSON.parse(btn.dataset.cols);
        document.getElementById('tpl-action').value    = 'edit';
        document.getElementById('tpl-id').value        = btn.dataset.id;
        document.getElementById('tpl-name').value      = btn.dataset.name;
        document.getElementById('tpl-guidance').value  = btn.dataset.guidance || '';
        document.getElementById('tpl-form-title').textContent = 'Edit: ' + btn.dataset.name;
        renderCols(cols);
        document.getElementById('tpl-form-card').scrollIntoView({behavior:'smooth'});
    });
});
</script>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
