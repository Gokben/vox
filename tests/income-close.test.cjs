const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(process.argv[2]||require('node:path').join(__dirname,'../assets/sale-payment-stages.js'),'utf8');
const code=source.slice(source.indexOf('  let saving=false;'),source.indexOf('  const stageColors='));
async function scenario({confirm=true,success=true,missing=false}={}){
 const listeners=[],alerts=[];let calls=0,release;
 const modal={hidden:false,style:{display:'grid'}};
 const panels=[0,1,2].map(()=>({dataset:{},querySelector:()=>null}));
 const rows=['cash','eft_transfer','eft_transfer'].map((payment_type,i)=>({payment_type:missing&&i===1?'':payment_type,amount:i===2?45000:50000}));
 const form={dataset:{},parentElement:modal,querySelectorAll:()=>[],querySelector:s=>s==='[name="csrf"]'?{value:'test'}:null};
 const close={closest:()=>form};
 const context={window:{addEventListener:(type,fn)=>listeners.push({type,fn})},document:{querySelector:()=>({value:'145000'})},formSelector:'form',isSale:()=>true,confirm:()=>confirm,alert:x=>alerts.push(x),readRecords:()=>rows,allTypes:[['cash'],['eft_transfer']],sections:()=>panels,syncPaymentTabs:()=>{},syncSalePaymentSummary:()=>{},money:Number,format:String,FormData,endpoint:'test',editId:'1',fetch:async(_,opts)=>{calls++;assert.equal(JSON.parse(opts.body.get('payment_records_json')).length,3);await new Promise(r=>release=r);return {ok:success,json:async()=>({success,message:'Save failed',records:[{id:1},{id:2},{id:3}]})};}};
 vm.runInNewContext(code,context);
 const click=()=>listeners[0].fn({target:{closest:()=>close},preventDefault(){},stopImmediatePropagation(){}});
 click();assert.equal(modal.hidden,false,'must stay open until save completes');
 if(confirm&&!missing){click();assert.equal(calls,1,'double click must not duplicate writes');release();await new Promise(r=>setImmediate(r));assert.equal(modal.hidden,success);if(success)assert.equal(panels[2].dataset.recordId,'3');else assert.equal(alerts[0],'Save failed');}
 else assert.equal(calls,0,'cancel or invalid payment must not save');
}
(async()=>{await scenario();await scenario({confirm:false});await scenario({success:false});await scenario({missing:true});console.log('PASS: X confirms; cancel and validation keep open; pending saves block duplicates; success closes; failure stays open');})().catch(e=>{console.error(e);process.exit(1)});
