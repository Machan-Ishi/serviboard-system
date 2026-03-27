(() => {
  const qs = (s, c = document) => c.querySelector(s);
  const qsa = (s, c = document) => Array.from(c.querySelectorAll(s));

  function setBadge(el, status) {
    if (!el) return;
    const s = String(status || 'UNPAID').toUpperCase();
    el.textContent = s;
    el.classList.remove('badge-unpaid', 'badge-partial', 'badge-paid', 'badge-overdue');
    if (s === 'PAID') el.classList.add('badge-paid');
    else if (s === 'PARTIAL') el.classList.add('badge-partial');
    else if (s === 'OVERDUE') el.classList.add('badge-overdue');
    else el.classList.add('badge-unpaid');
  }

  function initTabs() {
    const buttons = qsa('[data-tab]');
    const panels = qsa('[data-tab-content]');
    if (!buttons.length || !panels.length) return;

    const initial = document.body.dataset.activeTab || buttons[0].dataset.tab;
    const activate = (tab) => {
      buttons.forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
      panels.forEach((p) => p.classList.toggle('active', p.dataset.tabContent === tab));
    };

    buttons.forEach((b) => b.addEventListener('click', () => activate(b.dataset.tab)));
    activate(initial);
  }

  function initRecordPayment() {
    const clientSel = qs('#paymentClient');
    const invoiceSel = qs('#paymentInvoice');
    const amountInput = qs('#paymentAmount');
    const totalEl = qs('#rpInvoiceTotal');
    const paidEl = qs('#rpTotalPaid');
    const balEl = qs('#rpBalance');
    const statusEl = qs('#rpStatus');

    if (!clientSel || !invoiceSel) return;

    const allOptions = qsa('option', invoiceSel).map((o) => o.cloneNode(true));

    const renderInvoiceOptions = () => {
      const cid = clientSel.value;
      invoiceSel.innerHTML = '';
      const first = document.createElement('option');
      first.value = '';
      first.textContent = 'Select invoice';
      invoiceSel.appendChild(first);

      allOptions.forEach((opt) => {
        if (!opt.value) return;
        if (cid && opt.dataset.clientId !== cid) return;
        invoiceSel.appendChild(opt.cloneNode(true));
      });
      updateSummary();
    };

    const updateSummary = () => {
      const selected = invoiceSel.selectedOptions[0];
      if (!selected || !selected.value) {
        if (totalEl) totalEl.textContent = '0.00';
        if (paidEl) paidEl.textContent = '0.00';
        if (balEl) balEl.textContent = '0.00';
        setBadge(statusEl, 'UNPAID');
        if (amountInput) amountInput.removeAttribute('max');
        return;
      }

      const total = parseFloat(selected.dataset.total || '0');
      const paid = parseFloat(selected.dataset.paid || '0');
      const bal = parseFloat(selected.dataset.balance || '0');
      const status = selected.dataset.status || 'UNPAID';

      if (totalEl) totalEl.textContent = total.toFixed(2);
      if (paidEl) paidEl.textContent = paid.toFixed(2);
      if (balEl) balEl.textContent = bal.toFixed(2);
      setBadge(statusEl, status);
      if (amountInput) amountInput.max = String(Math.max(0, bal));
    };

    const form = qs('#recordPaymentForm');
    if (form) {
      form.addEventListener('submit', (e) => {
        const selected = invoiceSel.selectedOptions[0];
        if (!selected || !selected.value) return;
        const max = parseFloat(selected.dataset.balance || '0');
        const val = parseFloat(amountInput?.value || '0');
        if (!Number.isFinite(val) || val <= 0 || val > max) {
          e.preventDefault();
          alert('Payment amount must be greater than 0 and not exceed remaining balance.');
        }
      });
    }

    clientSel.addEventListener('change', renderInvoiceOptions);
    invoiceSel.addEventListener('change', updateSummary);
    if (amountInput) amountInput.addEventListener('input', () => {
      const max = parseFloat(amountInput.max || '0');
      const val = parseFloat(amountInput.value || '0');
      if (Number.isFinite(max) && Number.isFinite(val) && val > max) {
        amountInput.setCustomValidity('Amount cannot exceed remaining balance.');
      } else {
        amountInput.setCustomValidity('');
      }
    });

    renderInvoiceOptions();
  }

  function initInvoiceViewActions() {
    const totalEl = qs('#rpInvoiceTotal');
    const paidEl = qs('#rpTotalPaid');
    const balEl = qs('#rpBalance');
    const statusEl = qs('#rpStatus');
    qsa('[data-view-invoice]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (totalEl) totalEl.textContent = parseFloat(btn.dataset.total || '0').toFixed(2);
        if (paidEl) paidEl.textContent = parseFloat(btn.dataset.paid || '0').toFixed(2);
        if (balEl) balEl.textContent = parseFloat(btn.dataset.balance || '0').toFixed(2);
        setBadge(statusEl, btn.dataset.status || 'UNPAID');
      });
    });
  }

  function initReceiptPrint() {
    const printBtn = qs('[data-print-receipt]');
    if (!printBtn) return;
    printBtn.addEventListener('click', () => window.print());
  }

  function initConfirms() {
    qsa('[data-confirm]').forEach((el) => {
      el.addEventListener('submit', (e) => {
        const msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) e.preventDefault();
      });
      el.addEventListener('click', (e) => {
        if (el.tagName === 'FORM') return;
        const msg = el.getAttribute('data-confirm') || 'Are you sure?';
        if (!window.confirm(msg)) e.preventDefault();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initTabs();
    initRecordPayment();
    initInvoiceViewActions();
    initReceiptPrint();
    initConfirms();
  });
})();
