<?php
/**
 * Planning Poker — Game Room (Public)
 * Participants access this page directly via code.
 * Admins land here after creating/opening a session.
 * Version: 1.9.9
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once INCLUDES_PATH . '/bootstrap.php';

// Accept code from URL; name may come pre-filled from lobby join
$code     = strtoupper(trim($_GET['code'] ?? ''));
$isMod      = !empty($_GET['mod']) && is_admin_logged_in();
$prefName   = trim($_GET['name'] ?? '');
$isEmbedded = !empty($_GET['embedded']); // embedded in room.php iframe

// Validate code exists
$session = $code ? db_row('SELECT code, sprint, phase FROM pp_sessions WHERE code=?', [$code]) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Planning Poker<?= $session ? ' — ' . e($session['sprint']) : '' ?> — <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/app.css">
</head>
<body class="board-page<?= $isEmbedded ? ' board-embedded' : '' ?>">

<?php if (!$session): ?>
<!-- No valid session code — show lookup form -->
<div class="pp-entry-overlay">
  <div class="auth-card">
    <div class="auth-logo">🃏 Planning Poker</div>
    <p class="auth-subtitle">Enter a session code to join</p>
    <div class="form-group">
      <label class="form-label">Session Code</label>
      <input type="text" id="lookup-code" class="form-input"
        placeholder="e.g. AB12CD34"
        style="letter-spacing:2px;text-transform:uppercase;font-weight:700;"
        oninput="this.value=this.value.toUpperCase()" maxlength="8"
        value="<?= e($code) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Your Name</label>
      <input type="text" id="lookup-name" class="form-input"
        placeholder="e.g. Alex" maxlength="100" value="<?= e($prefName) ?>">
    </div>
    <p id="lookup-err" style="color:var(--accent-red);font-size:.8rem;margin-bottom:.75rem;display:none;"></p>
    <button class="btn btn--primary" style="width:100%;" onclick="enterRoom()">Join →</button>
    <?php if (is_admin_logged_in()): ?>
    <p style="text-align:center;margin-top:1rem;font-size:.8rem;">
      <a href="<?= e(BASE_URL) ?>/admin/poker.php">← Back to lobby</a>
    </p>
    <?php endif; ?>
  </div>
</div>
<script>
async function enterRoom() {
    var code = document.getElementById('lookup-code').value.trim().toUpperCase();
    var name = document.getElementById('lookup-name').value.trim();
    var err  = document.getElementById('lookup-err');
    err.style.display = 'none';
    if (!code) { err.textContent = 'Enter a session code.'; err.style.display = 'block'; return; }
    if (!name) { err.textContent = 'Enter your name.'; err.style.display = 'block'; return; }
    try {
        var res  = await fetch('<?= e(BASE_URL) ?>/poker-api.php?action=check&code=' + encodeURIComponent(code));
        var data = await res.json();
        if (!data.ok) { err.textContent = data.e || 'Session not found.'; err.style.display = 'block'; return; }
        window.location.href = '<?= e(BASE_URL) ?>/poker-room.php?code=' + encodeURIComponent(code) + '&name=' + encodeURIComponent(name);
    } catch(e) {
        err.textContent = 'Connection error. Try again.';
        err.style.display = 'block';
    }
}
document.addEventListener('keydown', function(e){ if(e.key==='Enter') enterRoom(); });
</script>
</body></html>
<?php exit; endif; ?>

<!-- ═══ Entry overlay (name prompt if not yet joined) ═══════════════ -->
<div id="pp-entry" class="pp-entry-overlay" style="display:none;">
  <div class="auth-card">
    <div class="auth-logo">🃏 Planning Poker</div>
    <p class="auth-subtitle">Session <strong><?= e($code) ?></strong> · <?= e($session['sprint']) ?></p>
    <div class="form-group">
      <label class="form-label" for="entry-name">Your Name</label>
      <input type="text" id="entry-name" class="form-input"
        placeholder="e.g. Alex" maxlength="100" autofocus
        value="<?= e($prefName) ?>">
    </div>
    <p id="entry-err" class="alert alert--error" style="display:none;" role="alert" aria-live="polite"></p>
    <button class="btn btn--primary btn--full" onclick="submitEntry()">Enter room →</button>
    <?php if (is_admin_logged_in()): ?>
    <p style="text-align:center;margin-top:1rem;font-size:.8rem;">
      <a href="<?= e(BASE_URL) ?>/admin/poker.php">← Back to lobby</a>
    </p>
    <?php endif; ?>
  </div>
</div>

<!-- ═══ Game board ═══════════════════════════════════════════════════ -->
<div id="pp-game" class="poker-wrap" style="display:none;">
  <div class="poker-grid">

    <!-- Header — same pattern as board-header -->
    <header class="board-header poker-hdr" style="grid-column:1/-1;height:auto;min-height:56px;padding:.6rem 1.25rem;position:relative;border-radius:0;">
      <div class="board-header__left">
        <span class="board-header__logo">🃏</span>
        <div>
          <div class="board-header__title" style="display:flex;align-items:center;gap:.5rem;">
            Planning Poker
            <span id="pp-mod-badge" class="badge badge--active" style="display:none;text-transform:none;font-size:.7rem;">Moderator</span>
          </div>
          <div class="board-header__sub" id="pp-sprint-badge"></div>
        </div>
      </div>
      <div class="board-header__right" style="gap:.6rem;">
        <button type="button" id="pp-code-display" onclick="copyCode()"
          class="board-header__nick" style="cursor:pointer;letter-spacing:.1em;font-weight:800;border:none;background:rgba(255,255,255,.15);"
          title="Click to copy session code"><?= e($code) ?></button>
        <?php if (is_admin_logged_in() && !$isEmbedded): ?>
        <a href="<?= e(BASE_URL) ?>/admin/poker.php" class="btn btn--sm btn--outline" style="color:#fff;border-color:rgba(255,255,255,.3);">← Lobby</a>
        <button type="button" id="pp-close-btn" onclick="confirmClose()"
          class="btn btn--sm btn--danger" style="display:none;"
          title="Close this session for all participants">⏹ Close Session</button>
        <?php endif; ?>
      </div>
    </header>

    <!-- Estimation Guide — full-width collapsible above the grid -->
    <div class="pp-guide-panel" style="grid-column:1/-1;">
      <button type="button" class="pp-guide-toggle" onclick="toggleGuide()"
        id="pp-guide-btn" aria-expanded="false" aria-controls="pp-guide-body">
        <span style="font-weight:700;font-size:.875rem;">📏 Estimation Guide</span>
        <div style="display:flex;gap:.5rem;align-items:center;margin-left:.75rem;">
          <span class="pp-spb sp1" style="font-size:.7rem;">1</span>
          <span class="pp-spb sp3" style="font-size:.7rem;">3</span>
          <span class="pp-spb sp5" style="font-size:.7rem;">5</span>
          <span class="pp-spb sp8" style="font-size:.7rem;">8</span>
          <span class="pp-spb sp13" style="font-size:.7rem;">13</span>
        </div>
        <span style="margin-left:auto;font-size:.72rem;color:var(--muted);">🔴 13+ SP must be split</span>
        <span id="pp-guide-chev" style="font-size:.8rem;margin-left:.5rem;transition:transform .2s;">▾</span>
      </button>
      <div id="pp-guide-body" style="display:none;border-top:1px solid var(--divider);">
        <div style="padding:.875rem 1.25rem;overflow-x:auto;">
          <table class="pp-guide-table" style="min-width:640px;">
            <thead>
              <tr>
                <th scope="col" style="text-align:left;width:110px;">Dimension</th>
                <th scope="col"><span class="pp-spb sp1">1</span></th>
                <th scope="col"><span class="pp-spb sp2">2</span></th>
                <th scope="col"><span class="pp-spb sp3">3</span></th>
                <th scope="col"><span class="pp-spb sp5">5</span></th>
                <th scope="col"><span class="pp-spb sp8">8</span></th>
                <th scope="col"><span class="pp-spb sp13">13</span></th>
                <th scope="col"><span class="pp-spb sp21">21</span></th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>Knowledge</td>
                <td>Everything</td><td>Almost all</td><td>Something</td><td>Little</td>
                <td>Nothing</td><td>Nothing</td><td>Nothing</td>
              </tr>
              <tr>
                <td>Dependencies</td>
                <td>None</td><td>Almost none</td><td>Some</td><td>Few</td>
                <td>Several</td><td>Unknown</td><td>Unknown</td>
              </tr>
              <tr>
                <td>Effort</td>
                <td>&lt;2h</td><td>½ day</td><td>2 days</td><td>Few days</td>
                <td>~1 week</td><td>&gt;1 wk</td><td>Multi-week</td>
              </tr>
              <tr style="border-top:2px solid var(--divider);">
                <td>Unit Tests</td>
                <td>Full cover</td><td>Most covered</td><td>Some tests</td><td>Few tests</td>
                <td>Minimal</td><td>None</td><td>None</td>
              </tr>
              <tr>
                <td>Documentation</td>
                <td>Complete</td><td>Mostly done</td><td>Partial</td><td>Basic</td>
                <td>Minimal</td><td>None</td><td>None</td>
              </tr>
              <tr>
                <td>Video Record.</td>
                <td>Not needed</td><td>Simple clip</td><td>Short demo</td><td>Full demo</td>
                <td>Complex</td><td>Series</td><td>Series</td>
              </tr>
              <tr>
                <td>Code Review</td>
                <td>Trivial</td><td>Simple</td><td>Standard</td><td>Complex</td>
                <td>Major</td><td>Architect.</td><td>Architect.</td>
              </tr>
            </tbody>
          </table>
          <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:.875rem;">
            <div class="pp-tip" style="flex:1;min-width:180px;">Pick the <strong>higher</strong> column when unsure.</div>
            <div class="pp-tip" style="flex:1;min-width:180px;background:var(--color-warning-bg);border-color:var(--color-warning-border);color:var(--color-warning);">
              <strong>🟡 8 SP</strong> — consider splitting into smaller stories
            </div>
            <div class="pp-tip" style="flex:1;min-width:180px;background:var(--color-danger-bg);border-color:var(--color-danger-border);color:var(--color-danger);">
              <strong>🔴 13+ SP</strong> — must split before the sprint
            </div>
            <div class="pp-tip" style="flex:1;min-width:180px;"><strong>Wide spread = hidden risk.</strong> Discuss before re-voting — that's where the value is.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main content area — full remaining width -->
    <main class="pp-main" id="pp-main" aria-live="polite" aria-atomic="false" role="main">
      <p style="color:var(--muted);font-size:.875rem;">Connecting…</p>
    </main>

    <!-- Players + History sidebar -->
    <aside class="pp-panel" id="pp-sidebar">
      <div class="pp-panel__head">Players (<span id="pp-player-count">0</span>)</div>
      <div style="padding:.65rem .75rem;">
        <div id="pp-players"></div>
        <div id="pp-history"></div>
      </div>
    </aside>


<script>
// ── Estimation guide toggle ──────────────────────────────────────────────────
function toggleGuide() {
    var body = document.getElementById('pp-guide-body');
    var btn  = document.getElementById('pp-guide-btn');
    var chev = document.getElementById('pp-guide-chev');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (btn)  btn.setAttribute('aria-expanded', open ? 'false' : 'true');
    if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
}

const API      = '<?= e(BASE_URL) ?>/poker-api.php';
const CODE     = '<?= e($code) ?>';
const PREF_NAME = '<?= e(addslashes($prefName)) ?>';
const IS_ADMIN_MOD = <?= ($isMod && is_admin_logged_in()) ? 'true' : 'false' ?>;

let IS_MOD  = false;
let SEL_SP  = null;
let pollTimer = null;
let joined  = false;
let myName  = '';

// ── SP colour helper ────────────────────────────────────────────
function spCls(v) {
    return {1:'sp1',2:'sp2',3:'sp3',5:'sp5',8:'sp8',13:'sp13',21:'sp21'}[+v] || '';
}

// ── API call ────────────────────────────────────────────────────
async function ppApi(action, data) {
    data = data || {};
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, String(data[k]));
    var r = await fetch(API, { method: 'POST', body: fd });
    var j = await r.json();
    if (!j.ok) throw new Error(j.e || 'API error');
    return j.d;
}

// ── Copy session code ───────────────────────────────────────────
function copyCode() {
    var url = '<?= e(BASE_URL) ?>/poker-room.php?code=' + CODE;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
            showToast('Session link copied!', 'success', 2000);
        });
    } else {
        showToast('Code: ' + CODE, 'info', 4000);
    }
}

// ── Entry ───────────────────────────────────────────────────────
function showEntry() {
    document.getElementById('pp-entry').style.display = 'flex';
    document.getElementById('pp-game').style.display  = 'none';
    if (PREF_NAME) document.getElementById('entry-name').value = PREF_NAME;
}

async function submitEntry() {
    var name = document.getElementById('entry-name').value.trim();
    var err  = document.getElementById('entry-err');
    err.style.display = 'none';
    if (!name) { err.textContent = 'Enter your name.'; err.style.display = 'block'; return; }
    try {
        await ppApi('join', { code: CODE, name: name });
        myName = name;
        joined = true;
        document.getElementById('pp-entry').style.display = 'none';
        document.getElementById('pp-game').style.display  = 'block';
        startPolling();
    } catch(e) {
        err.textContent = e.message;
        err.style.display = 'block';
    }
}

// ── Polling ─────────────────────────────────────────────────────
function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
}
function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    poll();
    pollTimer = setInterval(poll, 1500);
    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            clearInterval(pollTimer); pollTimer = null;
        } else {
            startPolling();
        }
    });
}

async function poll() {
    try {
        var d = await ppApi('state', { code: CODE });
        IS_MOD = d.is_mod;
        render(d);
    } catch(e) { /* silent */ }
}

