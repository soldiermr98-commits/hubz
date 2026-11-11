// Synthetic API and jsdom only. No real Apify account, paid run, or browser is used.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';

const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'http://example.test/admin/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');
const {JsonImportView}=await import('../src/components/JsonImportView');
let timerId=0;const timers=new Map<number,()=>Promise<void>>();let scrolled=0;
dom.window.HTMLElement.prototype.scrollIntoView=()=>{scrolled++;};
dom.window.setInterval=((callback:()=>Promise<void>)=>{const id=++timerId;timers.set(id,callback);return id;}) as typeof dom.window.setInterval;
dom.window.clearInterval=((id:number)=>timers.delete(id)) as typeof dom.window.clearInterval;
let confirmAnswer=true;const dialogs:string[]=[];
dom.window.confirm=(message?:string)=>{dialogs.push(message||'');return confirmAnswer;};

const calls:Array<{url:string;method:string;body:any}>=[];
const schedule:any={enabled:0,mode:'legacy',task_id:'',city_id:null,category_id:null,max_charge_usd:null,source_categories:[],min_rating:0,min_reviews:0,next_run_at:null,last_error:null};
let runs:any[]=[];let latestWithoutDestination=false;let mixed=false;let failPreview=false;let missingTime=false;
const item={title:'دندانپزشک آزمایشی',city:'لاهیجان',categoryName:'دندانپزشک',placeId:'ChIJTestFixture0001'};
const item2={title:'هتل نامرتبط',city:'لاهیجان',categoryName:'هتل',placeId:'ChIJTestFixture0002'};
const readyRun={id:42,task_id:'__hami_direct__',remote_run_id:'SyntheticRun42',status:'ready',city_id:2,category_id:3,source_categories:[],min_rating:0,min_reviews:0,started_at_iso:'2026-01-01T10:00:00Z',finished_at_iso:'2026-01-01T10:01:00Z',item_count:1,items:[item]};
let reviewNavigation=0;
globalThis.fetch=async(input:RequestInfo|URL,options:RequestInit={})=>{
  const url=String(input),method=options.method||'GET',body=options.body?JSON.parse(String(options.body)):null;
  calls.push({url,method,body});
  const result=(data:any)=>new Response(JSON.stringify(data),{status:200,headers:{'content-type':'application/json'}});
  if(url.endsWith('/status'))return result({ok:true,token_configured:true,schedule:{...schedule},runs:runs.map(r=>({...r})),search_terms:{'3':'دندانپزشکی'}});
  if(url.endsWith('/start')){
    runs=[{...readyRun,status:'running',item_count:null,error:null,created_at:'2026-01-01 10:00:00',updated_at:'2026-01-01 10:00:00'}];
    return result({ok:true,status:'started',run_id:42});
  }
  if(url.endsWith('/refresh')){runs=[{...runs[0],status:'ready',item_count:1}];return result({ok:true});}
  if(url.endsWith('/runs/42'))return result({ok:true,run:{...readyRun,city_id:latestWithoutDestination?null:2,category_id:latestWithoutDestination?null:3,started_at_iso:missingTime?null:readyRun.started_at_iso,items:mixed?[item,item2]:[item]}});
  if(url.endsWith('/latest'))return result({ok:true,id:42});
  if(url.endsWith('/schedule')){Object.assign(schedule,{...body,mode:'direct',next_run_at:'2026-01-15 10:00:00'});return result({ok:true});}
  if(url.endsWith('/json/prepare'))return result({ok:true,items:body.items.map((r:any)=>({name:r.title,city:r.city,category:r.categoryName,source:'google_maps',source_id:r.placeId,address:'نشانی آزمایشی'}))});
  if(url.endsWith('/json/preview')){
    if(failPreview)return result({ok:false,error:'خطای ساختگی پیش‌نمایش'});
    return result({ok:true,rows:body.items.map((r:any,index:number)=>({index,name:r.name,source_category:r.category,source:r.source,source_id:r.source_id,rating:4.5,reviews_count:22,status:'new',message:'مورد جدید',candidates:[]}))});
  }
  if(url.endsWith('/json/apply'))return result({ok:true,results:body.entries.map((r:any)=>({index:r.index,status:'created',business_id:r.index+1,message:'ثبت شد'}))});
  throw new Error('Unexpected synthetic request: '+url);
};
const city:any={id:2,name_fa:'لاهیجان',slug:'lahijan',is_active:1};
const category:any={id:3,name_fa:'کلینیک و دندانپزشکی',slug:'clinic',is_active:1};
const root=createRoot(document.getElementById('root')!);
const pause=()=>new Promise(resolve=>setTimeout(resolve,0));
function button(label:string):HTMLButtonElement {
  const found=Array.from(document.querySelectorAll('button')).find(b=>b.textContent?.trim()===label);
  assert.ok(found,'Missing button: '+label);return found;
}
async function click(label:string){await act(async()=>{button(label).click();await pause();await pause();});}
async function changeSelect(element:HTMLSelectElement,value:string){await act(async()=>{element.value=value;element.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});}
async function changeInput(element:HTMLInputElement,value:string){await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(element,value);element.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});}
function count(url:string){return calls.filter(c=>c.url.endsWith(url)).length;}
function pass(label:string){console.log('PASS:',label);}

