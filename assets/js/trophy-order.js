(() => {
    const toolbar = document.querySelector('.trophy-order-toolbar');
    if (!toolbar) return;
    const grid = document.querySelector('.trophy-showcase');
    const toggle = document.getElementById('trophy-order-toggle');
    const actions = document.getElementById('trophy-order-actions');
    const save = document.getElementById('trophy-order-save');
    const cancel = document.getElementById('trophy-order-cancel');
    const status = document.getElementById('trophy-order-status');
    let original = [], editing = false, saving = false;
    const cards = () => [...grid.querySelectorAll('.trophy-card')];
    function refresh() {
        const rows = cards();
        grid.classList.toggle('is-ordering', editing);
        toggle.hidden = editing;
        actions.hidden = !editing;
        save.disabled = saving;
        cancel.disabled = saving;
        rows.forEach((card, index) => {
            card.querySelector('.trophy-order-controls').hidden = !editing;
            card.querySelector('.trophy-order-position').textContent = `${index + 1}º`;
            card.querySelector('[data-move="-1"]').disabled = saving || index === 0;
            card.querySelector('[data-move="1"]').disabled = saving || index === rows.length - 1;
        });
    }
    toggle.addEventListener('click', () => {
        original = cards();
        editing = true;
        status.textContent = '';
        refresh();
    });
    cancel.addEventListener('click', () => {
        if (saving) return;
        original.forEach(card => grid.append(card));
        editing = false;
        status.textContent = '';
        refresh();
        toggle.focus();
    });
    grid.addEventListener('click', event => {
        const button = event.target.closest('[data-move]');
        if (!button || !editing || saving) return;
        const card = button.closest('.trophy-card');
        const before = button.dataset.move === '-1';
        const sibling = before ? card.previousElementSibling : card.nextElementSibling;
        if (!sibling) return;
        if (before) grid.insertBefore(card, sibling);
        else grid.insertBefore(sibling, card);
        refresh();
        const focusButton = button.disabled ? card.querySelector('[data-move]:not(:disabled)') : button;
        focusButton?.focus();
    });
    save.addEventListener('click', async () => {
        if (saving) return;
        saving = true;
        status.textContent = 'Salvando…';
        refresh();
        try {
            const response = await fetch('admin/tacas-ordenar.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': toolbar.dataset.csrf},
                body: JSON.stringify({ids: cards().map(card => Number(card.dataset.identityId))})
            });
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'Não foi possível salvar.');
            editing = false;
            status.textContent = result.message;
        } catch (error) {
            status.textContent = error.message || 'Falha de conexão. Tente novamente.';
        } finally {
            saving = false;
            refresh();
            if (!editing) toggle.focus();
        }
    });
})();
