import {toJalaali} from 'jalaali-js';
// Isolated jsdom UI test; all ledger responses are synthetic and no gateway is contacted.
import assert from 'node:assert/strict';
import React, {act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');
const {AdvertisingFinancePanel}=await import('../src/components/AdvertisingFinancePanel');
const calls:Array<{url:string;body:any;csrf:string|null}>=[];
const contracts:any[]=[],manual:any[]=[],statusLog:any[]=[];
let contractAttempts=0,manualAttempts=0,firstContract:any,firstManual:any;
const json=(value:any,status=200)=>new Response(JSON.stringify(value),{status,headers:{'content-type':'application/json'}});
globalThis.fetch=async(input,options={})=>{
  const url=String(input),method=options.method||'GET';
  if(method==='GET'&&url==='/api/v1/advertising/finance')return json({ok:true,schema_ready:true,contracts,manual_payments:manual,online_payments:[],status_log:statusLog,online_audit:[]});
  const body=options.body?JSON.parse(String(options.body)):null;
  calls.push({url,body,csrf:new Headers(options.headers).get('X-CSRF-Token')});
  if(url==='/api/v1/advertising/contracts'){
    contractAttempts++;
    if(contractAttempts===1){firstContract=body;contracts.push({id:1,version:1,...body,amount_toman:100000,manual_paid:0,online_paid:0,paid_toman:0,remaining_toman:100000,status:'active',created_at:'fixture'});throw new Error('fixture timeout after commit');}
    assert.deepEqual(body,firstContract,'retry must use the identical immutable payload and key');
    if(contractAttempts===2)return json({ok:false,error:'login expired'},401);
    return json({ok:true,message:'قرارداد ثبت شد',contract:{id:1,replayed:true}});
  }
  if(url==='/api/v1/advertising/payments/manual'){
    manualAttempts++;
    if(manualAttempts===1){firstManual=body;manual.push({id:2,contract_id:1,amount_toman:40000,method:body.method,reference:body.reference,notes:body.notes,status:'recorded',recorded_at:'fixture'});Object.assign(contracts[0],{version:2,paid_toman:40000,manual_paid:40000,remaining_toman:60000});return json({ok:false,error:'timeout'},504);}
    assert.deepEqual(body,firstManual,'manual retry must not create a new key or change the amount');return json({ok:true,message:'پرداخت ثبت شد',payment:{id:2,replayed:true}});
  }
  if(url==='/api/v1/advertising/contracts/status'){
    assert.equal(body.version,2);Object.assign(contracts[0],{status:body.status,version:3});statusLog.push({id:1,contract_id:1,old_status:'active',new_status:body.status,reason:body.reason,changed_at:'fixture'});return json({ok:true,message:'وضعیت ثبت شد'});
  }
  if(url==='/api/v1/advertising/payments/manual/void'){
    manual[0].status='voided';manual[0].void_reason=body.reason;Object.assign(contracts[0],{version:4,paid_toman:0,manual_paid:0,remaining_toman:100000});return json({ok:true,message:'ابطال ثبت شد'});
  }
  throw new Error('Unexpected request '+url);
};
const pause=()=>new Promise(resolve=>setTimeout(resolve,0));
const root=createRoot(document.getElementById('root')!);
const button=(text:string,scope:ParentNode=document)=>{const el=[...scope.querySelectorAll('button')].find(x=>x.textContent?.trim()===text);assert(el,`Missing button: ${text}`);return el as HTMLButtonElement;};
const control=(label:string)=>{const el=[...document.querySelectorAll('label')].find(x=>x.textContent?.trim().startsWith(label));assert(el,`Missing label: ${label}`);const field=el.querySelector('input,textarea,select');assert(field,`Missing control: ${label}`);return field as HTMLInputElement;};
async function click(el:HTMLElement){await act(async()=>{el.click();await pause();});}
async function type(el:HTMLInputElement,value:string){await act(async()=>{const proto=el.tagName==='TEXTAREA'?dom.window.HTMLTextAreaElement.prototype:dom.window.HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value')!.set!.call(el,value);el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});}
async function chooseDate(label:string, iso:string){
  const start=toJalaali(new Date()); const target=toJalaali(...iso.split('-').map(Number) as [number,number,number]);
  await click(document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)!);
  const diff=(target.jy-start.jy)*12+target.jm-start.jm;
  for(let i=0;i<Math.abs(diff);i++) await click(document.querySelector<HTMLButtonElement>(`button[aria-label="ماه ${diff>0?'بعد':'قبل'}"]`)!);
  const months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];const fa=(n:number)=>n.toLocaleString('fa-IR',{useGrouping:false});
  await click(document.querySelector<HTMLButtonElement>(`button[aria-label="${fa(target.jd)} ${months[target.jm-1]} ${fa(target.jy)}"]`)!);
}
async function render(){await act(async()=>{root.render(<AdvertisingFinancePanel csrfToken="fixture-csrf" banners={[]}/>);await pause();});}
dom.window.confirm=()=>true;dom.window.prompt=()=> 'دلیل آزمایشی کافی';
try {
  dom.window.sessionStorage.clear();await render();
  assert(document.body.textContent?.includes('ثبت دستی فقط اظهار مدیر'));
  await click(button('قرارداد جدید'));
  await type(control('عنوان قرارداد'),'قرارداد نمونه');await type(control('طرف قرارداد'),'مشتری نمونه');await type(control('مبلغ قرارداد'),'۱۰۰۰۰۰');
  await type(control('توضیحات قرارداد'),'شرایط مالی ساختگی');await chooseDate('شروع قرارداد','2026-11-01');await chooseDate('پایان قرارداد','2026-12-01');
  dom.window.confirm=()=>false;await click(button('ثبت قرارداد'));assert.equal(contractAttempts,0,'cancelled confirmation must not mutate');
  dom.window.confirm=()=>true;await click(button('ثبت قرارداد'));assert.equal(contractAttempts,1);
  assert.match(firstContract.request_key,/^[a-f0-9]{64}$/);assert.equal(firstContract.amount_toman,'۱۰۰۰۰۰');assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'));
  assert(button('قرارداد جدید').disabled,'ambiguous result blocks new mutation');
  await act(async()=>{root.render(<div/>);await pause();});await render();
  assert(document.body.textContent?.includes('نتیجه یک درخواست مالی هنوز نیاز به پیگیری دارد'),'pending survives remount in the same tab');
  await click(button('تکرار امن همان درخواست'));assert.equal(contractAttempts,2);
  assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'),'a later 401 must not erase an earlier ambiguous result');
  assert(button('قرارداد جدید').disabled);await click(button('تکرار امن همان درخواست'));assert.equal(contractAttempts,3);assert.equal(contracts.length,1);assert.equal(dom.window.sessionStorage.length,0);
  await click(button('پرداخت دستی'));await type(control('مبلغ پرداخت'),'40000');await type(control('مرجع یا شماره رسید'),'fixture-reference-1');await type(control('توضیح (اختیاری)'),'یادداشت ساختگی');
  assert(button('ثبت پرداخت دستی').disabled,'manual assertion confirmation required');
  await click(control('رسید را بررسی کرده‌ام'));await click(button('ثبت پرداخت دستی'));
  assert.equal(manualAttempts,1);assert(button('قرارداد جدید').disabled,'504 is ambiguous');
  await click(button('تکرار امن همان درخواست'));assert.equal(manualAttempts,2);assert.equal(manual.length,1);
  const card=document.querySelector('[aria-label="قرارداد قرارداد نمونه"]')!;
  assert(card.textContent?.includes('۶۰٬۰۰۰ تومان'));assert(card.textContent?.includes('شرایط مالی ساختگی'));assert(card.textContent?.includes('یادداشت ساختگی'));
  assert(card.textContent?.includes('2026-11-01')&&card.textContent?.includes('2026-12-01'),'immutable dates remain visible');
  assert(button('تکمیل قرارداد',card).disabled,'unpaid contract cannot be completed');
  await click(button('لغو قرارداد',card));assert.equal(contracts[0].status,'cancelled');assert.equal(statusLog.length,1);
  const details=card.querySelector('details')!;details.open=true;await click(button('ابطال ثبت دستی',card));assert.equal(manual[0].status,'voided');
  assert(calls.every(c=>c.csrf==='fixture-csrf'));assert(calls.every(c=>!c.url.includes('gateway')&&!c.url.includes('link')));
  console.log('PASS: finance forms, explicit confirmations, ambiguous request recovery across remount, exact idempotent retries, status and void UI.');
} finally {await act(async()=>root.unmount());dom.window.close();}
