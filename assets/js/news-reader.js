(() => {
    const reader = document.querySelector('[data-article-reader]');
    const toggle = reader?.querySelector('[data-article-theme-toggle]');
    if (!reader || !toggle) return;

    const storageKey = 'season3-news-reader-theme';

    const applyTheme = (theme) => {
        const dark = theme === 'dark';
        reader.classList.toggle('is-dark', dark);
        toggle.setAttribute('aria-pressed', String(dark));
        toggle.setAttribute('aria-label', dark ? 'Ativar modo claro na leitura' : 'Ativar modo escuro na leitura');
        toggle.title = dark ? 'Ativar modo claro' : 'Ativar modo escuro';
    };

    let savedTheme = 'light';
    try {
        savedTheme = localStorage.getItem(storageKey) === 'dark' ? 'dark' : 'light';
    } catch (error) {
        // A leitura segue no modo claro quando o navegador bloqueia o armazenamento local.
    }
    applyTheme(savedTheme);

    toggle.addEventListener('click', () => {
        const theme = reader.classList.contains('is-dark') ? 'light' : 'dark';
        applyTheme(theme);
        try {
            localStorage.setItem(storageKey, theme);
        } catch (error) {
            // O alternador continua funcionando durante a visita atual.
        }
    });
})();
