/* A prévia fica no documento principal também quando o sorteador está em um iframe. */
async function openDrawPreview(data, form) {
  const doc = window.parent.document;
  if (!doc.querySelector('[data-draw-styles]')) {
    await Promise.all(['bracket-v5.css', 'draw-preview.css'].map(file => new Promise((resolve, reject) => {
      const link = doc.createElement('link');
      link.rel = 'stylesheet';
      link.href = new URL('../assets/css/' + file, window.location.href).href;
      link.dataset.drawStyles = '1';
      link.onload = resolve;
      link.onerror = () => { link.remove(); reject(new Error('Não foi possível carregar a prévia. Tente novamente.')); };
      doc.head.append(link);
    })));
  }
  const escape = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  const modal = doc.createElement('dialog');
  modal.setAttribute('aria-labelledby', 'draw-preview-title');
  modal.className = 'draw-preview-modal';
  const side = (game, key) => {
    const name = game[`time_${key}`];
    const phase = game[`origem_${key}_fase`];
    const origin = game[`origem_${key}_tipo`] === 'perdedor' ? 'Perdedor' : 'Vencedor';
    const label = name || `${origin} de ${phase} ${game[`origem_${key}_ordem`]}`;
    const shield = game[`escudo_${key}`];
    const image = shield && /^(https?:\/\/|data:image\/|\/?assets\/)/i.test(shield)
      ? `<img style="width:30px;height:30px;object-fit:contain" src="${escape(shield.startsWith('assets/') ? '../' + shield : shield)}" alt="">`
      : `<span class="team-badge">${escape(game[`sigla_${key}`] || (name ? name.slice(0,3) : '?'))}</span>`;
    const position = data.positions.length && game.fase === 'Semifinal' ? data.positions[(Number(game.ordem)-1)*2 + (key === 'a' ? 0 : 1)] : null;
    return `<div class="bracket-team"><div class="bracket-team-info">${image}<span>${escape(label)}${position ? `<small>${position}º do Brasileirão</small>` : ''}</span></div><b>–</b></div>`;
  };
  const phaseHtml = phase => {
    const games = data.games.filter(game => game.fase === phase && Number(game.jogo) === 1);
    if (!games.length) return '';
    const decision = ['Final', 'Terceiro lugar'].includes(phase);
    const trophy = phase === 'Final' && /^data:image\/(png|webp|jpeg);base64,/i.test(data.trophy || '') ? `<img class="bracket-final-trophy" src="${escape(data.trophy)}" alt="Taça">` : '';
    return `<section class="${decision ? 'bracket-decision ' + (phase === 'Final' ? 'bracket-final' : 'bracket-third') : 'bracket-stage'}"><h4>${trophy}${escape(phase)}</h4>${games.map(game => {
      const legs = data.games.filter(item => item.fase === phase && item.ordem === game.ordem).length;
      return `<div class="bracket-game"><small class="bracket-legs">Confronto ${game.ordem} · ${legs === 2 ? 'Ida e volta' : 'Jogo único'}</small>${side(game,'a')}${side(game,'b')}</div>`;
    }).join('')}</section>`;
  };
  modal.innerHTML = `<header class="draw-preview-header"><small class="eyebrow">Prévia do sorteio</small><h2 id="draw-preview-title" tabindex="-1" autofocus>${escape(data.name)}</h2><p>Confira os jogos antes de confirmar. A competição só será criada após sua confirmação.</p>${data.positions.length ? '<p class="text-warning">No G4, as posições sorteadas ficam mantidas. Os clubes acompanham a classificação até o início da semifinal.</p>' : ''}</header><div class="draw-preview-body"><div class="bracket">${['Preliminar','Oitavas','Quartas','Semifinal'].map(phaseHtml).join('')}<div class="bracket-stage bracket-stage--decisions">${['Final','Terceiro lugar'].map(phaseHtml).join('')}</div></div></div><footer class="draw-preview-footer"><p role="alert" class="text-warning" data-error></p><div><button type="button" class="btn btn-outline-light" data-cancel>Cancelar</button><button type="button" class="btn btn-danger" data-confirm>Confirmar sorteio</button></div></footer>`;
  doc.body.append(modal);
  modal.showModal();
  let busy = false;
  const submit = async action => {
    if (busy) return;
    busy = true;
    modal.querySelectorAll('button').forEach(button => button.disabled = true);
    const payload = new FormData();
    payload.set('csrf', form.querySelector('[name="csrf"]').value);
    payload.set('_ajax', '1');
    payload.set('action', action);
    payload.set('draw_token', data.token);
    try {
      const response = await fetch('sorteador.php', {method:'POST', body:payload, credentials:'same-origin'});
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Não foi possível concluir. Tente novamente.');
      modal.close();
      modal.remove();
      if (action !== 'cancel_preview') { alert(result.message); window.parent.location.reload(); }
    } catch (error) {
      modal.querySelector('[data-error]').textContent = error.message;
    } finally {
      busy = false;
      modal.querySelectorAll('button').forEach(button => button.disabled = false);
    }
  };
  modal.querySelector('[data-confirm]').onclick = () => submit('confirm_preview');
  modal.querySelector('[data-cancel]').onclick = () => submit('cancel_preview');
  modal.addEventListener('cancel', event => { event.preventDefault(); submit('cancel_preview'); });
}
