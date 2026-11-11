import assert from 'node:assert/strict';
import React,{act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<div id="root"></div>',{url:'https://example.test/admin/settings/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');const {StaffManager}=await import('../src/components/StaffManager');
let submitted:any=null;
globalThis.fetch=async(_url,init={})=>{if(init.method==='POST'){submitted=JSON.parse(String(init.body));assert.equal(new Headers(init.headers).get('X-CSRF-Token'),'csrf-test');return new Response(JSON.stringify({ok:true,message:'ثبت شد'}),{headers:{'content-type':'application/json'}});}return new Response(JSON.stringify({ok:true,users:[]}),{headers:{'content-type':'application/json'}});};
const pause=()=>new Promise(r=>setTimeout(r,0));const root=createRoot(document.getElementById('root')!);
const click=async(el:HTMLElement)=>act(async()=>{el.click();await pause();});
const set=async(el:HTMLInputElement,v:string)=>act(async()=>{Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(el,v);el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();});
try {await act(async()=>{root.render(<StaffManager csrfToken="csrf-test"/>);await pause();});await click([...document.querySelectorAll('button')].find(b=>b.textContent==='افزودن کاربر')!);
const form=document.querySelector('form')!;const inputs=[...form.querySelectorAll('input')];await set(inputs[0],'همکار آزمایشی');await set(inputs[1],'fixture');await set(inputs[2],'fixture-password');
const article=[...form.querySelectorAll('label')].find(l=>l.textContent?.includes('مقالات'))!.querySelector('input')!;await click(article);
await act(async()=>{form.dispatchEvent(new dom.window.Event('submit',{bubbles:true,cancelable:true}));await pause();});
assert.equal(submitted.username,'fixture');assert.deepEqual(submitted.permissions,['articles']);assert.equal(submitted.is_active,true);assert.equal(document.querySelector('input[type=password]'),null,'password field cleared on save');
console.log('PASS: limited staff UI submits selected permissions with CSRF and clears password.');
} finally {await act(async()=>root.unmount());dom.window.close();}
