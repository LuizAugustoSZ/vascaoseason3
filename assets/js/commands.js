// Lista pública dos comandos do DreamTeam organizada por categoria.
const commandGroups = [
  {title:'Principais',items:[['/perfil','Mostra seu perfil ou o perfil de outro usuário.'],['/time','Mostra o seu time.'],['/elenco','Mostra todos os jogadores do elenco.'],['/escalar','Monta seu time titular.'],['/confronto','Desafia outro usuário para uma partida.'],['/rankeada','Gerencia sua participação na rankeada.'],['/scout','Pesquisa e compara cartas.'],['/cofre','Mostra o saldo do clube.'],['/loja','Mostra jogadores em promoção.'],['/caixas','Mostra e abre suas caixas.']]},
  {title:'Elenco e táticas',items:[['/promover','Promove jogadores ao time titular.'],['/remover','Remove ou troca titulares.'],['/show','Exibe estatísticas de um jogador.'],['/favoritar','Marca um jogador como favorito.'],['/treinador','Mostra ou troca o treinador.'],['/esquemas','Salva e alterna esquemas completos.'],['/formacao-custom','Cria link para formação personalizada.'],['/substituicoes','Configura banco e substituições.'],['/preparar','Ativa comissão e preparação.'],['/prancheta','Abre projetos de elenco.'],['/laboratorio','Testa contratações e ajustes.']]},
  {title:'Mercado e inventário',items:[['/contratar','Compra um jogador.'],['/negociar','Vende um jogador.'],['/multisell','Vende vários jogadores.'],['/lojadp','Abre a loja de DreamPoints.'],['/lojaparceiro','Abre a Loja do Parceiro.'],['/transactions','Exibe o histórico de transações.'],['/multiopen','Abre até 20 caixas.'],['/chaves','Gerencia chaves e shards.'],['/pacotes','Mostra pacotes do inventário.'],['/abrirpacote','Abre um pacote.'],['/comprarbilhete','Compra bilhete da loteria.'],['/comprarestadio','Pesquisa ou compra estádio.']]},
  {title:'Partidas e competições',items:[['/penaltis','Inicia uma disputa de pênaltis.'],['/retrospecto','Mostra o retrospecto entre jogadores.'],['/leaderboard','Mostra rankings dos usuários.'],['/bolao','Participa do Bolão da Copa de 2026.']]},
  {title:'Modo Carreira',items:[['/carreira-entrar','Entra no Modo Carreira PvE.'],['/calendario','Mostra os 38 jogos da carreira.'],['/jogar','Joga a próxima partida.'],['/missoes','Mostra missões diárias e semanais.'],['/status','Mostra o status da carreira.'],['/carreira-ajuda','Abre a ajuda do Modo Carreira.'],['/passe','Mostra o Passe Free e Elite.']]},
  {title:'Perfil e utilidades',items:[['/premium','Mostra o plano Premium.'],['/vote','Vota no bot e ganha recompensas.'],['/lembrete','Configura lembretes de /obter e /vote.'],['/earnings','Mostra ganhos de parceiro.'],['/clusters','Mostra dados dos clusters.'],['/ping','Mostra a latência do bot.'],['/help','Mostra os comandos.'],['/info','Mostra os comandos.']]},
  {title:'Configuração do servidor',items:[['/mitadachannel','Define o canal das mitadas.'],['/prefix','Altera o prefixo de comandos.']]}
];

const escapeCommandText = value => $('<div>').text(value ?? '').html();

function renderCommands(query='') {
  const normalized=query.toLocaleLowerCase('pt-BR').trim();
  let found=0;
  const html=commandGroups.map(group=>{
    const items=group.items.filter(([command,description])=>`${command} ${description} ${group.title}`.toLocaleLowerCase('pt-BR').includes(normalized));
    found+=items.length;
    if(!items.length)return '';
    return `<div class="col-md-6 col-xl-4"><article class="command-card"><h3>${escapeCommandText(group.title)}</h3>${items.map(([command,description])=>`<div class="command-item"><code>${escapeCommandText(command)}</code><span>${escapeCommandText(description)}</span></div>`).join('')}</article></div>`;
  }).join('');
  $('#commands-grid').html(html);
  $('#commands-empty').toggleClass('d-none',found>0);
  $('#commands-count').text(`${found} comando${found===1?'':'s'} encontrado${found===1?'':'s'}`);
}

$(function(){
  renderCommands();
  $('#command-search').on('input',function(){renderCommands(this.value)});
});
