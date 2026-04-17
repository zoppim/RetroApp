'use strict';
/**
 * RetroApp Board JS v1.9.9
 *
 * Key improvements:
 *  1. OPTIMISTIC UI  — note appears instantly on submit (before server responds)
 *  2. LIVE POLLING   — get_updates every 3s; injects new notes + syncs vote counts
 *  3. SINGLE POLL    — one API call per cycle covers notes + votes + hidden counts
 */

const API = RETRO.baseUrl + '/api.php';

// ── Client state ───────────────────────────────────────────────────────────────
let myVoteCount   = RETRO.myVoteCount;
let myVoteIds     = new Set(RETRO.myVoteIds.map(Number));
let maxSeenNoteId = RETRO.maxNoteId;          // tracks highest note ID we've rendered
let knownNoteIds  = new Set();                 // IDs already in the DOM (avoids duplicates)
let lastStatus    = RETRO.status;
let pollActive    = false;                     // prevents overlapping poll requests
let tempCounter   = 0;                         // temp IDs for optimistic notes

// ── Tiny helpers ───────────────────────────────────────────────────────────────
const $  = (sel, ctx) => (ctx || document).querySelector(sel);
const $$ = (sel, ctx) => [...(ctx || document).querySelectorAll(sel)];

function esc(str) {
    const d = document.createElement('div');
    d.textContent = String(str);
    return d.innerHTML;
}

// Seed knownNoteIds from server-rendered notes
function seedKnownIds() {
    $$('.note[data-note-id]').forEach(card => {
        const id = Number(card.dataset.noteId);
        if (id > 0) {
            knownNoteIds.add(id);
            if (id > maxSeenNoteId) maxSeenNoteId = id;
        }
    });
}

// ── Vote counter ───────────────────────────────────────────────────────────────
function updateVoteCounter() {
    const el = document.getElementById('votes-remaining');
    if (el) el.textContent = RETRO.maxVotes - myVoteCount;
}

// ── Column note count ──────────────────────────────────────────────────────────
function updateColCount(colId, delta) {
    const el = document.getElementById('col-count-' + colId);
    if (!el) return;
    // Parse only the numeric prefix (might contain "+ hidden" span)
    const current = parseInt(el.textContent) || 0;
    el.textContent = Math.max(0, current + delta);
}

function setHiddenCount(colId, n) {
    const el = document.getElementById('col-count-' + colId);
    if (!el) return;
    const ownCount = (el._ownCount !== undefined) ? el._ownCount : (parseInt(el.textContent) || 0);
    el._ownCount = ownCount;
    if (n > 0) {
        el.innerHTML = `${ownCount} <span style="color:var(--text-secondary);font-size:.65rem;font-weight:400;">(+${n} hidden)</span>`;
    } else {
        el.textContent = String(ownCount);
    }
}

// ── Build note card HTML ───────────────────────────────────────────────────────
function buildNoteHtml({ noteId, colId, content, isMine = true, voteCount = 0, iVoted = false, pending = false, authorName = null }) {
    const isTop   = RETRO.isRevealed && voteCount >= 3;
    const classes = [
        'note',
        'note--new',
        isMine    ? 'note--mine'    : '',
        isTop     ? 'note--top'     : '',
        pending   ? 'note--pending' : '',
    ].filter(Boolean).join(' ');

    const voteSection = RETRO.isRevealed && RETRO.canVote
        ? `<button class="note__vote-btn${iVoted ? ' note__vote-btn--voted' : ''}" data-note-id="${noteId}">
               👍 <span class="vote-count">${voteCount}</span>
           </button>`
        : RETRO.isRevealed
            ? `<span class="note__vote-static">👍 ${voteCount}</span>`
            : '';

    const editArea = (RETRO.canEditNotes && isMine && !RETRO.isRevealed) ? `
        <div class="note__edit-area">
            <textarea class="note__edit-input">${esc(content)}</textarea>
            <div class="note__edit-btns">
                <button class="btn btn--sm btn--primary note-save-btn">Save</button>
                <button class="btn btn--sm btn--ghost note-cancel-btn">Cancel</button>
            </div>
        </div>` : '';

    // Author tag: post-reveal shows name, pre-reveal shows "You" on own notes only
    let authorTag = '';
    if (RETRO.isRevealed && (authorName || isMine)) {
        authorTag = `<span class="note__author">👤 ${isMine ? 'You' : esc(authorName || 'Anonymous')}</span>`;
    } else if (!RETRO.isRevealed && isMine) {
        authorTag = `<span class="note__mine-tag">You</span>`;
    }
    const mineTag = ''; // replaced by authorTag
    const editBtn = (RETRO.canEditNotes && isMine && !RETRO.isRevealed)
        ? `<button class="btn btn--icon note-edit-btn" title="Edit">✏️</button>` : '';
    const delBtn  = (isMine && !RETRO.isRevealed)
        ? `<button class="btn btn--icon note-delete-btn" title="Delete">🗑</button>` : '';

    return `<div class="${classes}" data-note-id="${noteId}" data-col-id="${colId}">
        <div class="note__view"><p class="note__text">${esc(content)}</p></div>
        ${editArea}
        <div class="note__foot">
            <div>${voteSection}</div>
            <div style="display:flex;align-items:center;gap:.3rem;">
                ${mineTag}${editBtn}${delBtn}
            </div>
        </div>
    </div>`;
}

