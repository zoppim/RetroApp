<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();

$cid    = current_company_id();
$roomId = input_int('id', 'GET');
$isEdit = $roomId > 0;
$room   = null; $roomCols = [];

if ($isEdit) {
    $room = db_row('SELECT * FROM retro_rooms WHERE id=? AND company_id=?', [$roomId, $cid]);
    if (!$room) { flash_set('error', 'Session not found.'); redirect(BASE_URL . '/admin/rooms.php'); }
    $roomCols = db_query('SELECT * FROM retro_columns WHERE room_id=? ORDER BY display_order', [$roomId]);
}

$templates = db_query('SELECT * FROM board_templates ORDER BY is_default DESC, name');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $name        = input_str('name', 'POST', 200);
    $description = input_str('description', 'POST', 1000);
    $sessionDate = input_date('session_date');
    $sessionType = input_str('session_type', 'POST', 20);
    $templateId  = input_int('template_id');
    $maxVotes    = max(1, min(20, (int)($_POST['max_votes'] ?? 5)));
    $allowEdit   = isset($_POST['allow_edit_notes']) ? 1 : 0;
    $joinPwd     = input_str('join_password', 'POST', 100);
    $joinPwd     = $joinPwd !== '' ? $joinPwd : null;
    $status      = input_str('status', 'POST', 20);

    if (!in_array($sessionType, ['retrospective','daily','poker'], true)) $sessionType = 'retrospective';
    $retroFormat = input_str('retro_format', 'POST', 30) ?: 'start-stop-continue';
    if (!in_array($status, ['draft','active','revealed','closed','archived'], true)) $status = 'draft';

    $colTitles = array_map('trim', (array)($_POST['col_title'] ?? []));
    $colColors = (array)($_POST['col_color'] ?? []);
    $validCols = array_filter($colTitles, function($t) { return $t !== ''; });

    if ($name === '')               $errors[] = 'Session name is required.';
    if (count($validCols) < 1)     $errors[] = 'At least one column is required.';
    if (count($validCols) > MAX_COLUMNS) $errors[] = 'Maximum ' . MAX_COLUMNS . ' columns.';

    if (empty($errors)) {
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($isEdit) {
                db_exec(
                    'UPDATE retro_rooms SET name=?,session_type=?,description=?,session_date=?,max_votes=?,allow_edit_notes=?,join_password=?,status=? WHERE id=? AND company_id=?',
                    [$name,$sessionType,$description?:null,$sessionDate,$maxVotes,$allowEdit,$joinPwd,$status,$roomId,$cid]
                );
                // ── Preserve existing column IDs so notes are NOT cascade-deleted ───────
                // Strategy: match existing columns by display_order, UPDATE in place,
                // INSERT new ones, DELETE only columns that no longer exist.
                $existingCols = db_query(
                    'SELECT id, display_order FROM retro_columns WHERE room_id=? ORDER BY display_order',
                    [$roomId]
                );
                $existingIds = array_column($existingCols, 'id');
                // Build the new column list (skipping blanks)
                $newCols = [];
                foreach ($colTitles as $idx => $title) {
                    if (trim($title) === '') continue;
                    $color = preg_match('/^#[0-9a-fA-F]{6}$/', $colColors[$idx] ?? '') ? $colColors[$idx] : '#6366f1';
                    $newCols[] = ['title' => $title, 'color' => $color];
                }
                // UPDATE existing columns that have a matching position
                foreach ($newCols as $pos => $col) {
                    if (isset($existingIds[$pos])) {
                        db_exec(
                            'UPDATE retro_columns SET title=?,color=?,display_order=? WHERE id=?',
                            [$col['title'], $col['color'], $pos, $existingIds[$pos]]
                        );
                    } else {
                        // New column — safe to insert (no notes yet)
                        db_exec(
                            'INSERT INTO retro_columns (room_id,title,color,display_order) VALUES (?,?,?,?)',
                            [$roomId, $col['title'], $col['color'], $pos]
                        );
                    }
                }
                // DELETE columns that no longer exist (only those beyond the new count)
                // Notes in removed columns are cascade-deleted — user removed the column intentionally
                $keepIds = array_slice($existingIds, 0, count($newCols));
                if (!empty($existingIds)) {
                    foreach ($existingIds as $pos => $colId) {
                        if (!in_array($colId, $keepIds, true)) {
                            db_exec('DELETE FROM retro_columns WHERE id=?', [$colId]);
                        }
                    }
                }
                // Skip the generic column INSERT loop below for edits
                $colTitles = []; // already processed above
            } else {
                $uuid   = generate_uuid();
                $roomId = (int)db_insert(
                    'INSERT INTO retro_rooms (company_id,room_uuid,name,session_type,description,template_name,status,max_votes,allow_edit_notes,join_password,session_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$cid,$uuid,$name,$sessionType,$description?:null,'Custom','draft',$maxVotes,$allowEdit,$joinPwd,$sessionDate,current_admin_id()]
                );
            }
            $order = 0;
            foreach ($colTitles as $idx => $title) {
                if (trim($title) === '') continue;
                $color = preg_match('/^#[0-9a-fA-F]{6}$/', $colColors[$idx] ?? '') ? $colColors[$idx] : '#6366f1';
                db_exec('INSERT INTO retro_columns (room_id,title,color,display_order) VALUES (?,?,?,?)', [$roomId,$title,$color,$order++]);
            }
            if ($templateId > 0) {
                $tpl = db_row('SELECT name FROM board_templates WHERE id=?', [$templateId]);
                if ($tpl) db_exec('UPDATE retro_rooms SET template_name=? WHERE id=?', [$tpl['name'],$roomId]);
            }
            $pdo->commit();

            // For poker-type sessions: auto-create a linked pp_session
            if ($sessionType === 'poker' && !$isEdit) {
                try {
                    // Ensure pp_sessions exists
                    $ppExists = db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions'")['n'] ?? 0;
                    if ($ppExists) {
                        $alreadyLinked = db_row('SELECT id FROM pp_sessions WHERE retro_room_id=? LIMIT 1', [$roomId]);
                        if (!$alreadyLinked) {
                            do {
                                $ppCode = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                            } while (db_row('SELECT id FROM pp_sessions WHERE code=?', [$ppCode]));
                            $ppModToken = bin2hex(random_bytes(16));
                            db_exec(
                                "INSERT INTO pp_sessions (company_id, code, retro_room_id, mod_token, sprint, phase)
                                 VALUES (?,?,?,?,?,'waiting')",
                                [$cid, $ppCode, $roomId, $ppModToken, $name]
                            );
                            $_SESSION['pp_mod_' . $ppCode] = $ppModToken;
                        }
                    }
                } catch (Throwable $ppEx) { /* non-fatal */ }
            }

            flash_set('success', $isEdit ? 'Session updated.' : 'Session created!');
            redirect(BASE_URL . '/admin/room-manage.php?id=' . $roomId);
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'DB error: ' . $ex->getMessage();
        }
    }
}

