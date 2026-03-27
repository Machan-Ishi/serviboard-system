/* Disbursement module frontend */
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

    const current = tabs.find((t) => t.classList.contains('active')) || tabs[0];
    activate(current.dataset.tab);
  }

  function initDetailRows() {
    qsa('[data-toggle-detail]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-toggle-detail');
        const row = target ? qs(`#${target}`) : null;
        if (!row) return;
        row.style.display = row.style.display === 'table-row' ? 'none' : 'table-row';
      });
    });
  }

  function initApprovalActions() {
    const form = qs('#approvalActionForm');
    const actionInput = qs('#approvalActionInput');
    const remarks = qs('#approvalRemarks');
    if (!form || !actionInput) return;

    qsa('[data-approval]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const action = btn.getAttribute('data-approval') || '';
        if (action === 'REJECTED' && (!remarks || !remarks.value.trim())) {
          alert('Remarks are required for rejection.');
          return;
        }
        actionInput.value = action;
        form.submit();
      });
    });
  }

  function initReleaseActions() {
    qsa('[data-release-request]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const id = btn.getAttribute('data-release-request');
        if (!id) return;
        window.location.href = `?tab=release-of-funds&release_request_id=${encodeURIComponent(id)}`;
      });
    });
  }

  function initReleaseValidation() {
    const form = qs('#releaseFundsForm');
    const amount = qs('#releaseAmount');
    if (!form || !amount) return;

    form.addEventListener('submit', (e) => {
      const value = parseFloat(amount.value || '0');
      const max = parseFloat(amount.max || '0');
      if (!Number.isFinite(value) || value <= 0) {
        e.preventDefault();
        alert('Release amount must be greater than 0.');
        return;
      }
      if (Number.isFinite(max) && max > 0 && value > max) {
        e.preventDefault();
        alert('Release amount cannot exceed remaining amount.');
      }
    });
  }

  function initPrintActions() {
    qsa('[data-print-row]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        window.print();
      });
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initTabs();
    initDetailRows();
    initApprovalActions();
    initReleaseActions();
    initReleaseValidation();
    initPrintActions();
  });
})();
