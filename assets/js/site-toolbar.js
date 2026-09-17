document.addEventListener('DOMContentLoaded', () => {
    const siteRoot = new URL(document.querySelector('[data-site-root]')?.dataset.siteRoot || './', location.href);
    const search = document.querySelector('[data-site-search]');
    const results = document.querySelector('[data-search-results]');
    let timer, controller;

    const searchLink = (item) => {
        const a = document.createElement('a');
        a.href = new URL(item.url, siteRoot).href;
        const strong = document.createElement('strong');
        strong.textContent = item.label || item.title;
        a.append(strong);
        const small = document.createElement('small');
        small.textContent = item.type || item.body || '';
        a.append(small);
        return a;
    };

    search?.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        results.replaceChildren();
        results.hidden = true;
        const q = search.value.trim();
        if (q.length < 2) return;
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch(new URL('api/site-search.php?q=' + encodeURIComponent(q), siteRoot), { signal: controller.signal });
                if (!response.ok) throw Error();
                const items = await response.json();
                results.replaceChildren(...items.map(searchLink));
                if (!items.length) {
                    const empty = document.createElement('div');
                    empty.className = 'p-3 text-center text-secondary small';
                    empty.textContent = 'Nenhum resultado encontrado.';
                    results.append(empty);
                }
                results.hidden = false;
            } catch (error) {
                if (error.name !== 'AbortError') {
                    const err = document.createElement('div');
                    err.className = 'p-3 text-center text-secondary small';
                    err.textContent = 'Não foi possível pesquisar agora.';
                    results.append(err);
                    results.hidden = false;
                }
            }
        }, 250);
    });

    const bell = document.querySelector('[data-notification-toggle]');
    const panel = document.querySelector('[data-notification-panel]');
    const list = document.querySelector('[data-notification-list]');
    const badge = document.querySelector('[data-notification-count]');
    let latest = 0;

    function formatTimeAgo(dateStr) {
        if (!dateStr) return '';
        const date = new Date(dateStr.replace(' ', 'T'));
        const now = new Date();
        const diffMs = now - date;
        if (isNaN(diffMs)) return '';
        const diffMin = Math.floor(diffMs / 60000);
        if (diffMin < 1) return 'agora';
        if (diffMin < 60) return `há ${diffMin} min`;
        const diffHours = Math.floor(diffMin / 60);
        if (diffHours < 24) return `há ${diffHours} ${diffHours === 1 ? 'hora' : 'horas'}`;
        const diffDays = Math.floor(diffHours / 24);
        if (diffDays < 30) return `há ${diffDays} ${diffDays === 1 ? 'dia' : 'dias'}`;
        return `há ${Math.floor(diffDays / 30)} meses`;
    }

    function createNotificationItem(item) {
        const a = document.createElement('a');
        a.className = 'site-notification-item' + (!item.read_at ? ' unread' : '');
        a.href = new URL(item.url || 'notificacoes.php', siteRoot).href;

        const kind = (item.kind || '').toLowerCase();
        let iconName = 'bell';
        let category = 'AVISO';

        if (kind === 'news' || item.title?.toLowerCase().includes('notícia')) {
            iconName = 'newspaper';
            category = 'NOTÍCIA';
        } else if (kind === 'market' || item.title?.toLowerCase().includes('mercado')) {
            iconName = 'chart-no-axes-column-increasing';
            category = 'MERCADO';
        } else if (kind === 'competition' || item.title?.toLowerCase().includes('campeonato')) {
            iconName = 'trophy';
            category = 'COMPETIÇÃO';
        }

        const iconBox = document.createElement('div');
        iconBox.className = 'site-notification-item-icon';
        const iTag = document.createElement('i');
        iTag.setAttribute('data-lucide', iconName);
        iconBox.appendChild(iTag);

        const content = document.createElement('div');
        content.className = 'site-notification-item-content';

        const cat = document.createElement('span');
        cat.className = 'site-notification-item-cat';
        cat.textContent = category;

        const title = document.createElement('strong');
        title.className = 'site-notification-item-title';
        title.textContent = item.title || 'Aviso';

        const desc = document.createElement('span');
        desc.className = 'site-notification-item-desc';
        desc.textContent = item.body || '';

        const time = document.createElement('span');
        time.className = 'site-notification-item-time';
        time.textContent = formatTimeAgo(item.created_at);

        content.append(cat, title, desc, time);
        a.append(iconBox, content);

        if (!item.read_at) {
            const dot = document.createElement('span');
            dot.className = 'site-notification-unread-dot';
            a.appendChild(dot);
        }

        return a;
    }

    function createEmptyState(isHistoryPage = false) {
        const container = document.createElement('div');
        container.className = isHistoryPage ? 'notification-empty-state py-5' : 'notification-empty-state';

        const circle = document.createElement('div');
        circle.className = 'notification-empty-circle';
        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', 'bell');
        circle.appendChild(icon);

        const title = document.createElement('strong');
        title.textContent = isHistoryPage ? 'Tudo tranquilo por aqui' : 'Nenhuma notificação por enquanto';

        const subtitle = document.createElement('span');
        subtitle.textContent = isHistoryPage ? 'Quando houver novos avisos, eles aparecerão aqui.' : 'Você está em dia por enquanto.';

        container.append(circle, title, subtitle);
        return container;
    }

    async function refresh() {
        if (!bell) return;
        try {
            const response = await fetch(new URL('api/notifications.php', siteRoot));
            if (!response.ok) throw Error();
            const data = await response.json();

            const unread = Number(data.unread || 0);
            if (badge) {
                badge.hidden = unread === 0;
                if (unread > 0) {
                    badge.classList.add('has-count');
                    badge.textContent = unread > 99 ? '99+' : String(unread);
                } else {
                    badge.classList.remove('has-count');
                    badge.textContent = '';
                }
            }

            latest = Number(data.items[0]?.id || 0);

            if (list) {
                list.replaceChildren();
                if (!data.items || !data.items.length) {
                    list.appendChild(createEmptyState(false));
                } else {
                    data.items.forEach(item => list.appendChild(createNotificationItem(item)));
                }
            }

            const history = document.querySelector('#notification-history');
            if (history) {
                history.replaceChildren();
                if (!data.items || !data.items.length) {
                    history.appendChild(createEmptyState(true));
                } else {
                    data.items.forEach(item => history.appendChild(createNotificationItem(item)));
                }
            }

            if (window.lucide && typeof window.lucide.createIcons === 'function') {
                window.lucide.createIcons();
            }
        } catch {
            if (list) {
                list.innerHTML = '<div class="p-4 text-center text-secondary small">Não foi possível carregar os avisos.</div>';
            }
        }
    }

    bell?.addEventListener('click', (e) => {
        e.stopPropagation();
        const isOpen = !panel.hidden;
        panel.hidden = isOpen;
        bell.setAttribute('aria-expanded', String(!isOpen));
        if (!isOpen) {
            refresh();
        }
    });

    document.querySelector('[data-notification-read]')?.addEventListener('click', async (e) => {
        e.preventDefault();
        e.stopPropagation();
        const body = new URLSearchParams({ csrf: bell.dataset.csrf || '', through: String(latest) });
        const response = await fetch(new URL('api/notifications.php', siteRoot), { method: 'POST', body });
        if (response.ok) {
            refresh();
        }
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.site-search')) {
            if (results) results.hidden = true;
        }
        if (panel && !panel.hidden && !event.target.closest('.site-notifications')) {
            panel.hidden = true;
            bell?.setAttribute('aria-expanded', 'false');
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (results) results.hidden = true;
            if (panel && !panel.hidden) {
                panel.hidden = true;
                bell?.setAttribute('aria-expanded', 'false');
            }
        }
    });

    document.querySelector('#notification-preferences')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const status = document.querySelector('#preferences-status');
        const submitBtn = event.target.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const response = await fetch(new URL('api/notifications.php', siteRoot), {
                method: 'POST',
                body: new FormData(event.target)
            });
            if (!response.ok) throw Error();
            if (status) {
                status.className = 'alert alert-success d-flex align-items-center gap-2 mt-3';
                status.innerHTML = '<i data-lucide="circle-check"></i> Preferências salvas com sucesso!';
                if (window.lucide) window.lucide.createIcons();
                setTimeout(() => { status.className = 'd-none'; status.innerHTML = ''; }, 4000);
            }
        } catch {
            if (status) {
                status.className = 'alert alert-danger d-flex align-items-center gap-2 mt-3';
                status.innerHTML = '<i data-lucide="circle-x"></i> Não foi possível salvar. Tente novamente.';
                if (window.lucide) window.lucide.createIcons();
            }
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });

    refresh();
    if (bell) {
        setInterval(() => {
            if (!document.hidden) refresh();
        }, 60000);
    }
});

