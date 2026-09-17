(() => {
    const mobile = () => matchMedia('(max-width: 767.98px)').matches;
    const accountToggles = [...document.querySelectorAll('[data-account-popover-toggle]')];
    const accountPopover = document.getElementById('site-account-popover');
    if (accountToggles.length && accountPopover) {
        let accountAnchor = accountToggles[0];
        const positionAccount = () => {
            if (!accountPopover.classList.contains('from-topbar')) return;
            const rect = accountAnchor.getBoundingClientRect();
            const width = accountPopover.offsetWidth;
            const left = Math.max(12, Math.min(rect.right - width, document.documentElement.clientWidth - width - 12));
            accountPopover.style.left = `${left}px`;
            accountPopover.style.right = 'auto';
            accountPopover.style.top = `${rect.bottom + 8}px`;
        };
        addEventListener('resize', positionAccount);
        addEventListener('scroll', positionAccount, true);
        window.visualViewport?.addEventListener('resize', positionAccount);
        new ResizeObserver(positionAccount).observe(document.querySelector('.site-topbar'));
        let accountPinned = false;
        let accountCloseTimer = 0;
        const setAccountOpen = (open, source = null) => {
            document.body.classList.toggle('site-account-open', open);
            if (source) { accountAnchor = source; accountPopover.classList.toggle('from-topbar', source.dataset.accountPopoverOrigin === 'top'); }
            if (open) positionAccount();
            accountToggles.forEach(toggle => toggle.setAttribute('aria-expanded', String(open)));
            accountPopover.setAttribute('aria-hidden', String(!open));
        };
        const cancelAccountClose = () => clearTimeout(accountCloseTimer);
        const scheduleAccountClose = () => {
            cancelAccountClose();
            if (!accountPinned) accountCloseTimer = setTimeout(() => setAccountOpen(false), 140);
        };
        accountToggles.forEach(accountToggle => {
        accountToggle.addEventListener('pointerenter', () => { if (!mobile()) { cancelAccountClose(); setAccountOpen(true, accountToggle); } });
        accountToggle.addEventListener('pointerleave', () => { if (!mobile()) scheduleAccountClose(); });
        accountPopover.addEventListener('pointerenter', cancelAccountClose);
        accountPopover.addEventListener('pointerleave', () => { if (!mobile()) scheduleAccountClose(); });
        accountToggle.addEventListener('click', event => {
            event.stopPropagation();
            accountPinned = !accountPinned;
            cancelAccountClose();
            setAccountOpen(accountPinned || mobile() && !document.body.classList.contains('site-account-open'), accountToggle);
        });
        });
        accountPopover.addEventListener('click', event => event.stopPropagation());
        document.addEventListener('click', () => { accountPinned = false; setAccountOpen(false); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') { accountPinned = false; setAccountOpen(false); } });
    }
})();
