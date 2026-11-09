document.addEventListener('DOMContentLoaded',()=>{
  const layout=document.querySelector('.feed-layout');
  const grid=document.getElementById('feed-grid');
  const bar=document.getElementById('feedOrderBar');
  if(!layout||!grid)return;
  const canEdit=layout.dataset.canEdit==='true';
  let original=[...grid.querySelectorAll('[data-feed-id]')].map(item=>item.dataset.feedId);
  let dragged=null;
  const updateNumbers=()=>grid.querySelectorAll('.feed-tile').forEach((tile,index)=>{const number=tile.querySelector('.tile-order');if(number)number.textContent=String(index+1)});
  const changed=()=>{const current=[...grid.querySelectorAll('[data-feed-id]')].map(item=>item.dataset.feedId);const modified=current.some((id,index)=>id!==original[index]);bar.hidden=!modified;updateNumbers()};
  if(canEdit){grid.querySelectorAll('.feed-tile[draggable="true"]').forEach(tile=>{
    tile.addEventListener('dragstart',event=>{dragged=tile;tile.classList.add('dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',tile.dataset.feedId)});
    tile.addEventListener('dragend',()=>{tile.classList.remove('dragging');grid.querySelectorAll('.feed-tile').forEach(item=>item.classList.remove('drag-target'));dragged=null;changed()});
    tile.addEventListener('dragover',event=>{if(!dragged||dragged===tile)return;event.preventDefault();const rect=tile.getBoundingClientRect();const after=(event.clientY-rect.top)/rect.height>.52;grid.insertBefore(dragged,after?tile.nextSibling:tile);tile.classList.add('drag-target')});
    tile.addEventListener('dragleave',()=>tile.classList.remove('drag-target'));
    tile.addEventListener('drop',event=>{event.preventDefault();tile.classList.remove('drag-target')});
  })}
  document.getElementById('saveFeedOrder')?.addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;const message=document.getElementById('feedSaveMessage');try{const order=[...grid.querySelectorAll('[data-feed-id]')].map(item=>Number(item.dataset.feedId));const token=document.querySelector('meta[name="csrf-token"]')?.content||document.querySelector('.feed-layout')?.dataset.csrf||'';const csrfInput=document.querySelector('.feed-csrf-token');const csrf=csrfInput?.value||token;const response=await fetch(`/feed/${layout.dataset.feedClient}/reorder`,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({order})});const result=await response.json();if(!response.ok)throw new Error(result.error||'Não foi possível salvar a nova ordem.');original=order.map(String);bar.hidden=true;message.textContent='Ordem salva.';setTimeout(()=>{message.textContent=''},2400)}catch(error){message.textContent=error.message;button.disabled=false}});
  document.getElementById('cancelFeedOrder')?.addEventListener('click',()=>{const current=new Map([...grid.querySelectorAll('[data-feed-id]')].map(item=>[item.dataset.feedId,item]));original.forEach(id=>{const node=current.get(id);if(node)grid.appendChild(node)});bar.hidden=true;updateNumbers()});
  const modal=document.getElementById('feedPreviewModal');
  grid.querySelectorAll('.feed-tile-preview').forEach(button=>button.addEventListener('click',()=>{if(!modal)return;const card=button.closest('.feed-tile');document.getElementById('modalTitle').textContent=card.dataset.title||'Publicação';document.getElementById('modalNetwork').textContent=card.dataset.network||'';document.getElementById('modalDate').textContent=card.dataset.date?`Data recomendada: ${card.dataset.date}`:'Sem data de agendamento';document.getElementById('modalCaption').textContent=card.dataset.caption||'Legenda ainda não cadastrada.';document.getElementById('modalStatus').textContent=card.dataset.status||'';document.getElementById('modalBrief').textContent=card.dataset.brief||'';modal.showModal()}));
  document.getElementById('closeFeedPreview')?.addEventListener('click',()=>modal?.close());
  modal?.addEventListener('click',event=>{if(event.target===modal)modal.close()});
});