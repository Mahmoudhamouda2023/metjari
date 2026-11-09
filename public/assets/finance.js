(() => {
    'use strict';
    const root = document.querySelector('[data-finance-root]');
    if (!root) return;
    const feedback = root.querySelector('[data-finance-feedback]');
    const dirty = new Set();
    let writing = false, refreshing = false, queuedRefresh = false, revision = 0, timer;
    function tell(message, url) {
        feedback.className = 'notice';
        feedback.replaceChildren(document.createTextNode(message));
        if (url) {
            const target = new URL(url, location.origin);
            if (target.origin === location.origin) {
                const link = document.createElement('a');
                link.href = target.href; link.textContent = ' فتح الكشف'; link.className = 'text-link';
                feedback.append(link);
            }
        }
    }
    async function refresh() {
        if (refreshing || writing) { queuedRefresh = true; return; }
        const startedAt = revision;
        refreshing = true;
        try {
            const response = await fetch(root.dataset.liveUrl, {headers: {Accept: 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok || response.redirected) throw new Error('refresh');
            const data = await response.json();
            if (writing || startedAt !== revision) return;
            const doc = new DOMParser().parseFromString(data.html, 'text/html');
            root.querySelectorAll('[data-finance-region]').forEach(region => {
                if (region.contains(document.activeElement) || [...dirty].some(form => region.contains(form))) return;
                const fresh = doc.querySelector('[data-finance-region="' + region.dataset.financeRegion + '"]');
                if (fresh) region.replaceChildren(...fresh.childNodes);
            });
        } finally {
            refreshing = false;
            if (queuedRefresh && !writing) { queuedRefresh = false; refresh().catch(() => {}); }
        }
    }
    root.addEventListener('input', event => { if (event.target.form) dirty.add(event.target.form); });
    root.addEventListener('change', event => { if (event.target.form) dirty.add(event.target.form); });
    root.addEventListener('submit', async event => {
        const form = event.target.closest('form[data-finance-action]');
        if (!form) return;
        event.preventDefault();
        if (writing) return;
        writing = true; revision++;
        const buttons = [...form.querySelectorAll('button[type="submit"],button:not([type])')];
        buttons.forEach(button => { button.disabled = true; });
        const controller = new AbortController(), timeout = setTimeout(() => controller.abort(), 20000);
        let saved = false;
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}, credentials: 'same-origin', signal: controller.signal});
            if (response.redirected) throw new Error('session');
            if (response.status === 429) { tell('تم تجاوز حد المحاولات. لم تُنفّذ هذه المحاولة؛ راجع السجل وانتظر قليلًا.'); return; }
            const data = await response.json();
            if (!response.ok) {
                if (response.status === 422) {
                    tell(Object.values(data.errors || {}).flat().join(' ') || 'راجع الرصيد والطلبات قبل المحاولة.');
                } else { throw new Error('save'); }
                return;
            }
            saved = true; dirty.delete(form);
            document.activeElement?.blur();
            tell(data.message, data.receipt_url);
        } catch (_) {
            // The server may have committed before a timeout. Never retry a financial POST automatically.
            tell('تعذّر تأكيد نتيجة الطلب. راجع أحدث الكشوف والرصيد قبل إعادة الإرسال.');
        } finally {
            clearTimeout(timeout); writing = false; revision++;
            buttons.forEach(button => { button.disabled = false; });
            try { await refresh(); } catch (_) { if (saved) tell('تم الحفظ، وتعذّر تحديث الرصيد الآن. افتح سجل التسويات للتحقق.'); }
        }
    });
    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            if (!document.hidden) { try { await refresh(); } catch (_) {} }
            schedule();
        }, 15000);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh().catch(() => {}); });
    window.addEventListener('pagehide', () => clearTimeout(timer));
    schedule();
})();
