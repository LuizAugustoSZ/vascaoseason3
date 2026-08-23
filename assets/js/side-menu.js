(() => {
    const menu = document.getElementById('site-side-menu');
    const trigger = document.querySelector('.site-mobile-menu-trigger');
    if (!menu) return;
    const closeButtons = document.querySelectorAll('[data-site-menu-close]');
    const sidebarToggle = menu.querySelector('[data-sidebar-toggle]');
    const mobile = () => matchMedia('(max-width: 767.98px)').matches;
    if (!mobile()) menu.setAttribute('aria-hidden', 'false');
    const setMobileOpen = open => { document.body.classList.toggle('site-menu-open', open); trigger?.setAttribute('aria-expanded', String(open)); menu.setAttribute('aria-hidden', String(!open)); };
    const setCollapsed = collapsed => { document.body.classList.toggle('site-nav-collapsed', collapsed); try { sessionStorage.setItem('site-sidebar-state', collapsed ? 'collapsed' : 'expanded'); } catch (error) {} sidebarToggle?.setAttribute('aria-label', collapsed ? 'Expandir menu' : 'Recolher menu'); };
    sidebarToggle?.setAttribute('aria-label', document.body.classList.contains('site-nav-collapsed') ? 'Expandir menu' : 'Recolher menu');
    trigger?.addEventListener('click', () => setMobileOpen(true));
    sidebarToggle?.addEventListener('click', () => mobile() ? setMobileOpen(false) : setCollapsed(!document.body.classList.contains('site-nav-collapsed')));
    closeButtons.forEach(button => button.addEventListener('click', () => setMobileOpen(false)));
    menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => { if (mobile()) setMobileOpen(false); }));
    let restoreExpandedSidebar = false;
    document.addEventListener('show.bs.modal', event => {
        if (event.target?.id !== 'release-notes-modal') return;
        setMobileOpen(false);
        restoreExpandedSidebar = !mobile() && !document.body.classList.contains('site-nav-collapsed');
        if (!mobile()) document.body.classList.add('site-nav-collapsed');
    });
    document.addEventListener('hidden.bs.modal', event => {
        if (event.target?.id !== 'release-notes-modal') return;
        if (restoreExpandedSidebar && !mobile()) document.body.classList.remove('site-nav-collapsed');
        restoreExpandedSidebar = false;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && mobile() && document.body.classList.contains('site-menu-open')) setMobileOpen(false);
    });

    const accountToggle = document.querySelector('[data-account-popover-toggle]');
    const accountPopover = document.getElementById('site-account-popover');
    if (accountToggle && accountPopover) {
        const setAccountOpen = open => {
            document.body.classList.toggle('site-account-open', open);
            accountToggle.setAttribute('aria-expanded', String(open));
            accountPopover.setAttribute('aria-hidden', String(!open));
        };
        accountToggle.addEventListener('click', event => { event.stopPropagation(); setAccountOpen(!document.body.classList.contains('site-account-open')); });
        accountPopover.addEventListener('click', event => event.stopPropagation());
        document.addEventListener('click', () => setAccountOpen(false));
        document.addEventListener('keydown', event => { if (event.key === 'Escape') setAccountOpen(false); });
    }
})();