// ── Render ──────────────────────────────────────────────────────
function render(d) {
    document.getElementById('pp-sprint-badge').textContent = d.sprint || '';

    // Show Close button only to moderator when session is not yet closed
    var closeBtn = document.getElementById('pp-close-btn');
    if (closeBtn) {
        closeBtn.style.display = (d.is_mod && d.phase !== 'closed') ? '' : 'none';
    }
    document.getElementById('pp-mod-badge').style.display = d.is_mod ? 'inline' : 'none';
    renderPlayers(d.players, d.phase);
    renderHistory(d.history);
    renderMain(d);
}

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;')
        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Main area ───────────────────────────────────────────────────
// Track last rendered state to avoid destroying inputs mid-type
var _lastPhase = null;
var _lastStory = null;
var _lastIsMod = null;

function renderMain(d) {
    var el = document.getElementById('pp-main');

    if (d.phase === 'waiting') {
        if (d.is_mod) {
            // Only rebuild the DOM if phase/mod status changed — not on every poll.
            // This prevents the story input from being wiped while the user is typing.
            if (_lastPhase !== 'waiting' || _lastIsMod !== true) {
                el.innerHTML =
                    '<p class="cards-label" style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-secondary);">New Story</p>' +
                    '<div style="display:flex;gap:.6rem;">' +
                      '<input type="text" id="pp-story-in" class="form-input" style="flex:1;" placeholder="e.g. User login with OAuth" maxlength="500" autofocus>' +
                      '<button class="btn btn--primary" onclick="startStory()" style="white-space:nowrap;">▶ Start voting</button>' +
                    '</div>' +
                    '<div style="display:flex;align-items:center;gap:.6rem;margin-top:.75rem;padding:.65rem .85rem;background:rgba(0,0,0,.04);border-radius:var(--r-sm);font-size:.8rem;color:var(--text-secondary);">' +
                      '<span>Sprint:</span>' +
                      '<input type="text" id="pp-sprint-in" class="form-input form-input--sm" style="flex:1;" value="' + esc(d.sprint) + '" maxlength="100">' +
                      '<button class="btn btn--sm btn--outline" onclick="changeSprint()">Rename</button>' +
                    '</div>';
                _lastPhase = 'waiting';
                _lastIsMod = true;
                _lastStory = null;
                // Focus the story input after a brief delay
                setTimeout(function() {
                    var inp = document.getElementById('pp-story-in');
                    if (inp && document.activeElement !== inp) inp.focus();
                }, 50);
            }
            // Update sprint value without touching story input
            var sprintIn = document.getElementById('pp-sprint-in');
            if (sprintIn && document.activeElement !== sprintIn) {
                sprintIn.value = d.sprint;
            }
        } else {
            if (_lastPhase !== 'waiting' || _lastIsMod !== false) {
                el.innerHTML =
                    '<div class="pp-waiting"><div style="font-size:2rem;">⏳</div>' +
                    '<p>Waiting for moderator to start a round…</p></div>';
                _lastPhase = 'waiting';
                _lastIsMod = false;
            }
        }
        return;
    }

    if (d.phase === 'voting') {
        var voted = d.players.filter(function(p){ return p.voted; }).length;
        var total = d.players.length;
        var allVoted = voted === total;

        // Re-render if phase changed, story changed, vote count changed, or my_vote changed
        var stateKey = 'voting|' + (d.story||'') + '|' + voted + '|' + total + '|' + d.my_vote + '|' + (d.my_comment||'');
        if (_lastPhase === stateKey) {
            // Just update the status text without touching vote cards
            var ctrl = document.getElementById('pp-vote-ctrl');
            if (ctrl) {
                ctrl.innerHTML = d.is_mod
                    ? '<button class="btn ' + (allVoted ? 'btn--success' : 'btn--outline') + '" ' +
                      (allVoted ? 'onclick="doReveal()"' : '') +
                      '>' + (allVoted ? '👁 Reveal votes' : '⏳ Waiting… ' + voted + '/' + total + ' voted') + '</button>' +
                      (!allVoted && voted > 0 ? ' <button class="btn btn--warning" onclick="doReveal()">Reveal anyway</button>' : '')
                    : '<p style="font-size:.825rem;color:var(--text-secondary);">' + voted + ' / ' + total + ' voted</p>';
            }
            return;
        }
        _lastPhase = stateKey;
        _lastStory = d.story;

        var cardsHtml = [1,2,3,5,8,13,21].map(function(v) {
            return '<div class="pp-card ' + spCls(v) + (d.my_vote == v ? ' selected' : '') +
                   '" data-sp="' + v + '" onclick="castVote(' + v + ')" role="button" tabindex="0" aria-label="' + v + ' story points">' + v + '</div>';
        }).join('');

        var ctrlHtml = d.is_mod
            ? '<button class="btn ' + (allVoted ? 'btn--success' : 'btn--outline') + '" ' +
              (allVoted ? 'onclick="doReveal()"' : '') +
              '>' + (allVoted ? '👁 Reveal votes' : '⏳ Waiting… ' + voted + '/' + total + ' voted') + '</button>' +
              (!allVoted && voted > 0 ? ' <button class="btn btn--warning" onclick="doReveal()">Reveal anyway</button>' : '')
            : '<p style="font-size:.825rem;color:var(--text-secondary);">' + voted + ' / ' + total + ' voted</p>';

        var myComment = d.my_comment || '';
        el.innerHTML =
            '<div class="pp-story"><div class="pp-story__label">Estimating</div><div class="pp-story__text">' + esc(d.story) + '</div></div>' +
            '<div class="pp-vote-section">' +
              '<p class="pp-vote-label">Your estimate' + (d.my_vote != null ? ' <span class="pp-voted-badge">' + d.my_vote + ' SP ✓</span>' : '') + '</p>' +
              '<div class="pp-cards" id="pp-cards-row">' + cardsHtml + '</div>' +
            '</div>' +
            '<div class="pp-comment-section">' +
              '<label class="pp-comment-label" for="vote-comment">Your note <span style="font-weight:400;color:var(--muted);">(optional — reasoning, concerns, assumptions)</span></label>' +
              '<textarea id="vote-comment" class="note-textarea pp-comment-ta" rows="2" maxlength="500" placeholder="e.g. This depends on the API contract being finalised first…">' + esc(myComment) + '</textarea>' +
              (d.my_vote != null
                ? '<button class="btn btn--sm btn--outline" onclick="updateVote()" style="margin-top:.35rem;">Update vote &amp; note</button>'
                : '') +
            '</div>' +
            '<div id="pp-vote-ctrl" class="pp-vote-ctrl">' + ctrlHtml + '</div>';
        return;
    }

    if (d.phase === 'closed') {
        if (_lastPhase === 'closed') return;
        _lastPhase = 'closed';
        if (!d.is_mod) stopPolling();  // participants: no need to keep polling a closed session
        el.innerHTML =
            '<div class="pp-waiting" style="border-color:var(--color-danger-border);background:var(--color-danger-bg);">' +
              '<div style="font-size:2.5rem;margin-bottom:.5rem;">🔒</div>' +
              '<p style="font-weight:700;color:var(--color-danger);font-size:1rem;">Session closed</p>' +
              '<p style="color:var(--muted);font-size:.875rem;margin-top:.35rem;">The moderator has closed this session. Thank you for participating!</p>' +
              (d.is_mod
                ? '<div style="margin-top:1rem;"><button class="btn btn--outline btn--sm" onclick="doReopen()">↩ Reopen session</button></div>'
                : '') +
            '</div>';
        renderPlayers(d.players, d.phase);
        renderHistory(d.history);
        return;
    }

    if (d.phase === 'revealed') {
        var st = d.stats;
        var revKey = 'revealed|' + (d.story||'');
        if (!SEL_SP && st) SEL_SP = st.sug;
        if (_lastPhase === revKey) return; // already rendered, don't wipe final SP selection
        _lastPhase = revKey;

        var statsHtml = '';
        if (st) {
            var warnHtml = '';
            if (st.sug >= 13) {
                warnHtml = '<div class="pp-stat pp-stat--bad"><div class="pp-stat__label">Flag</div><div class="pp-stat__value" style="font-size:.85rem;">Must split</div></div>';
            } else if (st.sug >= 8) {
                warnHtml = '<div class="pp-stat pp-stat--warn"><div class="pp-stat__label">Flag</div><div class="pp-stat__value" style="font-size:.85rem;">Consider split</div></div>';
            }
            statsHtml =
                '<div class="pp-stats">' +
                  '<div class="pp-stat pp-stat--def"><div class="pp-stat__label">Average</div><div class="pp-stat__value">' + st.avg + '</div></div>' +
                  '<div class="pp-stat pp-stat--acc"><div class="pp-stat__label">Suggested</div><div class="pp-stat__value">' + st.sug + ' SP</div></div>' +
                  '<div class="pp-stat ' + (st.con ? 'pp-stat--ok' : 'pp-stat--bad') + '"><div class="pp-stat__label">Consensus</div><div class="pp-stat__value">' + (st.con ? '✓' : '✗') + '</div></div>' +
                  warnHtml +
                '</div>';

            var distHtml = Object.entries(st.dist)
                .sort(function(a,b){ return +a[0] - +b[0]; })
                .map(function(e) {
                    return '<div class="pp-dist-item ' + spCls(+e[0]) + '">' + e[0] + ' SP<span class="pp-dist-count">×' + e[1] + '</span></div>';
                }).join('');
            statsHtml += '<div class="pp-dist">' + distHtml + '</div>';
        } else {
            statsHtml = '<p style="color:var(--text-secondary);font-size:.825rem;">No votes were cast.</p>';
        }

        var finalHtml = '';
        if (d.is_mod) {
            var fCards = [1,2,3,5,8,13,21].map(function(v) {
                return '<div class="pp-final-card ' + spCls(v) + (SEL_SP == v ? ' selected' : '') +
                       '" onclick="pickFinal(' + v + ')">' + v + '</div>';
            }).join('');
            finalHtml =
                '<div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;padding-top:.5rem;border-top:1px solid var(--divider);">' +
                  '<span style="font-size:.8rem;font-weight:700;">Final SP:</span>' +
                  '<div class="pp-final-cards" id="pp-final-cards">' + fCards + '</div>' +
                  '<button class="btn btn--success" onclick="doSave()">💾 Save &amp; next →</button>' +
                '</div>';
        } else {
            finalHtml = '<p style="font-size:.825rem;color:var(--text-secondary);">Waiting for moderator to finalise…</p>';
        }

        // Build participant vote+comment cards
        var voteCardsHtml = '';
        if (d.players && d.players.length) {
            var voted = d.players.filter(function(p){ return p.vote != null; });
            if (voted.length) {
                voteCardsHtml =
                    '<div class="pp-reveal-section">' +
                    '<p class="pp-reveal-label">Participant Estimates</p>' +
                    '<div class="pp-reveal-grid">' +
                    voted.map(function(p) {
                        return '<div class="pp-reveal-card ' + spCls(p.vote) + (p.is_me ? ' pp-reveal-card--me' : '') + '">' +
                               '<div class="pp-reveal-card__sp">' + p.vote + '</div>' +
                               '<div class="pp-reveal-card__name">' + esc(p.name) + (p.is_me ? ' <span class="pp-you">(you)</span>' : '') + '</div>' +
                               (p.comment ? '<div class="pp-reveal-card__comment">' + esc(p.comment) + '</div>' : '') +
                               '</div>';
                    }).join('') +
                    '</div></div>';
            }
        }

        el.innerHTML =
            '<div class="pp-story"><div class="pp-story__label">Results</div><div class="pp-story__text">' + esc(d.story) + '</div></div>' +
            voteCardsHtml +
            '<div class="pp-results-panel">' +
              statsHtml +
              finalHtml +
            '</div>';
    }
}

