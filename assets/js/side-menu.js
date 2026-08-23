(() => {
    const menu = document.getElementById('site-side-menu');
    const trigger = document.querySelector('.site-menu-trigger');
    if (!menu || !trigger) return;
    const closeButtons = document.querySelectorAll('[data-site-menu-close]');
    const sidebarToggle = menu.querySelector('[data-sidebar-toggle]');
    const mobile = () => matchMedia('(max-width: 767.98px)').matches;
    if (!mobile()) menu.setAttribute('aria-hidden', 'false');
    const setMobileOpen = open => { document.body.classList.toggle('site-menu-open', open); trigger.setAttribute('aria-expanded', String(open)); menu.setAttribute('aria-hidden', String(!open)); };
    const setCollapsed = collapsed => { document.body.classList.toggle('site-nav-collapsed', collapsed); localStorage.setItem('site-sidebar-state', collapsed ? 'collapsed' : 'expanded'); sidebarToggle?.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu'); };
    sidebarToggle?.setAttribute('aria-label', document.body.classList.contains('site-nav-collapsed') ? 'Expandir menu' : 'Recolher menu');
    trigger.addEventListener('click', () => mobile() ? setMobileOpen(true) : setCollapsed(!document.body.classList.contains('site-nav-collapsed')));
    sidebarToggle?.addEventListener('click', () => mobile() ? setMobileOpen(false) : setCollapsed(!document.body.classList.contains('site-nav-collapsed')));
    closeButtons.forEach(button => button.addEventListener('click', () => setMobileOpen(false)));
    menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { if (mobile()) setMobileOpen(false); }));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && mobile() && document.body.classList.contains('site-menu-open')) setMobileOpen(false);
    });
})();
