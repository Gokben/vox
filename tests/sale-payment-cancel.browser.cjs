const {chromium}=require('playwright');
const fs=require('fs');
const assert=require('node:assert/strict');
(async()=>{
 const browser=await chromium.launch({headless:true});
 const page=await browser.newPage();
 page.on('dialog',d=>d.accept());
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const script=fs.readFileSync(require('node:path').join(__dirname,'../assets/sale-payment-stages.js'),'utf8');
 for(const saved of [false,true]){
  await page.setContent(`<form id="service-card-form"><input name="service_name" value="Satış"></form><div id="sales-details-modal"><input name="sales_payment_amount" value="1000"><select name="sales_payment_type"><option value="cash">Nakit</option></select></div><div><form action="cash.php"><header><h2>Gelir</h2><button type="button" data-cash-close>X</button></header><input name="csrf" value="test"><input name="action" value="save_transaction"><input name="id"><section><input name="transaction_date" value="2026-09-24"><select name="payment_type"><option value="cash">Nakit</option></select><input name="amount" value="250"><input name="description" value="Test"></section><section data-extra-income><input name="extra_transaction_date" value="2026-09-24"><select name="extra_payment_type"><option value="cash">Nakit</option></select><input name="extra_amount" value="250"><input name="extra_description" value="Test"></section><footer><button type="button" aria-label="Bir gelir kaydı daha ekle">+</button><button type="button">Kaydet</button></footer></form></div>`);
  await page.addScriptTag({content:fs.readFileSync(require('node:path').join(__dirname,'../assets/classic-forms.js'),'utf8')});
  await page.evaluate(saved=>{
   window.__savedCashRecords=saved?Array.from({length:4},(_,i)=>({id:i+1,transaction_date:'2026-09-24',payment_type:'cash',amount:250,description:'Test'})):[];
   window.calls=[];
   window.fetch=async(url,opts)=>{
    const id=Number(opts.body.get('cash_delete_id'));
    window.calls.push(id);
    if(id!==window.__savedCashRecords.at(-1)?.id)return {ok:false,json:async()=>({success:false,message:'Wrong order'})};
    return {ok:true,json:async()=>({success:true,records:window.__savedCashRecords.slice(0,-1)})};
   };
  },saved);
  await page.addScriptTag({content:script.replace('document.currentScript.dataset',"({endpoint:'/test',editId:'1'})")});
  await page.dispatchEvent('#service-card-form input','change');
  const view=fs.readFileSync(require('node:path').join(__dirname,'../patient-followup.php'),'utf8').replace(/\r\n/g,'\n');
  const confirmCss=view.indexOf('<style>#vox-sales-confirm{');
  await page.addStyleTag({content:view.slice(confirmCss+7,view.indexOf('</style>',confirmCss))});
  await page.addStyleTag({content:'#vox-sales-confirm{z-index:999999!important}'});
  const confirmStart=view.indexOf('window.voxConfirm=');
  await page.addScriptTag({content:view.slice(confirmStart,view.indexOf("document.addEventListener('click'",confirmStart))});
  const legacyStart=view.indexOf('  const normalizeIncomeFooter=');
  const legacyEnd=view.indexOf('\n});\n</script>',legacyStart);
  await page.addScriptTag({content:'(()=>{'+view.slice(legacyStart,legacyEnd)+'})();'});
  const cashNormalizer=view.split('\n').find(line=>line.includes('const normalizeCashFooterButtons='));
  await page.addScriptTag({content:'(()=>{const cashSourceForm=document.querySelector(\'form[action="cash.php"]\'),cashRecordForm=cashSourceForm;'+cashNormalizer+'})();'});
  const position=view.lastIndexOf('const normalizeFooter=');
  const decorator=view.slice(view.lastIndexOf('<script>',position)+8,view.indexOf('</script>',position));
  const cssPosition=view.indexOf('/* Gelir Kayıt: Satış kartının üzerinde');
  await page.addStyleTag({content:view.slice(cssPosition,view.indexOf('</style>',cssPosition))});
  await page.addScriptTag({content:decorator});
  await page.waitForFunction(()=>document.querySelector('[data-cancel-last-income]')?.textContent==='X');
  assert.equal(await page.getByLabel('Kaydı Sil',{exact:true}).isVisible(),true);
  assert.equal(await page.getByLabel('Kaydet',{exact:true}).count(),1);
  assert.equal(await page.locator('[data-cancel-last-income]').getAttribute('title'),'Kaydı Sil');
  assert.equal(await page.locator('[data-cancel-last-income]').evaluate(e=>e.getBoundingClientRect().width),29);
  assert.equal(await page.locator('[data-cancel-last-income]').evaluate(e=>e.nextElementSibling?.getAttribute('aria-label')),'Bir gelir kaydı daha ekle');

  if(!saved){await page.getByLabel('Bir gelir kaydı daha ekle').click();await page.getByLabel('Bir gelir kaydı daha ekle').click();}
  for(let n=4;n>0;n--){
   await page.waitForFunction(n=>document.querySelectorAll('form[action="cash.php"] > section').length===n,n);
   await page.getByLabel('Kaydı Sil',{exact:true}).click();
   if(n===4){
    await page.getByRole('button',{name:'Vazgeç',exact:true}).click();
    assert.equal(await page.locator('form[action="cash.php"] > section').count(),4);
    assert.deepEqual(await page.evaluate(()=>window.calls),[]);
    await page.getByLabel('Kaydı Sil',{exact:true}).click();
   }
   await page.getByRole('button',{name:'Tamam',exact:true}).click();
   if(n>1)await page.waitForFunction(n=>document.querySelectorAll('form[action="cash.php"] > section').length===n,n-1);
  }
  assert.deepEqual(await page.evaluate(()=>window.calls),saved?[4,3,2,1]:[]);
  assert.equal(await page.locator('form[action="cash.php"]').evaluate(f=>f.parentElement.hidden),true);
  assert.equal(await page.locator('[name="amount"]').inputValue(),'');
  assert.deepEqual(errors,[]);
  console.log('PASS '+(saved?'saved':'draft')+' reverse stage cancellation and final close');
  // Fresh page avoids retaining document observers from the preceding fixture.
  await page.goto('about:blank');
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