try {
  await act(async()=>{root.render(<JsonImportView cities={[city]} categories={[category]} csrfToken="synthetic-csrf" onRefreshData={async()=>{}} onGoReview={()=>reviewNavigation++}/>);await pause();});
  assert.ok(document.body.textContent?.includes('جمع‌آوری از Google Maps'));
  assert.ok(!document.body.textContent?.includes('Task ID')&&!document.body.textContent?.includes('Run ID'));
  assert.ok(document.body.textContent?.includes('هنوز نتیجه‌ای باز نشده'));
  pass('simple initial panel explains the next step without Task/Run IDs');
  await click('جمع‌آوری');assert.equal(count('/start'),0);
  assert.ok(document.querySelector('[role=alert]')?.textContent?.includes('شهر'));
  const selects=Array.from(document.querySelectorAll('select'));
  await changeSelect(selects[0],'2');await changeSelect(selects[1],'3');
  assert.ok(document.body.textContent?.includes('عبارت جست‌وجو: دندانپزشکی'));
  const cap=Array.from(document.querySelectorAll('input')).find(i=>i.type==='number'&&i.max==='100');assert.ok(cap);await changeInput(cap,'0.29');
  confirmAnswer=false;await click('جمع‌آوری');
  assert.equal(count('/start'),0);assert.ok(dialogs.at(-1)?.includes('0.29')&&dialogs.at(-1)?.includes('دندانپزشکی'));
  pass('paid collection cannot start without destination and explicit cost confirmation');
  confirmAnswer=true;await click('جمع‌آوری');
  assert.equal(count('/start'),1);
  assert.deepEqual(calls.find(c=>c.url.endsWith('/start'))?.body,{city_id:2,category_id:3,max_places:20,max_charge_usd:0.29,confirmed:true,expected_search_term:'دندانپزشکی'});
  assert.equal(timers.size,1);
  Object.defineProperty(document,'hidden',{value:true,configurable:true});
  await act(async()=>{await [...timers.values()][0]();await pause();});assert.equal(count('/refresh'),0);
  Object.defineProperty(document,'hidden',{value:false,configurable:true});
  await act(async()=>{await [...timers.values()][0]();await pause();await pause();});
  assert.equal(count('/start'),1);assert.equal(count('/refresh'),1);
  assert.equal(count('/json/prepare'),1);assert.equal(count('/json/preview'),1);assert.equal(count('/json/apply'),0);
  assert.ok(document.body.textContent?.includes('پیش‌نمایش و انتخاب'));
  assert.ok(document.body.textContent?.includes('دندانپزشک آزمایشی'));
  assert.ok(document.body.textContent?.includes('نشانی آزمایشی'));
  assert.equal(button('ثبت 0 مورد انتخاب‌شده').disabled,true,'even a single category requires an explicit selection');
  assert.ok(scrolled>0,'opening result should scroll to it');
  pass('finished direct run scrolls to an automatic preview without saving or preselecting records');

  confirmAnswer=false;await click('دریافت آخرین نتیجه موجود');
  assert.ok(dialogs.at(-1)?.includes('جایگزین'));assert.equal(count('/start'),1);assert.equal(count('/json/preview'),1);
  pass('preview replacement requires confirmation and latest starts no paid run');
  confirmAnswer=true;latestWithoutDestination=true;mixed=true;await click('دریافت آخرین نتیجه موجود');
  assert.equal(count('/json/preview'),1,'old run without destination must not guess it');
  assert.ok(document.body.textContent?.includes('شهر و دسته این نتیجه مشخص نیست'));
  assert.ok(document.body.textContent?.includes('هتل نامرتبط'));
  assert.ok(document.body.textContent?.includes('شهرهای نوشته‌شده در داده: لاهیجان'));
  const destination=Array.from(document.querySelectorAll('select'));
  assert.equal(destination[2].value,'0');assert.equal(destination[3].value,'0');
  await changeSelect(destination[2],'2');await changeSelect(destination[3],'3');await click('نمایش پیش‌نمایش');
  assert.equal(count('/json/preview'),2);assert.equal(count('/json/apply'),0);
  assert.equal(button('ثبت 0 مورد انتخاب‌شده').disabled,true);
  const rowChecks=Array.from(document.querySelectorAll('tbody input[type=checkbox]')) as HTMLInputElement[];
  assert.equal(rowChecks.length,2);assert.ok(rowChecks.every(c=>!c.checked));
  assert.ok(document.body.textContent?.includes('دسته‌های منبع متفاوت یا نامشخص‌اند'));
  pass('old mixed-category result shows raw examples, asks destination, and selects no records silently');
  const searchInput=document.querySelector('input[aria-label="جستجو در پیش‌نمایش"]') as HTMLInputElement;
  await changeInput(searchInput,'دندانپزشک');await click('انتخاب موارد قابل ثبتِ نمایش‌داده‌شده');
  await changeInput(searchInput,'');
  const checkedAfterFilter=Array.from(document.querySelectorAll('tbody input[type=checkbox]')) as HTMLInputElement[];
  assert.equal(checkedAfterFilter.filter(c=>c.checked).length,1,'select visible must not select hidden mismatched categories');
  confirmAnswer=false;await click('ثبت 1 مورد انتخاب‌شده');assert.equal(count('/json/apply'),0);
  assert.ok(dialogs.at(-1)?.includes('بلافاصله'));
  confirmAnswer=true;await click('ثبت 1 مورد انتخاب‌شده');assert.equal(count('/json/apply'),1);
  assert.equal(calls.find(c=>c.url.endsWith('/json/apply'))?.body.entries.length,1);
  assert.ok(document.body.textContent?.includes('موارد جدید برای تأیید و انتشار'));
  await click('رفتن به بازبینی');assert.equal(reviewNavigation,1);
  pass('explicit selection and confirmation precede registration; next step links to review');

  latestWithoutDestination=false;mixed=true;failPreview=true;await click('دریافت آخرین نتیجه موجود');
  assert.equal(count('/json/preview'),3);
  assert.ok(document.querySelector('[role=alert]')?.textContent?.includes('خطای ساختگی'));
  failPreview=false;await click('نمایش پیش‌نمایش');assert.equal(count('/json/preview'),4);
  assert.equal(button('ثبت 0 مورد انتخاب‌شده').disabled,true);
  pass('failed automatic preview surfaces an error and can be retried without applying');

  missingTime=true;await click('دریافت آخرین نتیجه موجود');
  assert.equal(count('/json/preview'),4,'missing time must prevent auto preview');
  assert.ok(document.body.textContent?.includes('زمان شروع مشخص نیست'));
  await click('نمایش پیش‌نمایش');assert.ok(document.querySelector('[role=alert]')?.textContent?.includes('زمان شروع جمع‌آوری'));
  pass('missing observation time is not guessed');

  await click('فعال‌سازی / تغییر تکرار');
  const saved=calls.filter(c=>c.url.endsWith('/schedule'));
  assert.equal(saved.length,1);assert.equal(saved[0].body.direct,true);assert.equal(saved[0].body.confirmed,true);assert.equal(saved[0].body.max_charge_usd,0.29);
  assert.ok(dialogs.at(-1)?.includes('۱۴ روز')&&dialogs.at(-1)?.includes('۵۰۰'));assert.equal(count('/start'),1);
  pass('optional schedule requires confirmation and does not start an immediate paid run');
  console.log('All import UI smoke checks passed with synthetic data and jsdom.');
} finally { await act(async()=>{root.unmount();});dom.window.close(); }
