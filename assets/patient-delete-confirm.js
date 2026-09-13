(() => {
  let pending=null;
  const dialog=document.createElement('dialog');
  dialog.setAttribute('aria-label','Hasta kartını silme onayı');
  dialog.style.cssText='position:fixed;inset:0;margin:auto;width:360px;max-width:90vw;height:fit-content;padding:24px;border:1px solid #8daece;border-radius:6px;background:#fff;color:#26384a';
  const heading=document.createElement('h2');heading.textContent='Hasta kartını sil';
  const message=document.createElement('p');message.style.whiteSpace='pre-wrap';
  const cancel=document.createElement('button');cancel.type='button';cancel.textContent='×';cancel.setAttribute('aria-label','Silme penceresini kapat');
  const confirm=document.createElement('button');confirm.type='button';confirm.textContent='Evet, kartı sil';
  for(const button of [cancel,confirm])button.style.cssText='display:inline-block!important;width:auto!important;min-width:32px!important;height:34px!important;min-height:34px!important;margin:8px!important;padding:4px 12px!important;border:1px solid #8daece!important;border-radius:3px!important;background:#fff!important;color:#26384a!important;cursor:pointer';
  dialog.append(heading,message,cancel,confirm);document.body.append(dialog);
  const open=form=>{
    pending=form;
    const cells=form.closest('tr')?.cells;
    message.textContent=(cells?`Kart No: ${cells[0].textContent.trim()}\n${cells[2].textContent.trim()}\n\n`:'')+'Hasta kartı kalıcı olarak silinecek. Onaylıyor musunuz?';
    dialog.showModal();cancel.focus();
  };
  window.addEventListener('click',event=>{
    const button=event.target.closest?.('form[data-patient-delete] button');if(!button)return;
    event.preventDefault();event.stopImmediatePropagation();open(button.form);
  },true);
  window.addEventListener('submit',event=>{
    if(!event.target.matches('form[data-patient-delete]'))return;
    event.preventDefault();event.stopImmediatePropagation();open(event.target);
  },true);
  cancel.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{pending=null;});
  confirm.addEventListener('click',()=>{
    if(!pending)return;
    confirm.disabled=true;
    HTMLFormElement.prototype.submit.call(pending);
  });
})();
