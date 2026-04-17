<?php
/**
 * Data Management — Export, Import & Backup
 * Full company data in/out with per-session and bulk options.
 * Version: 1.7.9
 */
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login();
require_company();

$cid = current_company_id();

// ── Helpers ──────────────────────────────────────────────────────────────────
function sanitise_filename(string $s): string {
    return preg_replace('/[^a-zA-Z0-9_-]/', '-', $s);
}

function json_export(array $data): string {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ── POST handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = input_str('action');

    // ── Export single session as JSON ─────────────────────────────────────────
    if ($action === 'export_session_json') {
        $roomId = input_int('room_id');
        $room   = db_row('SELECT * FROM retro_rooms WHERE id=? AND company_id=?', [$roomId, $cid]);
        if (!$room) { flash_set('error', 'Session not found.'); redirect(BASE_URL.'/admin/data.php'); }

        $columns = db_query('SELECT * FROM retro_columns WHERE room_id=? ORDER BY display_order', [$roomId]);
        $notes   = db_query(
            'SELECT n.*, p.nickname AS author FROM retro_notes n
             LEFT JOIN participants p ON p.id=n.participant_id
             WHERE n.room_id=? ORDER BY n.column_id, n.created_at', [$roomId]);
        $votes   = db_query(
            'SELECT nv.note_id, COUNT(*) AS total FROM note_votes nv
             JOIN retro_notes n ON n.id=nv.note_id WHERE n.room_id=? GROUP BY nv.note_id', [$roomId]);
        $actions = db_query('SELECT * FROM action_items WHERE room_id=? ORDER BY created_at', [$roomId]);
        $tpl     = $room['template_name'] ?? '';

        $voteMap = [];
        foreach ($votes as $v) { $voteMap[$v['note_id']] = (int)$v['total']; }

        foreach ($notes as &$n) {
            $n['vote_count'] = $voteMap[$n['id']] ?? 0;
        }

        $payload = [
            '_export_meta' => [
                'type'       => 'retroapp_session',
                'version'    => APP_VERSION,
                'exported_at'=> date('c'),
                'app'        => APP_NAME,
            ],
            'session'     => $room,
            'columns'     => $columns,
            'notes'       => $notes,
            'action_items'=> $actions,
        ];

        $fn = sanitise_filename($room['name']) . '-' . date('Ymd') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Cache-Control: no-cache');
        echo json_export($payload);
        exit;
    }

    // ── Export single session as CSV ──────────────────────────────────────────
    if ($action === 'export_session_csv') {
        $roomId = input_int('room_id');
        $room   = db_row('SELECT * FROM retro_rooms WHERE id=? AND company_id=?', [$roomId, $cid]);
        if (!$room) { flash_set('error', 'Session not found.'); redirect(BASE_URL.'/admin/data.php'); }

        $notes = db_query(
            'SELECT n.content, c.title AS column_name,
                    COALESCE(p.nickname, "Anonymous") AS author,
                    COUNT(DISTINCT v.id) AS votes,
                    n.created_at
             FROM retro_notes n
             JOIN retro_columns c ON c.id=n.column_id
             LEFT JOIN participants p ON p.id=n.participant_id
             LEFT JOIN note_votes v ON v.note_id=n.id
             WHERE n.room_id=? GROUP BY n.id ORDER BY c.display_order, votes DESC', [$roomId]);

        $actions = db_query('SELECT title, assignee, due_date, status FROM action_items WHERE room_id=? ORDER BY created_at', [$roomId]);

        $fn = sanitise_filename($room['name']) . '-' . date('Ymd') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Cache-Control: no-cache');

        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8

        fputcsv($out, ['=== RETRO NOTES ===']);
        fputcsv($out, ['Column', 'Note', 'Author', 'Votes', 'Created At']);
        foreach ($notes as $n) {
            fputcsv($out, [$n['column_name'], $n['content'], $n['author'], $n['votes'], $n['created_at']]);
        }

        fputcsv($out, []);
        fputcsv($out, ['=== ACTION ITEMS ===']);
        fputcsv($out, ['Title', 'Assignee', 'Due Date', 'Status']);
        foreach ($actions as $a) {
            fputcsv($out, [$a['title'], $a['assignee'] ?? '', $a['due_date'] ?? '', $a['status']]);
        }
        fclose($out);
        exit;
    }

    // ── Full company backup (JSON) ────────────────────────────────────────────
    if ($action === 'backup_full') {
        $company  = db_row('SELECT * FROM companies WHERE id=?', [$cid]);
        $rooms    = db_query('SELECT * FROM retro_rooms WHERE company_id=? ORDER BY created_at', [$cid]);
        $allData  = [];

        foreach ($rooms as $room) {
            $rid = (int)$room['id'];
            $allData[] = [
                'session'      => $room,
                'columns'      => db_query('SELECT * FROM retro_columns WHERE room_id=? ORDER BY display_order', [$rid]),
                'notes'        => db_query('SELECT n.*, p.nickname AS author FROM retro_notes n LEFT JOIN participants p ON p.id=n.participant_id WHERE n.room_id=? ORDER BY n.column_id, n.created_at', [$rid]),
                'votes'        => db_query('SELECT nv.* FROM note_votes nv JOIN retro_notes n ON n.id=nv.note_id WHERE n.room_id=?', [$rid]),
                'action_items' => db_query('SELECT * FROM action_items WHERE room_id=? ORDER BY created_at', [$rid]),
            ];
        }

        $templates = db_query('SELECT * FROM board_templates ORDER BY id', []);
        $poker     = [];
        try {
            $ppExists = db_row("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pp_sessions'")['n'] ?? 0;
            if ($ppExists) {
                $ppSessions = db_query('SELECT * FROM pp_sessions WHERE company_id=?', [$cid]);
                foreach ($ppSessions as $ps) {
                    $poker[] = [
                        'session' => $ps,
                        'history' => db_query('SELECT * FROM pp_history WHERE session_code=? ORDER BY id', [$ps['code']]),
                    ];
                }
            }
        } catch (Throwable $e) {}

        $payload = [
            '_export_meta' => [
                'type'        => 'retroapp_backup',
                'version'     => APP_VERSION,
                'exported_at' => date('c'),
                'app'         => APP_NAME,
                'session_count' => count($rooms),
            ],
            'company'   => $company,
            'sessions'  => $allData,
            'templates' => $templates,
            'poker'     => $poker,
        ];

        $fn = 'retroapp-backup-' . sanitise_filename($company['name'] ?? 'company') . '-' . date('Ymd-His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Cache-Control: no-cache');
        echo json_export($payload);
        exit;
    }

    // ── Import session from JSON ──────────────────────────────────────────────
    if ($action === 'import_session') {
        if (empty($_FILES['import_file']['tmp_name'])) {
            flash_set('error', 'No file uploaded.'); redirect(BASE_URL.'/admin/data.php');
        }
        $raw = file_get_contents($_FILES['import_file']['tmp_name']);
        $data = json_decode($raw, true);

        if (!$data || !isset($data['_export_meta'])) {
            flash_set('error', 'Invalid file: not a RetroApp export.'); redirect(BASE_URL.'/admin/data.php');
        }

        $type = $data['_export_meta']['type'] ?? '';
        if (!in_array($type, ['retroapp_session', 'retroapp_backup'], true)) {
            flash_set('error', 'Unsupported export type: ' . $type); redirect(BASE_URL.'/admin/data.php');
        }

        $sessions = $type === 'retroapp_backup' ? $data['sessions'] : [['session'=>$data['session'],'columns'=>$data['columns'],'notes'=>$data['notes'],'action_items'=>$data['action_items']]];

        $imported = 0;
        $pdo = db();

        foreach ($sessions as $sd) {
            $s = $sd['session'];
            // Create new session under this company
            $newUuid  = generate_uuid();
            $newRoomId = (int)db_insert(
                'INSERT INTO retro_rooms (company_id, room_uuid, name, session_type, description, template_name, status, max_votes, allow_edit_notes, join_password, session_date, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $cid, $newUuid,
                    ($s['name'] ?? 'Imported Session') . ' (imported)',
                    $s['session_type'] ?? 'retrospective',
                    $s['description'] ?? null,
                    $s['template_name'] ?? 'Custom',
                    'draft',
                    (int)($s['max_votes'] ?? 3),
                    (int)($s['allow_edit_notes'] ?? 1),
                    null, // strip password on import
                    $s['session_date'] ?? null,
                    current_admin_id(),
                ]
            );

            // Map old column IDs → new column IDs
            $colMap = [];
            foreach (($sd['columns'] ?? []) as $col) {
                $newColId = (int)db_insert(
                    'INSERT INTO retro_columns (room_id, title, color, display_order) VALUES (?,?,?,?)',
                    [$newRoomId, $col['title'], $col['color'] ?? '#6366f1', (int)($col['display_order'] ?? 0)]
                );
                $colMap[(int)$col['id']] = $newColId;
            }

            // Map old note IDs → new note IDs
            $noteMap = [];
            foreach (($sd['notes'] ?? []) as $note) {
                $oldColId = (int)($note['column_id'] ?? 0);
                $newColId = $colMap[$oldColId] ?? null;
                if (!$newColId) continue;

                // Create anonymous participant for import attribution
                $participantId = (int)db_insert(
                    'INSERT INTO participants (company_id, room_id, session_token, nickname, is_guest)
                     VALUES (?,?,?,?,1)',
                    [$cid, $newRoomId, bin2hex(random_bytes(16)),
                     $note['author'] ?? 'Imported']
                );

                $newNoteId = (int)db_insert(
                    'INSERT INTO retro_notes (company_id, room_id, column_id, participant_id, content, is_revealed)
                     VALUES (?,?,?,?,?,?)',
                    [$cid, $newRoomId, $newColId, $participantId,
                     $note['content'] ?? '', (int)($note['is_revealed'] ?? 0)]
                );
                $noteMap[(int)$note['id']] = $newNoteId;
            }

            // Import action items
            foreach (($sd['action_items'] ?? []) as $ai) {
                db_exec(
                    'INSERT INTO action_items (company_id, room_id, title, assignee, due_date, status, created_by)
                     VALUES (?,?,?,?,?,?,?)',
                    [$cid, $newRoomId, $ai['title'] ?? 'Action',
                     $ai['assignee'] ?? null, $ai['due_date'] ?? null,
                     in_array($ai['status'] ?? 'open', ['open','in_progress','done'], true) ? $ai['status'] : 'open',
                     current_admin_id()]
                );
            }

            $imported++;
        }

        flash_set('success', "Imported {$imported} session" . ($imported !== 1 ? 's' : '') . " successfully.");
        redirect(BASE_URL . '/admin/data.php');
    }

    // ── Import templates from backup ──────────────────────────────────────────
    if ($action === 'import_templates') {
        if (empty($_FILES['import_file']['tmp_name'])) {
            flash_set('error', 'No file uploaded.'); redirect(BASE_URL.'/admin/data.php');
        }
        $raw  = file_get_contents($_FILES['import_file']['tmp_name']);
        $data = json_decode($raw, true);
        if (!$data || empty($data['templates'])) {
            flash_set('error', 'No templates found in file.'); redirect(BASE_URL.'/admin/data.php');
        }
        $count = 0;
        foreach ($data['templates'] as $tpl) {
            if (empty($tpl['name']) || empty($tpl['columns_json'])) continue;
            db_exec(
                'INSERT INTO board_templates (name, columns_json, guidance, is_default)
                 VALUES (?,?,?,0)',
                [$tpl['name'] . ' (imported)', $tpl['columns_json'], $tpl['guidance'] ?? null]
            );
            $count++;
        }
        flash_set('success', "Imported {$count} template" . ($count !== 1 ? 's' : '') . ".");
        redirect(BASE_URL . '/admin/data.php');
    }
}

// ── Load data for display ─────────────────────────────────────────────────────
$rooms = db_query(
    'SELECT r.id, r.name, r.status, r.session_type, r.session_date, r.created_at,
            COUNT(DISTINCT n.id) AS note_count,
            COUNT(DISTINCT a.id) AS action_count
     FROM retro_rooms r
     LEFT JOIN retro_notes n ON n.room_id = r.id
     LEFT JOIN action_items a ON a.room_id = r.id
     WHERE r.company_id = ?
     GROUP BY r.id
     ORDER BY r.created_at DESC',
    [$cid]
);

$totalNotes   = array_sum(array_column($rooms, 'note_count'));
$totalActions = array_sum(array_column($rooms, 'action_count'));

$pageTitle  = 'Data Management';
$activePage = 'data';
require_once ROOT_PATH . '/templates/admin-header.php';
?>

<!-- Stats bar -->
<div style="display:flex;gap:.875rem;flex-wrap:wrap;margin-bottom:1.5rem;">
  <?php foreach ([
      ['📋', count($rooms), 'Sessions'],
      ['💬', $totalNotes,   'Notes'],
      ['✅', $totalActions, 'Action Items'],
  ] as [$ico, $val, $lbl]): ?>
  <div class="card" style="flex:1;min-width:120px;">
    <div class="card__body" style="text-align:center;padding:.875rem;">
      <div style="font-size:1.4rem;"><?= $ico ?></div>
      <div style="font-size:1.6rem;font-weight:800;letter-spacing:-.04em;margin:.1rem 0;"><?= number_format($val) ?></div>
      <div style="font-size:.72rem;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.06em;"><?= $lbl ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start;">

  <!-- ── LEFT: Export & Backup ── -->
  <div>

    <!-- Full backup -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card__header">
        <span class="card__title">🗄 Full Company Backup</span>
      </div>
      <div class="card__body">
        <p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:1rem;line-height:1.55;">
          Downloads a complete JSON backup of all sessions, notes, votes, action items, templates, and poker history for your workspace. Use this for migrations, archiving, or disaster recovery.
        </p>
        <div style="background:rgba(88,86,214,.07);border:1px solid rgba(88,86,214,.15);border-radius:var(--r-sm);padding:.65rem .9rem;margin-bottom:1rem;font-size:.78rem;">
          <strong>Includes:</strong> <?= count($rooms) ?> sessions · <?= number_format($totalNotes) ?> notes · <?= number_format($totalActions) ?> action items · templates · poker sessions
        </div>
        <form method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="backup_full">
          <button type="submit" class="btn btn--primary">⬇ Download Backup JSON</button>
        </form>
      </div>
    </div>

    <!-- Per-session export -->
    <div class="card">
      <div class="card__header">
        <span class="card__title">📤 Export Session</span>
      </div>
      <div class="card__body" style="padding:0;">
        <?php if (empty($rooms)): ?>
        <div class="empty-state">No sessions to export.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
          <table class="data-table">
            <thead>
              <tr>
                <th>Session</th>
                <th>Type</th>
                <th>Notes</th>
                <th>Status</th>
                <th style="text-align:right;">Export</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rooms as $r):
                if ($r['status'] === 'active') { $statusColor = 'var(--accent-green)'; }
                elseif ($r['status'] === 'revealed') { $statusColor = 'var(--accent)'; }
                elseif (in_array($r['status'], ['closed','archived'], true)) { $statusColor = 'var(--text-secondary)'; }
                else { $statusColor = 'var(--accent-orange)'; }
              ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:.85rem;"><?= e($r['name']) ?></div>
                  <?php if ($r['session_date']): ?>
                  <div style="font-size:.72rem;color:var(--text-secondary);"><?= e($r['session_date']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span style="font-size:.75rem;text-transform:capitalize;"><?= e($r['session_type']) ?></span></td>
                <td style="font-size:.85rem;"><?= (int)$r['note_count'] ?> <span style="color:var(--text-secondary);font-size:.7rem;">notes</span></td>
                <td><span style="font-size:.72rem;font-weight:600;color:<?= $statusColor ?>;"><?= ucfirst(e($r['status'])) ?></span></td>
                <td style="text-align:right;">
                  <div style="display:flex;gap:.4rem;justify-content:flex-end;">
                    <form method="POST" style="display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="export_session_json">
                      <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn--sm btn--outline" title="Export as JSON">JSON</button>
                    </form>
                    <form method="POST" style="display:inline;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="export_session_csv">
                      <input type="hidden" name="room_id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn--sm btn--outline" title="Export as CSV">CSV</button>
                    </form>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left -->

  <!-- ── RIGHT: Import & Restore ── -->
  <div>

    <!-- Import sessions -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card__header">
        <span class="card__title">📥 Import Sessions</span>
      </div>
      <div class="card__body">
        <p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:1rem;line-height:1.55;">
          Import sessions from a <strong>session JSON</strong> or a <strong>full backup JSON</strong>. All sessions are created as new drafts — existing data is never overwritten. Join passwords are stripped on import.
        </p>
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import_session">
          <div class="form-group">
            <label class="form-label">Select JSON File</label>
            <input type="file" name="import_file" class="form-input" accept=".json" required
              style="padding:.45rem .7rem;cursor:pointer;">
            <p class="form-hint">Accepts session exports or full backup files.</p>
          </div>
          <button type="submit" class="btn btn--primary">⬆ Import Sessions</button>
        </form>
      </div>
    </div>

    <!-- Import templates -->
    <div class="card" style="margin-bottom:1.25rem;">
      <div class="card__header">
        <span class="card__title">📋 Import Templates</span>
      </div>
      <div class="card__body">
        <p style="font-size:.85rem;color:var(--text-secondary);margin-bottom:1rem;line-height:1.55;">
          Import board templates from a backup JSON file. Imported templates are added as new entries — existing templates are not modified.
        </p>
        <form method="POST" enctype="multipart/form-data">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import_templates">
          <div class="form-group">
            <label class="form-label">Select Backup JSON File</label>
            <input type="file" name="import_file" class="form-input" accept=".json" required
              style="padding:.45rem .7rem;cursor:pointer;">
          </div>
          <button type="submit" class="btn btn--primary">⬆ Import Templates</button>
        </form>
      </div>
    </div>

    <!-- Backup guidance -->
    <div class="card">
      <div class="card__header"><span class="card__title">📖 Backup Guide</span></div>
      <div class="card__body">
        <div style="font-size:.82rem;line-height:1.7;color:var(--text-secondary);">
          <p style="margin-bottom:.65rem;"><strong style="color:var(--text-primary);">Recommended schedule</strong><br>
            Download a full backup before any upgrade, major change, or monthly as routine.</p>
          <p style="margin-bottom:.65rem;"><strong style="color:var(--text-primary);">What JSON backup includes</strong><br>
            All sessions, columns, notes (with authors), vote counts, action items, templates, and poker session history. Passwords and raw tokens are excluded for security.</p>
          <p style="margin-bottom:.65rem;"><strong style="color:var(--text-primary);">CSV vs JSON</strong><br>
            CSV is for reading in Excel or sharing with stakeholders. JSON is for full restore/import back into RetroApp.</p>
          <p style="margin-bottom:0;"><strong style="color:var(--text-primary);">Import safety</strong><br>
            Sessions are always imported as new drafts. No existing data can be overwritten by an import.</p>
        </div>
      </div>
    </div>

  </div><!-- /right -->

</div>

<style>
@media (max-width: 800px) {
  .data-grid { grid-template-columns: 1fr !important; }
}
</style>

<?php require_once ROOT_PATH . '/templates/admin-footer.php'; ?>
