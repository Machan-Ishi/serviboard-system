/* Budget Management module frontend */
(() => {
  const qs = (s, c = document) => c.querySelector(s);
  const qsa = (s, c = document) => Array.from(c.querySelectorAll(s));

  function toggleSidebar() {
    const s = qs('.sidebar');
    if (s) s.classList.toggle('open');
  }

  function initSidebar() {
    qsa('[data-sidebar-toggle]').forEach((btn) => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        toggleSidebar();
      });
    });
  }

  function initTabs() {
    const tabs = qsa('[data-tab]');
    const panels = qsa('[data-tab-content]');
    if (!tabs.length || !panels.length) return;

    const activate = (name) => {
      tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.tab === name));
      panels.forEach((panel) => panel.classList.toggle('active', panel.dataset.tabContent === name));
    };

    tabs.forEach((tab) => {
      tab.addEventListener('click', (e) => {
        e.preventDefault();
        activate(tab.dataset.tab);
      });
    });

    const current = tabs.find((t) => t.classList.contains('active'));
    activate(current ? current.dataset.tab : tabs[0].dataset.tab);
  }

  function initValidation() {
    const start = qs('#budgetStart');
    const end = qs('#budgetEnd');
    if (start && end) {
      const check = () => {
        if (start.value && end.value && end.value < start.value) {
          end.setCustomValidity('End date must be on or after start date.');
        } else {
          end.setCustomValidity('');
        }
      };
      start.addEventListener('change', check);
      end.addEventListener('change', check);
      check();
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initTabs();
    initValidation();
  });
})();
