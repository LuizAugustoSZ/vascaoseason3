const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
class Element extends EventTarget{
  constructor(id){super();this.id=id;this.visible=false;this.childNodes=[];this.scrollTop=0;this.isConnected=true}
  querySelector(selector){return this.parts[selector]}
  replaceChildren(...nodes){this.childNodes=nodes}
  setAttribute(name,value){this[name]=value}
  closest(){return this.source?.visible?this.source:null}
  focus(){this.focused=true}
}
const modals=['match-details-modal','player-stats-modal'].map(id=>{
  const modal=new Element(id),body=new Element(),title=new Element(),header=new Element();
  header.prepend=button=>modal.parts['.stats-modal-back']=button;
  modal.parts={'.modal-body':body,'.modal-title':title,'.modal-header':header};return modal;
});
const [match,player]=modals;
const bootstrap={Modal:{getOrCreateInstance:modal=>({
  show(){modal.visible=true;queueMicrotask(()=>modal.dispatchEvent(new Event('shown.bs.modal')))},
  hide(){if(!modal.dispatchEvent(new Event('hide.bs.modal',{cancelable:true})))return;modal.visible=false;queueMicrotask(()=>modal.dispatchEvent(new Event('hidden.bs.modal')))}
})}};
const window={};vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/stats-modal-navigation.js'),'utf8'),{window,document:{getElementById:id=>modals.find(m=>m.id===id),createElement:()=>new Element()},bootstrap});
const trigger=source=>Object.assign(new Element(),{source});
const body=modal=>modal.querySelector('.modal-body');
const back=modal=>modal.querySelector('.stats-modal-back');
const open=(modal,source,label)=>window.statsModalNavigation.open(modal,trigger(source),async()=>()=>{body(modal).replaceChildren({label});modal.querySelector('.modal-title').textContent=label});
const close=async modal=>{bootstrap.Modal.getOrCreateInstance(modal).hide();await new Promise(setImmediate)};
(async()=>{
  await open(player,null,'Jogador A');body(player).scrollTop=180;const original=body(player).childNodes[0];
  await open(match,player,'Partida A');body(match).scrollTop=240;const originalMatch=body(match).childNodes[0];
  assert.equal(back(match)['aria-label'],'Voltar ao histórico individual');
  await open(player,match,'Jogador B');assert.equal(back(player)['aria-label'],'Voltar aos detalhes da partida');
  await open(match,player,'Partida B');
  await close(match);assert.equal(body(player).childNodes[0].label,'Jogador B');
  back(player).dispatchEvent(new Event('click'));await new Promise(setImmediate);
  assert.equal(body(match).childNodes[0],originalMatch);assert.equal(body(match).scrollTop,240);
  await close(match);assert.equal(body(player).childNodes[0],original);assert.equal(body(player).scrollTop,180);assert.equal(back(player).hidden,true);
  await close(player);assert.ok(modals.every(m=>!m.visible));
  await open(match,null,'Partida direta');assert.equal(back(match).hidden,true);
  await close(match);assert.ok(modals.every(m=>!m.visible));
  await open(match,null,'Partida direta');await open(player,match,'Jogador C');await close(player);assert.equal(match.visible,true);await close(match);assert.ok(modals.every(m=>!m.visible));
  let release;const pending=window.statsModalNavigation.open(player,trigger(null),()=>new Promise(resolve=>{release=()=>resolve(()=>body(player).replaceChildren({label:'Único'}))}));
  await open(match,null,'Clique duplicado');release();await pending;assert.equal(player.visible,true);assert.equal(match.visible,false);await close(player);
  console.log('OK: nested histories, back arrow, scroll/content restoration, direct entry and duplicate clicks');
})().catch(error=>{console.error(error);process.exitCode=1});
