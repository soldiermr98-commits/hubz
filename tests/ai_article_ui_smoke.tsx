// jsdom and fake API responses only; no provider or production calls.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'http://example.test/admin/articles/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
dom.window.confirm=()=>true;
const {createRoot}=await import('react-dom/client');
const {AiArticleAssistant}=await import('../src/components/AiArticleAssistant');
const calls:Array<{url:string;method:string;body:any}>=[];
globalThis.fetch=async(input,options={})=>{
  const url=String(input),method=options.method||'GET',body=options.body?JSON.parse(String(options.body)):null;
  calls.push({url,method,body});
  const json=(data:any)=>new Response(JSON.stringify(data),{status:200,headers:{'content-type':'application/json'}});
  if(url==='/api/v1/ai/article/providers')return json({ok:true,schema_ready:true,providers:[{id:'gemini',label:'Gemini',model:'fixture-model',available:true},{id:'groq',label:'Groq',model:'fixture',available:true},{id:'openrouter',label:'OpenRouter',model:'openrouter/free',available:false},{id:'nvidia',label:'NVIDIA',model:'nvidia/nemotron-3.5-lightning-30b-a3b',available:true}]});
  if(url==='/api/v1/ai/article/generate')return json({ok:true,text:'متن پیشنهادی آزمایشی',provider:body.provider==='nvidia'?'NVIDIA':'Gemini',model:body.provider==='nvidia'?'nvidia/nemotron-3.5-lightning-30b-a3b':'fixture-model'});
  throw new Error('Unexpected request: '+url);
};
const pause=()=>new Promise(r=>setTimeout(r,0));
function button(text:string){const b=[...document.querySelectorAll('button')].find(x=>x.textContent?.trim()===text);assert(b,'Missing '+text);return b;}
async function click(text:string){await act(async()=>{button(text).click();await pause();});}
async function change(el:HTMLTextAreaElement,value:string){await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLTextAreaElement.prototype,'value')!.set!.call(el,value);el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});}
let inserted='';let insertMode:''|'append'|'replace'='';const root=createRoot(document.getElementById('root')!);
try {
  await act(async()=>{root.render(<AiArticleAssistant title="عنوان آزمایشی" currentText="متن قبلی محفوظ" csrfToken="fixture-csrf" onInsert={(text,mode)=>{inserted=text;insertMode=mode;}}/>);await pause();});
  const open=[...document.querySelectorAll('button')].find(b=>b.textContent?.includes('نوشتن با هوش مصنوعی'));assert(open);
  await act(async()=>{open.click();await pause();});
  assert.equal((document.querySelector('[aria-label="سرویس هوش مصنوعی"]') as HTMLSelectElement).value,'gemini');
  assert(button('ساخت متن پیشنهادی').disabled,'consent must be required');
  await change(document.querySelector('[aria-label="راهنمای نویسنده"]') as HTMLTextAreaElement,'راهنمای آزمایشی');
  await change(document.querySelector('[aria-label="اطلاعات بررسی‌شده"]') as HTMLTextAreaElement,'منبع آزمایشی');
  const checks=[...document.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];assert.equal(checks.length,2);
  await act(async()=>{checks[0].click();checks[1].click();await pause();});
  await click('ساخت متن پیشنهادی');
  const sent=calls.filter(c=>c.url==='/api/v1/ai/article/generate');assert.equal(sent.length,1);
  assert.deepEqual(sent[0].body,{provider:'gemini',title:'عنوان آزمایشی',brief:'راهنمای آزمایشی',facts:'منبع آزمایشی',confirm_send:true,confirm_quota:true});
  assert(!JSON.stringify(sent[0].body).includes('متن قبلی محفوظ'),'existing article must not be silently sent');
  assert.equal(inserted,'','result must not be inserted, saved, or published automatically');
  assert((document.querySelector('[aria-label="متن پیشنهادی هوش مصنوعی"]') as HTMLTextAreaElement).value.includes('پیشنهادی'));
  await click('افزودن به انتهای متن');assert.equal(inserted,'متن پیشنهادی آزمایشی');assert.equal(insertMode,'append');
  const selector=document.querySelector('[aria-label="سرویس هوش مصنوعی"]') as HTMLSelectElement;
  assert([...selector.options].some(o=>o.value==='nvidia'&&!o.disabled),'configured NVIDIA should be selectable');
  await act(async()=>{selector.value='nvidia';selector.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  await click('ساخت متن پیشنهادی');const last=calls.filter(c=>c.url==='/api/v1/ai/article/generate').at(-1)!;
  assert.equal(last.body.provider,'nvidia','generation must use the explicitly selected provider');
  assert(document.body.textContent?.includes('nvidia/nemotron-3.5-lightning-30b-a3b'));
  dom.window.confirm=()=>false;
  await click('جایگزینی متن مقاله');assert.equal(insertMode,'append','cancelled replacement must not modify the article');
  dom.window.confirm=()=>true;
  await click('جایگزینی متن مقاله');assert.equal(insertMode,'replace');
  assert(calls.every(c=>c.url.startsWith('/api/v1/ai/article/')),'no save or publish call');
  console.log('PASS: AI requires two acknowledgements, sends only selected fields, and inserts only after review');
} finally {await act(async()=>root.unmount());dom.window.close();}
