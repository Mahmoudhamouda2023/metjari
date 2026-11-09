<div class="live-notifications" data-notifications data-url="{{ route(($guard === 'merchant' ? 'merchant' : ($isAdmin ? 'admin' : 'captain')).'.notifications.index') }}" data-read-url="{{ route(($guard === 'merchant' ? 'merchant' : ($isAdmin ? 'admin' : 'captain')).'.notifications.read') }}" data-user="{{ $identity->id }}" data-identity-scope="{{ $guard }}" data-csrf="{{ csrf_token() }}">
    <details><summary aria-label="إشعارات الطلبات">🔔 <span>الإشعارات</span> <b data-notification-count aria-live="polite" aria-atomic="true" hidden>0</b></summary>
        <div class="notification-menu"><strong>آخر الإشعارات غير المقروءة</strong><div data-notification-items></div><button type="button" class="button button-quiet button-small" data-read-notifications>تحديد المعروض والسابق كمقروء</button>
    <button type="button" class="button button-quiet button-small" data-notification-sound aria-pressed="true">كتم صوت الجرس</button>
</div>
    </details>
    <div data-live-toast class="notification-toast" role="status" hidden></div>
    <small data-live-status role="status" aria-live="polite">جارٍ الاتصال…</small>
</div>
@push('scripts')<script src="{{ asset('assets/notifications.js') }}?v={{ config('delivery.release') }}" defer></script>@endpush