// ── Inject a new note card into its column ─────────────────────────────────────
function injectNote({ id, colId, content, isMine, voteCount = 0, iVoted = false, pending = false, authorName = null }) {
    const container = document.getElementById('col-notes-' + colId);
    if (!container) return null;

    const wrap = document.createElement('div');
    wrap.innerHTML = buildNoteHtml({ noteId: id, colId, content, isMine, voteCount, iVoted, pending, authorName });
    const card = wrap.firstElementChild;
    container.appendChild(card);
    bindNote(card);
    return card;
}

// ── Bind all events to a note card ────────────────────────────────────────────
function bindNote(card) {
    $('.note-edit-btn',   card)?.addEventListener('click', () => enterEdit(card));
    $('.note-cancel-btn', card)?.addEventListener('click', () => exitEdit(card));
    $('.note-save-btn',   card)?.addEventListener('click', () => saveEdit(card));
    $('.note__edit-input',card)?.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') saveEdit(card);
        if (e.key === 'Escape') exitEdit(card);
    });
    $('.note-delete-btn', card)?.addEventListener('click', () => deleteNote(card));
    $('.note__vote-btn',  card)?.addEventListener('click', e => toggleVote(e.currentTarget));
}

function enterEdit(card) {
    $('.note__view', card).style.display = 'none';
    const ea = $('.note__edit-area', card);
    if (!ea) return;
    ea.style.display = 'block';
    const ta = $('.note__edit-input', card);
    ta.focus(); ta.selectionStart = ta.value.length;
}
function exitEdit(card) {
    $('.note__view', card).style.display = '';
    const ea = $('.note__edit-area', card);
    if (ea) ea.style.display = 'none';
}

async function saveEdit(card) {
    const noteId  = Number(card.dataset.noteId);
    const content = $('.note__edit-input', card).value.trim();
    const btn     = $('.note-save-btn', card);
    if (!content) { showToast('Note cannot be empty.', 'warning'); return; }

    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span>';
    const res = await apiPost(API, { action: 'edit_note', room_id: RETRO.roomId, note_id: noteId, content }).catch(() => null);
    btn.disabled = false; btn.innerHTML = 'Save';

    if (!res?.ok) { showToast(res?.error || 'Save failed.', 'error'); return; }
    $('.note__text', card).textContent = content;
    exitEdit(card);
    showToast('Note saved ✓', 'success', 1600);
}

async function deleteNote(card) {
    const ok = await showConfirm('Delete this note?', {
        title: 'Delete Note', confirmText: 'Delete', danger: true
    });
    if (!ok) return;
    const noteId = Number(card.dataset.noteId);
    const colId  = Number(card.dataset.colId);
    const res    = await apiPost(API, { action: 'delete_note', room_id: RETRO.roomId, note_id: noteId }).catch(() => null);
    if (!res?.ok) { showToast(res?.error || 'Delete failed.', 'error'); return; }

    knownNoteIds.delete(noteId);
    card.style.transition = 'opacity .18s, transform .18s';
    card.style.opacity    = '0';
    card.style.transform  = 'scale(.95)';
    setTimeout(() => { card.remove(); updateColCount(colId, -1); }, 180);
}

