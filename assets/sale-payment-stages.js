(() => {
  'use strict';
  const settings = document.currentScript.dataset;
  const endpoint = settings.endpoint;
  const editId = settings.editId;
  const formSelector = 'form[action*="cash.php"]';
  const money = value => {
    let text = String(value ?? '').replace(/[^0-9,.-]/g, '');
    if (text.includes(',')) text = text.replaceAll('.', '').replace(',', '.');
    else if (/^-?\d{1,3}(?:\.\d{3})+$/.test(text)) text = text.replaceAll('.', '');
    return Number(text) || 0;
  };
  const format = value => Number(value || 0).toLocaleString('tr-TR', {minimumFractionDigits:2,maximumFractionDigits:2});
  const today = () => { const d=new Date();return [d.getFullYear(),String(d.getMonth()+1).padStart(2,'0'),String(d.getDate()).padStart(2,'0')].join('-'); };
  const isSale = () => document.querySelector('#service-card-form [name="service_name"]')?.value === 'Satış';
  const sections = form => [...form.querySelectorAll(':scope > section')];
  const records = () => window.__savedCashRecords || [];
  const allTypes = [['cash','Nakit'],['eft_transfer','EFT / Havale'],['credit_card','Kredi Kartı'],['mail_order','Mail Order'],['term','Vadeli']];
  const updateAdd = form => {
    const add=form.querySelector('[aria-label="Bir gelir kaydı daha ekle"]');
    if(!add)return;
    const full=sections(form).length>=4;
    add.disabled=full;
    add.style.setProperty('display',full?'none':'inline-grid','important');
  };
  function createStage(form, saved = null) {
    if(sections(form).length>=4)return;
    const number=sections(form).length+1;
    const section=document.createElement('section');
    section.className='repair-body sale-payment-stage';
    section.dataset.paymentStage=String(number);
    section.dataset.recordId=String(saved?.id||'');
    section.style.cssText='display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;align-content:start!important;';
    const heading=document.createElement('strong');heading.textContent=number+'. Gelir Kayıt';heading.style.gridColumn='1/-1';section.append(heading);
    const fields={};
    const field=(name,title,type='text',choices=null)=>{
      const label=document.createElement('label');label.textContent=title;
      const control=document.createElement(choices?'select':type==='textarea'?'textarea':'input');
      if(!choices&&type!=='textarea')control.type=type;
      if(choices)choices.forEach(([value,text])=>control.add(new Option(text,value)));
      control.name='payment'+number+'_'+name;control.dataset.paymentField=name;
      control.value=saved?.[name]??'';
      label.append(control);section.append(label);fields[name]=control;return control;
    };
    field('transaction_date','İşlem Tarihi','date').value=saved?.transaction_date||today();
    fields.transaction_date.required=true;
    field('payment_type','Ödeme Şekli','text',allTypes).value=saved?.payment_type||'cash';
    const options=name=>[...(form.querySelector(`[name="${name}"]`)?.options||[])].map(o=>[o.value,o.text]);
    field('bank_name','Banka','text',options('bank_name'));
    field('current_account_id','Cari Hesap','text',options('current_account_id'));
    field('installment_count','KK Taksit Sayısı','number').value=saved?.installment_count||1;
    fields.installment_count.min='1';fields.installment_count.max='12';
    field('amount','Tutar').value=saved?format(saved.amount):'';
    field('commission_rate','Komisyon Oranı').value=saved?.commission_rate||0;
    const schedule=document.createElement('div');schedule.dataset.stageSchedule='1';schedule.style.cssText='grid-column:1/-1;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px';section.append(schedule);
    field('description','Açıklama','textarea').value=saved?.description||form.querySelector('[name="description"]')?.value||'Satış tahsilatı';
    fields.description.closest('label').style.gridColumn='1/-1';
    let plan=[];try{plan=JSON.parse(saved?.term_schedule||'[]')||[];}catch(_){}
    const readPlan=()=>[...schedule.querySelectorAll('[data-stage-date]')].map((date,i)=>({date:date.value,amount:schedule.querySelectorAll('[data-stage-amount]')[i].value,paid:schedule.querySelectorAll('[data-stage-paid]')[i].checked}));
    const total=()=>{fields.amount.value=format(readPlan().reduce((sum,row)=>sum+money(row.amount),0));};
    const renderPlan=()=>{
      if(schedule.children.length)plan=readPlan();
      schedule.replaceChildren();
      const count=Math.min(12,Math.max(1,Number(fields.installment_count.value)||1));fields.installment_count.value=count;
      for(let i=0;i<count;i++){
        const dateLabel=document.createElement('label');dateLabel.textContent=(i+1)+'. Vade Tarihi';
        const date=document.createElement('input');date.type='date';date.dataset.stageDate='1';
        const base=new Date((fields.transaction_date.value||today())+'T12:00:00');base.setMonth(base.getMonth()+i);
        date.value=plan[i]?.date||[base.getFullYear(),String(base.getMonth()+1).padStart(2,'0'),String(base.getDate()).padStart(2,'0')].join('-');dateLabel.append(date);
        const amountLabel=document.createElement('label');amountLabel.textContent=(i+1)+'. Aylık Ödeme';
        const amount=document.createElement('input');amount.inputMode='decimal';amount.dataset.stageAmount='1';amount.value=plan[i]?.amount??'';
        const paid=document.createElement('input');paid.type='checkbox';paid.dataset.stagePaid='1';paid.checked=!!plan[i]?.paid;paid.style.cssText='width:18px!important;min-height:18px!important;height:18px!important;';
        const paidLabel=document.createElement('span');paidLabel.append(paid,' Ödendi');amountLabel.append(amount,paidLabel);schedule.append(dateLabel,amountLabel);
      }
      total();
    };
    const show=(name,visible)=>{const label=fields[name].closest('label');label.hidden=!visible;label.style.setProperty('display',visible?'flex':'none','important');};
    const sync=()=>{
      const type=fields.payment_type.value;
      show('bank_name',['eft_transfer','credit_card','mail_order'].includes(type));
      show('current_account_id',['mail_order','eft_transfer'].includes(type));show('installment_count',['credit_card','term'].includes(type));show('commission_rate',type==='credit_card');
      fields.installment_count.closest('label').firstChild.nodeValue=type==='term'?'Vade Sayısı':'KK Taksit Sayısı';
      fields.amount.readOnly=type==='term';schedule.hidden=type!=='term';schedule.style.display=type==='term'?'grid':'none';
      if(type==='term')renderPlan();
    };
    fields.payment_type.addEventListener('change',sync);
    fields.installment_count.addEventListener('change',()=>{if(fields.payment_type.value==='term')renderPlan();});
    schedule.addEventListener('input',total);
    let gross=money(saved?.amount||0);
    fields.amount.addEventListener('change',()=>{gross=money(fields.amount.value);});
    fields.commission_rate.addEventListener('input',()=>{if(fields.payment_type.value==='credit_card'){if(!gross)gross=money(fields.amount.value);fields.amount.value=format(gross*(1-money(fields.commission_rate.value)/100));}});
    form.querySelector('footer').before(section);sync();updateAdd(form);
  }
  function readRecords(form) {
    return sections(form).map((section,index)=>{
      const prefix=index===0?'':index===1?'extra_':'payment'+(index+1)+'_';
      const value=name=>section.querySelector(`[name="${prefix}${name}"]`)?.value||'';
      const data={id:Number(section.dataset.recordId||records()[index]?.id||0)};
      ['transaction_date','payment_type','amount','description','bank_name','current_account_id','installment_count','commission_rate'].forEach(name=>data[name]=value(name));
      if(data.payment_type==='term'){
        const dates=index<2?section.querySelectorAll(`[name="${prefix}term_date[]"]`):section.querySelectorAll('[data-stage-date]');
        const amounts=index<2?section.querySelectorAll(index===0?'[data-primary-term-amount]':'[data-term-amount]'):section.querySelectorAll('[data-stage-amount]');
        const paid=index<2?section.querySelectorAll(`[name="${prefix}term_paid[]"]`):section.querySelectorAll('[data-stage-paid]');
        data.term_schedule=[...dates].map((date,i)=>({date:date.value,amount:amounts[i]?.value||'',paid:!!paid[i]?.checked}));
      }
      return data;
    });
  }
  function incomeTotals(payments) {
    return payments.reduce((totals,payment)=>{
      if(payment.payment_type==='term'){
        for(const row of payment.term_schedule||[]){
          const amount=money(row.amount);
          if(row.paid)totals.paid+=amount;else totals.balance+=amount;
        }
      }else totals.paid+=money(payment.amount);
      return totals;
    },{paid:0,balance:0});
  }
  function paymentTypeSummary(payments){
    const labels=new Map(allTypes);
    return [...new Set(payments.map(payment=>labels.get(payment.payment_type)).filter(Boolean))].join(', ');
  }
  function syncSalePaymentSummary(form){
    const field=document.querySelector('#sales-details-modal [name="sales_payment_type"]');
    if(!field||!records().length)return;
    const payments=form.dataset.paymentOpened==='1'?readRecords(form):records();
    const summary=paymentTypeSummary(payments);
    if(!summary)return;
    let option=[...field.options].find(item=>item.value===summary);
    if(!option){option=new Option(summary,summary);option.dataset.paymentSummary='1';field.add(option);}
    if(field.value!==summary)field.value=summary;
    field.disabled=true;
    field.title='Gelir kayıtlarındaki farklı ödeme şekilleri';
  }
  function syncIncomeTotal(form){
    syncSalePaymentSummary(form);
    const header=form.querySelector('header');if(!header)return;
    let summary=header.querySelector('[data-income-header-total]');
    if(!summary){summary=document.createElement('span');summary.dataset.incomeHeaderTotal='1';header.append(summary);}
    const totals=incomeTotals(readRecords(form));
    const text='Ödenen: '+format(totals.paid)+' ₺'+(totals.balance>0?' · Bakiye: '+format(totals.balance)+' ₺':'');
    if(summary.textContent!==text)summary.textContent=text;
    if(!summary.dataset.aggregateStyled){summary.dataset.aggregateStyled='1';summary.style.cssText='margin-left:auto;color:#e6525d;font-size:13px;font-weight:700;white-space:nowrap';}
  }
  let saving=false;
  async function save(form) {
    if(saving)return;
    const invalid=[...form.querySelectorAll('.vox-date-editor')].find(input=>input.getClientRects().length&&(!input.value||!input.validity.valid));
    if(invalid){invalid.reportValidity();invalid.focus();return;}
    const payments=readRecords(form);
    const missingPaymentIndex=payments.findIndex(payment=>!allTypes.some(([type])=>type===payment.payment_type));
    if(missingPaymentIndex>=0){
      form.dataset.activePayment=String(missingPaymentIndex);
      syncPaymentTabs(form);
      const section=sections(form)[missingPaymentIndex];
      const prefix=missingPaymentIndex===0?'':missingPaymentIndex===1?'extra_':'payment'+(missingPaymentIndex+1)+'_';
      alert((missingPaymentIndex+1)+'. gelir kaydında ödeme şekli seçilmemiş. Lütfen bu kaydın ödeme şeklini seçiniz.');
      section?.querySelector(`[name="${prefix}payment_type"]`)?.focus();
      return;
    }
    const total=payments.reduce((sum,p)=>sum+(p.payment_type==='term'?p.term_schedule.reduce((n,row)=>n+money(row.amount),0):money(p.amount)),0);
    const saleTotal=money(document.querySelector('#sales-details-modal [name="sales_payment_amount"]')?.value);
    if(saleTotal>0&&Math.abs(total-saleTotal)>0.009){alert('Dört ödeme kaydının toplamı satış tutarına eşit olmalıdır. Satış tutarı: '+format(saleTotal)+' ₺');return;}
    saving=true;
    try{
      const data=new FormData();data.set('csrf',form.querySelector('[name="csrf"]').value);data.set('action','cash_update_only');data.set('ajax','1');data.set('edit_id',editId);data.set('payment_records_json',JSON.stringify(payments));
      const response=await fetch(endpoint,{method:'POST',body:data,credentials:'same-origin'});
      const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'Ödemeler kaydedilemedi.');
      window.__savedCashRecords=result.records;
      syncSalePaymentSummary(form);
      sections(form).forEach((section,i)=>section.dataset.recordId=String(result.records[i]?.id||''));
      const id=form.querySelector('[name="id"]');if(id)id.value=result.records[0]?.id||'';
      alert('Ödeme kayıtları kaydedildi.');
    }catch(error){alert(error.message);}finally{saving=false;}
  }
  // Register before the legacy two-payment listeners so every save uses all stages.
  window.addEventListener('click',event=>{
    const button=event.target.closest(formSelector+' footer button');if(!button||!isSale())return;
    const form=button.closest('form');
    if(button.matches('[aria-label="Bir gelir kaydı daha ekle"]')){
      if(sections(form).length<2)return;
      event.preventDefault();event.stopImmediatePropagation();createStage(form);return;
    }
    if(button.matches('[data-cash-close]'))return;
    event.preventDefault();event.stopImmediatePropagation();save(form);
  },true);
  window.addEventListener('submit',event=>{if(!event.target.matches(formSelector)||!isSale())return;event.preventDefault();event.stopImmediatePropagation();save(event.target);},true);
  const stageColors=['#e1effb','#e3f3e6','#fff1d8','#f0e6fa'];
  function syncPaymentTabs(form){
    const panels=sections(form);if(!panels.length)return;
    form.classList.add('payment-tabs-dialog');
    let tabs=form.querySelector('[data-payment-tabs]');
    if(!tabs){
      tabs=document.createElement('nav');tabs.dataset.paymentTabs='1';tabs.setAttribute('role','tablist');tabs.setAttribute('aria-label','Gelir kayıtları');
      panels[0].before(tabs);
      tabs.addEventListener('click',event=>{
        const button=event.target.closest('[data-payment-tab]');if(!button)return;
        form.dataset.activePayment=button.dataset.paymentTab;syncPaymentTabs(form);
      });
    }
    const previous=Number(tabs.dataset.count||0);
    if(previous!==panels.length){
      if(previous&&panels.length>previous)form.dataset.activePayment=String(panels.length-1);
      tabs.dataset.count=String(panels.length);
      tabs.replaceChildren(...panels.map((panel,index)=>{
        const button=document.createElement('button');button.type='button';button.dataset.paymentTab=String(index);button.setAttribute('role','tab');
        button.textContent=(index+1)+'. Gelir Kaydı';button.style.setProperty('--payment-color',stageColors[index]);
        panel.id='sale-payment-panel-'+index;button.setAttribute('aria-controls',panel.id);return button;
      }));
    }
    const active=Math.min(panels.length-1,Number(form.dataset.activePayment||0));
    panels.forEach((panel,index)=>{
      const display=index===active?'grid':'none';
      if(panel.style.getPropertyValue('display')!==display||panel.style.getPropertyPriority('display')!=='important')panel.style.setProperty('display',display,'important');
      if(panel.style.getPropertyValue('--payment-color')!==stageColors[index])panel.style.setProperty('--payment-color',stageColors[index]);
      const button=tabs.children[index];const selected=String(index===active);
      if(button.getAttribute('aria-selected')!==selected)button.setAttribute('aria-selected',selected);
    });
  }
  // Keep payment visibility independent of the window's decorative styles.
  function syncPaymentVisibility(form) {
    sections(form).forEach((section,index)=>{
      const prefix=index===0?'':index===1?'extra_':'payment'+(index+1)+'_';
      const type=section.querySelector(`[name="${prefix}payment_type"]`)?.value||'cash';
      const show=(name,visible)=>{
        const control=section.querySelector(`[name="${prefix}${name}"]`),label=control?.closest('label');
        if(!label)return;
        if(label.hidden===visible)label.hidden=!visible;
        const display=visible?'flex':'none';
        if(label.style.getPropertyValue('display')!==display||label.style.getPropertyPriority('display')!=='important')label.style.setProperty('display',display,'important');
      };
      show('bank_name',['eft_transfer','credit_card','mail_order'].includes(type));
      show('current_account_id',['mail_order','eft_transfer'].includes(type));
      show('installment_count',['credit_card','term'].includes(type));
      show('commission_rate',type==='credit_card');
      const accountLabel=section.querySelector(`[name="${prefix}current_account_id"]`)?.closest('label');
      const caption=[...(accountLabel?.childNodes||[])].find(n=>n.nodeType===Node.TEXT_NODE);
      const account=section.querySelector(`[name="${prefix}current_account_id"]`);
      const accountTitle=type==='eft_transfer'?'Ödemenin Geldiği Cari Hesap':'Cari Hesap';
      if(caption&&caption.nodeValue!==accountTitle)caption.nodeValue=accountTitle;
      if(account){
        [...account.options].forEach(option=>{
          const owner=option.value!=='' && (option.value===settings.ownerAccountId || /^CR-00(?:\s|$)/.test(option.textContent.trim()));
          const allowed=type==='eft_transfer'||!owner;
          if(option.hidden!==!allowed)option.hidden=!allowed;
          if(option.disabled!==!allowed)option.disabled=!allowed;
        });
        if(account.selectedOptions[0]?.disabled)account.value='';
      }
      section.querySelector('[data-company-payment-account]')?.remove();
    });
    syncPaymentTabs(form);
    syncIncomeTotal(form);
  }
  const initialized=new WeakSet();
  const initialize=()=>{
    const form=document.querySelector(formSelector);if(!form||!isSale()||initialized.has(form))return;
    initialized.add(form);
    form.dataset.aggregateIncomeTotal='1';
    form.addEventListener('input',()=>syncIncomeTotal(form));
    const restore=()=>{
      if(records().length>1&&!form.querySelector('[data-extra-income]'))return;
      while(sections(form).length<Math.min(4,records().length))createStage(form,records()[sections(form).length]);
      updateAdd(form);
    };
    new MutationObserver(restore).observe(form,{childList:true,subtree:true});
    restore();
    syncPaymentVisibility(form);
    new MutationObserver(()=>syncPaymentVisibility(form)).observe(form,{childList:true,subtree:true,attributes:true,attributeFilter:['style','hidden']});
    form.addEventListener('change',()=>syncPaymentVisibility(form));
  };
  new MutationObserver(initialize).observe(document.documentElement,{childList:true,subtree:true});
  window.addEventListener('DOMContentLoaded',initialize);
  document.addEventListener('change',initialize);
})();
