// Synthetic CSV and mocked APIs only: no uploads, external calls, or production data.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
dom.window.HTMLElement.prototype.scrollIntoView=()=>{};
const {createRoot}=await import('react-dom/client');
const {JsonImportView}=await import('../src/components/JsonImportView');
const calls:Array<{url:string,body:any}>=[];
globalThis.fetch=async(input,options={})=>{
  const url=String(input),body=options.body?JSON.parse(String(options.body)):null;calls.push({url,body});
  const json=(data:any)=>new Response(JSON.stringify(data),{status:200,headers:{'content-type':'application/json'}});
  if(url.endsWith('/status'))return json({ok:true,token_configured:false,schedule:{enabled:0,mode:'legacy',source_categories:[]},runs:[],search_terms:{}});
  if(url.endsWith('/json/prepare'))return json({ok:true,items:body.items});
  if(url.endsWith('/json/preview'))return json({ok:true,rows:body.items.map((r:any,index:number)=>({index,name:r.name,source_category:r.category,source:r.source,source_id:r.source_id,rating:r.rating,reviews_count:r.reviews_count,status:'new',message:'ساختگی',candidates:[]}))});
  throw new Error('Unexpected request: '+url);
};
const root=createRoot(document.getElementById('root')!);
const pause=()=>new Promise(r=>setTimeout(r,0));
const button=(name:string)=>{const b=[...document.querySelectorAll('button')].find(x=>x.textContent?.trim()===name);assert(b,`Missing button ${name}`);return b;};
const label=(name:string)=>{const l=[...document.querySelectorAll('label')].find(x=>x.textContent?.trim().startsWith(name));assert(l,`Missing label ${name}`);return l;};
try {
  await act(async()=>{root.render(<JsonImportView csrfToken="fixture" cities={[{id:1,name_fa:'رشت',slug:'rasht',is_active:1} as any]} categories={[{id:1,name_fa:'کافه',slug:'cafe',is_active:1} as any]} onRefreshData={async()=>{}}/>);await pause();});
  const csv='نام کسب‌وکار,شهر,دسته‌بندی,منبع,زمان استخراج (شمسی),شناسه مکان (source_id)\nنمونه,رشت,کافه,گوگل مپ,۱۴۰۵/۰۸/۱۵ ۱۳:۴۰,0x123:0x456';
  const file=new dom.window.File([csv],'synthetic.csv',{type:'text/csv'});Object.defineProperty(file,'text',{value:async()=>csv});
  const input=document.querySelector('input[type="file"]') as HTMLInputElement;assert(input.accept.includes('.csv'));
  await act(async()=>{Object.defineProperty(input,'files',{value:[file],configurable:true});input.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  for (const name of ['شهر مقصد','دسته مقصد']) {
    const s=label(name).querySelector('select')!;await act(async()=>{s.value='1';s.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  }
  await act(async()=>{button('نمایش پیش‌نمایش').click();await pause();});
  assert(document.querySelector('[role="alert"]')?.textContent?.includes('زمان واقعی'));
  assert(!calls.some(c=>c.url.endsWith('/json/prepare')),'missing time must not cause preview or registration');
  const time=label('زمان واقعی شروع جمع‌آوری').querySelector('input')!;
  await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(time,'2026-11-06T10:00:00Z');time.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});
  await act(async()=>{button('نمایش پیش‌نمایش').click();await pause();await pause();});
  const sent=calls.find(c=>c.url.endsWith('/json/prepare'));assert(sent);
  assert.equal(sent.body.items[0].source,'google_maps');assert.equal(sent.body.items[0].fetched_at,'2026-11-06T10:00:00Z');
  assert.equal(sent.body.items[0].csv_recorded_time_jalali,'۱۴۰۵/۰۸/۱۵ ۱۳:۴۰');assert(!('__csv_needs_time' in sent.body.items[0]));
  assert(calls.some(c=>c.url.endsWith('/json/preview')));assert(!calls.some(c=>c.url.endsWith('/json/apply')));
  assert(button('ثبت 0 مورد انتخاب‌شده').disabled,'preview must not silently select or import');
  console.log('PASS: CSV upload requires a source time, preserves provenance, and only previews without selecting or importing.');
} finally {await act(async()=>root.unmount());dom.window.close();}
