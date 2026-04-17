<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();

$cid = current_company_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $actionId  = input_int('action_id');
    $newStatus = input_str('status', 'POST', 20);
    $do        = input_str('do');
    $validStatuses = ['open','in_progress','done','cancelled'];

    if ($do === 'delete' && $actionId) {
        db_exec('DELETE FROM action_items WHERE id=? AND company_id=?', [$actionId, $cid]);
        flash_set('success', 'Action item deleted.');
    } elseif ($actionId && in_array($newStatus, $validStatuses, true)) {
        db_exec('UPDATE action_items SET status=? WHERE id=? AND company_id=?', [$newStatus, $actionId, $cid]);
        flash_set('success', 'Status updated.');
    }
    redirect(BASE_URL . '/admin/actions.php?' . http_build_query(array_filter(['status'=>input_str('filter_status','POST',20),'owner'=>input_str('filter_owner','POST',100)])));
}

$filterStatus = input_str('status', 'GET', 20);
$filterOwner  = input_str('owner',  'GET', 100);
$filterSplit  = input_str('split',  'GET',  5);
$validStatuses = ['open','in_progress','done','cancelled'];
if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';
if (!in_array($filterSplit, ['must','con'], true)) $filterSplit = '';

$where = ['a.company_id = ?']; $params = [$cid];
if ($filterStatus) { $where[] = 'a.status=?';         $params[] = $filterStatus; }
if ($filterOwner)  { $where[] = 'a.owner_name LIKE ?'; $params[] = '%'.$filterOwner.'%'; }
if ($filterSplit === 'must') { $where[] = "a.title LIKE '[MUST SPLIT]%'"; }
if ($filterSplit === 'con')  { $where[] = "a.title LIKE '[Consider splitting]%'"; }

