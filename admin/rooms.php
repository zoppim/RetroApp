<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();
$cid = current_company_id();

// ── Handle delete ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (input_str('action') === 'delete') {
        $delId = input_int('room_id');
        $check = db_row('SELECT id FROM retro_rooms WHERE id=? AND company_id=?', [$delId, $cid]);
        if ($check) {
            db_exec('DELETE FROM retro_rooms WHERE id=? AND company_id=?', [$delId, $cid]);
            flash_set('success', 'Session deleted.');
        }
    }
    redirect(BASE_URL . '/admin/rooms.php');
}

$pageTitle  = 'Sessions';
$activePage = 'rooms';

$search     = input_str('q', 'GET', 100);
$status     = input_str('status', 'GET', 20);
$typeFilter = input_str('type', 'GET', 20);
$validStatuses = ['draft','active','revealed','closed','archived'];
$validTypes    = ['retrospective','daily','poker'];
if (!in_array($status, $validStatuses, true)) $status = '';
if (!in_array($typeFilter, $validTypes, true)) $typeFilter = '';

$where = ['r.company_id=?']; $params = [$cid];
if ($search)     { $where[] = 'r.name LIKE ?';       $params[] = '%'.$search.'%'; }
if ($status)     { $where[] = 'r.status = ?';        $params[] = $status; }
if ($typeFilter) { $where[] = 'r.session_type = ?';  $params[] = $typeFilter; }

$rooms = db_query("
    SELECT r.id, r.room_uuid, r.name, r.status, r.session_type, r.session_date, r.template_name, r.created_at,
           COUNT(DISTINCT n.id) AS notes,
           COUNT(DISTINCT v.id) AS votes,
           COUNT(DISTINCT a.id) AS actions
    FROM retro_rooms r
    LEFT JOIN retro_notes  n ON n.room_id = r.id
    LEFT JOIN note_votes   v ON v.room_id = r.id
    LEFT JOIN action_items a ON a.room_id = r.id
    WHERE " . implode(' AND ', $where) . "
    GROUP BY r.id
    ORDER BY r.created_at DESC
", $params);

$topbarActions = '<a href="'.e(BASE_URL).'/admin/room-create.php" class="btn btn--primary btn--sm">+ New Session</a>';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<div class="filter-bar">
  <form method="GET" class="filter-form">
    <input type="text" name="q" class="form-input form-input--sm"
      placeholder="Search sessions…" value="<?= e($search) ?>" style="min-width:200px;">
    <select name="type" class="form-select form-select--sm" style="min-width:120px;">
      <option value="">All types</option>
      <option value="retrospective" <?= $typeFilter==='retrospective' ? 'selected' : '' ?>>🔄 Retro</option>
      <option value="daily"         <?= $typeFilter==='daily'         ? 'selected' : '' ?>>☀ Daily</option>
      <option value="poker"         <?= $typeFilter==='poker'         ? 'selected' : '' ?>>🃏 Poker</option>
    </select>
    <select name="status" class="form-select form-select--sm" style="min-width:130px;">
      <option value="">All statuses</option>
      <?php foreach ($validStatuses as $s): ?>
      <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn--outline btn--sm">Filter</button>
    <?php if ($search || $status || $typeFilter): ?>
      <a href="<?= e(BASE_URL) ?>/admin/rooms.php" class="btn-link text-sm">Clear filters</a>
    <?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (empty($rooms)): ?>
  <div class="empty-state">
    <p>No sessions found<?= ($search||$status) ? ' for these filters' : '' ?>.</p>
    <?php if (!$search && !$status): ?>
    <a href="<?= e(BASE_URL) ?>/admin/room-create.php" class="btn btn--primary">Create your first session →</a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="table-wrap"><table class="data-table">
    <th scope="col"ead><tr>
      <th scope="col">Session Name</th><th scope="col">Type</th><th scope="col">Template</th><th scope="col">Status</th><th scope="col">Date</th><th scope="col">Notes</th><th scope="col">Votes</th><th scope="col">Actions</th><th scope="col"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rooms as $r): ?>
    <tr>
      <td>
        <strong><?= e($r['name']) ?></strong>
        <br><span class="text-xs" style="color:var(--text-tertiary);"><?= e(date('M j, Y', strtotime($r['created_at']))) ?></span>
      </td>
      <td>
        <?php if ($r['session_type']==='poker'):  ?>
        <span class="badge" style="background:var(--color-violet-bg);color:var(--color-violet);border:1px solid #C4B5FD;">🃏 Poker</span>
        <?php elseif ($r['session_type']==='daily'): ?>
        <span class="badge badge--active">☀ Daily</span>
        <?php else: ?>
        <span class="badge" style="background:var(--brand-lt);color:var(--brand-mid);border:1px solid var(--color-info-border);">🔄 Retro</span>
        <?php endif; ?>
      </td>
      <td class="text-sm" style="color:var(--text-secondary);"><?= e($r['template_name']) ?></td>
      <td><span class="badge badge--<?= e($r['status']) ?>"><?= e(ucfirst($r['status'])) ?></span></td>
      <td class="text-sm"><?= $r['session_date'] ? e(date('M j, Y', strtotime($r['session_date']))) : '<span style="color:var(--text-secondary);">—</span>' ?></td>
      <td data-label="Notes"><?= (int)$r['notes'] ?></td>
      <td data-label="Votes"><?= (int)$r['votes'] ?></td>
      <td data-label="Actions"><?= (int)$r['actions'] ?></td>
      <td>
        <div class="btn-group">
          <a href="<?= e(BASE_URL) ?>/admin/room-manage.php?id=<?= (int)$r['id'] ?>" class="btn btn--outline btn--sm">Manage</a>
          <a href="<?= e(BASE_URL) ?>/room.php?id=<?= e($r['room_uuid']) ?>" target="_blank" class="btn btn--ghost btn--sm" title="Open board">↗</a>
          <form method="POST" style="display:inline" id="del-room-<?= (int)$r['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
            <button type="button" class="btn btn--ghost btn--sm" style="color:var(--accent-red);" title="Delete"
              data-confirm="Delete &quot;<?= e($r['name']) ?>&quot;? This cannot be undone."
              data-confirm-title="Delete Session"
              data-confirm-btn="Delete"
              data-confirm-form="del-room-<?= (int)$r['id'] ?>">🗑</button>
          </form>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="table-footer">Showing <?= count($rooms) ?> session<?= count($rooms) !== 1 ? 's' : '' ?></div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
