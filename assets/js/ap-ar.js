/* AP & AR module frontend logic */
(() => {
  const qs = (s, c = document) => c.querySelector(s);
  const qsa = (s, c = document) => Array.from(c.querySelectorAll(s));

  function toggleSidebar() {
    const sidebar = qs('.sidebar');
    if (sidebar) sidebar.classList.toggle('open');
  }

  function initSidebar() {
    qsa('[data-sidebar-toggle]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        toggleSidebar();
      });
    });
  }

  function setStatusBadge(el, status) {
    if (!el) return;
    el.textContent = status;
    el.classList.remove('badge-unpaid', 'badge-partial', 'badge-paid', 'badge-overdue');
    if (status === 'UNPAID') el.classList.add('badge-unpaid');
    if (status === 'PARTIAL') el.classList.add('badge-partial');
    if (status === 'PAID') el.classList.add('badge-paid');
    if (status === 'OVERDUE') el.classList.add('badge-overdue');
  }

  function initTabs() {
    const tabs = qsa('[data-tab]');
    const panels = qsa('[data-tab-content]');
    if (!tabs.length || !panels.length) return;

    const activate = (name) => {
      tabs.forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
      panels.forEach((p) => p.classList.toggle('active', p.dataset.tabContent === name));
    };

    tabs.forEach((tab) => {
      tab.addEventListener('click', (e) => {
        e.preventDefault();
        activate(tab.dataset.tab);
      });
    });

    const pre = tabs.find((t) => t.classList.contains('active'));
    activate(pre ? pre.dataset.tab : tabs[0].dataset.tab);
  }

  function initDetailRows() {
    qsa('[data-toggle-detail]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-toggle-detail');
        const row = id ? qs(`#${id}`) : null;
        if (!row) return;
        row.style.display = row.style.display === 'none' || row.style.display === '' ? 'table-row' : 'none';
      });
    });
  }

  function initPaymentSummary() {
    const select = qs('#paymentPayableSelect');
    const amount = qs('#paymentAmount');
    if (!select || !amount) return;

    const totalEl = qs('#summaryBillTotal');
    const paidEl = qs('#summaryTotalPaid');
    const balEl = qs('#summaryBalance');
    const balEcho = qs('#summaryBalanceEcho');
    const dueEl = qs('#summaryDueDate');
    const statusEl = qs('#summaryStatus');

    const update = () => {
      const opt = select.selectedOptions[0];
      const total = parseFloat(opt?.dataset.total || '0');
      const paid = parseFloat(opt?.dataset.paid || '0');
      const bal = parseFloat(opt?.dataset.balance || '0');
      const due = opt?.dataset.due || '-';
      const status = opt?.dataset.status || 'UNPAID';

      if (totalEl) totalEl.textContent = total.toFixed(2);
      if (paidEl) paidEl.textContent = paid.toFixed(2);
      if (balEl) balEl.textContent = bal.toFixed(2);
      if (balEcho) balEcho.textContent = bal.toFixed(2);
      if (dueEl) dueEl.textContent = due;
      setStatusBadge(statusEl, status);

      amount.max = String(bal);
      if (parseFloat(amount.value || '0') > bal) {
        amount.setCustomValidity('Payment amount cannot exceed remaining balance.');
      } else {
        amount.setCustomValidity('');
      }
    };

    select.addEventListener('change', update);
    amount.addEventListener('input', update);
    update();

    qsa('[data-process-payable]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-process-payable');
        select.value = id || '';
        update();
        const paymentTab = qs('[data-tab="payment-processing"]');
        if (paymentTab) paymentTab.click();
      });
    });
  }

  function initPayableValidation() {
    const form = qs('#addPayableForm');
    if (!form) return;
    form.addEventListener('submit', (e) => {
      const total = parseFloat(qs('#payableTotal')?.value || '0');
      const bill = qs('#billDate')?.value || '';
      const due = qs('#payableDueDate')?.value || '';
      if (!Number.isFinite(total) || total <= 0) {
        e.preventDefault();
        alert('Total amount must be greater than 0.');
        return;
      }
      if (bill && due && due < bill) {
        e.preventDefault();
        alert('Due date must be on or after bill date.');
      }
    });
  }

  function initPrint() {
    const btn = qs('[data-print-statement]');
    if (!btn) return;
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.print();
    });
  }

  function initStatusBadges() {
    qsa('.status-badge').forEach((badge) => setStatusBadge(badge, badge.textContent.trim().toUpperCase()));
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initTabs();
    initDetailRows();
    initPaymentSummary();
    initPayableValidation();
    initPrint();
    initStatusBadges();
  });
})();
