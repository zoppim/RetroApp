/**
 * RetroApp — Shared JS v1.9.9
 * In-app notifications: glass-style toasts + modal confirm dialogs
 * Replaces all browser confirm()/alert() calls.
 */
'use strict';

// ── Fetch wrapper ──────────────────────────────────────────────────────────────
async function apiPost(url, data = {}) {
    const form = new FormData();
    for (const [k, v] of Object.entries(data)) form.append(k, v);
    const res  = await fetch(url, {
        method: 'POST',
        body: form,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    return res.json();
}

// ═══════════════════════════════════════════════════════════════════════════════
//  IN-APP TOAST  (bottom-right, glass style)
// ═══════════════════════════════════════════════════════════════════════════════
(function initToastContainer() {
    if (document.getElementById('ra-toasts')) return;
    const el = document.createElement('div');
    el.id = 'ra-toasts';
    el.setAttribute('aria-live', 'polite');
    el.style.cssText =
        'position:fixed;bottom:1.5rem;right:1.25rem;z-index:99999;' +
        'display:flex;flex-direction:column;gap:.45rem;pointer-events:none;' +
        'max-width:340px;width:calc(100vw - 2.5rem);';
    document.body.appendChild(el);
})();

function showToast(message, type, duration) {
    type     = type     || 'info';
    duration = duration || 3200;

    const themes = {
        success: { bg:'rgba(220,252,231,.92)', border:'rgba(134,239,172,.7)', color:'#166534',  icon:'✓' },
        error:   { bg:'rgba(254,226,226,.92)', border:'rgba(252,165,165,.7)', color:'#991b1b',  icon:'✕' },
        warning: { bg:'rgba(254,243,199,.92)', border:'rgba(252,211,77,.7)',  color:'#92400e',  icon:'⚠' },
        info:    { bg:'rgba(238,242,255,.92)', border:'rgba(165,180,252,.7)', color:'#3730a3',  icon:'ℹ' },
    };
    const t = themes[type] || themes.info;

    const toast = document.createElement('div');
    toast.style.cssText =
        'background:' + t.bg + ';' +
        'border:1px solid ' + t.border + ';' +
        'color:' + t.color + ';' +
        'backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);' +
        'padding:.65rem .9rem;border-radius:12px;font-size:.875rem;font-weight:500;' +
        'box-shadow:0 4px 20px rgba(0,0,0,.12),inset 0 1px 0 rgba(255,255,255,.6);' +
        'display:flex;align-items:flex-start;gap:.5rem;pointer-events:auto;' +
        'animation:raToastIn .22s cubic-bezier(.34,1.56,.64,1);' +
        'line-height:1.4;';

    const icon = document.createElement('span');
    icon.style.cssText = 'font-weight:700;flex-shrink:0;margin-top:.05rem;';
    icon.textContent = t.icon;

    const msg = document.createElement('span');
    msg.style.cssText = 'flex:1;';
    msg.textContent = message;

    const close = document.createElement('button');
    close.style.cssText =
        'background:none;border:none;cursor:pointer;opacity:.5;padding:0;' +
        'font-size:.875rem;line-height:1;color:inherit;flex-shrink:0;margin-left:.25rem;';
    close.textContent = '×';
    close.onclick = () => dismissToast(toast);

    toast.appendChild(icon);
    toast.appendChild(msg);
    toast.appendChild(close);
    document.getElementById('ra-toasts').appendChild(toast);

    // Inject keyframe once
    if (!document.getElementById('ra-toast-style')) {
        const s = document.createElement('style');
        s.id = 'ra-toast-style';
        s.textContent =
            '@keyframes raToastIn{from{opacity:0;transform:translateX(20px) scale(.95)}to{opacity:1;transform:none}}' +
            '@keyframes raToastOut{from{opacity:1;transform:none}to{opacity:0;transform:translateX(20px) scale(.95)}}';
        document.head.appendChild(s);
    }

    const timer = setTimeout(() => dismissToast(toast), duration);
    toast._timer = timer;
}

function dismissToast(toast) {
    if (!toast.parentNode) return;
    clearTimeout(toast._timer);
    toast.style.animation = 'raToastOut .2s ease forwards';
    setTimeout(() => toast.remove(), 200);
}

// ═══════════════════════════════════════════════════════════════════════════════
//  IN-APP CONFIRM MODAL  (replaces browser confirm())
// ═══════════════════════════════════════════════════════════════════════════════
function showConfirm(message, opts) {
    opts = opts || {};
    const confirmText = opts.confirmText || 'Confirm';
    const cancelText  = opts.cancelText  || 'Cancel';
    const danger      = opts.danger !== false;
    const title       = opts.title || null;

    return new Promise(function(resolve) {
        // Backdrop
        const backdrop = document.createElement('div');
        backdrop.style.cssText =
            'position:fixed;inset:0;z-index:99998;' +
            'background:rgba(0,0,0,.35);' +
            'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);' +
            'display:flex;align-items:center;justify-content:center;padding:1.25rem;' +
            'animation:raFadeIn .15s ease;';

        const modal = document.createElement('div');
        modal.style.cssText =
            'background:rgba(255,255,255,.75);' +
            'backdrop-filter:blur(28px) saturate(200%);-webkit-backdrop-filter:blur(28px) saturate(200%);' +
            'border:1px solid rgba(255,255,255,.6);border-top-color:rgba(255,255,255,.9);' +
            'border-radius:20px;padding:1.75rem 1.5rem 1.25rem;' +
            'max-width:380px;width:100%;' +
            'box-shadow:0 20px 60px rgba(0,0,0,.2),inset 0 1px 0 rgba(255,255,255,.7);' +
            'animation:raSlideUp .2s cubic-bezier(.34,1.56,.64,1);';

        // Inject keyframes once
        if (!document.getElementById('ra-modal-style')) {
            const s = document.createElement('style');
            s.id = 'ra-modal-style';
            s.textContent =
                '@keyframes raFadeIn{from{opacity:0}to{opacity:1}}' +
                '@keyframes raSlideUp{from{opacity:0;transform:translateY(16px) scale(.97)}to{opacity:1;transform:none}}';
            document.head.appendChild(s);
        }

        let html = '';
        if (title) {
            html += '<p style="font-size:1rem;font-weight:700;letter-spacing:-.02em;margin-bottom:.6rem;color:#1e293b;">' + escHtmlStr(title) + '</p>';
        }
        html += '<p style="font-size:.9rem;color:#475569;line-height:1.55;margin-bottom:1.35rem;">' + escHtmlStr(message) + '</p>';
        html += '<div style="display:flex;gap:.6rem;justify-content:flex-end;">';
        html += '<button id="ra-cancel-btn" style="' +
            'min-height:40px;padding:0 1rem;border-radius:999px;border:1.5px solid rgba(0,0,0,.12);' +
            'background:rgba(255,255,255,.5);font-size:.875rem;font-weight:600;cursor:pointer;color:#475569;">' +
            escHtmlStr(cancelText) + '</button>';
        html += '<button id="ra-confirm-btn" style="' +
            'min-height:40px;padding:0 1.1rem;border-radius:999px;border:none;' +
            'background:' + (danger ? 'linear-gradient(180deg,#ff5e57,#FF3B30)' : 'linear-gradient(180deg,#6b69e0,#5856D6)') + ';' +
            'color:#fff;font-size:.875rem;font-weight:600;cursor:pointer;' +
            'box-shadow:0 2px 10px ' + (danger ? 'rgba(255,59,48,.35)' : 'rgba(88,86,214,.35)') + ';">' +
            escHtmlStr(confirmText) + '</button>';
        html += '</div>';
        modal.innerHTML = html;

        backdrop.appendChild(modal);
        document.body.appendChild(backdrop);

        function cleanup(result) {
            backdrop.style.animation = 'raFadeIn .15s ease reverse';
            setTimeout(() => backdrop.remove(), 150);
            resolve(result);
        }

        modal.querySelector('#ra-confirm-btn').onclick = () => cleanup(true);
        modal.querySelector('#ra-cancel-btn').onclick  = () => cleanup(false);
        backdrop.addEventListener('click', function(e) { if (e.target === backdrop) cleanup(false); });
        document.addEventListener('keydown', function handler(e) {
            if (e.key === 'Escape') { cleanup(false); document.removeEventListener('keydown', handler); }
            if (e.key === 'Enter')  { cleanup(true);  document.removeEventListener('keydown', handler); }
        });
    });
}

function escHtmlStr(str) {
    const d = document.createElement('div');
    d.textContent = String(str || '');
    return d.innerHTML;
}

// ── Auto-dismiss flash messages ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.flash').forEach(function(el) {
        setTimeout(function() {
            el.style.transition = 'opacity .4s';
            el.style.opacity = '0';
            setTimeout(function() { el.remove(); }, 400);
        }, 4000);
    });

    // Replace all native confirm() on form submits with in-app modal
    document.querySelectorAll('[data-confirm]').forEach(function(el) {
        const msg  = el.dataset.confirm;
        const title = el.dataset.confirmTitle || null;
        const isDanger = el.dataset.confirmDanger !== 'false';
        const btnLabel = el.dataset.confirmBtn || 'Confirm';

        el.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            const ok = await showConfirm(msg, { title, danger: isDanger, confirmText: btnLabel });
            if (ok) {
                // data-confirm-form targets a form by id (for buttons outside their form)
                const formId = el.dataset.confirmForm;
                if (formId) {
                    document.getElementById(formId)?.submit();
                } else {
                    const form = el.closest('form');
                    if (form) { el.removeAttribute('data-confirm'); form.submit(); }
                    else if (el.tagName === 'A') { window.location.href = el.href; }
                }
            }
        });
    });
});
