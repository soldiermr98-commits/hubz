// Synthetic browser test; gateway and finance responses are mocked, never sent to a provider.
import assert from 'node:assert/strict';
import React,{act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/advertising/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');const {AdvertisingFinancePanel}=await import('../src/components/AdvertisingFinancePanel');
const contracts:any[]=[{id:1,title:'قرارداد ساختگی',client_name:'مشتری ساختگی',client_phone:'',banner_title:null,amount_toman:100000,paid_toman:0,remaining_toman:100000,status:'active',version:1,created_at:'fixture'}];
const online:any[]=[],audits:any[]=[],calls:any[]=[];
const providers:any[]=[{id:'zibal',label:'زیبال',mode:'disabled',key_configured:false,version:0,error:''},{id:'aqayepardakht',label:'آقای پرداخت',mode:'disabled',key_configured:false,version:0,error:''},{id:'idpay',label:'آیدی‌پی',mode:'disabled',key_configured:false,version:0,error:''}];
let linkAttempts=0,firstLink:any,settingsAttempts=0,resolveAttempts=0;
const json=(body:any,status=200)=>new Response(JSON.stringify(body),{status,headers:{'content-type':'application/json'}});
globalThis.fetch=async(input,options={})=>{
 const url=String(input),method=options.method||'GET';
 if(method==='GET'&&url==='/api/v1/advertising/finance')return json({ok:true,schema_ready:true,online_sandbox_enabled:true,contracts,manual_payments:[],online_payments:online,status_log:[],online_audit:audits});
 if(method==='GET'&&url==='/api/v1/advertising/gateways')return json({ok:true,schema_ready:true,providers,log:[]});
 const body=options.body?JSON.parse(String(options.body)):null;calls.push({url,body,csrf:new Headers(options.headers).get('X-CSRF-Token')});
 if(url==='/api/v1/advertising/gateways/save'){
   settingsAttempts++;assert.equal(body.confirm_live,false);assert.notEqual(body.mode,'live');
   const p=providers.find(x=>x.id===body.provider);p.mode=body.mode;p.key_configured=!body.remove;p.version++;
   if(settingsAttempts===2)return json({ok:false,error:'timeout'},504);
   return json({ok:true,message:'تنظیمات ثبت شد'});
 }
 if(url==='/api/v1/advertising/payments/online'){
   linkAttempts++;
   if(linkAttempts===1){firstLink=body;online.push({id:2,contract_id:1,provider:'zibal',mode:'sandbox',state:'link_ready',amount_toman:body.amount_toman,order_id:'adfixture',created_at:'fixture'});contracts[0].version++;throw new Error('fixture lost response');}
   assert.deepEqual(body,firstLink,'same link payload and capability must be retried');
   if(linkAttempts===2)return json({ok:true,link:{id:2,url:'https://attacker.invalid/api/v1/public/advertising-payment/'+body.link_token}});
   if(linkAttempts===3)return json({ok:false,error:'session expired'},401);
   return json({ok:true,link:{id:2,state:'link_ready',url:'https://example.test/api/v1/public/advertising-payment/'+body.link_token,replayed:true}});
 }
 if(url==='/api/v1/advertising/payments/online/resolve'){
   resolveAttempts++;assert.equal(body.confirmed,true);contracts[0].version++;
   if(body.resolution==='cancel_link'){
     online[0].state='cancelled';audits.push({id:3,payment_id:2,old_state:'link_ready',new_state:'cancelled',reason:body.reason,changed_by:1,changed_at:'fixture'});
     return json({ok:false,error:'lost response'},504);
   }
   assert.equal(body.resolution,'not_paid');assert.equal(body.checked_provider,true);assert.equal(body.no_future_charge_confirmed,true);
   online.find(p=>p.id===body.payment_id).state='cancelled';return json({ok:true,message:'تصمیم آزمایشی ثبت شد'});
 }
 throw new Error('Unexpected request '+url);
};
const pause=()=>new Promise(r=>setTimeout(r,0));const root=createRoot(document.getElementById('root')!);
const button=(text:string,scope:ParentNode=document)=>{const x=[...scope.querySelectorAll('button')].find(b=>b.textContent?.trim()===text);assert(x,'missing button '+text);return x as HTMLButtonElement;};
const control=(text:string,scope:ParentNode=document)=>{const x=[...scope.querySelectorAll('label')].find(l=>l.textContent?.trim().startsWith(text));assert(x,'missing label '+text);const f=x.querySelector('input,select,textarea');assert(f,'missing field '+text);return f as HTMLInputElement;};
async function click(e:HTMLElement){await act(async()=>{e.click();await pause();});}
async function type(e:HTMLInputElement,value:string){await act(async()=>{const proto=e.tagName==='SELECT'?dom.window.HTMLSelectElement.prototype:e.tagName==='TEXTAREA'?dom.window.HTMLTextAreaElement.prototype:dom.window.HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value')!.set!.call(e,value);e.dispatchEvent(new dom.window.Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}));await pause();});}
async function render(){await act(async()=>{root.render(<AdvertisingFinancePanel csrfToken="fixture-csrf" banners={[]}/>);await pause();await pause();});}
dom.window.confirm=()=>true;dom.window.prompt=()=> 'دلیل آزمایشی برای لغو لینک';
try {
 dom.window.sessionStorage.clear();await render();
 assert(document.body.textContent?.includes('درگاه آزمایشی — بدون دریافت وجه واقعی'));
 assert(button('لینک آزمایشی').disabled,'no gateway configured');
 await type(control('حالت زیبال'),'sandbox');await type(control('کلید زیبال'),'zibal');await click(button('ذخیره تنظیمات زیبال'));
 assert.equal(settingsAttempts,1);assert.equal(dom.window.sessionStorage.length,0,'credentials are never stored in browser storage');assert.equal(control('کلید زیبال').value,'');
 assert(!button('لینک آزمایشی').disabled);
 // A 504 on settings leaves a review gate; key is immediately cleared, never retried automatically.
 await type(control('کلید زیبال'),'zibal');await click(button('ذخیره تنظیمات زیبال'));assert.equal(settingsAttempts,2);assert.equal(control('کلید زیبال').value,'');assert(button('لینک آزمایشی').disabled);
 assert.equal(dom.window.sessionStorage.getItem('hhx.advertising.gateway.pending.v1'),'1','only a non-secret review marker persists');
 await act(async()=>{root.render(<div/>);await pause();});await render();assert(button('لینک آزمایشی').disabled,'unresolved settings survive remount');
 await click(button('بررسی مجدد تنظیمات'));await click(button('پایان بررسی تنظیمات'));assert(!button('لینک آزمایشی').disabled);assert.equal(dom.window.sessionStorage.getItem('hhx.advertising.gateway.pending.v1'),null);
 await click(button('لینک آزمایشی'));await type(control('مبلغ آزمایشی'),'30000');await type(control('درگاه آزمایشی'),'zibal');
 assert(button('ساخت لینک آزمایشی').disabled);await click(control('این قرارداد ساختگی است'));await click(button('ساخت لینک آزمایشی'));
 assert.equal(linkAttempts,1);assert.match(firstLink.request_key,/^[a-f0-9]{64}$/);assert.match(firstLink.link_token,/^[a-f0-9]{64}$/);assert.notEqual(firstLink.request_key,firstLink.link_token);
 assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'));assert(button('قرارداد جدید').disabled);
 await act(async()=>{root.render(<div/>);await pause();});await render();
 await click(button('تکرار امن همان درخواست'));assert.equal(linkAttempts,2);assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'),'foreign-origin link response cannot clear pending');
 await click(button('تکرار امن همان درخواست'));assert.equal(linkAttempts,3);assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'),'later 401 cannot clear pending');
 await click(button('تکرار امن همان درخواست'));assert.equal(linkAttempts,4);assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'),'successful link remains recoverable until acknowledged');
 await act(async()=>{root.render(<div/>);await pause();});await render();await click(button('تکرار امن همان درخواست'));assert.equal(linkAttempts,5);
 const link=document.querySelector('input[aria-label="لینک آزمایشی"]') as HTMLInputElement;assert.equal(link.value,'https://example.test/api/v1/public/advertising-payment/'+firstLink.link_token);
 dom.window.confirm=()=>false;await click(button('لینک را نگه داشتم؛ پایان پیگیری'));assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'));
 dom.window.confirm=()=>true;await click(button('لینک را نگه داشتم؛ پایان پیگیری'));assert.equal(dom.window.sessionStorage.length,0);
 assert(button('پرداخت دستی').disabled,'open sandbox blocks manual ledger changes');
 const card=document.querySelector('[aria-label="قرارداد قرارداد ساختگی"]')!;const details=card.querySelector('details')!;details.open=true;
 await click(button('لغو لینک آزمایشی شروع‌نشده',card));assert.equal(resolveAttempts,1);assert(dom.window.sessionStorage.getItem('hhx.advertising.finance.pending.v1'));
 assert(![...document.querySelectorAll('button')].some(b=>b.textContent?.trim()==='تکرار امن همان درخواست'),'resolution is never automatically retried');
 await click(button('بررسی مجدد فهرست و تاریخچه'));assert(document.body.textContent?.includes('دلیل آزمایشی برای لغو لینک'));
 await click(button('پایان پیگیری پس از بررسی'));assert.equal(dom.window.sessionStorage.length,0);assert.equal(online[0].state,'cancelled');
 online.push({id:4,contract_id:1,provider:'zibal',mode:'sandbox',state:'uncertain',amount_toman:20000,order_id:'adfixture2',created_at:'fixture'});contracts[0].version++;
 await click(button('تازه‌سازی قراردادها'));const card2=document.querySelector('[aria-label="قرارداد قرارداد ساختگی"]')!;(card2.querySelector('details') as HTMLDetailsElement).open=true;
 await click(button('بررسی تراکنش آزمایشی',card2));await type(control('نتیجه بررسی'),'not_paid');await type(control('دلیل بررسی'),'درگاه آزمایشی مستقل بررسی شد');
 assert(button('ثبت نتیجه بررسی آزمایشی').disabled);await click(control('وضعیت را مستقل'));assert(button('ثبت نتیجه بررسی آزمایشی').disabled);
 await click(control('نبود امکان پرداخت بعدی'));await click(button('ثبت نتیجه بررسی آزمایشی'));assert.equal(resolveAttempts,2);assert.equal(online[1].state,'cancelled');
 assert(calls.every(x=>x.csrf==='fixture-csrf'));assert(!calls.some(x=>x.body?.mode==='live'));
 console.log('PASS: sandbox-only gateway settings, secret handling, safe link creation/replay, origin validation and manual reconciliation after ambiguity.');
} finally {await act(async()=>root.unmount());dom.window.close();}
