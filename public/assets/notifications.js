(() => {
    'use strict';
    const root = document.querySelector('[data-notifications]');
    if (!root) return;
    const status = root.querySelector('[data-live-status]');
    const soundButton = root.querySelector('[data-notification-sound]');
    const itemsRoot = root.querySelector('[data-notification-items]');
    const count = root.querySelector('[data-notification-count]');
    const key = 'metjari-alerts-' + root.dataset.user + '-' + new URL(root.dataset.url).pathname;
    const soundScope = root.dataset.identityScope || (new URL(root.dataset.url).pathname.startsWith('/merchant/') ? 'merchant' : 'staff');
    const muteKey = 'metjari-notification-muted-v1:' + soundScope + ':' + root.dataset.user;
    let audio, pendingBell = false, sound = true, latest = 0, version = null, timer, running = false;
    let items = [], unread = 0, readRevision = 0, repoll = false, bulkReading = false;
    const pendingReads = new Set(), readIds = new Set(), activeTones = new Set();
    let seen = null;
    try {
        const stored = localStorage.getItem(key); if (stored !== null) seen = Number(stored);
        sound = localStorage.getItem(muteKey) !== '1';
    } catch (_) {}
    function syncSoundButton() {
        soundButton.textContent = sound ? 'كتم صوت الجرس' : 'تفعيل صوت الجرس';
        soundButton.setAttribute('aria-pressed', String(sound));
        soundButton.setAttribute('title', sound ? 'صوت الجرس مفعّل — اضغط للكتم' : 'صوت الجرس مكتوم — اضغط للتفعيل');
    }
    function prepareAudio() {
        if (!sound) return null;
        try {
            const Audio = window.AudioContext || window.webkitAudioContext;
            if (!Audio) throw new Error('unsupported');
            if (!audio || audio.state === 'closed') {
                audio = new Audio();
                audio.addEventListener?.('statechange', playPendingBell);
            }
            // Browser autoplay blocking must not stall notification polling or mute the preference.
            Promise.resolve(audio.resume()).then(playPendingBell).catch(() => { status.textContent = 'الصوت مفعّل؛ اضغط داخل الصفحة للسماح بتشغيله'; });
            return audio;
        } catch (_) { status.textContent = 'الصوت غير متاح في هذا المتصفح'; return null; }
    }
    function stopTones() {
        activeTones.forEach(tone => { try { tone.stop(); } catch (_) {} }); activeTones.clear();
    }
    function playPendingBell() {
        if (!sound || !pendingBell || !audio || audio.state !== 'running') return;
        pendingBell = false;
        const context = audio;
        try {
            for (const [hz, offset] of [[880, 0], [660, 0.16]]) {
                const o = context.createOscillator(), g = context.createGain(), t = context.currentTime + offset;
                o.type = 'sine'; o.frequency.value = hz;
                g.gain.setValueAtTime(0, t); g.gain.linearRampToValueAtTime(0.07, t + 0.015);
                g.gain.exponentialRampToValueAtTime(0.001, t + 0.55);
                activeTones.add(o); o.onended = () => activeTones.delete(o);
                o.connect(g); g.connect(context.destination); o.start(t); o.stop(t + 0.6);
            }
        } catch (_) { status.textContent = 'الصوت مفعّل؛ اضغط داخل الصفحة للسماح بتشغيله'; }
    }
    async function bell() {
        if (!sound) return;
        pendingBell = true;
        const context = prepareAudio();
        if (!context || context.state !== 'running') {
            status.textContent = 'الصوت مفعّل؛ اضغط داخل الصفحة للسماح بتشغيله'; return;
        }
        playPendingBell();
    }
    syncSoundButton();
    soundButton.addEventListener('click', async () => {
        sound = !sound;
        try { localStorage.setItem(muteKey, sound ? '0' : '1'); } catch (_) {}
        syncSoundButton();
        if (sound) { prepareAudio(); await bell(); } else { pendingBell = false; stopTones(); }
    });
    document.addEventListener('pointerdown', () => { if (sound) prepareAudio(); }, {passive: true});
    document.addEventListener('keydown', () => { if (sound) prepareAudio(); });
    window.addEventListener('storage', event => {
        if (event.key !== muteKey) return;
        sound = event.newValue !== '1'; syncSoundButton(); if (!sound) { pendingBell = false; stopTones(); }
    });
    function message(text) {
        status.textContent = text;
        const toast = root.querySelector('[data-live-toast]');
        toast.textContent = text; toast.hidden = false;
        setTimeout(() => { toast.hidden = true; }, 8000);
    }
    function renderNotices() {
        count.textContent = String(unread);
        count.hidden = unread === 0;
        itemsRoot.replaceChildren();
        items.forEach(item => {
            let url;
            try { url = new URL(item.url, location.origin); } catch (_) { return; }
            if (url.origin !== location.origin) return;
            const link = document.createElement('a');
            link.href = url.href; link.textContent = item.title;
            link.addEventListener('click', event => openNotice(item, url, event));
            link.addEventListener('auxclick', event => {
                if (event.button === 1) return openNotice(item, url, event);
            });
            itemsRoot.append(link);
        });
        if (!itemsRoot.childNodes.length) itemsRoot.textContent = 'لا توجد إشعارات جديدة';
    }
    async function postRead(body) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 8000);
        try {
            const response = await fetch(root.dataset.readUrl, {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
                body: JSON.stringify(body), signal: controller.signal,
            });
            if (!response.ok || response.redirected) throw new Error('read');
            const result = await response.json();
            if (result.ok !== true) throw new Error('read');
        } finally { clearTimeout(timeout); }
    }
    function queuePoll() {
        repoll = true; clearTimeout(timer);
        if (!running) timer = setTimeout(poll, 0);
    }
    async function openNotice(item, url, event) {
        if (event.defaultPrevented) return;
        const navigate = (event.button === undefined || event.button === 0)
            && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
        if (navigate) event.preventDefault();
        if (bulkReading) {
            if (navigate) message('جارٍ حفظ قراءة الإشعارات، حاول بعد لحظة.');
            return;
        }
        if (pendingReads.has(item.id)) return;
        if (readIds.has(item.id)) {
            if (navigate) location.assign(url.href);
            return;
        }
        // Update the badge before waiting for the network. No sibling notice is changed.
        pendingReads.add(item.id); readRevision++;
        unread = Math.max(0, unread - 1);
        items = items.filter(notice => notice.id !== item.id);
        renderNotices();
        let saved = false;
        try {
            await postRead({ notification_id: item.id });
            readIds.add(item.id); saved = true;
        } catch (_) {
            unread++;
            items = [...items.filter(notice => notice.id !== item.id), item].sort((a, b) => b.id - a.id).slice(0, 20);
            renderNotices();
            message('تعذّر حفظ قراءة الإشعار. حاول فتحه مرة أخرى.');
        } finally {
            pendingReads.delete(item.id); readRevision++;
        }
        if (saved && navigate) {
            // Wait for the save so the destination's first poll sees the correct count.
            location.assign(url.href);
        } else if (!pendingReads.size) queuePoll();
    }
    async function refreshRegions() {
        if (!document.querySelector('[data-live-region]')) return true;
        if (window.getSelection()?.toString() || document.activeElement?.matches('input,select,textarea')) return false;
        const response = await fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
        if (!response.ok || response.redirected) throw new Error('refresh');
        const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
        document.querySelectorAll('[data-live-region]').forEach(region => {
            if (region.contains(document.activeElement)) return;
            const fresh = doc.querySelector('[data-live-region="' + region.dataset.liveRegion + '"]');
            if (fresh) region.replaceChildren(...Array.from(fresh.childNodes));
        });
        return true;
    }
    async function poll() {
        if (running) return;
        running = true;
        let expired = false;
        const revision = readRevision;
        try {
            const response = await fetch(root.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if ([401, 403, 419].includes(response.status) || response.redirected) {
                status.textContent = 'انتهت الجلسة. حدّث الصفحة لتسجيل الدخول'; expired = true; return;
            }
            if (!response.ok) throw new Error('poll');
            const data = await response.json();
            // A poll started before a read must not resurrect that notice or its badge.
            if (revision !== readRevision || pendingReads.size || bulkReading) { repoll = true; return; }
            latest = data.latest; unread = Number(data.unread); items = data.items;
            renderNotices();
            if (seen !== null && latest > seen) {
                await bell();
                message(data.items[0]?.title || 'توجد إشعارات جديدة');
            }
            seen = latest; try { localStorage.setItem(key, String(latest)); } catch (_) {}
            if (version !== data.version && await refreshRegions()) version = data.version;
            status.textContent = 'متصل · تحديث تلقائي';
        } catch (_) { status.textContent = 'الاتصال متقطع · نحاول مجددًا تلقائيًا'; }
        finally {
            running = false; clearTimeout(timer);
            if (!expired) {
                const delay = repoll && !pendingReads.size && !bulkReading ? 0 : (document.hidden ? 30000 : 10000);
                repoll = false; timer = setTimeout(poll, delay);
            }
        }
    }
    root.querySelector('[data-read-notifications]').addEventListener('click', async () => {
        if (bulkReading || pendingReads.size) return;
        bulkReading = true; readRevision++;
        try { await postRead({ through: latest }); }
        catch (_) { message('تعذّر حفظ القراءة. حاول مجددًا'); }
        finally {
            bulkReading = false; readRevision++;
            clearTimeout(timer);
            if (running) repoll = true;
            else poll();
        }
    });
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { clearTimeout(timer); poll(); } });
    window.addEventListener('online', () => { clearTimeout(timer); poll(); });
    window.addEventListener('pagehide', () => clearTimeout(timer));
    poll();
})();
