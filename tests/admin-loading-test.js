const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/admin-loading.js'), 'utf8');
const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b}); return {promise,resolve,reject}; };
const classes = () => {const values=new Set();return {add:x=>values.add(x),remove:x=>values.delete(x),contains:x=>values.has(x)};};
const storage = new Map();
function page() {
  const loader = {classList:classes(),querySelector:()=>label};
  const label = {textContent:''};
  const main = {tagName:'MAIN',inert:false};
  const prelocked = {tagName:'DIV',inert:true};
  const events = {}, timers = new Map();let timerId=0;
  const body = {children:[loader,main,prelocked],classList:classes(),setAttribute(k,v){this[k]=v},removeAttribute(k){delete this[k]}};
  const context = {URL,Promise,Event,queueMicrotask,setTimeout:fn=>{timers.set(++timerId,fn);return timerId},clearTimeout:id=>timers.delete(id),requestAnimationFrame:fn=>fn(),document:{body,readyState:'loading',querySelector:()=>loader,addEventListener:(name,fn)=>events['document:'+name]=fn},sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)},location:{href:'https://example.com/admin/?tab=campeonatos',pathname:'/admin/',origin:'https://example.com',replace(url){this.next=url}}};
  context.window=context;context.scrollY=320;context.scrollTo=(x,y)=>context.restoredScroll=y;
  context.addEventListener=(event,fn)=>events[event]=fn;
  context.dispatchEvent=event=>events[event.type]?.(event);
  context.responseBody=deferred();
  context.fetch=async()=>({clone:()=>({text:()=>context.responseBody.promise})});
  vm.runInNewContext(source,context);
  const flush=async()=>{await new Promise(resolve=>setImmediate(resolve));while(timers.size){const items=[...timers.values()];timers.clear();items.forEach(fn=>fn());await new Promise(resolve=>setImmediate(resolve));}};
  return {context,body,main,prelocked,loader,events,flush};
}
(async()=>{
  const p=page();assert.equal(p.main.inert,true,'Carga inicial deve bloquear teclado');
  const job=deferred();const tracked=p.context.adminLoading.track(job.promise);
  p.events.load();await p.flush();assert.equal(p.main.inert,true,'window.load não significa dados prontos');
  job.resolve();await tracked;await p.flush();assert.equal(p.main.inert,false);assert.equal(p.prelocked.inert,true,'Preserva bloqueios anteriores');
  await p.context.fetch('campeonatos-dados.php');await p.flush();assert.equal(p.main.inert,true,'Headers não podem liberar tela');
  p.context.responseBody.resolve('{}');await p.flush();assert.equal(p.main.inert,false,'Corpo consumido deve liberar tela');
  const failed=deferred();const failure=p.context.adminLoading.track(failed.promise).catch(()=>{});failed.reject(new Error('offline'));await failure;await p.flush();assert.equal(p.main.inert,false,'Erro deve permitir nova tentativa');
  p.context.__adminListState={'tab-campeonatos:0':{page:3,values:['Liga','Todos']}};
  p.context.adminLoading.navigate('campeonatos');await p.flush();assert.equal(p.main.inert,true,'Navegação deve permanecer bloqueada');
  assert.equal(p.context.location.next,'https://example.com/admin/?tab=campeonatos');
  const next=page();next.events.load();await next.flush();assert.equal(next.context.__adminListState['tab-campeonatos:0'].page,3);assert.equal(next.context.restoredScroll,320);
  const finish=next.context.adminLoading.begin('SALVANDO');finish();finish();await next.flush();assert.equal(next.main.inert,false,'Finalização duplicada não desequilibra contador');
  console.log('OK: carga inicial, fetch lento, bloqueio de teclado, falha, navegação e restauração de filtros/página.');
})().catch(error=>{console.error(error);process.exitCode=1});
