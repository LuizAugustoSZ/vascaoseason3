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
    menu.querySelectorAll('.site-side-nav li > a').forEach(link => {
        const placeFlyout = () => link.style.setProperty('--sidebar-flyout-top', `${link.getBoundingClientRect().top}px`);
        link.addEventListener('pointerenter', placeFlyout);
        link.addEventListener('focus', placeFlyout);
    });
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

    // Na landing page, acompanha a seção visível e destaca seu atalho na sidebar.
    const landingSections = ['competicao', 'artilharia', 'participantes']
        .map(id => ({ id, section: document.getElementById(id), link: menu.querySelector(`a[href="#${id}"]`) }))
        .filter(item => item.section && item.link);
    if (landingSections.length) {
        let scheduled = false;
        const setLandingActive = activeId => {
            landingSections.forEach(({ id, link }) => {
                const active = id === activeId;
                link.classList.toggle('active', active);
                active ? link.setAttribute('aria-current', 'location') : link.removeAttribute('aria-current');
            });
        };
        const updateLandingActive = () => {
            scheduled = false;
            const probe = Math.min(180, Math.max(90, innerHeight * .28));
            const current = landingSections.find(({ section }) => {
                const rect = section.getBoundingClientRect();
                return rect.top <= probe && rect.bottom > probe;
            });
            setLandingActive(current?.id || '');
        };
        const scheduleLandingUpdate = () => {
            if (scheduled) return;
            scheduled = true;
            requestAnimationFrame(updateLandingActive);
        };
        landingSections.forEach(({ id, link }) => link.addEventListener('click', () => setLandingActive(id)));
        addEventListener('scroll', scheduleLandingUpdate, { passive: true });
        addEventListener('resize', scheduleLandingUpdate);
        addEventListener('hashchange', scheduleLandingUpdate);
        updateLandingActive();
    }

    const accountToggle = document.querySelector('[data-account-popover-toggle]');
    const accountPopover = document.getElementById('site-account-popover');
    if (accountToggle && accountPopover) {
        let accountPinned = false;
        let accountCloseTimer = 0;
        const setAccountOpen = open => {
            document.body.classList.toggle('site-account-open', open);
            accountToggle.setAttribute('aria-expanded', String(open));
            accountPopover.setAttribute('aria-hidden', String(!open));
        };
        const cancelAccountClose = () => clearTimeout(accountCloseTimer);
        const scheduleAccountClose = () => {
            cancelAccountClose();
            if (!accountPinned) accountCloseTimer = setTimeout(() => setAccountOpen(false), 140);
        };
        accountToggle.addEventListener('pointerenter', () => { if (!mobile()) { cancelAccountClose(); setAccountOpen(true); } });
        accountToggle.addEventListener('pointerleave', () => { if (!mobile()) scheduleAccountClose(); });
        accountPopover.addEventListener('pointerenter', cancelAccountClose);
        accountPopover.addEventListener('pointerleave', () => { if (!mobile()) scheduleAccountClose(); });
        accountToggle.addEventListener('click', event => {
            event.stopPropagation();
            accountPinned = !accountPinned;
            cancelAccountClose();
            setAccountOpen(accountPinned || mobile() && !document.body.classList.contains('site-account-open'));
        });
        accountPopover.addEventListener('click', event => event.stopPropagation());
        document.addEventListener('click', () => { accountPinned = false; setAccountOpen(false); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { accountPinned = false; setAccountOpen(false); } });
    }
})();
