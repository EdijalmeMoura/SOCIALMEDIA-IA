document.addEventListener('DOMContentLoaded',()=>{
  const canvas=document.getElementById('studioCanvas');
  if(!canvas)return;
  const ctx=canvas.getContext('2d');
  const layout=JSON.parse(canvas.dataset.layout||'{"elements":[]}');
  let selected=layout.elements.find(element=>element.id==='title')||layout.elements[0];
  let bg=canvas.dataset.bgColor||'#3158d8';
  const img=new Image();
  let imageReady=false;
  const logoImg=new Image();
  let logoReady=false;
  const label=document.getElementById('selectedLabel');
  const colorInput=document.getElementById('elementColor');
  const sizeInput=document.getElementById('elementSize');
  const noImage=document.getElementById('canvasNoImage');
  const controls=[...document.querySelectorAll('[data-layout-text]')];
  const clamp=(value,min,max)=>Math.max(min,Math.min(max,value));
  function wrap(text,maxWidth){const words=(text||'').split(/\s+/);const lines=[];let line='';for(const word of words){const next=line?`${line} ${word}`:word;if(ctx.measureText(next).width>maxWidth&&line){lines.push(line);line=word}else line=next}if(line)lines.push(line);return lines}
  function draw(showSelection=true){
    const width=canvas.width,height=canvas.height;
    ctx.clearRect(0,0,width,height);
    ctx.fillStyle=bg;ctx.fillRect(0,0,width,height);
    if(imageReady)ctx.drawImage(img,0,0,width,height);
    else{const gradient=ctx.createLinearGradient(0,0,width,height);gradient.addColorStop(0,bg);gradient.addColorStop(1,'#1b2344');ctx.fillStyle=gradient;ctx.fillRect(0,0,width,height);ctx.beginPath();ctx.arc(width*.78,height*.24,Math.min(width,height)*.29,0,Math.PI*2);ctx.fillStyle='rgba(255,255,255,.08)';ctx.fill();ctx.strokeStyle='rgba(255,255,255,.23)';ctx.lineWidth=Math.max(2,width*.002);ctx.beginPath();ctx.arc(width*.78,height*.24,Math.min(width,height)*.36,0,Math.PI*2);ctx.stroke()}
    const shade=ctx.createLinearGradient(0,0,0,height);shade.addColorStop(0,'rgba(0,0,0,.15)');shade.addColorStop(.45,'rgba(0,0,0,.02)');shade.addColorStop(1,'rgba(0,0,0,.28)');ctx.fillStyle=shade;ctx.fillRect(0,0,width,height);
    for(const el of layout.elements){
      ctx.save();
      if(el.fill){ctx.fillStyle=el.fill;const radius=Math.min(el.h*.2,24);ctx.beginPath();ctx.roundRect(el.x,el.y,el.w,el.h,radius);ctx.fill()}
      ctx.beginPath();ctx.rect(el.x,el.y,el.w,el.h);ctx.clip();
      if(el.id==='logo'&&logoReady){const scale=Math.min(el.w/logoImg.width,el.h/logoImg.height);const w=logoImg.width*scale,h=logoImg.height*scale;ctx.drawImage(logoImg,el.x+(el.w-w)/2,el.y+(el.h-h)/2,w,h)}
      else{ctx.font=`${el.bold?'700':'500'} ${el.fontSize}px Manrope, Arial, sans-serif`;ctx.fillStyle=el.color||'#fff';ctx.textBaseline='top';ctx.textAlign=el.align||'left';ctx.shadowColor='rgba(0,0,0,.22)';ctx.shadowBlur=el.fill?0:Math.max(1,el.fontSize*.12);const lines=wrap(el.text,Math.max(10,el.w-24));const lineHeight=el.fontSize*1.18;const visible=Math.max(1,Math.floor(el.h/lineHeight));let tx=el.x+12;if(el.align==='center')tx=el.x+el.w/2;else if(el.align==='right')tx=el.x+el.w-12;for(let i=0;i<Math.min(visible,lines.length);i++)ctx.fillText(lines[i],tx,el.y+Math.max(0,(el.h-Math.min(visible,lines.length)*lineHeight)/2)+i*lineHeight,Math.max(10,el.w-24))}
      ctx.restore();
      if(showSelection&&el===selected){ctx.save();ctx.setLineDash([Math.max(4,width*.007),Math.max(3,width*.004)]);ctx.lineWidth=Math.max(2,width*.002);ctx.strokeStyle='#ffffff';ctx.strokeRect(el.x,el.y,el.w,el.h);ctx.setLineDash([]);ctx.fillStyle='#fff';ctx.strokeStyle='#5267df';ctx.lineWidth=Math.max(2,width*.002);ctx.beginPath();ctx.arc(el.x+el.w,el.y+el.h,Math.max(8,width*.012),0,Math.PI*2);ctx.fill();ctx.stroke();ctx.restore()}
    }
    if(noImage)noImage.hidden=imageReady;
  }
  if(canvas.dataset.imageUrl){img.onload=()=>{imageReady=true;draw()};img.onerror=()=>{imageReady=false;draw()};img.src=canvas.dataset.imageUrl}
  if(canvas.dataset.logoUrl){logoImg.onload=()=>{logoReady=true;draw()};logoImg.onerror=()=>{logoReady=false;draw()};logoImg.src=canvas.dataset.logoUrl}
  function select(el){selected=el;label.textContent=el.label||el.id;colorInput.value=/^#[\da-f]{6}$/i.test(el.color||'')?el.color:'#ffffff';if(sizeInput)sizeInput.value=el.fontSize;controls.forEach(input=>{if(input.dataset.layoutText===el.id&&input.value!==el.text)input.value=el.text});draw()}
  select(selected);
  controls.forEach(input=>input.addEventListener('input',()=>{const el=layout.elements.find(item=>item.id===input.dataset.layoutText);if(el){el.text=input.value;if(el===selected)draw()}}));
  colorInput?.addEventListener('input',()=>{if(selected){selected.color=colorInput.value;draw()}});
  sizeInput?.addEventListener('input',()=>{if(selected){selected.fontSize=Number(sizeInput.value);draw()}});
  document.getElementById('backgroundColor')?.addEventListener('input',event=>{bg=event.target.value;draw()});
  let gesture=null;
  function point(event){const rect=canvas.getBoundingClientRect();return{x:(event.clientX-rect.left)*canvas.width/rect.width,y:(event.clientY-rect.top)*canvas.height/rect.height}}
  canvas.addEventListener('pointerdown',event=>{const p=point(event);const el=[...layout.elements].reverse().find(item=>p.x>=item.x&&p.x<=item.x+item.w&&p.y>=item.y&&p.y<=item.y+item.h);if(!el)return;select(el);const resize=p.x>el.x+el.w-Math.max(24,canvas.width*.03)&&p.y>el.y+el.h-Math.max(24,canvas.width*.03);gesture={el,mode:resize?'resize':'move',x:p.x,y:p.y,origin:{x:el.x,y:el.y,w:el.w,h:el.h}};canvas.setPointerCapture(event.pointerId);event.preventDefault()});
  canvas.addEventListener('pointermove',event=>{if(!gesture)return;const p=point(event),dx=p.x-gesture.x,dy=p.y-gesture.y,el=gesture.el;if(gesture.mode==='move'){el.x=clamp(Math.round(gesture.origin.x+dx),0,canvas.width-el.w);el.y=clamp(Math.round(gesture.origin.y+dy),0,canvas.height-el.h)}else{el.w=clamp(Math.round(gesture.origin.w+dx),80,canvas.width-el.x);el.h=clamp(Math.round(gesture.origin.h+dy),50,canvas.height-el.y)}draw()});
  canvas.addEventListener('pointerup',()=>{gesture=null});canvas.addEventListener('pointercancel',()=>{gesture=null});
  document.getElementById('studioSave')?.addEventListener('submit',event=>{const data={elements:layout.elements};document.getElementById('layoutJson').value=JSON.stringify(data)});
  document.getElementById('imageGenerationForm')?.addEventListener('submit',()=>{document.getElementById('imageGenerationPrompt').value=document.getElementById('imagePromptSave').value});
  document.querySelector('[data-image-upload]')?.addEventListener('change',event=>{if(event.target.files?.length)event.target.form.requestSubmit()});
  document.querySelectorAll('[data-export-format]').forEach(button=>button.addEventListener('click',()=>{const format=button.dataset.exportFormat;button.disabled=true;draw(false);canvas.toBlob(async blob=>{draw(true);if(!blob){window.alert('Não foi possível renderizar a arte.');button.disabled=false;return}const data=new FormData();data.append('csrf_token',document.getElementById('studioCsrf').value);data.append('file',blob,`socialmedia-ia.${format==='jpg'?'jpg':'png'}`);try{const response=await fetch(`/studio/${window.location.pathname.split('/').pop()}/export`,{method:'POST',body:data});const result=await response.json();if(!response.ok)throw new Error(result.error||'Erro ao exportar');window.location.reload()}catch(error){window.alert(error.message);button.disabled=false}},format==='jpg'?'image/jpeg':'image/png',.93)}));
});