// Default columns based on session type + retro format
$defaultType   = $_POST['session_type'] ?? ($room['session_type'] ?? 'retrospective');
$defaultFormat = $_POST['retro_format'] ?? 'start-stop-continue';
$retroColMap   = [
    'start-stop-continue'         => [['Start','#22c55e'],['Stop','#ef4444'],['Continue','#3b82f6']],
    'mad-sad-glad'                => [['Mad','#ef4444'],['Sad','#f59e0b'],['Glad','#22c55e']],
    'liked-learned-lacked-longed' => [['Liked','#22c55e'],['Learned','#3b82f6'],['Lacked','#ef4444'],['Longed For','#a855f7']],
    'went-well-improve-action'    => [['Went Well','#22c55e'],['Improve','#f59e0b'],['Action Items','#6366f1']],
    'www'                         => [['Wins','#22c55e'],['Wishes','#f59e0b'],['Wonders','#3b82f6']],
    'lean-coffee'                 => [['To Discuss','#6366f1'],['Discussing','#f59e0b'],['Discussed','#22c55e']],
    'daki'                        => [['Drop','#ef4444'],['Add','#22c55e'],['Keep','#3b82f6'],['Improve','#f59e0b']],
    'custom'                      => [],
];
if ($defaultType === 'daily') {
    $defaultCols = [['title'=>'Yesterday','color'=>'#3b82f6'],['title'=>'Today','color'=>'#22c55e'],['title'=>'Blockers','color'=>'#ef4444']];
} else {
    $rawCols     = $retroColMap[$defaultFormat] ?? $retroColMap['start-stop-continue'];
    $defaultCols = array_map(function($c){ return ['title'=>$c[0],'color'=>$c[1]]; }, $rawCols);
}
if ($isEdit && !empty($roomCols)) {
    $defaultCols = array_map(function($c) { return ['title'=>$c['title'],'color'=>$c['color']]; }, $roomCols);
} elseif (!empty($_POST['col_title'])) {
    $defaultCols = [];
    foreach (array_keys($_POST['col_title']) as $i) {
        $defaultCols[] = ['title'=>$_POST['col_title'][$i],'color'=>$_POST['col_color'][$i]??'#5856D6'];
    }
}

