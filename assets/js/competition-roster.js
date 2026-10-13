window.initializeCompetitionRoster = function() {
    const positions = ['ATA', 'PD', 'PE', 'MEI', 'MC', 'VOL', 'LE', 'LD', 'ZAG', 'GOL'];
    const positionNames = {ATA:'Atacantes', PD:'Pontas direitas', PE:'Pontas esquerdas', MEI:'Meias ofensivos', MC:'Meias centrais', VOL:'Volantes', LE:'Laterais esquerdos', LD:'Laterais direitos', ZAG:'Zagueiros', GOL:'Goleiros'};
    const normalize = value => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR');
    document.querySelectorAll('[data-roster-form]').forEach(form => {
        const registration = form.dataset.rosterForm === 'registration';
        const cards = [...form.querySelectorAll('.registration-card')];
        const grid = form.querySelector('.registration-grid');
        const labels = {out: 'Não inscrito', reserve: 'Reserva', starter: 'Titular'};
        const counts = () => ({starters: cards.filter(card => card.dataset.state === 'starter').length, reserves: cards.filter(card => card.dataset.state === 'reserve').length});
        const snapshot = () => cards.map(card => card.dataset.state).join(',') + '|' + (form.querySelector('[name="formacao"]')?.value || '') + '|' + (form.querySelector('[name="formacao_custom"]')?.value || '');
        const initial = snapshot();
        const draftKey = 'market-roster:' + document.querySelector('.market-page').dataset.rosterScope + ':' + form.dataset.rosterForm;
        const savedAction = document.querySelector('.market-page').dataset.savedAction || '';
        const cardKey = card => card.querySelector('input[name="titular_geral_id[]"], input[name="titular_id[]"]').value;
        const persistDraft = () => {
            if(!form.isConnected) return;
            try {
                if (snapshot() === initial) { sessionStorage.removeItem(draftKey); return; }
                sessionStorage.setItem(draftKey, JSON.stringify({states:Object.fromEntries(cards.map(card => [cardKey(card), card.dataset.state])), formation:formationSelect?.value, custom:formationCustom?.value, scroll:window.scrollY}));
            } catch (_) {}
        };
        const notify = message => askClubConfirmation(registration ? 'REVISE A INSCRIÇÃO' : 'REVISE A ESCALAÇÃO', message, 'Entendi');
        const sectorNames = {ataque: 'Ataque', meio: 'Meio', defesa: 'Defesa', goleiro: 'Goleiro'};
        const sectorOf = card => ({ATA:'ataque', PD:'ataque', PE:'ataque', MEI:'meio', MC:'meio', VOL:'meio', LE:'defesa', LD:'defesa', ZAG:'defesa', GOL:'goleiro'}[card.dataset.position] || 'meio');
        const formationSelect = form.querySelector('[name="formacao"]');
        const formationCustom = form.querySelector('[name="formacao_custom"]');
        function formationLimits() {
            let value = registration ? form.dataset.savedFormation : formationSelect?.value;
            if (value === '__custom__') value = formationCustom.value.trim().replace(/^([1-9])([1-9])([1-9])$/, '$1-$2-$3');
            const match = (value || '').match(/^([1-9](?:-[1-9]){2,3})/);
            if (!match) return null;
            const lines = match[1].split('-').map(Number);
            if (lines.reduce((a, b) => a + b, 0) !== 10) return null;
            return {ataque:lines[lines.length - 1], meio:lines.slice(1,-1).reduce((a,b) => a+b,0), defesa:lines[0], goleiro:1};
        }
        const sectorCounts = () => cards.filter(card => card.dataset.state === 'starter').reduce((total, card) => {total[sectorOf(card)]++; return total;}, {ataque:0, meio:0, defesa:0, goleiro:0});
        const sectorStatus = document.createElement('div');
        sectorStatus.className = 'roster-sector-status';
        sectorStatus.setAttribute('role', 'status');
        form.querySelector('.registration-counts').after(sectorStatus);
        function updateSectors() {
            const limits = formationLimits();
            const selected = sectorCounts();
            sectorStatus.replaceChildren();
            if (!limits) { sectorStatus.textContent = 'Escolha uma formação válida para conferir os limites por setor.'; return; }
            Object.keys(sectorNames).forEach(sector => {
                const item = document.createElement('span');
                item.textContent = sectorNames[sector] + ' ' + selected[sector] + '/' + limits[sector];
                item.className = selected[sector] > limits[sector] ? 'is-over' : selected[sector] === limits[sector] ? 'is-full' : '';
                sectorStatus.append(item);
            });
            const hint = document.createElement('small');
            hint.textContent = registration ? 'Limites da formação salva: ' + form.dataset.savedFormation + '. Para mudá-la, salve a formação no elenco abaixo.' : 'Limites por setor da formação selecionada. Os demais inscritos ficam na reserva.';
            sectorStatus.append(hint);
        }
        formationSelect?.addEventListener('change', updateCounts);
        formationCustom?.addEventListener('input', updateCounts);
        const tools = document.createElement('div');
        tools.className = 'roster-tools';
        tools.innerHTML = '<label>Buscar jogador<input class="form-control" type="search" placeholder="Nome do jogador" data-roster-search></label><label>Estado<select class="form-select" data-roster-status><option value="all">Todos os estados</option><option value="starter">Titulares</option><option value="reserve">Reservas</option>' + (registration ? '<option value="out">Não inscritos</option>' : '') + '</select></label><label>Ordenar dentro da posição<select class="form-select" data-roster-sort><option value="desc">Maior overall</option><option value="asc">Menor overall</option><option value="name">Nome: A → Z</option></select></label><button class="btn btn-outline-light btn-sm" type="button" data-roster-clear>Limpar filtros</button>';
        const positionFilters = document.createElement('div');
        positionFilters.className = 'roster-position-filters';
        positionFilters.setAttribute('role', 'group');
        positionFilters.setAttribute('aria-label', 'Filtrar por posição, do ataque ao gol');
        const availablePositions = [...positions, ...new Set(cards.map(card => card.dataset.position).filter(position => !positions.includes(position)))];
        ['all', ...availablePositions.filter(position => cards.some(card => card.dataset.position === position))].forEach(position => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.positionFilter = position;
            button.setAttribute('aria-pressed', String(position === 'all'));
            const count = position === 'all' ? cards.length : cards.filter(card => card.dataset.position === position).length;
            button.setAttribute('aria-label', (position === 'all' ? 'Todas as posições' : positionNames[position] || position) + ' (' + count + ')');
            button.textContent = position === 'all' ? 'Todas' : position;
            const total = document.createElement('small');
            total.textContent = count;
            total.setAttribute('aria-hidden', 'true');
            button.append(total);
            positionFilters.append(button);
        });
        const summary = document.createElement('p');
        summary.className = 'roster-filter-summary';
        summary.setAttribute('role', 'status');
        const empty = document.createElement('p');
        empty.className = 'roster-filter-empty';
        empty.textContent = 'Nenhum jogador encontrado. Limpe os filtros para ver o elenco completo.';
        empty.hidden = true;
        grid.before(tools, positionFilters, summary);
        grid.after(empty);
        let selectedPosition = 'all';
        const search = tools.querySelector('[data-roster-search]');
        const status = tools.querySelector('[data-roster-status]');
        const sort = tools.querySelector('[data-roster-sort]');
        function filterAndSort(reorder = true) {
            const focused = document.activeElement;
            const query = normalize(search.value.trim());
            const rank = card => { const index = positions.indexOf(card.dataset.position); return index < 0 ? positions.length : index; };
            const ordered = [...cards].sort((a, b) => {
                const position = rank(a) - rank(b) || a.dataset.position.localeCompare(b.dataset.position, 'pt-BR');
                const overall = sort.value === 'name' ? 0 : (Number(b.dataset.overall) - Number(a.dataset.overall)) * (sort.value === 'asc' ? -1 : 1);
                return position || overall || a.querySelector('.registration-name').textContent.localeCompare(b.querySelector('.registration-name').textContent, 'pt-BR');
            });
            let visible = 0;
            ordered.forEach(card => {
                card.hidden = (selectedPosition !== 'all' && card.dataset.position !== selectedPosition) || (status.value !== 'all' && card.dataset.state !== status.value) || !normalize(card.querySelector('.registration-name').textContent).includes(query);
                if (!card.hidden) visible++;
                if (reorder && grid.children[ordered.indexOf(card)] !== card) grid.insertBefore(card, grid.children[ordered.indexOf(card)] || null);
            });
            summary.textContent = visible + ' de ' + cards.length + ' jogadores · Posição primeiro, do ataque ao gol · Filtros não alteram a seleção';
            empty.hidden = visible !== 0;
            if (grid.contains(focused) && !focused.closest('.registration-card').hidden) focused.focus({preventScroll:true});
        }
        tools.addEventListener('input', filterAndSort);
        tools.addEventListener('change', filterAndSort);
        positionFilters.addEventListener('click', event => {
            const button = event.target.closest('[data-position-filter]');
            if (!button) return;
            selectedPosition = button.dataset.positionFilter;
            positionFilters.querySelectorAll('button').forEach(option => option.setAttribute('aria-pressed', String(option === button)));
            filterAndSort();
        });
        tools.querySelector('[data-roster-clear]').addEventListener('click', () => {
            search.value = ''; status.value = 'all'; sort.value = 'desc'; selectedPosition = 'all';
            positionFilters.querySelectorAll('button').forEach(option => option.setAttribute('aria-pressed', String(option.dataset.positionFilter === 'all')));
            filterAndSort();
        });
        function updateCounts() {
            updateSectors();
            const count = counts();
            form.querySelector('[data-registration-starters]').textContent = count.starters;
            form.querySelector('[data-registration-reserves]').textContent = count.reserves;
            form.querySelector('[data-registration-unsaved]').textContent = snapshot() === initial ? (registration ? 'Inscrição atual' : 'Escalação atual') : 'Alterações não salvas';
        }
        function setState(card, state) {
            card.dataset.state = state;
            const registered = card.querySelector('input[name="inscrito_id[]"]');
            if (registered) registered.checked = state !== 'out';
            const starter = card.querySelector(registration ? 'input[name="titular_geral_id[]"]' : 'input[name="titular_id[]"]');
            starter.checked = state === 'starter';
            if (!registration) starter.dispatchEvent(new Event('change', {bubbles: true}));
            card.querySelector('.registration-badge').textContent = labels[state];
            card.querySelectorAll('[data-registration-state]').forEach(option => option.setAttribute('aria-pressed', String(option.dataset.registrationState === state)));
            delete form.dataset.confirmedSubmit;
        }
        form.addEventListener('click', event => {
            const button = event.target.closest('[data-registration-state]');
            if (!button) return;
            const card = button.closest('.registration-card');
            const state = button.dataset.registrationState;
            if (state === card.dataset.state) return;
            const count = counts();
            if (state === 'starter' && count.starters >= 11) {
                notify('Você já selecionou os 11 titulares. Coloque um titular na reserva antes de escolher outro.');
                return;
            }
            if (state === 'starter') {
                const limits = formationLimits();
                const sector = sectorOf(card);
                if (!limits) { notify('Escolha uma formação válida antes de selecionar os titulares.'); return; }
                if (sectorCounts()[sector] >= limits[sector]) {
                    notify('Limite máximo de ' + sectorNames[sector].toLocaleLowerCase('pt-BR') + ': ' + limits[sector] + '. Coloque um titular desse setor na reserva antes de escolher outro.');
                    return;
                }
            }
            if (registration && state === 'reserve' && count.reserves >= 15) {
                notify('O limite é de 15 reservas. Mude o estado de um reserva antes de escolher outro.');
                return;
            }
            setState(card, state);
            updateCounts();
            filterAndSort(false);
            if (card.hidden) status.focus({preventScroll:true});
            persistDraft();
        });
        form.addEventListener('submit', event => {
            const count = counts();
            const limits = formationLimits();
            const sectors = sectorCounts();
            const validSectors = limits && Object.keys(sectorNames).every(sector => sectors[sector] === limits[sector]);
            if (count.starters === 11 && (!registration || count.reserves <= 15) && validSectors) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            delete form.dataset.confirmedSubmit;
            notify(count.starters !== 11 ? 'Selecione exatamente 11 titulares antes de salvar.' : registration && count.reserves > 15 ? 'A inscrição permite no máximo 15 reservas.' : 'Ajuste os titulares aos limites de ataque, meio, defesa e goleiro da formação.');
        }, true);
        try {
            if (savedAction === form.querySelector('[name="action"]').value) sessionStorage.removeItem(draftKey);
            const draft = JSON.parse(sessionStorage.getItem(draftKey) || 'null');
            if (draft) {
                cards.forEach(card => { const state = draft.states?.[cardKey(card)]; if (state && (registration ? ['out','reserve','starter'] : ['reserve','starter']).includes(state)) setState(card, state); });
                if (formationSelect && [...formationSelect.options].some(option => option.value === draft.formation)) formationSelect.value = draft.formation;
                if (formationCustom && typeof draft.custom === 'string') formationCustom.value = draft.custom;
                requestAnimationFrame(() => window.scrollTo(0, draft.scroll || 0));
            }
        } catch (_) {}
        form.addEventListener('change', persistDraft);
        form.addEventListener('input', persistDraft);
        window.addEventListener('pagehide', persistDraft);
        form.addEventListener('market-player-updated', updateCounts);
        updateCounts();
        filterAndSort();
        const save = form.querySelector('[data-registration-save]');
        if (save) save.disabled = false;
    });
};
document.addEventListener('DOMContentLoaded', window.initializeCompetitionRoster);