function pickFinal(v) {
    SEL_SP = v;
    var fc = document.getElementById('pp-final-cards');
    if (fc) {
        fc.innerHTML = [1,2,3,5,8,13,21].map(function(fv) {
            return '<div class="pp-final-card ' + spCls(fv) + (SEL_SP == fv ? ' selected' : '') +
                   '" onclick="pickFinal(' + fv + ')">' + fv + '</div>';
        }).join('');
    }
}

// ── Players ─────────────────────────────────────────────────────
function renderPlayers(players, phase) {
    var html = players.map(function(p) {
        var chip, comment = '';
        if (phase === 'revealed') {
            chip    = '<div class="pp-vote-chip ' + spCls(p.vote) + '">' + (p.vote != null ? p.vote : '—') + '</div>';
            comment = p.comment
                ? '<div class="pp-player__comment">' + esc(p.comment) + '</div>'
                : '';
        } else {
            chip = '<div class="pp-vote-chip ' + (p.voted ? 'voted' : 'waiting') + '">' + (p.voted ? '✓' : '') + '</div>';
        }
        return '<div class="pp-player ' + (p.is_me ? 'me' : '') + '">' +
               '<div style="flex:1;min-width:0;">' +
               '<div class="pp-player__name">' + esc(p.name) +
               (p.is_me ? ' <small style="color:var(--muted);font-size:.7rem;">(you)</small>' : '') + '</div>' +
               comment +
               '</div>' +
               chip + '</div>';
    }).join('');
    document.getElementById('pp-players').innerHTML = html;
    document.getElementById('pp-player-count').textContent = players.length;
}

