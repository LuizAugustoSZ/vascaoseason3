(() => {
    const menu = document.getElementById('site-side-menu');
    const trigger = document.querySelector('.site-menu-trigger');
    if (!menu || !trigger) return;
    const rail = document.querySelector('.site-menu-rail');
    const closeButtons = document.querySelectorAll('[data-site-menu-close]');
    const mobile = () => matchMedia('(max-width: 767.98px)').matches;
    const setState = (state, focus = true) => {
        document.body.classList.remove('site-menu-rail-open', 'site-menu-open');
        if (state === 'rail') document.body.classList.add('site-menu-rail-open');
        if (state === 'expanded') document.body.classList.add('site-menu-open');
        trigger.setAttribute('aria-expanded', String(state !== 'closed'));
        menu.setAttribute('aria-hidden', String(state !== 'expanded'));
        rail?.setAttribute('aria-hidden', String(state === 'closed'));
        if (focus && state === 'expanded') menu.querySelector('a,button')?.focus();
        if (focus && state === 'closed') trigger.focus();
    };
    trigger.addEventListener('click', () => {
        if (mobile()) return setState('expanded');
        if (document.body.classList.contains('site-menu-rail-open')) return setState('expanded');
        if (document.body.classList.contains('site-menu-open')) return setState('closed');
        setState('rail');
    });
    document.querySelectorAll('[data-menu-expand]').forEach(button => button.addEventListener('click', () => {
        setState('expanded');
        const group = menu.querySelector(`[data-nav-group="${button.dataset.menuSection}"]`);
        group?.scrollIntoView({block: 'start'});
    }));
    closeButtons.forEach(button => button.addEventListener('click', () => setState('closed')));
    menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setState('closed', false)));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && (document.body.classList.contains('site-menu-open') || document.body.classList.contains('site-menu-rail-open'))) setState('closed');
    });
})();
