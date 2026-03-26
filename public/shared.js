// ============================================================
// Toast notifications
// showToast(message, type, link?)
//   type: 'success' | 'error' | 'info'
//   link: optional { href, label } — renders a clickable link inside the toast
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

  window.showToast = function (message, type, link) {
    type = type || 'info';

    const toast = document.createElement('div');
    toast.className = 'toast toast-' + type;

    const msg = document.createElement('span');
    msg.className = 'toast-msg';
    msg.textContent = message;
    toast.appendChild(msg);

    if (link && link.href) {
      const a = document.createElement('a');
      a.href      = link.href;
      a.textContent = link.label || 'View';
      a.target    = '_blank';
      a.className = 'toast-link';
      toast.appendChild(a);
    }

    const close = document.createElement('button');
    close.className = 'toast-close';
    close.textContent = '×';
    close.addEventListener('click', function () { toast.remove(); });
    toast.appendChild(close);

    getContainer().appendChild(toast);
  };
})();
