(() => {
  const style=document.createElement('style');
  style.textContent=`dialog.vox-passport-dialog{position:fixed!important;inset:0!important;margin:auto!important;width:340px!important;max-width:calc(100vw - 32px)!important;height:fit-content!important;max-height:90vh!important;padding:20px!important;border:1px solid #8daece!important;border-radius:6px!important;background:#edf5ff!important;color:#26384a!important;box-shadow:0 12px 40px #0005!important;font:13px Tahoma,sans-serif!important}
  dialog.vox-passport-dialog:not([open]){display:none!important}dialog.vox-passport-dialog::backdrop{background:#0006}
  dialog.vox-passport-dialog form{display:block!important;margin:0!important;padding:0!important}
  dialog.vox-passport-dialog h2{margin:0 0 16px!important;font:bold 16px Tahoma,sans-serif!important;color:#26384a!important}
  dialog.vox-passport-dialog label{display:block!important;font:13px Tahoma,sans-serif!important}
  dialog.vox-passport-dialog input{display:block!important;box-sizing:border-box!important;width:100%!important;height:34px!important;margin-top:6px!important;padding:6px 8px!important;border:1px solid #8daece!important;border-radius:3px!important;background:white!important;color:#26384a!important;font:14px Tahoma,sans-serif!important}
  dialog.vox-passport-dialog p{font:12px/1.5 Tahoma,sans-serif!important;margin:12px 0!important}
  dialog.vox-passport-dialog footer{display:flex!important;justify-content:flex-end!important;gap:8px!important;padding:0!important;margin-top:16px!important}
  dialog.vox-passport-dialog button{display:inline-flex!important;align-items:center!important;justify-content:center!important;position:static!important;width:auto!important;min-width:72px!important;height:32px!important;min-height:32px!important;margin:0!important;padding:0 12px!important;border:1px solid #8daece!important;border-radius:3px!important;background:#fff!important;color:#26384a!important;font:bold 12px Tahoma,sans-serif!important;cursor:pointer}
  dialog.vox-passport-dialog button.vox-passport-cancel{position:absolute!important;top:8px!important;right:8px!important;width:24px!important;min-width:24px!important;height:24px!important;min-height:24px!important;padding:0!important;font-size:18px!important}dialog.vox-passport-dialog .vox-passport-apply{background:#16863c!important;border-color:#16863c!important;color:white!important}`;
  document.head.append(style);
  const setup = () => document.querySelectorAll('input[name="national_id"]').forEach(input => {
    input.maxLength=11; input.minLength=11; input.pattern='[0-9]{11}'; input.inputMode='numeric';
    input.setAttribute('aria-label','T.C. Kimlik No');
    const passport=input.form?.querySelector('input[name="passport_no"]');
    const row=input.closest('.icon-form-row, label');
    const label=row?.querySelector('.icon-form-label, :scope > span');

    const toggle=document.createElement('button');
    toggle.type='button'; toggle.textContent='+';
    toggle.style.cssText='display:inline-flex!important;align-items:center!important;justify-content:center!important;flex:0 0 16px!important;width:16px!important;min-width:16px!important;max-width:16px!important;height:15px!important;min-height:0!important;max-height:15px!important;margin:0 0 0 5px!important;padding:0!important;border:1px solid #8daece!important;border-radius:2px!important;background:#fff!important;color:#26384a!important;font:bold 13px/13px Tahoma,sans-serif!important;box-shadow:none!important;vertical-align:top!important;cursor:pointer';
    const title=document.createElement('span');title.textContent='T.C. Kimlik No';
    if(passport && label){
      label.style.setProperty('display','flex','important');
      label.style.setProperty('align-items','center','important');
      label.style.setProperty('gap','0','important');
      title.style.cssText='display:inline!important;line-height:15px!important;color:inherit!important';
      label.replaceChildren(title,toggle);
    }
    const notice=document.createElement('small'); notice.setAttribute('role','status');
    notice.style.cssText='display:block;color:#925400;font-size:11px';
    input.closest('.icon-form-row, label')?.append(notice);
    const validate=()=>{
      const hasPassport=!!passport?.value.trim();
      input.readOnly=hasPassport;
      if(hasPassport){
        input.removeAttribute('pattern'); input.removeAttribute('minlength'); input.removeAttribute('maxlength');
        input.setCustomValidity(''); notice.textContent=''; return;
      }
      input.pattern='[0-9]{11}'; input.minLength=11; input.maxLength=11;
      const blank=input.value==='';
      input.setCustomValidity(blank || /^[0-9]{11}$/.test(input.value) ? '' : 'T.C. kimlik numarası tam 11 rakam olmalıdır.');
      notice.textContent=blank && !passport?.value.trim() ? 'T.C. kimlik numarası boş. Kayda devam edebilirsiniz.' : '';
    };
    const refresh=()=>{
      if(passport){
        const hasPassport=passport.value.trim()!=='';
        passport.hidden=!hasPassport;
        passport.readOnly=true;
        // Retain an existing T.C. number; do not discard it when a passport is added.
        input.hidden=hasPassport;
        title.textContent=hasPassport ? 'Pasaport No' : 'T.C. Kimlik No';
        passport.placeholder='Pasaport numarası';
        toggle.textContent='+';
        toggle.title=hasPassport ? 'Pasaport numarasını düzenle' : 'Pasaport numarası ekle';
        toggle.setAttribute('aria-label',toggle.title);
        toggle.setAttribute('aria-haspopup','dialog');
      }
      validate();
    };
    if(passport){
      const dialog=document.createElement('dialog');
      dialog.className='vox-passport-dialog';
      dialog.setAttribute('aria-label','Pasaport numarası');
      dialog.innerHTML='<form method="dialog"><button type="button" class="vox-passport-cancel" aria-label="Pasaport penceresini kapat">×</button><h2>Pasaport No</h2><label>Pasaport numarası<input class="vox-passport-draft" type="text" maxlength="64" autocomplete="off"></label><p>Onayladığınız numara hasta formuna aktarılır.</p><footer><button type="submit" class="vox-passport-apply">Tamam</button></footer></form>';
      document.body.append(dialog);
      const draft=dialog.querySelector('input');
      const open=()=>{draft.value=passport.value;dialog.showModal();toggle.setAttribute('aria-expanded','true');draft.focus();draft.select();};
      toggle.addEventListener('click',open);
      passport.addEventListener('click',open);
      dialog.querySelector('.vox-passport-cancel').addEventListener('click',()=>dialog.close());
      dialog.querySelector('form').addEventListener('submit',event=>{
        event.preventDefault();
        passport.value=draft.value.trim();
        refresh();
        dialog.close();
      });
      dialog.addEventListener('close',()=>{toggle.setAttribute('aria-expanded','false');toggle.focus();});
    }
    passport?.addEventListener('input',refresh);
    input.addEventListener('input',refresh); refresh();
    input.form?.addEventListener('reset',()=>setTimeout(refresh,0));
    input.form?.addEventListener('submit',event=>{
      if(event.submitter?.value==='delete_external_patient') return;
      validate();
      if(!input.validity.valid){event.preventDefault();event.stopImmediatePropagation();input.reportValidity();return;}
      if(!input.value && !passport?.value.trim() && input.form.checkValidity()) alert('T.C. kimlik numarası girilmedi. Kayıt kimlik numarası olmadan devam edecek.');
    },true);
  });
  document.readyState==='loading' ? document.addEventListener('DOMContentLoaded',setup,{once:true}) : setup();
})();
