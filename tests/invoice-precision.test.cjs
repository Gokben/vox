const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const source=fs.readFileSync(process.argv[2]||require('node:path').join(__dirname,'../invoice-entry-v2.php'),'utf8');
const percent=source.slice(source.indexOf('percent=v=>')+8,source.indexOf(',money=v=>'));
const calculation=source.slice(source.indexOf('const rate=()=>'),source.indexOf(',refresh=()=>'))+';';
const blur=source.match(/discount.onblur=\(\)=>\{[^}]+\}/)[0]+';';
function row(q,c,d){
 const ctx={qty:{value:String(q)},cost:{value:String(c)},discount:{value:d},purchaseTotal:{value:'0'},vat:{value:'0'},vatTotal:{},discountTotal:{},priceSource:{value:'unit'},updateSummary(){},money:v=>new Intl.NumberFormat('tr-TR',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0)),num:v=>Number(String(v||'').replaceAll('.','').replace(',','.'))||0};
 vm.createContext(ctx);vm.runInContext('const percent='+percent+';'+calculation+blur+'discount.onblur();',ctx);return ctx;
}
const a=row(2,6000,'64,7059'),b=row(1,5000,'64,7058');
assert.equal(a.discount.value,'%64,7059');assert.equal(a.purchaseTotal.value,'4.235,29');assert.equal(b.purchaseTotal.value,'1.764,71');
assert.equal(a.discountTotal.textContent,'7.764,71 TL');assert.equal(b.discountTotal.textContent,'3.235,29 TL');
for(let i=0;i<3;i++){a.discount.onblur();assert.equal(a.purchaseTotal.value,'4.235,29');}
assert.equal(row(8,31500,'55').purchaseTotal.value,'113.400,00');
assert.equal(row(2,19500,'40').purchaseTotal.value,'23.400,00');
console.log('PASS: proportional discount, focus changes, and prior whole-percent invoices');

const bicore=row(2,22800,"52,592705"),kit=row(1,86000,"52,592705");
assert.equal(bicore.purchaseTotal.value,"21.617,73");assert.equal(kit.purchaseTotal.value,"40.770,27");
for(let i=0;i<3;i++){kit.discount.onblur();assert.equal(kit.purchaseTotal.value,"40.770,27");}
assert.equal(bicore.num(bicore.purchaseTotal.value)+kit.num(kit.purchaseTotal.value)+27000,89388);
console.log("PASS: Bicore and Styletto allocation totals exactly 89388 including chargers and VAT");