$pageTitle  = $isEdit ? 'Edit Session' : 'New Session';
$activePage = 'rooms';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<?php if (!empty($errors)): ?>
<div class="alert alert--error"><?php foreach ($errors as $e_): ?><div>• <?= e($e_) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<form method="POST" action="<?= e(BASE_URL) ?>/admin/room-create.php<?= $isEdit ? '?id='.$roomId : '' ?>">
  <?= csrf_field() ?>

<?php
// Retro format definitions — each has columns, a description, and a best-for note
$retroFormats = [
    'start-stop-continue' => [
        'label' => 'Start · Stop · Continue',
        'desc'  => 'What should we start, stop, and keep doing?',
        'best'  => 'Most popular · great for any team',
        'cols'  => [['Start','#22c55e'],['Stop','#ef4444'],['Continue','#3b82f6']],
    ],
    'mad-sad-glad' => [
        'label' => 'Mad · Sad · Glad',
        'desc'  => 'Surface emotions and morale from the sprint.',
        'best'  => 'Team health · culture check',
        'cols'  => [['Mad','#ef4444'],['Sad','#f59e0b'],['Glad','#22c55e']],
    ],
    'liked-learned-lacked-longed' => [
        'label' => '4Ls: Liked · Learned · Lacked · Longed For',
        'desc'  => 'Four lenses on what worked, what was learned, what was missing, and what you wished for.',
        'best'  => 'Deep reflection · post-project reviews',
        'cols'  => [['Liked','#22c55e'],['Learned','#3b82f6'],['Lacked','#ef4444'],['Longed For','#a855f7']],
    ],
    'went-well-improve-action' => [
        'label' => 'Went Well · Improve · Action Items',
        'desc'  => 'Keep the good, fix the bad, commit to actions.',
        'best'  => 'Action-focused · pragmatic teams',
        'cols'  => [['Went Well','#22c55e'],['Improve','#f59e0b'],['Action Items','#6366f1']],
    ],
    'www' => [
        'label' => 'Wins · Wishes · Wonders',
        'desc'  => 'Celebrate wins, express wishes, and open questions.',
        'best'  => 'Positive framing · remote teams',
        'cols'  => [['Wins','#22c55e'],['Wishes','#f59e0b'],['Wonders','#3b82f6']],
    ],
    'lean-coffee' => [
        'label' => 'Lean Coffee',
        'desc'  => 'Community-driven agenda: propose topics, vote, discuss.',
        'best'  => 'Open agenda · self-organising',
        'cols'  => [['To Discuss','#6366f1'],['Discussing','#f59e0b'],['Discussed','#22c55e']],
    ],
    'daki' => [
        'label' => 'DAKI: Drop · Add · Keep · Improve',
        'desc'  => 'More granular than Start/Stop/Continue — explicit about what to drop entirely.',
        'best'  => 'Process improvement · mature teams',
        'cols'  => [['Drop','#ef4444'],['Add','#22c55e'],['Keep','#3b82f6'],['Improve','#f59e0b']],
    ],
    'custom' => [
        'label' => 'Custom',
        'desc'  => 'Define your own columns.',
        'best'  => 'Any format you need',
        'cols'  => [],
    ],
];
$currentSessionType   = $room['session_type'] ?? 'retrospective';
$currentRetroFormat   = $_POST['retro_format'] ?? 'start-stop-continue';
?>

  <!-- Session type selector -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card__body" style="padding:.9rem 1.25rem;">
      <p style="font-size:.8rem;font-weight:600;color:var(--text-secondary);margin-bottom:.6rem;">Session Type</p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;" id="type-selector">
        <?php foreach ([
            'retrospective' => ['🔄','Retrospective','End-of-sprint review'],
            'daily'         => ['☀️','Daily Standup','Yesterday · Today · Blockers'],
            'poker'         => ['🃏','Planning Poker','Story point estimation'],
        ] as $type=>[$ico,$label,$sub]): ?>
        <label style="cursor:pointer;">
          <input type="radio" name="session_type" value="<?= $type ?>" style="display:none;" class="type-radio"
            <?= $currentSessionType === $type || (empty($room) && $type==='retrospective') ? 'checked' : '' ?>>
          <div class="type-card <?= $currentSessionType===$type ? 'type-card--active' : '' ?>" data-type="<?= $type ?>">
            <div style="font-size:1.5rem;margin-bottom:.3rem;"><?= $ico ?></div>
            <div style="font-weight:700;font-size:.9rem;"><?= $label ?></div>
            <div style="font-size:.75rem;color:var(--text-secondary);margin-top:.15rem;"><?= $sub ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Retro format selector (shown only for retrospective type) -->
  <div class="card" id="retro-format-card" style="margin-bottom:1.25rem;<?= $currentSessionType !== 'retrospective' ? 'display:none;' : '' ?>">
    <div class="card__header"><span class="card__title">Retrospective Format</span></div>
    <div class="card__body" style="padding:.75rem 1rem;">
      <input type="hidden" name="retro_format" id="retro-format-input" value="<?= e($currentRetroFormat) ?>">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.6rem;" id="retro-format-grid">
        <?php foreach ($retroFormats as $fKey => $fmt): ?>
        <?php $isActive = $currentRetroFormat === $fKey; ?>
        <div class="retro-fmt-card <?= $isActive ? 'retro-fmt-card--active' : '' ?>"
             data-format="<?= $fKey ?>"
             data-cols='<?= json_safe(array_map(function($c){ return ['title'=>$c[0],'color'=>$c[1]]; }, $fmt['cols'])) ?>'
             onclick="selectRetroFormat('<?= $fKey ?>')">
          <div style="font-weight:700;font-size:.825rem;margin-bottom:.25rem;line-height:1.3;"><?= e($fmt['label']) ?></div>
          <div style="font-size:.72rem;color:var(--text-secondary);margin-bottom:.4rem;line-height:1.45;"><?= e($fmt['desc']) ?></div>
          <?php if (!empty($fmt['cols'])): ?>
          <div style="display:flex;gap:.3rem;flex-wrap:wrap;">
            <?php foreach ($fmt['cols'] as [$colName, $colColor]): ?>
            <span style="font-size:.65rem;font-weight:600;padding:.1rem .45rem;border-radius:999px;background:<?= $colColor ?>22;color:<?= $colColor ?>;border:1px solid <?= $colColor ?>55;"><?= e($colName) ?></span>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div style="font-size:.7rem;color:var(--text-secondary);font-style:italic;">Define your own columns below</div>
          <?php endif; ?>
          <div style="font-size:.65rem;color:var(--accent);margin-top:.4rem;font-weight:600;">✦ <?= e($fmt['best']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Details -->
  <div class="card" style="margin-bottom:1.25rem;">
    <div class="card__header"><span class="card__title">Session Details</span></div>
    <div class="card__body">
      <div class="form-group">
        <label class="form-label">Session Name <span class="required">*</span></label>
        <input type="text" name="name" class="form-input"
          value="<?= e($room['name'] ?? $_POST['name'] ?? '') ?>"
          placeholder="e.g. Sprint 42 Retrospective" maxlength="200" required autofocus>
      </div>
      <div class="form-group">
        <label class="form-label">Description <span style="font-weight:400;color:var(--text-secondary)">(optional)</span></label>
        <textarea name="description" class="form-input" rows="2" maxlength="1000"
          placeholder="Context or agenda…"><?= e($room['description'] ?? $_POST['description'] ?? '') ?></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Session Date</label>
          <input type="date" name="session_date" class="form-input"
            value="<?= e($room['session_date'] ?? $_POST['session_date'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Max Votes per Person</label>
          <input type="number" name="max_votes" class="form-input"
            value="<?= (int)($room['max_votes'] ?? MAX_VOTES_DEFAULT) ?>" min="1" max="20">
        </div>
        <?php if ($isEdit): ?>
        <div class="form-group">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <?php foreach (['draft','active','revealed','closed','archived'] as $s): ?>
            <option value="<?= e($s) ?>" <?= ($room['status']??'draft')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php else: ?>
        <input type="hidden" name="status" value="draft">
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label class="checkbox-label">
          <input type="checkbox" name="allow_edit_notes" value="1"
            <?= ($room['allow_edit_notes'] ?? 1) ? 'checked' : '' ?>>
          Allow participants to edit notes before reveal
        </label>
      </div>
      <div class="form-group" style="margin-bottom:0;">
        <label class="form-label">Session Password <span style="font-weight:400;color:var(--text-secondary)">(optional)</span></label>
        <input type="text" name="join_password" class="form-input"
          value="<?= e($room['join_password'] ?? '') ?>"
          placeholder="Leave blank for open access"
          maxlength="100" autocomplete="off">
        <p class="form-hint">Participants must enter this password to join the session.</p>
      </div>
    </div>
  </div>

  <!-- Board columns -->
  <div class="card" id="col-section" style="margin-bottom:1.25rem;<?= $currentSessionType==='poker' ? 'display:none;' : '' ?>">
    <div class="card__header"><span class="card__title">Board Columns</span></div>
    <div class="card__body">
      <?php if (!empty($templates)): ?>
      <p style="font-size:.8rem;font-weight:600;color:var(--text-secondary);margin-bottom:.5rem;">Quick start:</p>
      <div class="template-pills" style="margin-bottom:.75rem;">
        <?php foreach ($templates as $tpl): ?>
        <button type="button" class="template-pill"
          data-id="<?= (int)$tpl['id'] ?>"
          data-cols="<?= e($tpl['columns_json']) ?>"
          data-guidance="<?= e($tpl['guidance'] ?? '') ?>"><?= e($tpl['name']) ?></button>
        <?php endforeach; ?>
      </div>
      <input type="hidden" name="template_id" id="template_id" value="">
      <!-- Guidance preview shown when a template is selected -->
      <div id="tpl-guidance-preview" style="display:none;margin-top:.5rem;padding:.65rem .85rem;
        background:rgba(88,86,214,.06);border:1px solid rgba(88,86,214,.15);
        border-left:3px solid var(--accent);border-radius:0 var(--r-sm) var(--r-sm) 0;
        font-size:.825rem;color:var(--text-secondary);line-height:1.55;">
        <span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--accent);display:block;margin-bottom:.3rem;">📋 Session Guidance</span>
        <span id="tpl-guidance-text"></span>
      </div>
      <hr style="margin:.6rem 0;border:none;border-top:1px solid var(--divider);">
      <?php endif; ?>
      <div class="col-builder" id="col-builder">
        <?php foreach ($defaultCols as $col): ?>
        <div class="col-builder-row">
          <input type="text" name="col_title[]" class="form-input"
            value="<?= e($col['title']) ?>" placeholder="Column title" maxlength="100" required>
          <input type="color" name="col_color[]" class="col-color-picker" value="<?= e($col['color']) ?>">
          <button type="button" class="btn btn--ghost btn--sm col-remove-btn">✕</button>
        </div>
        <?php endforeach; ?>
      </div>
      <button type="button" id="add-col-btn" class="btn btn--outline btn--sm" style="margin-top:.65rem;width:100%;">+ Add Column</button>
      <p class="form-hint">Maximum <?= MAX_COLUMNS ?> columns.</p>
    </div>
  </div>

  <div style="display:flex;gap:.6rem;justify-content:flex-end;">
    <a href="<?= e(BASE_URL) ?>/admin/rooms.php" class="btn btn--outline">Cancel</a>
    <button type="submit" class="btn btn--primary"><?= $isEdit ? '💾 Save Changes' : '🚀 Create Session' ?></button>
  </div>
