// Isolated jsdom check: unresolved browser snapshots are warned about and removed on logout.
import assert from 'node:assert/strict';
import React,{act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/review/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');const {default:App}=await import('../src/App');
let logouts=0,confirmations=0;const json=(body:any)=>new Response(JSON.stringify({ok:true,...body}),{headers:{'content-type':'application/json'}});
globalThis.fetch=async(input)=>{const path=String(input);
  if(path==='/api/v1/auth/me')return json({authenticated:true,user:{id:1,username:'fixture',display_name:'fixture',role:'admin'},csrf_token:'fixture'});
  if(path==='/api/v1/cities')return json({cities:[],neighborhoods:[]});
  if(path==='/api/v1/categories')return json({categories:[],packs:[]});
  if(path.startsWith('/api/v1/review/'))return json({items:[],change_logs:[],pending_counts:{all:0}});
  if(path==='/api/v1/auth/logout'){logouts++;return json({csrf_token:'next'});}
  throw new Error('Unexpected fixture request '+path);
};
const pause=()=>new Promise(r=>setTimeout(r,0));const root=createRoot(document.getElementById('root')!);
try {
  await act(async()=>{root.render(<App/>);await pause();await pause();});
  const logout=[...document.querySelectorAll('button')].find(b=>b.textContent?.includes('خروج')) as HTMLButtonElement;assert(logout);
  dom.window.sessionStorage.setItem('hhx.advertising.gateway.pending.v1','1');
  dom.window.confirm=()=>{confirmations++;return false;};await act(async()=>{logout.click();await pause();});
  assert.equal(confirmations,1);assert.equal(logouts,0);assert.equal(dom.window.sessionStorage.getItem('hhx.advertising.gateway.pending.v1'),'1');
  dom.window.sessionStorage.setItem('hhx.advertising.finance.pending.v1',JSON.stringify({kind:'contract',payload:{client_name:'private fixture'}}));
  await act(async()=>{logout.click();await pause();});assert.equal(confirmations,2);assert.equal(logouts,0);
  dom.window.confirm=()=>{confirmations++;return true;};await act(async()=>{logout.click();await pause();});
  assert.equal(logouts,1);assert.equal(dom.window.sessionStorage.length,0,'sensitive retry snapshot must not survive an explicit logout');
  console.log('PASS: logout warns before discarding unresolved finance request and clears its sensitive same-tab snapshot.');
} finally {await act(async()=>root.unmount());dom.window.close();}