// ── History ─────────────────────────────────────────────────────
function parseVotesJson(raw) {
    // Handles both old format {name: sp_int} and new format {name: {sp, comment}}
    if (!raw) return {};
    try {
        var parsed = (typeof raw === 'string') ? JSON.parse(raw) : raw;
        var out = {};
        Object.entries(parsed).forEach(function(e) {
            var val = e[1];
            out[e[0]] = (val !== null && typeof val === 'object')
                ? { sp: val.sp, comment: val.comment || null }
                : { sp: val,    comment: null };
        });
        return out;
    } catch(e) { return {}; }
}

function renderHistory(history) {
    if (!history || !history.length) { document.getElementById('pp-history').innerHTML = ''; return; }
    var sprints = {};
    history.forEach(function(h) {
        if (!sprints[h.sprint]) sprints[h.sprint] = [];
        sprints[h.sprint].push(h);
    });
    var html = '<div class="pp-hist-panel">' +
               '<p class="pp-hist-panel__title">Sprint History</p>';
    Object.entries(sprints).forEach(function(entry) {
        var sprint = entry[0], items = entry[1];
        var total = items.reduce(function(s,h){ return s + (+h.final_sp); }, 0);
        html += '<div class="pp-sprint-group">';
        html += '<div class="pp-sprint-title">' + esc(sprint) + '</div>';
        items.forEach(function(h) {
            var votes = parseVotesJson(h.votes_json);
            var hasComments = Object.values(votes).some(function(v){ return v.comment; });
            html += '<div class="pp-hist-row" style="flex-direction:column;align-items:stretch;">' +
                    '<div style="display:flex;align-items:center;gap:.4rem;">' +
                    '<div class="pp-hist-story" title="' + esc(h.story) + '">' + esc(h.story) + '</div>' +
                    (h.avg_vote ? '<span style="font-size:.7rem;color:var(--muted);">avg ' + h.avg_vote + '</span>' : '') +
                    '<span class="pp-hist-con ' + (h.consensus == '1' ? 'y' : 'n') + '">' + (h.consensus == '1' ? '✓' : '~') + '</span>' +
                    '<span class="pp-hist-sp">' + h.final_sp + ' SP</span>' +
                    '</div>';
            if (hasComments) {
                html += '<div class="pp-hist-comments">' +
                    Object.entries(votes).filter(function(e){ return e[1].comment; }).map(function(e) {
                        return '<div class="pp-hist-comment-row">' +
                               '<span class="pp-hist-comment-name">' + esc(e[0]) + ' (' + e[1].sp + ' SP)</span>' +
                               '<span class="pp-hist-comment-text">' + esc(e[1].comment) + '</span>' +
                               '</div>';
                    }).join('') +
                    '</div>';
            }
            html += '</div>';
        });
        html += '<div class="pp-total"><span>Total</span><span>' + total + ' SP</span></div>';
        html += '</div>';
    });
    html += '</div>';
    document.getElementById('pp-history').innerHTML = html;
}

