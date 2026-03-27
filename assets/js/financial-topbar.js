document.addEventListener('DOMContentLoaded', () => {
  const topbars = document.querySelectorAll('.js-financial-topbar');
  if (!topbars.length) {
    return;
  }

  const applyTheme = (theme) => {
    document.body.classList.toggle('theme-light', theme === 'light');
    try {
      localStorage.setItem('financial-theme', theme);
    } catch (error) {
      void error;
    }
  };

  let savedTheme = 'dark';
  try {
    savedTheme = localStorage.getItem('financial-theme') || 'dark';
  } catch (error) {
    void error;
  }
  applyTheme(savedTheme);

  const closePanels = () => {
    document.querySelectorAll('[data-topbar-toggle]').forEach((button) => {
      button.classList.remove('is-active');
      button.setAttribute('aria-expanded', 'false');
    });
    document.querySelectorAll('.topbar-panel').forEach((panel) => {
      panel.hidden = true;
    });
  };

  document.querySelectorAll('[data-topbar-toggle]').forEach((button) => {
    const panelId = button.getAttribute('aria-controls');
    const panel = panelId ? document.getElementById(panelId) : null;
    if (!panel) {
      return;
    }

    button.addEventListener('click', (event) => {
      event.stopPropagation();
      const isOpen = !panel.hidden;
      closePanels();
      if (!isOpen) {
        panel.hidden = false;
        button.classList.add('is-active');
        button.setAttribute('aria-expanded', 'true');
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (!event.target.closest('.topbar-menu')) {
      closePanels();
    }
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closePanels();
    }
  });

  document.querySelectorAll('[data-notification-item]').forEach((item) => {
    item.addEventListener('click', () => {
      if (item.classList.contains('is-read')) {
        return;
      }

      item.classList.add('is-read');
      const badge = document.querySelector('[data-notification-count]');
      if (!badge) {
        return;
      }

      const nextValue = Math.max(0, (parseInt(badge.textContent || '0', 10) || 0) - 1);
      if (nextValue > 0) {
        badge.textContent = String(nextValue);
      } else {
        badge.remove();
        document.querySelectorAll('.icon-btn.has-badge').forEach((button) => {
          button.classList.remove('has-badge');
        });
      }
    });
  });

  document.querySelectorAll('[data-theme-toggle]').forEach((toggle) => {
    toggle.checked = document.body.classList.contains('theme-light');
    toggle.addEventListener('change', () => {
      applyTheme(toggle.checked ? 'light' : 'dark');
    });
  });

  document.querySelectorAll('[data-settings-placeholder]').forEach((button) => {
    button.addEventListener('click', () => {
      const panel = button.closest('.topbar-panel');
      const note = panel ? panel.querySelector('[data-settings-note]') : null;
      if (note) {
        note.hidden = false;
      }
    });
  });
});
