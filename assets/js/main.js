// Prevent dropdown menus (e.g. the "Manage" three-dots menu in tables) from
// being clipped by scrollable/overflow table wrappers — position them with
// the 'fixed' strategy so they escape any clipping ancestor.
if (window.bootstrap && bootstrap.Dropdown) {
  bootstrap.Dropdown.Default.popperConfig = function (defaultBsPopperConfig) {
    return Object.assign({}, defaultBsPopperConfig, { strategy: 'fixed' });
  };
}

document.addEventListener('DOMContentLoaded', function () {
  // Wide tables (Buy/Sale/Rental/Leads etc.) scroll horizontally natively
  // (touch drag, trackpad, scrollbar drag, shift+wheel). On top of that,
  // let desktop mouse users grab-and-drag anywhere on the table to pan it
  // left/right, without breaking clicks on row buttons/dropdowns or the
  // native scrollbar itself.
  document.querySelectorAll('.table-responsive-modern').forEach(function (wrap) {
    let dragging = false;
    let started = false;
    let startX = 0;
    let startScrollLeft = 0;

    wrap.addEventListener('pointerdown', function (e) {
      // Only pan for an actual mouse (not touch/pen — those already scroll
      // natively), and never when the press starts on something interactive.
      if (e.pointerType !== 'mouse') return;
      if (e.target.closest('a, button, input, select, textarea, .dropdown-menu, .btn')) return;

      started = true;
      dragging = false;
      startX = e.clientX;
      startScrollLeft = wrap.scrollLeft;
    });

    wrap.addEventListener('pointermove', function (e) {
      if (!started) return;
      const dx = e.clientX - startX;

      if (!dragging) {
        // Require a small real movement before treating this as a drag, so
        // a plain click still behaves like a plain click.
        if (Math.abs(dx) < 6) return;
        dragging = true;
        wrap.setPointerCapture(e.pointerId);
        wrap.classList.add('is-panning');
      }

      wrap.scrollLeft = startScrollLeft - dx;
    });

    function endPan(e) {
      if (dragging) wrap.classList.remove('is-panning');
      started = false;
      dragging = false;
    }
    wrap.addEventListener('pointerup', endPan);
    wrap.addEventListener('pointercancel', endPan);
    wrap.addEventListener('pointerleave', endPan);
  });

  // Mobile sidebar toggle — still used by the top navbar's hamburger button.
  const toggleBtn = document.getElementById('sidebarToggle');
  const bottomMenuBtn = document.getElementById('bottomNavMenu');
  const sidebar = document.getElementById('appSidebar');
  const backdrop = document.getElementById('sidebarBackdrop');

  function openSidebar() {
    sidebar?.classList.add('show');
    backdrop?.classList.add('show');
  }
  function closeSidebar() {
    sidebar?.classList.remove('show');
    backdrop?.classList.remove('show');
  }
  window.openSidebar = openSidebar;
  window.closeSidebar = closeSidebar;

  toggleBtn?.addEventListener('click', () => {
    sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
  });
  bottomMenuBtn?.addEventListener('click', () => {
    sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
  });
  backdrop?.addEventListener('click', closeSidebar);

  // Bootstrap tooltips
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
  });

  // Register service worker for PWA-style experience (optional, safe no-op if file absent)
  if ('serviceWorker' in navigator) {
    const base = document.body.dataset.baseUrl || '';
    navigator.serviceWorker.register(base + '/sw.js').catch(() => {});
  }
});
