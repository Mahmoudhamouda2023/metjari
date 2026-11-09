(() => {
  'use strict';
  const booted = new WeakSet();
  function boot(scope = document) {
    const once = selector => [...scope.querySelectorAll(selector)].filter(node => { if (booted.has(node)) return false; booted.add(node); return true; });
once('[name="coupon_code"]').forEach(coupon => {
  const form = coupon.closest('form');
  const payer = form?.querySelector('[name="fee_payer"]');
  if (!payer) return;
  const recipient = payer.querySelector('option[value="recipient"]');
  const hint = form.querySelector('[data-coupon-payer-hint]');
  const refresh = () => {
    const applied = coupon.value.trim() !== '';
    if (applied) {
      payer.value = 'merchant';
      recipient?.remove();
    } else if (recipient && !recipient.parentNode) {
      recipient.hidden = false;
      recipient.disabled = false;
      payer.append(recipient);
    }
    if (hint) hint.hidden = !applied;
  };
  coupon.addEventListener('input', refresh);
  coupon.addEventListener('change', refresh);
  form.addEventListener('submit', refresh);
  window.addEventListener('pageshow', refresh);
  refresh();
});
once('[data-zone-select]').forEach(zone => {
  const target = document.querySelector(`[data-neighborhood-select="${zone.dataset.zoneSelect}"]`);
  if (!target) return;
  const refresh = () => {
    [...target.options].forEach(option => {
      if (!option.value) return;
      const visible = option.dataset.zone === zone.value;
      option.hidden = !visible;
      option.disabled = !visible;
      if (!visible && option.selected) target.value = '';
    });
  };
  zone.addEventListener('change', refresh);
  refresh();
});
const schedule = scope.querySelector('[data-schedule-field]');
if (schedule && !booted.has(schedule)) {
  booted.add(schedule);
  const refresh = () => {
    const enabled = document.querySelector('[name="delivery_mode"]:checked')?.value === 'scheduled';
    schedule.hidden = !enabled;
    const input = schedule.querySelector('input');
    input.disabled = !enabled;
    input.required = enabled;
  };
  document.querySelectorAll('[name="delivery_mode"]').forEach(radio => radio.addEventListener('change', refresh));
  refresh();
}
const candidate = scope.querySelector('[data-transition-select]');
const transition = candidate && !booted.has(candidate) ? candidate : null;
if (transition) booted.add(transition);

if (transition) {
  const form = transition.closest('form');
  const payment = form.querySelector('[data-delivery-payment]');
  const note = form.querySelector('[data-transition-note]');

  const refresh = () => {
    const delivered = transition.value === 'delivered';
    const needsReason = ['failed', 'cancelled', 'office_pending', 'office_received', 'returned']
      .includes(transition.value);

    if (payment) {
      payment.hidden = !delivered;
      payment.querySelectorAll('input,select').forEach(input => {
        input.disabled = !delivered;
      });
    }

    if (note) {
      note.hidden = !needsReason;

      const input = note.querySelector('[name="note"]');
      input.disabled = !needsReason;
      input.required = needsReason;
    }
  };

  transition.addEventListener('change', refresh);
  refresh();
}
if (transition) {
  const payment = document.querySelector('[data-delivery-payment]');
  const refresh = () => {
    if (!payment) return;
    const visible = transition.value === 'delivered';
    payment.hidden = !visible;
    payment.querySelectorAll('input,select').forEach(input => input.disabled = !visible);
  };
  transition.addEventListener('change', refresh);
  refresh();
}
once('.payment-fields').forEach(fields => {
  const method = fields.querySelector('[name="payment_method"]');
  const recipient = fields.querySelector('[name="payment_recipient"]');
  const refresh = () => {
    const digital = method.value !== 'cash';
    if (!digital) recipient.value = 'captain';
    recipient.querySelector('option[value="merchant"]').disabled = !digital;
    fields.querySelectorAll('[name="payment_account"],[name="payment_reference"]').forEach(input => {
      input.required = digital;
      input.closest('.field').hidden = !digital;
    });
  };
  method.addEventListener('change', refresh);
  refresh();
});

// The single collection field follows the selected stage; hidden fields cannot submit stale cash.
if (transition) {
  const block = transition.closest('form').querySelector('[data-collected-field]');
  const syncCash = () => {
    if (!block) return;
    const enabled = ['delivered','failed','returned'].includes(transition.value);
    block.hidden = !enabled;
    block.querySelectorAll('input').forEach(input => { input.disabled = !enabled; input.required = enabled; });
  };
  transition.addEventListener('change', syncCash);
  syncCash();
}

  }
  window.MetjariFormBoot = boot;
  boot();
})();