</form>

<style>
.type-card { border:2px solid rgba(255,255,255,.3); border-radius:var(--r-md); padding:.85rem 1rem; text-align:center; transition:all .15s; background:rgba(255,255,255,.8); }
.type-card:hover { border-color:var(--accent); }
.type-card--active { border-color:var(--accent); background:rgba(88,86,214,.08); }
</style>
<script>
const MAX_COLS = <?= MAX_COLUMNS ?>;
const builder  = document.getElementById('col-builder');
const DAILY_COLS = <?= json_safe([
    ['title'=>'Yesterday','color'=>'#3b82f6'],
    ['title'=>'Today','color'=>'#22c55e'],
    ['title'=>'Blockers','color'=>'#ef4444']
]) ?>;
const RETRO_FORMAT_COLS = <?= json_safe(array_map(
    function($fmt) {
        return array_map(function($c){ return ['title'=>$c[0],'color'=>$c[1]]; }, $fmt['cols']);
    },
    $retroFormats
)) ?>;
const RETRO_COLS = RETRO_FORMAT_COLS['start-stop-continue'];

function selectRetroFormat(fKey) {
    document.getElementById('retro-format-input').value = fKey;
    document.querySelectorAll('.retro-fmt-card').forEach(function(el) {
        el.classList.toggle('retro-fmt-card--active', el.dataset.format === fKey);
    });
    <?php if (!$isEdit): ?>
    var cols = RETRO_FORMAT_COLS[fKey];
    if (cols && cols.length > 0) {
        builder.innerHTML = '';
        cols.forEach(function(col) { addColRow(col.title, col.color); });
    }
    <?php endif; ?>
}

