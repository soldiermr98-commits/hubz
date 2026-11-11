import {toJalaali} from 'jalaali-js';
// Synthetic API and jsdom only. Nothing is published to a real site.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', {
  url: 'http://example.test/admin/articles/', pretendToBeVisual: true,
});
Object.assign(globalThis, { window: dom.window, document: dom.window.document, IS_REACT_ACT_ENVIRONMENT: true });
Object.defineProperty(globalThis, 'navigator', { value: dom.window.navigator, configurable: true });
dom.window.confirm = () => true;
const { createRoot } = await import('react-dom/client');
const { ArticlesManagerView } = await import('../src/components/ArticlesManagerView');

const existingItem = {
  business_id: 11, business_name_fa: 'کافه قدیمی', business_slug: 'old-cafe', rank_num: 1,
  badge_fa: 'منتخب', verdict_fa: 'یادداشت قبلی', custom_desc_html: 'توضیح قبلی',
  highlights: '{"custom":"preserve"}', pros: ['مزیت قبلی'], cons: ['نکته قبلی'],
  is_sponsored: 1, sponsor_label: 'حامی قدیمی', sponsor_start: '2025-01-01 08:30:00', sponsor_end: '2030-01-01 09:45:00',
};
let existing: any = {
  id: 1, status: 'published', title_fa: 'مقاله قبلی', intro_html: 'متن قبلی', slug: 'old-article',
  city_id: null, category_id: null, meta_title: 'عنوان سئو', meta_description: 'توضیح سئو',
  methodology_html: '', cover_media_id: null, cover_url: null, items: [existingItem], faqs: [],
  revision: null, edit_token: 'old-token',
};
const newBusiness = { id: 22, name: 'کافه جدید', slug: 'new-cafe', city_name: 'رشت' };
const requests: Array<{url:string; method:string; body:any}> = [];
let created: any = null;

globalThis.fetch = async (input:RequestInfo|URL, options:RequestInit = {}) => {
  const url = String(input), method = options.method || 'GET';
  const body = options.body ? JSON.parse(String(options.body)) : null;
  requests.push({url, method, body});
  const json = (data:any) => new Response(JSON.stringify(data), {status:200, headers:{'content-type':'application/json'}});
  if (url === '/api/v1/articles' && method === 'GET') return json({ok:true, schema_ready:true, articles:created?[created,existing]:[existing]});
  if (url.startsWith('/api/v1/businesses?')) return json({ok:true, businesses:[newBusiness], total:1, per_page:50});
  if (url === '/api/v1/articles' && method === 'POST') {
    if (body.id === 1) {
      existing = {...existing, edit_token:'working-token', revision:{status:body.status, base_conflict:false, updated_by:1, updated_at:'2026-11-07 12:00:00', data:body}};
      return json({ok:true, message:'پیش‌نویس ذخیره شد', article:existing});
    }
    created = {...body, id:2, slug:'new-article', status:body.status, revision:null, edit_token:'new-token'};
    return json({ok:true, message:'پیش‌نویس ذخیره شد', article:created});
  }
  throw new Error('Unexpected synthetic request: ' + url);
};

const pause = (ms=0) => new Promise(resolve => setTimeout(resolve,ms));
const node = document.getElementById('root')!;
let root = createRoot(node);
const props = {cities:[], categories:[], csrfToken:'test-only', isAdmin:true, onGoReview:()=>{}, onDirtyChange:()=>{}};
function button(label:string):HTMLButtonElement {
  const found = Array.from(document.querySelectorAll('button')).find(el => el.textContent?.trim() === label);
  assert.ok(found,'Missing button: '+label); return found;
}
async function click(label:string) { await act(async()=>{button(label).click(); await pause();}); }
function input(label:string):HTMLInputElement {
  const found = document.querySelector(`input[aria-label="${label}"]`) as HTMLInputElement | null;
  assert.ok(found,'Missing input: '+label); return found;
}
async function setInput(element:HTMLInputElement, value:string) {
  await act(async()=>{
    Object.getOwnPropertyDescriptor(dom.window.HTMLInputElement.prototype,'value')!.set!.call(element,value);
    element.dispatchEvent(new dom.window.Event('input',{bubbles:true}));
    element.dispatchEvent(new dom.window.Event('change',{bubbles:true}));
    await pause();
  });
}
async function setPersianDate(label:string, iso:string, initial?:string) {
  const start = initial ? toJalaali(...initial.split('-').map(Number) as [number,number,number]) : toJalaali(new Date());
  const target = toJalaali(...iso.slice(0,10).split('-').map(Number) as [number,number,number]);
  const open = document.querySelector<HTMLButtonElement>(`button[aria-label="${label}"]`)!;
  assert.ok(open); await act(async()=>open.click());
  const diff = (target.jy-start.jy)*12+target.jm-start.jm;
  for (let i=0;i<Math.abs(diff);i++) await act(async()=>document.querySelector<HTMLButtonElement>(`button[aria-label="ماه ${diff>0?'بعد':'قبل'}"]`)!.click());
  const months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const fa=(n:number)=>n.toLocaleString('fa-IR',{useGrouping:false});
  const day=document.querySelector<HTMLButtonElement>(`button[aria-label="${fa(target.jd)} ${months[target.jm-1]} ${fa(target.jy)}"]`)!;
  assert.ok(day); await act(async()=>day.click());
  await setInput(input(label+'، ساعت'),iso.slice(11,16));
}
const saves = () => requests.filter(r=>r.url==='/api/v1/articles' && r.method==='POST');

