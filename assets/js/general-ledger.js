/* General Ledger module frontend */
(() => {
  const qs = (s, c = document) => c.querySelector(s);
  const qsa = (s, c = document) => Array.from(c.querySelectorAll(s));

  function initSidebar() {
    qsa('[data-sidebar-toggle]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const sidebar = qs('.sidebar');
        if (sidebar) sidebar.classList.toggle('open');
      });
    });
  }

  function setBadge(el, text) {
    if (!el) return;
    const t = String(text || '').toUpperCase();
    el.textContent = t;
    el.classList.remove('badge-unpaid', 'badge-partial', 'badge-paid', 'badge-overdue');
    if (t.includes('NOT')) el.classList.add('badge-overdue');
    else if (t.includes('BALANCED') || t === 'ACTIVE' || t === 'PUBLISHED') el.classList.add('badge-paid');
    else el.classList.add('badge-unpaid');
  }

  function initTabs() {
    const tabs = qsa('[data-tab]');
    const panels = qsa('[data-tab-content]');
    if (!tabs.length || !panels.length) return;

    const activate = (name) => {
      tabs.forEach((t) => t.classList.toggle('active', t.dataset.tab === name));
      panels.forEach((p) => p.classList.toggle('active', p.dataset.tabContent === name));
    };

    tabs.forEach((tab) => tab.addEventListener('click', () => activate(tab.dataset.tab)));
    const current = tabs.find((t) => t.classList.contains('active')) || tabs[0];
    activate(current.dataset.tab);
  }

  function initAccountNormalBalance() {
    const type = qs('#accountType');
    const normal = qs('#normalBalance');
    if (!type || !normal) return;

    const update = () => {
      const t = type.value;
      if (t === 'Asset' || t === 'Expense') normal.value = 'Debit';
      else if (t) normal.value = 'Credit';
      else normal.value = '';
    };

    type.addEventListener('change', update);
    update();
  }

  function initJournalLines() {
    const body = qs('#journalLinesBody');
    const addBtn = qs('#addJournalLineBtn');
    const saveBtn = qs('#saveJournalBtn');
    const totalDebitEl = qs('#journalTotalDebit');
    const totalCreditEl = qs('#journalTotalCredit');
    const indicator = qs('#journalBalanceIndicator');
    const form = qs('#journalEntryForm');

    if (!body || !addBtn || !saveBtn || !form) return;

    const optionTemplate = qs('.journal-line-row .line-account');

    const createRow = () => {
      const tr = document.createElement('tr');
      tr.className = 'journal-line-row';
      tr.innerHTML = `
        <td></td>
        <td><input class="line-debit" name="line_debit[]" type="number" min="0" step="0.01" value="0"></td>
        <td><input class="line-credit" name="line_credit[]" type="number" min="0" step="0.01" value="0"></td>
        <td class="table-actions"><button class="btn-link danger" type="button" data-remove-line>Remove</button></td>
      `;
      const td = tr.children[0];
      const sel = document.createElement('select');
      sel.name = 'line_account_id[]';
      sel.className = 'line-account';
      sel.required = true;
      if (optionTemplate) sel.innerHTML = optionTemplate.innerHTML;
      td.appendChild(sel);
      return tr;
    };

    const recalc = () => {
      const rows = qsa('.journal-line-row', body);
      let totalDebit = 0;
      let totalCredit = 0;
      let validRows = 0;
      let invalidLine = false;

      rows.forEach((row) => {
        const debitInput = qs('.line-debit', row);
        const creditInput = qs('.line-credit', row);
        const accountInput = qs('.line-account', row);
        const debit = parseFloat(debitInput?.value || '0') || 0;
        const credit = parseFloat(creditInput?.value || '0') || 0;
        const account = accountInput?.value || '';

        if (debit < 0 || credit < 0) invalidLine = true;
        if (account || debit > 0 || credit > 0) {
          if (!account) invalidLine = true;
          if ((debit > 0 && credit > 0) || (debit <= 0 && credit <= 0)) invalidLine = true;
          else validRows++;
        }

        totalDebit += debit;
        totalCredit += credit;
      });

      const balanced = Math.abs(totalDebit - totalCredit) < 0.00001;
      const canSave = validRows >= 2 && balanced && !invalidLine;

      if (totalDebitEl) totalDebitEl.textContent = totalDebit.toFixed(2);
      if (totalCreditEl) totalCreditEl.textContent = totalCredit.toFixed(2);
      setBadge(indicator, canSave ? 'Balanced' : 'Not Balanced');
      saveBtn.disabled = !canSave;
    };

    addBtn.addEventListener('click', () => {
      body.appendChild(createRow());
      recalc();
    });

    body.addEventListener('click', (e) => {
      const target = e.target;
      if (!(target instanceof HTMLElement)) return;
      if (target.matches('[data-remove-line]')) {
        const rows = qsa('.journal-line-row', body);
        if (rows.length <= 2) {
          alert('At least 2 lines are required.');
          return;
        }
        const row = target.closest('.journal-line-row');
        if (row) row.remove();
        recalc();
      }
    });

    body.addEventListener('input', recalc);
    body.addEventListener('change', recalc);

    form.addEventListener('submit', (e) => {
      recalc();
      if (saveBtn.disabled) {
        e.preventDefault();
        alert('Journal entry must be balanced and have at least 2 valid lines.');
      }
    });

    recalc();
  }

  function initPrint() {
    const btn = qs('[data-print-statement]');
    if (!btn) return;
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      window.print();
    });
  }

  function initBadges() {
    qsa('.status-badge').forEach((b) => setBadge(b, b.textContent));
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initTabs();
    initAccountNormalBalance();
    initJournalLines();
    initPrint();
    initBadges();
  });
})();