function colCount() { return builder.querySelectorAll('.col-builder-row').length; }

function addColRow(title, color) {
  if (colCount() >= MAX_COLS) return;
  const row = document.createElement('div');
  row.className = 'col-builder-row';
  row.innerHTML = `<input type="text" name="col_title[]" class="form-input" value="${(title||'').replace(/"/g,'&quot;')}" placeholder="Column title" maxlength="100" required>`
    + `<input type="color" name="col_color[]" class="col-color-picker" value="${color||'#6366f1'}">`
    + `<button type="button" class="btn btn--ghost btn--sm col-remove-btn">✕</button>`;
  builder.appendChild(row);
}

builder.addEventListener('click', function(e) {
  if (e.target.classList.contains('col-remove-btn')) {
    if (colCount() <= 1) { showToast('At least one column is required.', 'warning'); return; }
    e.target.closest('.col-builder-row').remove();
  }
});
document.getElementById('add-col-btn').addEventListener('click', () => addColRow('','#6366f1'));

// Template pills
document.querySelectorAll('.template-pill').forEach(p => {
  p.addEventListener('click', function() {
    var cols = JSON.parse(p.dataset.cols);
    builder.innerHTML = '';
    cols.forEach(c => addColRow(c.title, c.color));
    document.getElementById('template_id').value = p.dataset.id;
    document.querySelectorAll('.template-pill').forEach(x => x.classList.remove('active'));
    p.classList.add('active');
    // Show guidance if available
    var guidance  = p.dataset.guidance || '';
    var preview   = document.getElementById('tpl-guidance-preview');
    var textEl    = document.getElementById('tpl-guidance-text');
    if (preview && textEl) {
      if (guidance.trim()) {
        textEl.textContent  = guidance;
        preview.style.display = 'block';
      } else {
        preview.style.display = 'none';
      }
    }
  });
});