async function toggleVote(btn) {
    const noteId = Number(btn.dataset.noteId);
    if (!myVoteIds.has(noteId) && myVoteCount >= RETRO.maxVotes) {
        showToast(`Max ${RETRO.maxVotes} votes per session.`, 'warning'); return;
    }
    btn.disabled = true;
    const res = await apiPost(API, { action: 'vote', room_id: RETRO.roomId, note_id: noteId }).catch(() => null);
    btn.disabled = false;
    if (!res?.ok) { showToast(res?.error || 'Vote failed.', 'error'); return; }

    if (res.voted) { myVoteIds.add(noteId); myVoteCount++; btn.classList.add('note__vote-btn--voted'); }
    else           { myVoteIds.delete(noteId); myVoteCount--; btn.classList.remove('note__vote-btn--voted'); }

    const countEl = btn.querySelector('.vote-count');
    if (countEl) countEl.textContent = res.vote_count;
    btn.closest('.note')?.classList.toggle('note--top', res.vote_count >= 3);
    updateVoteCounter();
}

// ═══════════════════════════════════════════════════════════════════════════════
//  ADD NOTE — OPTIMISTIC (instant render, background confirm)
// ═══════════════════════════════════════════════════════════════════════════════
function initNoteForms() {
    $$('.note-form').forEach(form => {
        const colId = Number(form.dataset.colId);
        const ta    = $('textarea', form);
        const btn   = $('button[type=submit]', form);

        // Typing indicators
        let typingTimer = null;
        ta.addEventListener('input', () => {
            if (ta.value.trim().length > 0) {
                sendTyping(colId, 'typing_start');
                clearTimeout(typingTimer);
                typingTimer = setTimeout(() => sendTyping(colId, 'typing_stop'), 3000);
            } else {
                sendTyping(colId, 'typing_stop');
            }
        });
        ta.addEventListener('blur', () => { clearTimeout(typingTimer); sendTyping(colId, 'typing_stop'); });

        // Ctrl/Cmd + Enter shortcut
        ta.addEventListener('keydown', e => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
        });

        form.addEventListener('submit', async e => {
            e.preventDefault();
            const content = ta.value.trim();
            if (!content) return;

            // ── STEP 1: Instant render (optimistic) ─────────────────────────
            ta.value = '';          // clear immediately — feels snappy
            ta.focus();
            sendTyping(colId, 'typing_stop');
            clearTimeout(typingTimer);

            const tempId = `temp-${++tempCounter}`;
            const card   = injectNote({ id: tempId, colId, content, isMine: true, pending: true });
            updateColCount(colId, 1);
            btn.disabled = true;

            // ── STEP 2: Confirm with server in background ───────────────────
            const res = await apiPost(API, {
                action: 'add_note', room_id: RETRO.roomId, column_id: colId, content,
            }).catch(() => null);

            btn.disabled = false;

            if (!res?.ok) {
                // Rollback: remove the optimistic card, restore text
                if (card) {
                    card.style.transition = 'opacity .15s';
                    card.style.opacity    = '0';
                    setTimeout(() => card?.remove(), 150);
                }
                updateColCount(colId, -1);
                ta.value = content; // give back what they typed
                showToast(res?.error || 'Failed to save note.', 'error');
                return;
            }

            // ── STEP 3: Confirm — promote temp to real ID ───────────────────
            if (card) {
                card.dataset.noteId = res.note_id;
                card.classList.remove('note--pending');
            }
            knownNoteIds.add(res.note_id);
            if (res.note_id > maxSeenNoteId) maxSeenNoteId = res.note_id;
        });
    });
}

