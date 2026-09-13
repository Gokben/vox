(() => {
  const dialog=document.createElement('dialog');
  dialog.setAttribute('aria-label','Hasta kartını düzenle');
  dialog.style.cssText='width:min(1100px,94vw);height:90vh;max-width:94vw;max-height:94vh;padding:0;border:1px solid #8daece;border-radius:5px;background:#edf5ff';
  const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Düzenleme penceresini kapat');
  close.style.cssText='position:absolute!important;right:8px!important;top:5px!important;z-index:10!important;width:28px!important;min-width:28px!important;height:26px!important;min-height:26px!important;padding:0!important;border:1px solid #8daece!important;background:white!important;color:#26384a!important;font:20px/24px Tahoma!important;cursor:pointer';
  const frame=document.createElement('iframe');frame.title='Hasta düzenleme formu';frame.style.cssText='display:block;width:100%;height:calc(100% - 36px);margin-top:36px;border:0';
  dialog.append(close,frame);document.body.append(dialog);
  const open=link=>{
    const url=new URL(link.href,location.href);url.searchParams.set('_vox_window','1');url.searchParams.set('_identity_audit','1');
    frame.src=url.href;dialog.showModal();
  };
  // Intercept before the application's general navigation and double-click handlers.
  window.addEventListener('click',event=>{
    const link=event.target.closest?.('.identity-audit tbody a[href]');
    if(!link)return;
    event.preventDefault();event.stopImmediatePropagation();open(link);
  },true);
  window.addEventListener('dblclick',event=>{
    const row=event.target.closest?.('.identity-audit tbody tr');
    if(!row || event.target.closest('button,input,a'))return;
    const link=row.querySelector('a[href]');if(!link)return;
    event.preventDefault();event.stopImmediatePropagation();open(link);
  },true);
  close.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{frame.removeAttribute('src');});
  const refresh=async()=>{
    const section=document.querySelector('.identity-audit');const scrollY=window.scrollY;const scrollTop=section.scrollTop;
    try{
      const response=await fetch(location.href,{credentials:'same-origin',cache:'no-store'});
      if(!response.ok)throw Error('refresh');
      const next=new DOMParser().parseFromString(await response.text(),'text/html').querySelector('.identity-audit');
      if(!next)throw Error('refresh');
      section.replaceChildren(...next.childNodes);section.scrollTop=scrollTop;window.scrollTo(0,scrollY);
    }catch(error){
      const notice=document.createElement('p');notice.setAttribute('role','status');notice.textContent='Kayıt tamamlandı. Güncel listeyi görmek için sayfayı yenileyin.';section.prepend(notice);
    }
  };
  window.addEventListener('message',event=>{
    if(event.origin!==location.origin || event.source!==frame.contentWindow)return;
    if(event.data?.type==='vox-patient-saved'){dialog.close();refresh();}
    else if(event.data?.type==='vox-close-window')dialog.close();
  });
})();
