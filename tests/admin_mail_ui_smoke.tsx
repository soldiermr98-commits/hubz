import assert from 'node:assert/strict';
import React, {act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<div id="root"></div>',{url:'https://example.test/admin/settings/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');
const {AdminMailPanel}=await import('../src/components/AdminMailPanel');
const calls:Array<{url:string;body:any;csrf:string|null}>=[];
let settings={enabled:false,method:'smtp',recipient:'admin@example.test',from:'site@example.test',host:'smtp.example.test',port:587,security:'tls',username:'fixture',password_configured:true,version:1};
globalThis.fetch=async(input,init={})=>{const url=String(input);const headers=new Headers(init.headers);const body=init.body?JSON.parse(String(init.body)):null;calls.push({url,body,csrf:headers.get('X-CSRF-Token')});
  let result:any={ok:true,settings};
  if(init.method==='POST'&&url==='/api/v1/admin-mail'){settings={...settings,...body,version:2};delete (settings as any).password;result={ok:true,message:'ذخیره شد'};}
  if(url==='/api/v1/admin-mail/test')result={ok:true,message:'پیام آزمایشی ارسال شد'};
  return new Response(JSON.stringify(result),{headers:{'content-type':'application/json'}});
};
const pause=()=>new Promise(r=>setTimeout(r,0));const root=createRoot(document.getElementById('root')!);
try {
  await act(async()=>{root.render(<AdminMailPanel csrfToken="test-csrf"/>);await pause();});
  assert(document.body.textContent?.includes('درخواست جدید تبلیغات'));
  const secret=document.querySelector<HTMLInputElement>('input[type=password]')!;assert(secret);
  await act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(secret,'synthetic-secret');secret.dispatchEvent(new dom.window.Event('input',{bubbles:true}));});
  await act(async()=>{document.querySelector<HTMLButtonElement>('button[type=submit], form button:not([type])')!.click();await pause();});
  const save=calls.find(x=>x.url==='/api/v1/admin-mail'&&x.body)!;
  assert.equal(save.body.password,'synthetic-secret');assert.equal(save.body.version,1);assert.equal(save.csrf,'test-csrf');assert.equal(document.querySelector<HTMLInputElement>('input[type=password]')!.value,'');
  const button=Array.from(document.querySelectorAll('button')).find(x=>x.textContent==='ارسال ایمیل آزمایشی')!;
  await act(async()=>{button.click();await pause();});assert(calls.some(x=>x.url==='/api/v1/admin-mail/test'&&x.csrf==='test-csrf'));
  console.log('PASS: admin mail settings save, secret clearing and explicit test with CSRF.');
} finally {await act(async()=>root.unmount());dom.window.close();}
