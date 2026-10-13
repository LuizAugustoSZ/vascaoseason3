window.completeDraw = async message => {
  const status = document.createElement('div'); status.className='alert alert-success';status.setAttribute('role','status');status.textContent=message;document.querySelector('main').prepend(status);
  try {
    const data=await requestJSON('sorteador.php?_models=1',{cache:'no-store'});
    document.querySelectorAll('.competition-model').forEach(select=>{
      const value=select.value;select.replaceChildren(new Option('Criar uma nova competição',''));
      data.models.forEach(item=>select.add(new Option(`${item.nome}: próxima edição ${item.proxima_edicao}`,item.id)));
      select.value=value;
    });
    const parent=window.parent;
    if(parent.refreshAdminPane){
      const tab=parent.document.querySelector('.tab-content>.tab-pane.active')?.id.replace('tab-','');
      if(['campeonatos','jogos','mata','supercopa'].includes(tab)) await parent.refreshAdminPane(tab);
      parent.document.querySelectorAll('#tab-campeonatos,#tab-jogos,#tab-mata,#tab-supercopa').forEach(pane=>{if(pane.id!==`tab-${tab}`)pane.dataset.stale='1';});
    }
  } catch(error){status.className='alert alert-warning';status.textContent=`${message} Não foi possível renovar as opções: ${error.message}`;}
};
