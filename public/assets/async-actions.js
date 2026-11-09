(() => {
    'use strict';
    document.querySelectorAll('[data-ajax-root]').forEach(root => {
        if (root.dataset.ajaxBound) return;
        root.dataset.ajaxBound = 'true';
        const feedback = root.querySelector('[data-ajax-feedback]');
        let sending = false;
        const tell = text => { feedback.className = 'notice'; feedback.textContent = text; };
        async function refresh() {
            const response = await fetch(location.href, {headers: {'X-Requested-With': 'XMLHttpRequest'}, cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok || response.redirected) throw new Error('refresh');
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = doc.querySelector('[data-ajax-root="' + root.dataset.ajaxRoot + '"] [data-ajax-content]');
            if (!fresh) throw new Error('refresh');
            root.querySelector('[data-ajax-content]').replaceChildren(...fresh.childNodes);
            window.MetjariFormBoot?.(root);
        }
        root.addEventListener('submit', async event => {
            const form = event.target.closest('form[data-ajax-action]');
            if (!form || !root.contains(form)) return;
            event.preventDefault();
            if (sending) return;
            sending = true;
            const buttons = [...form.querySelectorAll('button[type="submit"],button:not([type])')];
            buttons.forEach(button => { button.disabled = true; });
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 20000);
            let reload = false;
            try {
                const response = await fetch(form.action, {method: 'POST', credentials: 'same-origin', headers: {Accept: 'application/json'}, body: new FormData(form), signal: controller.signal});
                if (response.redirected || [401,403,419].includes(response.status)) {
                    tell('انتهت الجلسة أو لا تملك صلاحية هذا الإجراء. حدّث الصفحة وتحقق من حسابك.'); return;
                }
                if (response.status === 429) { tell('تم تجاوز حد المحاولات. انتظر قليلًا وتحقق من السجل قبل إعادة الإرسال.'); return; }
                const data = await response.json();
                if (!response.ok) {
                    if (response.status === 422) { tell(Object.values(data.errors || {}).flat().join(' ') || 'راجع البيانات.'); return; }
                    throw new Error('save');
                }
                reload = true; tell(data.message || 'تم تسجيل الإجراء.');
            } catch (_) {
                // A timed-out POST may already be committed: read state, never replay a write.
                reload = true; tell('تعذّر تأكيد نتيجة الإجراء. راجع الحالة والسجل المحدثين قبل إعادة المحاولة.');
            } finally {
                clearTimeout(timeout);
                if (reload) {
                    try { await refresh(); }
                    catch (_) { tell('تعذّر تحديث التفاصيل. تحقق من الحالة والسجل قبل إعادة إرسال الإجراء.'); }
                }
                sending = false;
                buttons.forEach(button => { button.disabled = false; });
            }
        });
    });
})();
