// ============================================================
// Toast notifications
// ============================================================
(function () {
  let container = null;

  function getContainer() {
    if (!container) {
      container = document.createElement('div');
      container.id = 'toast-container';
      document.body.appendChild(container);
    }
    return container;
  }

  window.showToast = function (message, type) {
    type = type || 'info';
    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    const msg = document.createElement('span');
    msg.className = 'toast-msg';
    msg.textContent = message;

    const close = document.createElement('button');
    close.className = 'toast-close';
    close.textContent = '×';
    close.addEventListener('click', function () { toast.remove(); });

    toast.appendChild(msg);
    toast.appendChild(close);
    getContainer().appendChild(toast);
  };
})();
