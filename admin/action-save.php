<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once INCLUDES_PATH . '/bootstrap.php';
require_admin_login(); require_company();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_verify();

$cid        = current_company_id();
$roomId     = input_int('room_id');
$actionId   = input_int('action_id');
$redirectTo = input_str('redirect_to', 'POST', 500) ?: BASE_URL . '/admin/rooms.php';

$room = db_row('SELECT id FROM retro_rooms WHERE id=? AND company_id=?', [$roomId, $cid]);
if (!$room) { flash_set('error', 'Room not found.'); redirect($redirectTo); }

$validStatuses = ['open','in_progress','done','cancelled'];

if ($actionId > 0) {
    $status = input_str('status', 'POST', 20);
    if (in_array($status, $validStatuses, true)) {
        db_exec('UPDATE action_items SET status=? WHERE id=? AND company_id=?', [$status, $actionId, $cid]);
        flash_set('success', 'Action item updated.');
    }
} else {
    $title   = input_str('title', 'POST', 300);
    $desc    = input_str('description', 'POST', 500);
    $owner   = input_str('owner_name', 'POST', 100);
    $dueDate = input_date('due_date');
    $noteId  = input_int('note_id') ?: null;

    if ($title === '') { flash_set('error', 'Title is required.'); redirect($redirectTo); }

    if ($noteId) {
        $noteCheck = db_row('SELECT id FROM retro_notes WHERE id=? AND room_id=?', [$noteId, $roomId]);
        if (!$noteCheck) $noteId = null;
    }
    db_insert(
        'INSERT INTO action_items (company_id,room_id,note_id,title,description,owner_name,due_date,status) VALUES (?,?,?,?,?,?,?,?)',
        [$cid,$roomId,$noteId,$title,$desc?:null,$owner?:null,$dueDate,'open']
    );
    flash_set('success', 'Action item added.');
}
redirect($redirectTo);