// ── Actions ─────────────────────────────────────────────────────
async function startStory() {
    var inp = document.getElementById('pp-story-in');
    if (!inp || !inp.value.trim()) { showToast('Enter a story name first.', 'warning'); return; }
    SEL_SP = null;
    try { await ppApi('start', { code: CODE, story: inp.value.trim() }); await poll(); }
    catch(e) { showToast(e.message, 'error'); }
}

async function castVote(v) {
    // Highlight selected card immediately
    document.querySelectorAll('.pp-card').forEach(function(c) {
        c.classList.toggle('selected', parseInt(c.dataset.sp) === v);
    });
    var commentEl = document.getElementById('vote-comment');
    var comment   = commentEl ? commentEl.value.trim() : '';
    try {
        await ppApi('vote', { code: CODE, vote: v, comment: comment });
        // Show confirmation in comment box
        if (commentEl) commentEl.placeholder = '✓ Voted ' + v + ' SP' + (comment ? ' with note' : ' — add a note and re-submit to update');
        await poll();
    }
    catch(e) { showToast(e.message, 'error'); }
}

// Keyboard support for SP cards (tabindex=0 + role=button)
document.addEventListener('keydown', function(e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('pp-card')) {
        e.preventDefault();
        var sp = parseInt(e.target.dataset.sp);
        if (!isNaN(sp)) castVote(sp);
    }
});

