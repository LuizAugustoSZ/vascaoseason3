document.addEventListener('DOMContentLoaded',()=>{
  const control=document.getElementById('database-sync-control');
  if(!control)return;
  const button=control.querySelector('button');
  const status=control.querySelector('.database-sync-status');
  const csrf=control.dataset.csrf;
  const check=async()=>{
    try{
      const response=await fetch('sync-status.php',{cache:'no-store',credentials:'same-origin'});
      const data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.message||'Não foi possível verificar os bancos.');
      control.classList.remove('d-none');control.classList.add('d-flex');
      button.classList.toggle('d-none',!data.changed);
      status.textContent=data.changed?'A produção possui dados diferentes e pode atualizar esta homologação.':'Esta homologação já está sincronizada com a produção.';
    }catch(error){
      control.classList.remove('d-none');control.classList.add('d-flex');
      control.classList.replace('alert-warning','alert-danger');
      status.textContent=error.message;
      button.classList.add('d-none');
    }
  };
  button.addEventListener('click',async()=>{
    if(!confirm('Atualizar a homologação com os dados atuais da produção? Os dados de teste das tabelas sincronizadas serão substituídos.'))return;
    button.disabled=true;status.textContent='Sincronizando e validando...';
    try{
      const body=new URLSearchParams({csrf});
      const response=await fetch('sync-run.php',{method:'POST',body,headers:{'Content-Type':'application/x-www-form-urlencoded'},credentials:'same-origin'});
      const data=await response.json();
      if(!response.ok||!data.ok)throw new Error(data.message||'Falha na sincronização.');
      status.textContent=data.message;button.classList.add('d-none');
      setTimeout(()=>control.classList.add('d-none'),3500);
    }catch(error){status.textContent=error.message;button.disabled=false;}
  });
  check();
});
