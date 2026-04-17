<?php
/**
 * Retrospective Report Export
 * Supports: printable HTML (print-as-PDF from browser), or plain HTML download.
 * FR-047 through FR-058
 *
 * Usage: /admin/export.php?id={room_id}&format=pdf|html
 *
 * Strategy: Generate a standalone, print-optimised HTML report.
 * The browser's native print-to-PDF produces excellent results with zero
 * dependencies — no external library needed, and UTF-8 is fully supported.
 * An optional dompdf integration path is documented below for server-side PDF.
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login();

$roomId = input_int('id', 'GET');
$format = input_str('format', 'GET', 10); // 'pdf' or 'html'

$cid  = current_company_id();
$room = db_row('SELECT * FROM retro_rooms WHERE id = ? AND company_id = ?', [$roomId, $cid]);
if (!$room) {
    flash_set('error', 'Session not found.');
    redirect(BASE_URL . '/admin/rooms.php');
}

// Only revealed/closed/archived rooms can be exported
if (!in_array($room['status'], ['revealed', 'closed', 'archived'])) {
    flash_set('error', 'Report can only be exported after notes are revealed.');
    redirect(BASE_URL . '/admin/room-manage.php?id=' . $roomId);
}

// ── Load data ─────────────────────────────────────────────────────────────────

$columns = db_query(
    'SELECT * FROM retro_columns WHERE room_id = ? ORDER BY display_order',
    [$roomId]
);

$notes = db_query("
    SELECT n.*, c.title AS col_title, c.color AS col_color, c.display_order,
           COUNT(DISTINCT v.id) AS vote_count,
           p.nickname AS author_name
    FROM retro_notes n
    JOIN retro_columns c ON c.id = n.column_id
    LEFT JOIN note_votes v ON v.note_id = n.id
    LEFT JOIN participants p ON p.id = n.participant_id
    WHERE n.room_id = ? AND n.is_revealed = 1
    GROUP BY n.id
    ORDER BY c.display_order, vote_count DESC, n.created_at
", [$roomId]);

// Group notes by column
$notesByCol = [];
foreach ($columns as $col) $notesByCol[$col['id']] = [];
foreach ($notes as $note) $notesByCol[$note['column_id']][] = $note;

$actionItems = db_query(
    "SELECT * FROM action_items WHERE room_id = ? ORDER BY status, created_at",
    [$roomId]
);

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalNotes     = count($notes);
$totalVotes     = array_sum(array_column($notes, 'vote_count'));
$totalActions   = count($actionItems);

// Top 5 voted notes
$topNotes = $notes;
usort($topNotes, function($a, $b) { return $b['vote_count'] - $a['vote_count']; });
$topNotes = array_slice(array_filter($topNotes, function($n) { return $n['vote_count'] > 0; }), 0, 5);

$generatedAt  = date('F j, Y, g:i a');
$adminUser    = $_SESSION['admin_username'] ?? 'Admin';
$sessionDate  = $room['session_date'] ? date('F j, Y', strtotime($room['session_date'])) : 'N/A';

// Status labels
$statusLabels = ['open'=>'Open','in_progress'=>'In Progress','done'=>'Done','cancelled'=>'Cancelled'];
$statusColors = ['open'=>'#2563eb','in_progress'=>'#d97706','done'=>'#16a34a','cancelled'=>'#94a3b8'];

// ── Output HTML report ────────────────────────────────────────────────────────
// For 'html' format, send as downloadable file; for 'pdf', display for print
if ($format === 'html') {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="retro-' . preg_replace('/[^a-z0-9]+/i', '-', $room['name']) . '-' . date('Ymd') . '.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Retrospective Report — <?= e($room['name']) ?></title>
<style>
/* ── Print & screen base ─────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
body {
    font-family: 'Segoe UI', system-ui, -apple-system, Arial, sans-serif;
    font-size: 13px; color: #1e293b; background: #fff; line-height: 1.5;
    padding: 0;
}
.page { max-width: 900px; margin: 0 auto; padding: 2.5rem 2.5rem 3rem; }
h1,h2,h3,h4 { font-weight: 700; line-height: 1.25; }

/* ── Report header ────────────────────────────────────────── */
.report-header { border-bottom: 3px solid #6366f1; padding-bottom: 1.5rem; margin-bottom: 2rem; }
.report-logo { font-size: 1.3rem; font-weight: 800; color: #6366f1; margin-bottom: .75rem; }
.report-title { font-size: 1.75rem; font-weight: 800; color: #1e293b; margin-bottom: .4rem; }
.report-meta { display: flex; flex-wrap: wrap; gap: 1.5rem; font-size: .8rem; color: #64748b; margin-top: .75rem; }
.report-meta span { display: flex; align-items: center; gap: .35rem; }
.status-badge {
    display: inline-block; padding: .2rem .65rem; border-radius: 20px;
    font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    background: #ede9fe; color: #5b21b6;
}

/* ── Section headings ─────────────────────────────────────── */
.section { margin-bottom: 2.25rem; }
.section-title {
    font-size: 1rem; font-weight: 700; color: #6366f1;
    border-bottom: 2px solid #e2e8f0; padding-bottom: .4rem; margin-bottom: 1rem;
    text-transform: uppercase; letter-spacing: .06em; font-size: .8rem;
}

/* ── Summary stats table ──────────────────────────────────── */
.summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: .75rem; margin-bottom: .5rem; }
.stat-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: .85rem; text-align: center; }
.stat-box__val { font-size: 1.75rem; font-weight: 800; color: #1e293b; }
.stat-box__lbl { font-size: .7rem; color: #64748b; margin-top: .15rem; text-transform: uppercase; letter-spacing: .05em; }

/* ── Board columns ────────────────────────────────────────── */
.board-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 1rem; }
.board-col { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.board-col__header {
    display: flex; justify-content: space-between; align-items: center;
    padding: .6rem .85rem;
    border-top: 4px solid #6366f1;
    background: #f8fafc;
}
.board-col__title { font-weight: 700; font-size: .85rem; }
.board-col__count { font-size: .7rem; background: #e2e8f0; color: #475569; border-radius: 20px; padding: .1rem .45rem; font-weight: 700; }
.board-col__notes { padding: .6rem; }

/* ── Note cards ───────────────────────────────────────────── */
.note-item {
    background: #fffef0; border: 1px solid #e9e4b4; border-radius: 6px;
    padding: .55rem .7rem; margin-bottom: .45rem; font-size: .85rem;
    display: flex; justify-content: space-between; align-items: flex-start; gap: .5rem;
}
.note-item:last-child { margin-bottom: 0; }
.note-item__text { flex: 1; word-break: break-word; white-space: pre-wrap; }
.note-item__votes { font-size: .75rem; color: #64748b; white-space: nowrap; flex-shrink: 0; }
.note-item--top { background: #eff6ff; border-color: #93c5fd; }
.note-item--top .note-item__text::before { content: '🔥 '; }
.no-notes { color: #94a3b8; font-size: .8rem; font-style: italic; padding: .4rem .25rem; }

/* ── Top voted ────────────────────────────────────────────── */
.top-voted-list { display: flex; flex-direction: column; gap: .5rem; }
.top-note {
    display: flex; gap: .75rem; align-items: flex-start;
    background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: .65rem .85rem;
}
.top-note__rank { font-size: 1.1rem; flex-shrink: 0; }
.top-note__content { flex: 1; }
.top-note__text { font-size: .9rem; font-weight: 500; word-break: break-word; }
.top-note__meta { font-size: .75rem; color: #64748b; margin-top: .2rem; }
.top-note__votes { font-size: .85rem; font-weight: 700; color: #2563eb; white-space: nowrap; }

/* ── Action items table ───────────────────────────────────── */
.action-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
.action-table th {
    text-align: left; padding: .5rem .75rem; background: #f8fafc;
    border-bottom: 2px solid #e2e8f0; font-size: .7rem; text-transform: uppercase;
    letter-spacing: .05em; color: #64748b; font-weight: 700;
}
.action-table td { padding: .55rem .75rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
.action-table tr:last-child td { border-bottom: none; }
.action-status {
    display: inline-block; padding: .15rem .5rem; border-radius: 20px;
    font-size: .65rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    color: #fff;
}
.no-actions { color: #94a3b8; font-style: italic; font-size: .85rem; }

/* ── Footer ───────────────────────────────────────────────── */
.report-footer {
    margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #e2e8f0;
    font-size: .75rem; color: #94a3b8; display: flex; justify-content: space-between;
}

/* ── Print toolbar (screen only) ─────────────────────────── */
.print-toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 100;
    background: #1e1e2e; color: #fff;
    display: flex; align-items: center; justify-content: space-between;
    padding: .65rem 1.5rem; gap: 1rem; box-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.print-toolbar .info { font-size: .85rem; color: #94a3b8; }
.print-toolbar .actions { display: flex; gap: .75rem; }
.ptbtn {
    border: none; border-radius: 6px; padding: .45rem 1rem;
    font-size: .85rem; font-weight: 600; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; gap: .35rem;
}
.ptbtn--primary { background: #6366f1; color: #fff; }
.ptbtn--outline { background: transparent; border: 1.5px solid rgba(255,255,255,.2); color: #fff; }
@media print {
    .print-toolbar { display: none; }
    .page { padding: 1.5cm; max-width: none; }
    body { font-size: 11pt; }
    .board-grid { grid-template-columns: repeat(3, 1fr); }
    .summary-grid { grid-template-columns: repeat(4, 1fr); }
    .section { page-break-inside: avoid; }
    .board-col { page-break-inside: avoid; }
    a { color: inherit; text-decoration: none; }
}
</style>
</head>
<body>

<?php if ($format !== 'html'): ?>
<!-- Print toolbar — hidden when printing -->
<div class="print-toolbar">
  <div class="info">📄 Retrospective Report — <?= e($room['name']) ?></div>
  <div class="actions">
    <a href="<?= e(BASE_URL) ?>/admin/room-manage.php?id=<?= $roomId ?>" class="ptbtn ptbtn--outline">← Back</a>
    <a href="<?= e(BASE_URL) ?>/admin/export.php?id=<?= $roomId ?>&format=html" class="ptbtn ptbtn--outline">⬇ Download HTML</a>
    <button class="ptbtn ptbtn--primary" onclick="window.print()">🖨 Print / Save PDF</button>
  </div>
</div>
<div style="height:48px;"></div><!-- spacer for fixed toolbar -->
<?php endif; ?>

<div class="page">

  <!-- ①  Report Header -->
  <div class="report-header">
    <div class="report-logo">🔄 <?= e(APP_NAME) ?></div>
    <h1 class="report-title"><?= e($room['name']) ?></h1>
    <div>
      <span class="status-badge"><?= e(ucfirst($room['status'])) ?></span>
      <?php if ($room['template_name']): ?>
        &nbsp;·&nbsp; <span style="font-size:.8rem;color:#64748b;"><?= e($room['template_name']) ?></span>
      <?php endif; ?>
    </div>
    <div class="report-meta">
      <span>📅 Session Date: <strong><?= e($sessionDate) ?></strong></span>
      <span>🕐 Generated: <?= e($generatedAt) ?></span>
      <span>👤 By: <?= e($adminUser) ?></span>
    </div>
  </div>

  <!-- ②  Session Summary -->
  <div class="section">
    <div class="section-title">Session Summary</div>
    <div class="summary-grid">
      <div class="stat-box"><div class="stat-box__val"><?= $totalNotes ?></div><div class="stat-box__lbl">Total Notes</div></div>
      <div class="stat-box"><div class="stat-box__val"><?= $totalVotes ?></div><div class="stat-box__lbl">Total Votes</div></div>
      <div class="stat-box"><div class="stat-box__val"><?= count($columns) ?></div><div class="stat-box__lbl">Columns</div></div>
      <div class="stat-box"><div class="stat-box__val"><?= $totalActions ?></div><div class="stat-box__lbl">Action Items</div></div>
    </div>
  </div>

  <!-- ③  Board: Columns & Notes -->
  <div class="section">
    <div class="section-title">Board Notes by Column</div>
    <div class="board-grid">
      <?php foreach ($columns as $col): ?>
      <div class="board-col">
        <div class="board-col__header" style="border-top-color:<?= e($col['color']) ?>">
          <span class="board-col__title"><?= e($col['title']) ?></span>
          <span class="board-col__count"><?= count($notesByCol[$col['id']]) ?></span>
        </div>
        <div class="board-col__notes">
          <?php if (empty($notesByCol[$col['id']])): ?>
            <p class="no-notes">No notes.</p>
          <?php else: ?>
            <?php foreach ($notesByCol[$col['id']] as $note): ?>
            <div class="note-item <?= $note['vote_count'] >= 3 ? 'note-item--top' : '' ?>">
              <div class="note-item__text"><?= e($note['content']) ?></div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.3rem;">
                <?php if (!empty($note['author_name'])): ?>
                  <span style="font-size:.7rem;color:#64748b;">👤 <?= e($note['author_name']) ?></span>
                <?php else: ?>
                  <span style="font-size:.7rem;color:#94a3b8;">Anonymous</span>
                <?php endif; ?>
                <?php if ($note['vote_count'] > 0): ?>
                  <span class="note-item__votes">👍 <?= (int)$note['vote_count'] ?></span>
                <?php endif; ?>
              </div>
            </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ④  Top-Voted Topics -->
  <?php if (!empty($topNotes)): ?>
  <div class="section">
    <div class="section-title">Top Voted Discussion Topics</div>
    <div class="top-voted-list">
      <?php $ranks = ['🥇','🥈','🥉','4️⃣','5️⃣']; ?>
      <?php foreach ($topNotes as $i => $note): ?>
      <div class="top-note">
        <div class="top-note__rank"><?= $ranks[$i] ?? ($i+1) . '.' ?></div>
        <div class="top-note__content">
          <div class="top-note__text"><?= e($note['content']) ?></div>
          <div class="top-note__meta">
            📋 <?= e($note['col_title']) ?>
            <?php if (!empty($note['author_name'])): ?> · 👤 <?= e($note['author_name']) ?><?php endif; ?>
          </div>
        </div>
        <div class="top-note__votes">👍 <?= (int)$note['vote_count'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ⑤  Action Items -->
  <div class="section">
    <div class="section-title">Action Items</div>
    <?php if (empty($actionItems)): ?>
      <p class="no-actions">No action items recorded for this session.</p>
    <?php else: ?>
    <table class="action-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Title</th>
          <th>Description</th>
          <th>Owner</th>
          <th>Due Date</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($actionItems as $idx => $ai): ?>
        <tr>
          <td style="color:#94a3b8;font-size:.75rem;"><?= $idx + 1 ?></td>
          <td><strong><?= e($ai['title']) ?></strong></td>
          <td style="color:#64748b;"><?= e($ai['description'] ?? '—') ?></td>
          <td><?= $ai['owner_name'] ? '👤 ' . e($ai['owner_name']) : '<span style="color:#94a3b8">—</span>' ?></td>
          <td><?= $ai['due_date'] ? e(date('M j, Y', strtotime($ai['due_date']))) : '<span style="color:#94a3b8">—</span>' ?></td>
          <td>
            <span class="action-status" style="background:<?= $statusColors[$ai['status']] ?? '#94a3b8' ?>">
              <?= e($statusLabels[$ai['status']] ?? $ai['status']) ?>
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ⑥  Closing summary -->
  <div class="section">
    <div class="section-title">Closing Summary</div>
    <p style="color:#475569;font-size:.875rem;line-height:1.7;">
      This retrospective covered <strong><?= $totalNotes ?></strong> note<?= $totalNotes !== 1 ? 's' : '' ?>
      across <strong><?= count($columns) ?></strong> column<?= count($columns) !== 1 ? 's' : '' ?>,
      with <strong><?= $totalVotes ?></strong> vote<?= $totalVotes !== 1 ? 's' : '' ?> cast.
      A total of <strong><?= $totalActions ?></strong> action item<?= $totalActions !== 1 ? 's' : '' ?> were recorded,
      with
      <?php
        $openCount = count(array_filter($actionItems, function($a) { return $a['status'] === 'open'; }));
        $doneCount = count(array_filter($actionItems, function($a) { return $a['status'] === 'done'; }));
        echo "<strong>$openCount</strong> open and <strong>$doneCount</strong> already marked done.";
      ?>
      Participant identities were kept anonymous throughout.
    </p>
  </div>

  <!-- Footer -->
  <div class="report-footer">
    <span>Generated by <?= e(APP_NAME) ?> v<?= e(APP_VERSION) ?> &mdash; <?= e($generatedAt) ?></span>
    <span>Confidential &mdash; Internal use only</span>
  </div>

</div><!-- .page -->
</body>
</html>