$items = db_query("
    SELECT a.*, r.name AS room_name, r.id AS room_id
    FROM action_items a
    JOIN retro_rooms r ON r.id=a.room_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY FIELD(a.status,'open','in_progress','done','cancelled'),
             COALESCE(a.due_date,'9999-12-31'), a.created_at DESC
", $params);

$counts = db_query("SELECT status, COUNT(*) AS n FROM action_items WHERE company_id=? GROUP BY status", [$cid]);
$cMap   = array_column($counts, 'n', 'status');

$topbarActions = '<a href="'.e(BASE_URL).'/admin/action-report.php" class="btn btn--outline btn--sm">📊 Summary Report</a>';
$pageTitle = 'Action Items'; $activePage = 'actions';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<!-- Status filters -->
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem;align-items:center;">
  <?php $pills = [''=> 'All ('.array_sum($cMap).')','open'=>'Open ('.($cMap['open']??0).')','in_progress'=>'In Progress ('.($cMap['in_progress']??0).')','done'=>'Done ('.($cMap['done']??0).')','cancelled'=>'Cancelled ('.($cMap['cancelled']??0).')']; ?>
  <?php foreach ($pills as $val => $lbl): ?>
  <a href="<?= e(BASE_URL.'/admin/actions.php?'.http_build_query(array_filter(['status'=>$val,'owner'=>$filterOwner]))) ?>"
    style="padding:.28rem .75rem;border-radius:20px;font-size:.78rem;font-weight:600;text-decoration:none;border:1.5px solid;
      <?= $filterStatus===$val ? 'background:var(--accent);color:#fff;border-color:var(--accent);' : 'background:rgba(255,255,255,.8);color:var(--text-secondary);border-color:rgba(255,255,255,.3);' ?>">
    <?= e($lbl) ?>
  </a>
  <?php endforeach; ?>
  <?php if ($filterSplit): ?><input type="hidden" name="split" value="<?= e($filterSplit) ?>"><?php endif; ?>
  <a href="<?= e(BASE_URL.'/admin/actions.php?'.http_build_query(array_filter(['status'=>$filterStatus,'owner'=>$filterOwner,'split'=>'must']))) ?>"
     style="padding:.28rem .75rem;border-radius:20px;font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid;
       <?= $filterSplit==='must' ? 'background:var(--color-danger);color:#fff;border-color:var(--color-danger);' : 'background:var(--color-danger-bg);color:var(--color-danger);border-color:var(--color-danger-border);' ?>">
    🔴 Must Split
  </a>
  <a href="<?= e(BASE_URL.'/admin/actions.php?'.http_build_query(array_filter(['status'=>$filterStatus,'owner'=>$filterOwner,'split'=>'con']))) ?>"
     style="padding:.28rem .75rem;border-radius:20px;font-size:.78rem;font-weight:700;text-decoration:none;border:1.5px solid;
       <?= $filterSplit==='con' ? 'background:var(--color-warning);color:#fff;border-color:var(--color-warning);' : 'background:var(--color-warning-bg);color:var(--color-warning);border-color:var(--color-warning-border);' ?>">
    🟡 Consider Split
  </a>
  <form method="GET" style="margin-left:auto;display:flex;gap:.4rem;align-items:center;">
    <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
    <input type="text" name="owner" class="form-input form-input--sm" placeholder="Filter by owner…" value="<?= e($filterOwner) ?>" style="min-width:160px;">
    <button type="submit" class="btn btn--outline btn--sm">Go</button>
    <?php if ($filterOwner || $filterSplit): ?><a href="<?= e(BASE_URL.'/admin/actions.php?'.($filterStatus?'status='.e($filterStatus):'')) ?>" class="btn-link text-sm">Clear</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <?php if (empty($items)): ?>
  <div class="empty-state"><p>No action items found.</p></div>
  <?php else: ?>
  <table class="data-table">
    <thead><tr><th scope="col">Title</th><th scope="col">Session</th><th scope="col">Owner</th><th scope="col">Due</th><th scope="col">Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item):
      $overdue = $item['status']==='open' && $item['due_date'] && strtotime($item['due_date']) < strtotime('today');
    ?>
    <?php
      $isMustSplit  = strpos($item['title'],'[MUST SPLIT]') === 0;
      $isSplitFlag  = strpos($item['title'],'[Consider splitting]') === 0;
      $rowStyle     = $overdue ? 'background:var(--color-danger-bg);' : ($isMustSplit ? 'background:var(--color-danger-bg);' : ($isSplitFlag ? 'background:var(--color-warning-bg);' : ''));
    ?>
    <tr <?= $rowStyle ? 'style="'.$rowStyle.'"' : '' ?>>
      <td>
        <?php if ($isMustSplit): ?>
        <span class="badge" style="background:var(--color-danger-bg);color:var(--color-danger);border:1px solid var(--color-danger-border);margin-bottom:.25rem;display:inline-block;">🔴 Must Split</span><br>
        <strong><?= e(substr($item['title'], strlen('[MUST SPLIT] '))) ?></strong>
        <?php elseif ($isSplitFlag): ?>
        <span class="badge" style="background:var(--color-warning-bg);color:var(--color-warning);border:1px solid var(--color-warning-border);margin-bottom:.25rem;display:inline-block;">🟡 Consider Splitting</span><br>
        <strong><?= e(substr($item['title'], strlen('[Consider splitting] '))) ?></strong>
        <?php else: ?>
        <strong><?= e($item['title']) ?></strong>
        <?php endif; ?>
        <?php if ($item['description']): ?><br><span class="text-xs" style="color:var(--muted);"><?= e(mb_substr($item['description'],0,70)) ?></span><?php endif; ?>
        <?php if ($overdue): ?><br><span style="font-size:.7rem;color:var(--color-danger);font-weight:700;">⚠ Overdue</span><?php endif; ?>
      </td>
      <td class="text-sm"><a href="<?= e(BASE_URL.'/admin/room-manage.php?id='.(int)$item['room_id']) ?>"><?= e($item['room_name']) ?></a></td>
      <td class="text-sm"><?= $item['owner_name'] ? e($item['owner_name']) : '<span style="color:var(--text-secondary);">—</span>' ?></td>
      <td class="text-sm"><?= $item['due_date'] ? e(date('M j, Y',strtotime($item['due_date']))) : '<span style="color:var(--text-secondary);">—</span>' ?></td>
      <td>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action_id" value="<?= (int)$item['id'] ?>">
          <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>">
          <input type="hidden" name="filter_owner" value="<?= e($filterOwner) ?>">
          <select name="status" class="form-select form-select--xs" onchange="this.form.submit()">
            <?php foreach ($validStatuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $item['status']===$s?'selected':'' ?>><?= e(str_replace('_',' ',ucfirst($s))) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      </td>
      <td>
        <form method="POST" id="del-action-<?= (int)$item['id'] ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action_id" value="<?= (int)$item['id'] ?>">
          <input type="hidden" name="do" value="delete">
          <input type="hidden" name="filter_status" value="<?= e($filterStatus) ?>">
          <button type="button" class="btn btn--ghost btn--sm" style="color:var(--accent-red);" title="Delete"
            data-confirm="Delete this action item? This cannot be undone."
            data-confirm-title="Delete Action Item"
            data-confirm-btn="Delete"
            data-confirm-form="del-action-<?= (int)$item['id'] ?>">🗑</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="table-footer">Showing <?= count($items) ?> item<?= count($items)!==1?'s':'' ?></div>
  <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
