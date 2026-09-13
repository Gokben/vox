(() => {
  'use strict';
  const missingFields = () => {
    const modal=document.querySelector('#sales-details-modal');
    if(!modal)return [];
    const missing=[];
    for(let number=1;number<=4;number++){
      const names=number===1?['sales_brand','sales_model','sales_device_serial','sales_device_net_price']:[`sales_device_${number}_brand`,`sales_device_${number}_model`,`sales_device_${number}_serial`,`sales_device_${number}_net_price`];
      const fields=names.map(name=>modal.querySelector(`[name="${name}"]`));
      if(number>1&&!fields.some(field=>field&&(!field.closest('.sales-device-details')?.hidden||field.value.trim())))continue;
      const titles=['Marka','Model','Seri No','Net Fiyat'];
      fields.forEach((field,index)=>{
        const value=field?.value.trim()||'';
        const price=index===3?Number(value.replace(/[^0-9,.-]/g,'').replaceAll('.','').replace(',','.')):1;
        if(!value||(index===3&&price<=0))missing.push({field,title:`İşitme Cihazı ${number}: ${titles[index]}`});
      });
    }
    return missing;
  };
  let lastWarning=0;
  function block(event){
    const service=document.querySelector('#service-card-form [name="service_name"]');
    if(service&&service.value!=='Satış')return;
    const missing=missingFields();if(!missing.length)return;
    event.preventDefault();event.stopImmediatePropagation();
    if(Date.now()-lastWarning<400)return;
    lastWarning=Date.now();
    alert('Ödeme seçmeden önce işitme cihazı bilgilerini tamamlayın.\n\nEksik alanlar:\n'+missing.map(item=>'• '+item.title).join('\n'));
    document.querySelector('#add-hearing-device')?.click();
    missing.find(item=>item.field&&!item.field.disabled)?.field.focus();
  }
  window.addEventListener('submit',event=>{if(event.target.matches('form[action*="cash.php"]'))block(event);},true);
  const paymentTarget=target=>target.closest('[name="sales_payment_type"],label:has([name="sales_payment_type"]),[aria-label="Kasa"]');
  window.addEventListener('pointerdown',event=>{if(paymentTarget(event.target))block(event);},true);
  window.addEventListener('click',event=>{if(paymentTarget(event.target))block(event);},true);
  window.addEventListener('keydown',event=>{if(paymentTarget(event.target)&&['Enter',' ','ArrowDown','ArrowUp'].includes(event.key))block(event);},true);
  window.addEventListener('change',event=>{
    if(!event.target.matches('[name="sales_payment_type"]')||!missingFields().length)return;
    event.target.value='';block(event);
  },true);
  window.addEventListener('click',event=>{
    const button=event.target.closest('form[action*="cash.php"] footer button');
    if(button&&!button.matches('[data-cash-close],[aria-label="Bir gelir kaydı daha ekle"]'))block(event);
  },true);
})();