// Session type switcher — show/hide retro format panel, column builder, auto-load columns
<?php if (!$isEdit): ?>
document.querySelectorAll('.type-radio').forEach(function(radio) {
  radio.addEventListener('change', function() {
    document.querySelectorAll('.type-card').forEach(function(c) { c.classList.remove('type-card--active'); });
    radio.closest('label').querySelector('.type-card').classList.add('type-card--active');
    var formatCard  = document.getElementById('retro-format-card');
    var colSection  = document.getElementById('col-section');  // column builder card
    var tplSection  = document.getElementById('tpl-section');  // template selector card
    var isPoker     = radio.value === 'poker';
    var isRetro     = radio.value === 'retrospective';

    if (formatCard) formatCard.style.display = isRetro ? '' : 'none';
    if (colSection) colSection.style.display = isPoker ? 'none' : '';
    if (tplSection) tplSection.style.display = isPoker ? 'none' : '';

    if (!isPoker) {
      if (radio.value === 'daily') {
          builder.innerHTML = '';
          DAILY_COLS.forEach(function(c) { addColRow(c.title, c.color); });
      } else {
          var fmt = document.getElementById('retro-format-input').value || 'start-stop-continue';
          selectRetroFormat(fmt);
      }
      document.getElementById('template_id').value = '';
      document.querySelectorAll('.template-pill').forEach(function(x) { x.classList.remove('active'); });
    }
  });
});
document.querySelectorAll('.type-card').forEach(function(card) {
  card.addEventListener('click', function() {
    var radio = card.closest('label').querySelector('.type-radio');
    radio.checked = true;
    radio.dispatchEvent(new Event('change'));
  });
});
<?php endif; ?>
</script>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
