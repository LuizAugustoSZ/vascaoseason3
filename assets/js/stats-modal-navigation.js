// Keep each visit separate, including repeated visits to the same modal.
(()=>{
const modals=['match-details-modal','player-stats-modal'].map(id=>document.getElementById(id)).filter(Boolean);
const history=[];let busy=false,switching=false;
const transition=(modal,action)=>new Promise(resolve=>{
  modal.addEventListener(action==='show'?'shown.bs.modal':'hidden.bs.modal',resolve,{once:true});
  bootstrap.Modal.getOrCreateInstance(modal)[action]();
});
const updateBack=modal=>{
  const button=modal.querySelector('.stats-modal-back'),previous=history.at(-1);
  button.hidden=!previous;
  if(previous){const label=previous.modal.id==='player-stats-modal'?'Voltar ao histórico individual':'Voltar aos detalhes da partida';button.title=label;button.setAttribute('aria-label',label)}
};
for(const modal of modals){
  const button=document.createElement('button');button.type='button';button.className='stats-modal-back';button.textContent='←';button.hidden=true;
  modal.querySelector('.modal-header').prepend(button);
  button.addEventListener('click',()=>{if(!busy)bootstrap.Modal.getOrCreateInstance(modal).hide()});
  modal.addEventListener('hide.bs.modal',event=>{if(busy&&!switching)event.preventDefault()});
  modal.addEventListener('hidden.bs.modal',async()=>{
    if(switching)return;
    const previous=history.pop();if(!previous)return;
    busy=true;
    previous.body.replaceChildren(...previous.nodes);
    previous.modal.querySelector('.modal-title').textContent=previous.title;
    updateBack(previous.modal);
    await transition(previous.modal,'show');
    previous.body.scrollTop=previous.scrollTop;
    if(previous.trigger?.isConnected)previous.trigger.focus({preventScroll:true});
    busy=false;
  });
}
window.statsModalNavigation={
  async open(modal,trigger,load){
    if(busy)return;busy=true;
    const source=trigger.closest('.modal.show');
    try{
      const render=await load();
      if(source){
        if(modals.includes(source)){
          const body=source.querySelector('.modal-body');
          history.push({modal:source,body,nodes:[...body.childNodes],title:source.querySelector('.modal-title').textContent,scrollTop:body.scrollTop,trigger});
        }else history.length=0;
        switching=true;await transition(source,'hide');switching=false;
      }else history.length=0;
      render();modal.querySelector('.modal-body').scrollTop=0;updateBack(modal);
      await transition(modal,'show');
    }finally{switching=false;busy=false}
  }
};
})();
