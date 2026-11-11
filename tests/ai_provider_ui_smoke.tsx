// jsdom only: no real credentials, network, or production data.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/settings/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');
const {AiWorkerPanel}=await import('../src/components/AiWorkerPanel');
const requests:Array<{url:string;method:string;body:any}>=[];
const rows=[
  {id:'gemini',label:'Gemini',model:'gemini-test',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:'',suggested_models:[{id:'gemini-3.5-flash-lite',note:'پیش‌فرض'}]},
  {id:'groq',label:'Groq',model:'openai/gpt-oss-120b',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:'',suggested_models:[{id:'openai/gpt-oss-120b',note:'پیش‌فرض'},{id:'qwen/qwen3.8-27b',note:'آزمایشی'}]},
  {id:'openrouter',label:'OpenRouter',model:'openrouter/free',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:'',suggested_models:[{id:'openrouter/free',note:'پیش‌فرض'}]},
  {id:'nvidia',label:'NVIDIA',model:'nvidia/nemotron-3.5-lightning-30b-a3b',available:false,enabled:false,free_tier_confirmed:false,key_configured:false,source:'none',version:0,error:'',suggested_models:[{id:'nvidia/nemotron-3.5-lightning-30b-a3b',note:'پیش‌فرض'}]},
];
const secrets=new Map<string,string>();
globalThis.fetch=async(input,options={})=>{
  const url=String(input),method=options.method||'GET',body=options.body?JSON.parse(String(options.body)):null;
  requests.push({url,method,body});
  const json=(data:any,status=200)=>new Response(JSON.stringify(data),{status,headers:{'content-type':'application/json'}});
  if(url==='/api/v1/ai/providers/manage')return json({ok:true,schema_ready:true,providers:rows});
  if(url==='/api/v1/ai/article/providers')return json({ok:true,schema_ready:true,providers:rows.map(({id,label,model,available})=>({id,label,model,available}))});
  if(url==='/api/v1/ai/providers/save'){
    const p=rows.find(x=>x.id===body.provider)!;
    if(body.enabled&&!body.free_tier_confirmed)return json({ok:false,error:'سهمیه را تأیید کنید'},422);
    if(body.api_key)secrets.set(body.provider,body.api_key);
    const hasKey=secrets.has(body.provider);
    Object.assign(p,{model:body.model,enabled:body.enabled,free_tier_confirmed:body.free_tier_confirmed,key_configured:hasKey,available:body.enabled&&hasKey,source:'panel',version:p.version+1});
    return json({ok:true,message:'ذخیره شد'});
  }
  if(url==='/api/v1/ai/providers/test')return json({ok:true,message:'اتصال موفق بود'});
  if(url==='/api/v1/ai/providers/remove'){
    const p=rows.find(x=>x.id===body.provider)!;secrets.delete(body.provider);Object.assign(p,{enabled:false,key_configured:false,available:false,version:p.version+1});
    return json({ok:true,message:'کلید حذف شد'});
  }
  throw new Error('Unexpected request: '+url);
};
const pause=()=>new Promise(r=>setTimeout(r,0));
function card(id:string){const c=document.querySelector(`[aria-label="تنظیمات ${id}"]`);assert(c);return c;}
function button(scope:ParentNode,text:string){const b=[...scope.querySelectorAll('button')].find(x=>x.textContent?.trim()===text);assert(b,'Missing '+text);return b;}
async function click(b:HTMLElement){await act(async()=>{b.click();await pause();});}
async function input(el:HTMLInputElement,value:string){await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(el,value);el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});}
async function select(el:HTMLSelectElement,value:string){await act(async()=>{el.value=value;el.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});}
const root=createRoot(document.getElementById('root')!);
try {
  await act(async()=>{root.render(<AiWorkerPanel csrfToken="fixture-csrf" isAdmin/>);await pause();});
  const gemini=card('Gemini');const field=gemini.querySelector('[aria-label="کلید API Gemini"]') as HTMLInputElement;
  assert.equal(field.type,'password');await input(field,'synthetic-ui-key');
  const checks=[...gemini.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];assert.equal(checks.length,2);assert(checks[0].checked,'entering a new key enables the service');
  await click(button(gemini,'ذخیره تنظیمات'));assert(gemini.textContent?.includes('سهمیه را تأیید کنید'));
  assert(!requests.some(r=>r.url==='/api/v1/ai/providers/test'));
  await click(checks[1]);dom.window.confirm=()=>false;
  const before=requests.length;await click(button(gemini,'ذخیره و تست اتصال'));assert.equal(requests.length,before,'cancelled test cannot save or call provider');
  dom.window.confirm=()=>true;await click(button(gemini,'ذخیره و تست اتصال'));
  const saved=requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!;assert.equal(saved.body.api_key,'synthetic-ui-key');
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/test').length,1);assert.equal(field.value,'','secret must be cleared after saving');
  assert(gemini.textContent?.includes('اتصال موفق بود'));
  const model=gemini.querySelector('[aria-label="مدل Gemini"]') as HTMLInputElement;await input(model,'new-model');await click(button(gemini,'ذخیره تنظیمات'));
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!.body.api_key,'','blank field preserves key');
  await click(button(gemini,'حذف کلید'));assert(requests.some(r=>r.url==='/api/v1/ai/providers/remove'));assert(!gemini.textContent?.includes('synthetic-ui-key'));
  const groq=card('Groq');const groqModel=groq.querySelector('[aria-label="مدل Groq"]') as HTMLInputElement;
  const groqSuggestions=groq.querySelector('[aria-label="مدل پیشنهادی Groq"]') as HTMLSelectElement;
  assert.deepEqual([...groqSuggestions.options].slice(1).map(x=>x.value),['openai/gpt-oss-120b','qwen/qwen3.8-27b']);
  assert(groqSuggestions.textContent?.includes('آزمایشی'));
  const beforeSelect=requests.length;await select(groqSuggestions,'qwen/qwen3.8-27b');assert.equal(groqModel.value,'qwen/qwen3.8-27b');assert.equal(requests.length,beforeSelect,'selecting a model must not save or test');
  await input(groq.querySelector('[aria-label="کلید API Groq"]') as HTMLInputElement,'synthetic-groq-ui-key');
  const groqChecks=[...groq.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];await click(groqChecks[1]);await click(button(groq,'ذخیره تنظیمات'));
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!.body.model,'qwen/qwen3.8-27b');
  await input(groqModel,'vendor/new-groq-model');assert.equal(groqSuggestions.value,'');await click(button(groq,'ذخیره تنظیمات'));
  const groqSave=requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!;assert.equal(groqSave.body.model,'vendor/new-groq-model');assert.equal(groqSave.body.api_key,'');
  const nvidia=card('NVIDIA');const nvModel=nvidia.querySelector('[aria-label="مدل NVIDIA"]') as HTMLInputElement;
  await input(nvModel,'vendor/custom-nvidia');await input(nvidia.querySelector('[aria-label="کلید API NVIDIA"]') as HTMLInputElement,'synthetic-nvidia-ui-key');
  const nvChecks=[...nvidia.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];await click(nvChecks[1]);await click(button(nvidia,'ذخیره و تست اتصال'));
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!.body.model,'vendor/custom-nvidia');
  assert.equal(requests.filter(r=>r.url==='/api/v1/ai/providers/test').at(-1)!.body.provider,'nvidia');
  await select(nvidia.querySelector('[aria-label="مدل پیشنهادی NVIDIA"]') as HTMLSelectElement,'nvidia/nemotron-3.5-lightning-30b-a3b');
  await click(button(nvidia,'ذخیره تنظیمات'));const nvSave=requests.filter(r=>r.url==='/api/v1/ai/providers/save').at(-1)!;
  assert.equal(nvSave.body.model,'nvidia/nemotron-3.5-lightning-30b-a3b');assert.equal(nvSave.body.api_key,'','changing models preserves the NVIDIA key');
  await click(button(nvidia,'حذف کلید'));assert(!nvidia.textContent?.includes('synthetic-nvidia-ui-key'));
  await act(async()=>{root.render(<AiWorkerPanel csrfToken="fixture-csrf" isAdmin={false}/>);await pause();});
  assert.equal(document.querySelector('[aria-label="کلید API Gemini"]'),null,'editor must not see management controls');
  assert(!requests.some(r=>r.url.includes('/articles')||r.url.includes('/generate')),'settings never publishes or generates content');
  console.log('PASS: admin key input, save/test consent, rotation, removal and editor-only status UI.');
} finally {await act(async()=>root.unmount());dom.window.close();}