async function updateVote() {
    var commentEl = document.getElementById('vote-comment');
    var cardSel   = document.querySelector('.pp-card.selected');
    if (!cardSel) { showToast('Select a card first.', 'warning'); return; }
    var v       = parseInt(cardSel.dataset.sp);
    var comment = commentEl ? commentEl.value.trim() : '';
    try {
        await ppApi('vote', { code: CODE, vote: v, comment: comment });
        showToast('Vote updated.', 'success', 1500);
        await poll();
    }
    catch(e) { showToast(e.message, 'error'); }
}

async function doReveal() {
    try { await ppApi('reveal', { code: CODE }); await poll(); }
    catch(e) { showToast(e.message, 'error'); }
}

function confirmClose() {
    if (!confirm('Close this session? Participants will see a closed screen and no more voting will be accepted.\n\nYou can reopen the session afterwards if needed.')) return;
    doClose();
}

async function doClose() {
    try {
        await ppApi('close', { code: CODE });
        showToast('Session closed.', 'success');
        await poll();
    }
    catch(e) { showToast(e.message, 'error'); }
}

async function doReopen() {
    try {
        await ppApi('reopen', { code: CODE });
        showToast('Session reopened.', 'success');
        await poll();
    }
    catch(e) { showToast(e.message, 'error'); }
}

