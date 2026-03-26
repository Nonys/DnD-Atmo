// ============================================================
// Navigation builder
// Populates #dm-nav with links, marks the active one, and wires
// the mobile dropdown toggle. CSS lives in theme.css.
// ============================================================
(function () {
  var NAV_LINKS = [
    { href: '/generate.html', html: '&#9881;&nbsp; Generate' },
    { href: '/upload.html',   html: '&#8679;&nbsp; Upload'   },
    { href: '/gallery.html',  html: '&#9783;&nbsp; Gallery'  },
    { href: '/logs.html',     html: '&#9776;&nbsp; Logs'     },
  ];

  function buildNav() {
    var nav = document.getElementById('dm-nav');
    if (!nav) return;

    var path = window.location.pathname;

    // Inject links
    NAV_LINKS.forEach(function (item) {
      var a = document.createElement('a');
      a.href      = item.href;
      a.innerHTML = item.html;
      if (path === item.href || path.endsWith(item.href)) {
        a.className = 'active';
      }
      nav.appendChild(a);
    });

    // Mobile toggle button (CSS shows it only at ≤560px)
    var activeLink = nav.querySelector('a.active');
    var label = activeLink ? activeLink.textContent.trim() : 'Menu';

    var toggle = document.createElement('button');
    toggle.id = 'dm-nav-toggle';
    toggle.innerHTML =
      '<span class="nav-label">' + label + '</span>' +
      '<span class="nav-chevron">&#9662;</span>';

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      nav.classList.toggle('open');
    });

    nav.insertBefore(toggle, nav.firstChild);

    document.addEventListener('click', function (e) {
      if (!nav.contains(e.target)) nav.classList.remove('open');
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', buildNav);
  } else {
    buildNav();
  }
})();

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
