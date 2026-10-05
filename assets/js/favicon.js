(()=>{
  const icon=document.getElementById('site-favicon'),source=icon?.dataset.clubIcon;
  if(!source)return;
  const image=new Image();
  image.onload=()=>{
    if(!image.naturalWidth||!image.naturalHeight)return;
    const canvas=document.createElement('canvas');
    canvas.width=64;canvas.height=64;
    const context=canvas.getContext('2d');
    if(!context)return;
    const scale=Math.min(64/image.naturalWidth,64/image.naturalHeight),width=image.naturalWidth*scale,height=image.naturalHeight*scale;
    context.drawImage(image,(64-width)/2,(64-height)/2,width,height);
    try{icon.href=canvas.toDataURL('image/png');icon.type='image/png';}
    catch{icon.removeAttribute('type');icon.href=source;}
  };
  // Keep the official logo if the registered shield is unavailable.
  image.src=source;
})();