// ═══════════════════════════════════════════════════════════════════════════════
//  LIVE POLLING — new notes + vote sync every 3 seconds
// ═══════════════════════════════════════════════════════════════════════════════
async function pollUpdates() {
    if (pollActive) return;   // skip if previous call hasn't finished
    pollActive = true;

    try {
        const url = `${API}?action=get_updates&room_id=${RETRO.roomId}&since_id=${maxSeenNoteId}`;
        const res  = await fetch(url, { cache: 'no-store' });
        const data = await res.json();

        if (!data.ok) { pollActive = false; return; }

        // ── Status change ──────────────────────────────────────────────────
        if (data.status && data.status !== lastStatus) {
            const prev  = lastStatus;
            lastStatus  = data.status;
            const msgs  = {
                revealed: ['👁 Notes revealed! Reloading…', 2500],
                closed:   ['🔒 Session closed.', 4000],
                active:   ['✅ Session is live!', 2000],
            };
            const [msg, delay] = msgs[data.status] || [];
            if (msg) { showToast(msg, 'info', delay); setTimeout(() => location.reload(), delay); }
        }

        // ── Inject new notes ───────────────────────────────────────────────
        if (data.new_notes?.length) {
            for (const note of data.new_notes) {
                if (knownNoteIds.has(note.id)) continue;  // already in DOM

                // For pre-reveal retros, skip other people's notes (server shouldn't send them, but guard anyway)
                if (!RETRO.isRevealed && !RETRO.isDailyRoom && !note.is_mine) continue;

                const iVoted = myVoteIds.has(note.id);
                const card   = injectNote({
                    id: note.id, colId: note.column_id, content: note.content,
                    isMine: note.is_mine, voteCount: note.vote_count, iVoted,
                    authorName: note.nickname || null,
                });
                if (card) {
                    knownNoteIds.add(note.id);
                    if (note.id > maxSeenNoteId) maxSeenNoteId = note.id;
                    updateColCount(note.column_id, 1);
                }
            }
        }

        // ── Sync vote counts on already-rendered notes ─────────────────────
        if (data.vote_counts) {
            for (const [noteIdStr, count] of Object.entries(data.vote_counts)) {
                const noteId = Number(noteIdStr);
                const card   = document.querySelector(`.note[data-note-id="${noteId}"]`);
                if (!card) continue;

                const countEl = card.querySelector('.vote-count');
                if (countEl) countEl.textContent = count;

                const voteBtn = card.querySelector('.note__vote-btn');
                if (voteBtn) {
                    voteBtn.classList.toggle('note__vote-btn--voted', myVoteIds.has(noteId));
                }
                card.classList.toggle('note--top', count >= 3);
            }
        }

        // ── Update hidden counts (pre-reveal only) ─────────────────────────
        if (data.hidden_counts) {
            for (const [colIdStr, n] of Object.entries(data.hidden_counts)) {
                setHiddenCount(Number(colIdStr), n);
            }
        }

    } catch (_) {
        // silent — network hiccup, will retry next cycle
    }

    pollActive = false;
}

// ── Typing indicators ──────────────────────────────────────────────────────────
function sendTyping(colId, action) {
    apiPost(API, { action, room_id: RETRO.roomId, column_id: colId }).catch(() => {});
}

async function pollTyping() {
    if (!RETRO.status || RETRO.status === 'closed' || RETRO.status === 'archived') return;
    try {
        const res  = await fetch(`${API}?action=get_typing&room_id=${RETRO.roomId}`, { cache: 'no-store' });
        const data = await res.json();
        if (!data.ok) return;

        $$('.board-col').forEach(col => {
            const colId = Number(col.dataset.colId);
            const ind   = document.getElementById('typing-' + colId);
            const txt   = document.getElementById('typing-text-' + colId);
            if (!ind || !txt) return;

            const names = data.typing[colId] || data.typing[String(colId)] || [];
            if (names.length > 0) {
                txt.textContent = names.join(', ') + (names.length === 1 ? ' is typing…' : ' are typing…');
                ind.classList.add('typing-active');
            } else {
                txt.textContent = '';
                ind.classList.remove('typing-active');
            }
        });
    } catch (_) {}
}

// ═══════════════════════════════════════════════════════════════════════════════
//  INIT
// ═══════════════════════════════════════════════════════════════════════════════
document.addEventListener('DOMContentLoaded', () => {
    // Seed known IDs from server-rendered notes
    seedKnownIds();

    // Bind existing note cards
    $$('.note').forEach(bindNote);

    // Note forms (optimistic submit)
    if (RETRO.canAddNotes) initNoteForms();

    updateVoteCounter();

    // ── Poll schedules ───────────────────────────────────────────────────────
    // Notes + votes: every 1 second. Typing: every 500 ms.
    // Polling pauses automatically when the tab is hidden (Page Visibility API)
    // and resumes immediately when the user returns — no wasted requests.

    let updatesTimer = null;
    let typingTimer2 = null;

    function startPolling() {
        if (updatesTimer) return; // already running
        updatesTimer = setInterval(pollUpdates, 1000);
        typingTimer2 = setInterval(pollTyping,  500);
    }
    function stopPolling() {
        clearInterval(updatesTimer); updatesTimer = null;
        clearInterval(typingTimer2); typingTimer2 = null;
    }

    document.addEventListener('visibilitychange', function() {
        if (document.hidden) {
            stopPolling();
        } else {
            // Resume and fire immediately on return
            startPolling();
            pollUpdates();
            pollTyping();
        }
    });

    // Immediate first polls then start intervals
    startPolling();
    setTimeout(pollUpdates, 100);
    setTimeout(pollTyping,  200);
});