try {
  await act(async()=>{root.render(<ArticlesManagerView {...props}/>); await pause();});
  await click('ویرایش');
  const heading = document.getElementById('article-sponsor-heading');
  assert.ok(heading);
  assert.ok(heading.textContent?.includes('جایگاه اسپانسری'));
  assert.equal(heading.closest('details'),null,'sponsorship must not be hidden in advanced options');
  let check = input('جایگاه اسپانسری کافه قدیمی');
  assert.equal(check.checked,true);
  assert.equal(input('برچسب تبلیغ کافه قدیمی').value,'حامی قدیمی');
  assert.match(document.querySelector('button[aria-label="شروع اسپانسر کافه قدیمی"]')!.textContent!, /۱۴۰۳/); assert.equal(input('شروع اسپانسر کافه قدیمی، ساعت').value,'08:30');
  assert.equal(input('پایان اسپانسر کافه قدیمی، ساعت').value,'09:45');
  await act(async()=>{check.click();await pause();});
  assert.equal(document.querySelector('input[aria-label="برچسب تبلیغ کافه قدیمی"]'),null);
  check = input('جایگاه اسپانسری کافه قدیمی');
  await act(async()=>{check.click();await pause();});
  assert.equal(input('برچسب تبلیغ کافه قدیمی').value,'حامی قدیمی','temporarily disabling must not erase fields');
  await setInput(input('برچسب تبلیغ کافه قدیمی'),'تبلیغ تازه');
  await setPersianDate('پایان اسپانسر کافه قدیمی','2031-02-03T10:20','2030-01-01');
  assert.equal(saves().length,0,'changing a placement must not auto-publish or auto-save');
  await click('ذخیره پیش‌نویس');
  let sent=saves().at(-1)!.body;
  assert.equal(sent.status,'draft'); assert.equal(sent.id,1); assert.equal(sent.edit_token,'old-token');
  assert.equal(sent.items[0].is_sponsored,1); assert.equal(sent.items[0].sponsor_label,'تبلیغ تازه');
  assert.equal(sent.items[0].sponsor_start,'2025-01-01 08:30:00'); assert.equal(sent.items[0].sponsor_end,'2031-02-03T10:20');
  assert.equal(sent.items[0].highlights,'{"custom":"preserve"}'); assert.equal(sent.items[0].pros[0],'مزیت قبلی');
  assert.equal(existing.items[0].sponsor_label,'حامی قدیمی','published data must not be changed by the draft in the fixture');
  console.log('PASS: visible sponsorship section preserves existing details and sends an explicit draft');

  await click('بازگشت به فهرست'); await click('مقاله جدید');
  assert.ok(document.getElementById('article-sponsor-heading'));
  assert.ok(document.body.textContent?.includes('هنوز کسب‌وکاری به این مقاله اضافه نشده'));
  const title = document.querySelector('input[placeholder="مثلاً راهنمای سفر به رشت"]') as HTMLInputElement;
  await setInput(title,'مقاله آزمایشی جدید');
  await click('افزودن کسب‌وکار');
  await act(async()=>{await pause(260);});
  const pickerRequest=requests.find(r=>r.url.startsWith('/api/v1/businesses?'))!;
  const pickerQuery=new URL(pickerRequest.url,'http://example.test').searchParams;
  assert.equal(pickerQuery.get('status'),'published');assert.equal(pickerQuery.get('article_picker'),'1','drafts and businesses without valid city/category must be excluded at the server');
  await click('افزودن');
  check=input('جایگاه اسپانسری کافه جدید');
  assert.equal(check.checked,false);
  await act(async()=>{check.click();await pause();});
  assert.equal(input('برچسب تبلیغ کافه جدید').value,'تبلیغ');
  await setPersianDate('شروع اسپانسر کافه جدید','2027-01-01T08:00');
  await setPersianDate('پایان اسپانسر کافه جدید','2027-02-01T08:00');
  assert.equal(saves().length,1,'adding and marking a business requires an explicit save');
  await click('ذخیره پیش‌نویس');
  sent=saves().at(-1)!.body;
  assert.equal(sent.status,'draft'); assert.equal(sent.items[0].business_id,22); assert.equal(sent.items[0].is_sponsored,1);
  assert.equal(sent.items[0].sponsor_start,'2027-01-01T08:00'); assert.equal(sent.items[0].sponsor_end,'2027-02-01T08:00');
  console.log('PASS: a new placement can be added, scheduled and saved without automatic publication');

  await act(async()=>{root.unmount();});
  root=createRoot(node);
  await act(async()=>{root.render(<ArticlesManagerView {...props} isAdmin={false}/>);await pause();});
  // One editor button per article; select the existing published article, not the new draft.
  const editButtons=Array.from(document.querySelectorAll('button')).filter(el=>el.textContent?.trim()==='ویرایش');
  assert.equal(editButtons.length,2);
  await act(async()=>{editButtons[1].click();await pause();});
  assert.ok(input('جایگاه اسپانسری کافه قدیمی').checked);
  await click('ارسال برای بررسی مدیر');
  sent=saves().at(-1)!.body;
  assert.equal(sent.status,'review'); assert.equal(sent.items[0].sponsor_label,'تبلیغ تازه');
  assert.equal(existing.items[0].sponsor_label,'حامی قدیمی');
  console.log('PASS: editors can submit sponsorship edits for review without publishing them');
  console.log('All article sponsorship UI smoke checks passed with synthetic data and jsdom.');
} finally {
  await act(async()=>{root.unmount();});
  dom.window.close();
}
