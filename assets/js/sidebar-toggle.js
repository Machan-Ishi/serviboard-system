(() => {
  const MOBILE_BREAKPOINT = 860;

  function isMobile() {
    return window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT}px)`).matches;
  }

  function resolveLayout(toggleButton) {
    if (toggleButton) {
      const localLayout = toggleButton.closest('.layout');
      if (localLayout) {
        return localLayout;
      }
    }
    return document.querySelector('.layout');
  }

  function toggleSidebar(toggleButton) {
    const layout = resolveLayout(toggleButton);
    if (!layout) {
      return;
    }

    const sidebar = layout.querySelector('.sidebar');
    if (!sidebar) {
      return;
    }

    if (isMobile()) {
      sidebar.classList.toggle('open');
      return;
    }

    sidebar.classList.remove('open');
    layout.classList.toggle('sidebar-collapsed');
  }

  // Backward compatibility for existing inline onclick="showSidebar()"
  window.showSidebar = function showSidebar() {
    toggleSidebar(document.activeElement);
  };

  document.addEventListener(
    'click',
    (event) => {
      const toggleButton = event.target.closest('[data-sidebar-toggle], .menu-btn');
      if (!toggleButton) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      toggleSidebar(toggleButton);
    },
    true
  );

  window.addEventListener('resize', () => {
    if (!isMobile()) {
      return;
    }
    document.querySelectorAll('.layout.sidebar-collapsed').forEach((layout) => {
      layout.classList.remove('sidebar-collapsed');
    });
  });
})();
