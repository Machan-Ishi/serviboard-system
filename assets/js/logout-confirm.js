(() => {
  const LOGOUT_PATH = '/FinancialSM/auth/logout.php';
  const CONFIRM_MESSAGE = 'Are you sure you want to log out?';
  const MODAL_ID = 'logoutConfirmModal';

  function getModal() {
    let modal = document.getElementById(MODAL_ID);
    if (modal) {
      return modal;
    }

    modal = document.createElement('div');
    modal.id = MODAL_ID;
    modal.className = 'sb-modal-backdrop';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('aria-labelledby', 'logoutConfirmTitle');
    modal.innerHTML = `
      <div class="sb-modal-card">
        <div class="sb-modal-head">
          <h3 id="logoutConfirmTitle">Confirm Logout</h3>
          <p>${CONFIRM_MESSAGE}</p>
        </div>
        <div class="sb-modal-actions">
          <button type="button" class="btn subtle" data-logout-cancel>Cancel</button>
          <button type="button" class="btn primary" data-logout-confirm>Log out</button>
        </div>
      </div>
    `;

    document.body.appendChild(modal);
    return modal;
  }

  function openModal(onConfirm) {
    const modal = getModal();
    const confirmBtn = modal.querySelector('[data-logout-confirm]');
    const cancelBtn = modal.querySelector('[data-logout-cancel]');

    const close = () => {
      modal.classList.remove('open');
      document.body.classList.remove('sb-modal-open');
      confirmBtn.removeEventListener('click', confirmAction);
      cancelBtn.removeEventListener('click', close);
      modal.removeEventListener('click', backdropClose);
      document.removeEventListener('keydown', escClose);
    };

    const confirmAction = () => {
      close();
      onConfirm();
    };

    const backdropClose = (event) => {
      if (event.target === modal) {
        close();
      }
    };

    const escClose = (event) => {
      if (event.key === 'Escape') {
        close();
      }
    };

    confirmBtn.addEventListener('click', confirmAction);
    cancelBtn.addEventListener('click', close);
    modal.addEventListener('click', backdropClose);
    document.addEventListener('keydown', escClose);

    modal.classList.add('open');
    document.body.classList.add('sb-modal-open');
    confirmBtn.focus();
  }

  document.addEventListener('click', (event) => {
    const link = event.target.closest(`a[href="${LOGOUT_PATH}"]`);
    if (!link) {
      return;
    }

    event.preventDefault();
    const href = link.getAttribute('href');
    if (!href) {
      return;
    }

    openModal(() => {
      window.location.href = href;
    });
  });
})();
