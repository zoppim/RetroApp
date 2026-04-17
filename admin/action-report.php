<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();

$cid     = current_company_id();
$company = db_row('SELECT name FROM companies WHERE id=?', [$cid]);

$items = db_query("
    SELECT a.*, r.name AS room_name, r.session_type, r.session_date
    FROM action_items a
    JOIN retro_rooms r ON r.id=a.room_id
    WHERE a.company_id=?
    ORDER BY FIELD(a.status,'open','in_progress','done','cancelled'), a.due_date, a.room_id
", [$cid]);

// Group by status
$byStatus = ['open'=>[],'in_progress'=>[],'done'=>[],'cancelled'=>[]];
foreach ($items as $i) $byStatus[$i['status']][] = $i;

// Group by owner
$byOwner = [];
foreach ($items as $i) {
    $key = $i['owner_name'] ?: '(Unassigned)';
    $byOwner[$key][] = $i;
}
ksort($byOwner);

// Group by session
$bySession = [];
foreach ($items as $i) {
    $key = $i['room_name'];
    $bySession[$key][] = $i;
}

$generatedAt = date('F j, Y, g:i a');
$statusColors = ['open'=>'#2563eb','in_progress'=>'#d97706','done'=>'#16a34a','cancelled'=>'#94a3b8'];
$statusLabels = ['open'=>'Open','in_progress'=>'In Progress','done'=>'Done','cancelled'=>'Cancelled'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Action Items Report — <?= e($company['name'] ?? APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
<style>
.print-bar { position:fixed;top:0;left:0;right:0;background:#1e1b4b;color:#fff;display:flex;align-items:center;justify-content:space-between;padding:.6rem 1.5rem;z-index:200; }
.print-bar a,.print-bar button { color:#a5b4fc;text-decoration:none;background:none;border:1.5px solid rgba(255,255,255,.2);border-radius:6px;padding:.3rem .75rem;font-size:.825rem;cursor:pointer; }
.print-bar button { background:#6366f1;color:#fff;border-color:#6366f1; }
.report { max-width:900px;margin:80px auto 3rem;padding:0 1.5rem; }
.rep-header { border-bottom:3px solid var(--accent);padding-bottom:1.25rem;margin-bottom:2rem; }
.rep-logo { font-size:1.2rem;font-weight:800;color:var(--brand-mid);margin-bottom:.5rem; }
.rep-title { font-size:1.6rem;font-weight:800; }
.rep-meta  { font-size:.8rem;color:var(--muted);margin-top:.5rem;display:flex;gap:1.5rem;flex-wrap:wrap; }
.rep-section { margin-bottom:2rem; }
.rep-section-title { font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--brand-mid);border-bottom:2px solid var(--border);padding-bottom:.35rem;margin-bottom:.9rem; }
.rep-summary { display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.5rem; }
.rep-stat { background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:.75rem;text-align:center; }
.rep-stat__n { font-size:1.6rem;font-weight:800; }
.rep-stat__l { font-size:.72rem;color:var(--muted);text-transform:uppercase; }
.rep-table { width:100%;border-collapse:collapse;font-size:.85rem; }
.rep-table th { text-align:left;padding:.5rem .75rem;background:var(--surface);border-bottom:2px solid var(--border);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--muted); }
.rep-table td { padding:.5rem .75rem;border-bottom:1px solid var(--border);vertical-align:top; }
.rep-table tr:last-child td { border-bottom:none; }
.status-dot { display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:.35rem; }
.group-heading { font-weight:700;font-size:.85rem;color:var(--muted);margin:1rem 0 .5rem;padding-bottom:.3rem;border-bottom:1px solid var(--border); }
@media print {
  .print-bar { display:none; }
  .report { margin-top:0; }
  body { background:#fff; }
}
</style>
</head>
<body>

<div class="print-bar">
  <a href="<?= e(BASE_URL) ?>/admin/actions.php">← Back</a>
  <span style="font-size:.875rem;color:#a5b4fc;">Action Items Report — <?= e($company['name'] ?? APP_NAME) ?></span>
  <button onclick="window.print()">🖨 Print / Save PDF</button>
</div>

<div class="report">
  <div class="rep-header">
    <div class="rep-logo">🔄 <?= e(APP_NAME) ?></div>
    <h1 class="rep-title">Action Items Summary Report</h1>
    <div class="rep-meta">
      <span>🏢 <?= e($company['name'] ?? '') ?></span>
      <span>🕐 Generated: <?= e($generatedAt) ?></span>
      <span>👤 By: <?= e($_SESSION['admin_username'] ?? '') ?></span>
    </div>
  </div>

  <!-- Summary -->
  <div class="rep-section">
    <div class="rep-section-title">Summary</div>
    <div class="rep-summary">
      <div class="rep-stat"><div class="rep-stat__n"><?= count($items) ?></div><div class="rep-stat__l">Total Items</div></div>
      <?php
      $mustSplit = array_filter($items, function($i){ return strpos($i['title'],'[MUST SPLIT]') === 0; });
      $consplit  = array_filter($items, function($i){ return strpos($i['title'],'[Consider splitting]') === 0; });
      if (count($mustSplit) || count($consplit)):
      ?>
      <div class="rep-stat" style="background:var(--color-danger-bg);border-color:var(--color-danger-border);">
        <div class="rep-stat__n" style="color:var(--color-danger);"><?= count($mustSplit) ?></div>
        <div class="rep-stat__l">Must Split</div>
      </div>
      <div class="rep-stat" style="background:var(--color-warning-bg);border-color:var(--color-warning-border);">
        <div class="rep-stat__n" style="color:var(--color-warning);"><?= count($consplit) ?></div>
        <div class="rep-stat__l">Consider Split</div>
      </div>
      <?php endif; ?>
      <?php foreach (['open','in_progress','done'] as $s): ?>
      <div class="rep-stat"><div class="rep-stat__n" style="color:<?= $statusColors[$s] ?>"><?= count($byStatus[$s]) ?></div><div class="rep-stat__l"><?= $statusLabels[$s] ?></div></div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- By Status -->
  <div class="rep-section">
    <div class="rep-section-title">Grouped by Status</div>
    <?php foreach ($byStatus as $status => $sItems): ?>
    <?php if (empty($sItems)) continue; ?>
    <div class="group-heading">
      <span class="status-dot" style="background:<?= $statusColors[$status] ?>"></span>
      <?= $statusLabels[$status] ?> (<?= count($sItems) ?>)
    </div>
    <table class="rep-table" style="margin-bottom:1rem;">
      <thead><tr><th>Title</th><th>Owner</th><th>Due Date</th><th>Session</th></tr></thead>
      <tbody>
        <?php foreach ($sItems as $i):
          $isMust = strpos($i['title'], '[MUST SPLIT]') === 0;
          $isCon  = strpos($i['title'], '[Consider splitting]') === 0;
        ?>
        <tr <?= $isMust ? 'style="background:var(--color-danger-bg);"' : ($isCon ? 'style="background:var(--color-warning-bg);"' : '') ?>>
          <td>
            <?php if ($isMust): ?>
              <span style="font-size:.68rem;font-weight:700;color:var(--color-danger);border:1px solid var(--color-danger-border);border-radius:999px;padding:.1rem .45rem;margin-right:.3rem;">🔴 Must Split</span>
              <strong><?= e(substr($i['title'], strlen('[MUST SPLIT] '))) ?></strong>
            <?php elseif ($isCon): ?>
              <span style="font-size:.68rem;font-weight:700;color:var(--color-warning);border:1px solid var(--color-warning-border);border-radius:999px;padding:.1rem .45rem;margin-right:.3rem;">🟡 Consider Split</span>
              <strong><?= e(substr($i['title'], strlen('[Consider splitting] '))) ?></strong>
            <?php else: ?>
              <strong><?= e($i['title']) ?></strong>
            <?php endif; ?>
            <?php if ($i['description']): ?><br><span style="color:var(--muted);font-size:.78rem;"><?= e($i['description']) ?></span><?php endif; ?>
          </td>
          <td><?= $i['owner_name'] ? e($i['owner_name']) : '<span style="color:var(--muted)">—</span>' ?></td>
          <td><?= $i['due_date'] ? e(date('M j, Y',strtotime($i['due_date']))) : '—' ?></td>
          <td>
            <?= e($i['room_name']) ?>
            <?php if (!empty($i['session_type']) && $i['session_type'] === 'poker'): ?>
            <span style="font-size:.65rem;font-weight:700;background:var(--color-violet-bg);color:var(--color-violet);border-radius:999px;padding:.1rem .4rem;margin-left:.3rem;">🃏</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
  </div>

  <!-- By Owner -->
  <div class="rep-section">
    <div class="rep-section-title">Grouped by Owner</div>
    <?php foreach ($byOwner as $owner => $oItems): ?>
    <div class="group-heading">👤 <?= e($owner) ?> (<?= count($oItems) ?>)</div>
    <table class="rep-table" style="margin-bottom:1rem;">
      <thead><tr><th>Title</th><th>Status</th><th>Due Date</th><th>Session</th></tr></thead>
      <tbody>
        <?php foreach ($oItems as $i): ?>
        <tr>
          <td><?= e($i['title']) ?></td>
          <td><span class="status-dot" style="background:<?= $statusColors[$i['status']] ?>"></span><?= $statusLabels[$i['status']] ?></td>
          <td><?= $i['due_date'] ? e(date('M j, Y',strtotime($i['due_date']))) : '—' ?></td>
          <td>
            <?= e($i['room_name']) ?>
            <?php if (!empty($i['session_type']) && $i['session_type'] === 'poker'): ?>
            <span style="font-size:.65rem;font-weight:700;background:var(--color-violet-bg);color:var(--color-violet);border-radius:999px;padding:.1rem .4rem;margin-left:.3rem;">🃏</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
  </div>

  <!-- By Session -->
  <div class="rep-section">
    <div class="rep-section-title">Grouped by Session</div>
    <?php foreach ($bySession as $sessionName => $sItems): ?>
    <div class="group-heading">📋 <?= e($sessionName) ?> (<?= count($sItems) ?>)</div>
    <table class="rep-table" style="margin-bottom:1rem;">
      <thead><tr><th>Title</th><th>Owner</th><th>Status</th><th>Due Date</th></tr></thead>
      <tbody>
        <?php foreach ($sItems as $i): ?>
        <tr>
          <td><?= e($i['title']) ?></td>
          <td><?= $i['owner_name'] ? e($i['owner_name']) : '—' ?></td>
          <td><span class="status-dot" style="background:<?= $statusColors[$i['status']] ?>"></span><?= $statusLabels[$i['status']] ?></td>
          <td><?= $i['due_date'] ? e(date('M j, Y',strtotime($i['due_date']))) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endforeach; ?>
  </div>

  <div style="font-size:.75rem;color:var(--muted);border-top:1px solid rgba(255,255,255,.3);padding-top:.75rem;display:flex;justify-content:space-between;">
    <span><?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> — <?= e($generatedAt) ?></span>
    <span>Confidential · Internal use only</span>
  </div>
</div>
</body>
</html>