async function doSave() {
    if (!SEL_SP) { showToast('Pick a final SP value first.', 'warning'); return; }
    try { await ppApi('save', { code: CODE, sp: SEL_SP }); SEL_SP = null; await poll(); }
    catch(e) { showToast(e.message, 'error'); }
}

async function changeSprint() {
    var inp = document.getElementById('pp-sprint-in');
    if (!inp || !inp.value.trim()) { showToast('Enter a sprint name.', 'warning'); return; }
    try { await ppApi('sprint', { code: CODE, sprint: inp.value.trim() }); await poll(); }
    catch(e) { showToast(e.message, 'error'); }
}

// ── Init ────────────────────────────────────────────────────────
(async function init() {
    // If admin opened directly from lobby (mod=1), join immediately with admin name
    if (IS_ADMIN_MOD && PREF_NAME) {
        try {
            await ppApi('join', { code: CODE, name: PREF_NAME });
            myName = PREF_NAME;
            joined = true;
            document.getElementById('pp-entry').style.display = 'none';
            document.getElementById('pp-game').style.display  = 'block';
            startPolling();
        } catch(e) {
            showEntry();
        }
    } else if (PREF_NAME) {
        // Pre-filled name from lobby — try auto-join
        try {
            await ppApi('join', { code: CODE, name: PREF_NAME });
            myName = PREF_NAME;
            joined = true;
            document.getElementById('pp-entry').style.display = 'none';
            document.getElementById('pp-game').style.display  = 'block';
            startPolling();
        } catch(e) {
            showEntry();
        }
    } else {
        showEntry();
    }
})();
</script>

<!-- Load app.js for showToast / showConfirm -->
<script src="<?= e(BASE_URL) ?>/assets/js/app.js"></script>
</body>
</html>
