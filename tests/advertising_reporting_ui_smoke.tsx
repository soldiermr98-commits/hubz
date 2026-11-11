// Synthetic jsdom reporting panel; no network or production records.
import assert from 'node:assert/strict';
import React,{act} from 'react';
import {JSDOM} from 'jsdom';
const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{url:'https://example.test/admin/',pretendToBeVisual:true});
Object.assign(globalThis,{window:dom.window,document:dom.window.document,IS_REACT_ACT_ENVIRONMENT:true});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
const {createRoot}=await import('react-dom/client');const {AdvertisingView}=await import('../src/components/AdvertisingView');
let installed=false;const requests:string[]=[];
const json=(d:any)=>new Response(JSON.stringify(d),{status:200,headers:{'content-type':'application/json'}});
globalThis.fetch=async(input,options={})=>{
  const url=String(input);requests.push(url);
  if(url==='/api/v1/advertising')return json({ok:true,schema_ready:true,reporting_schema_ready:true,placements:{home:'صفحه اصلی — بالای هیرو',home_below_hero:'صفحه اصلی — زیر هیرو سکشن',home_mid:'صفحه اصلی — بین مقالات و کسب‌وکارها',footer_above:'بالای فوتر سایت (سراسری یا هدفمند)'},inquiries:[],banners:[{id:1,version:1,title:'بنر ساختگی',alt_text:'a',media_id:1,image_url:null,target_url:'/',placement:'home',city_id:null,category_id:null,starts_at:null,ends_at:null,active:1,priority:0,views_total:40,clicks_total:7,views_30:20,clicks_30:5}]});
  if(url==='/api/v1/advertising/finance')return json({ok:true,schema_ready:installed,contracts:[],manual_payments:[],online_payments:[],status_log:[]});
  if(url==='/api/v1/system/update-advertising-schema'){assert.equal(options.method,'POST');assert.equal(new Headers(options.headers).get('X-CSRF-Token'),'fixture');installed=true;return json({ok:true,message:'آماده شد'});}
  throw new Error('Unexpected '+url);
};
const pause=()=>new Promise(r=>setTimeout(r,0));const root=createRoot(document.getElementById('root')!);dom.window.confirm=()=>true;
try{
  await act(async()=>{root.render(<AdvertisingView csrfToken="fixture" cities={[{id:1,name_fa:'رشت',slug:'rasht',lat:37.28,lng:49.58,bbox_json:null,publish_mode:'manual',is_active:1}]} categories={[{id:2,name_fa:'کافه',slug:'cafe',parent_id:null,sort:1,is_active:1}]}/>);await pause();});
  const text=document.body.textContent||'';assert(text.includes('۳۰ روز اخیر'));assert(text.includes('۲۰ نمایش'));assert(text.includes('۵ کلیک'));assert(text.includes('۲۵٪'));assert(text.includes('مبنای صورتحساب یا اثبات بازدید واقعی نیست'));
  assert(text.includes('حداکثر ۲ بنر فعال هم‌زمان نمایش داده می‌شود'));
  const newBtn=[...document.querySelectorAll('button')].find(x=>x.textContent?.includes('بنر جدید'));assert(newBtn);
  await act(async()=>{newBtn.click();await pause();});
  const selects=[...document.querySelectorAll('form select')] as HTMLSelectElement[];assert.equal(selects.length,3);
  const [slotSelect,citySelect,catSelect]=selects;
  assert.equal(citySelect.disabled,true);assert.equal(catSelect.disabled,true);
  await act(async()=>{slotSelect.value='footer_above';slotSelect.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  assert.equal(citySelect.disabled,false);assert.equal(catSelect.disabled,false);
  await act(async()=>{slotSelect.value='home_below_hero';slotSelect.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  assert.equal(citySelect.disabled,true);assert.equal(catSelect.disabled,true);
  await act(async()=>{slotSelect.value='home_mid';slotSelect.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  assert.equal(citySelect.disabled,true);assert.equal(catSelect.disabled,true);
  const prepare=[...document.querySelectorAll('button')].find(x=>x.textContent?.trim()==='آماده‌سازی قراردادها');assert(prepare);
  await act(async()=>{prepare.click();await pause();await pause();});
  assert(requests.includes('/api/v1/system/update-advertising-schema'));assert(document.body.textContent?.includes('هنوز قراردادی ثبت نشده است'));
  console.log('PASS: reporting labels, approximate counts and CTR, and separate finance-schema preparation UI.');
}finally{await act(async()=>root.unmount());dom.window.close();}
