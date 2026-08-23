(() => {
    const menu = document.getElementById('site-side-menu');
    const trigger = document.querySelector('.site-menu-trigger');
    if (!menu || !trigger) return;
    const closeButtons = document.querySelectorAll('[data-site-menu-close]');
    const setOpen = open => {
        document.body.classList.toggle('site-menu-open', open);
        trigger.setAttribute('aria-expanded', String(open));
        menu.setAttribute('aria-hidden', String(!open));
        if (open) menu.querySelector('a,button')?.focus();
        else trigger.focus();
    };
    trigger.addEventListener('click', () => setOpen(true));
    closeButtons.forEach(button => button.addEventListener('click', () => setOpen(false)));
    menu.querySelectorAll('a').forEach(link => link.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && document.body.classList.contains('site-menu-open')) setOpen(false);
    });
})();
