// Synthetic integration only: requires the guarded local fixture router and a disposable hhx_test_* DB.
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
process.on('uncaughtException', err => { console.log(`::error title=ai_ads_http_smoke::${String(err?.stack||err).replace(/\r?\n/g,'%0A')}`); process.exit(1); });
process.on('unhandledRejection', err => { console.log(`::error title=ai_ads_http_smoke::${String(err?.stack||err).replace(/\r?\n/g,'%0A')}`); process.exit(1); });
const base=process.env.HHX_TEST_URL||'', nonce=process.env.HHX_TEST_NONCE||'', password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12) throw new Error('A guarded loopback fixture server and disposable DB are required.');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());
assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data,form=false) {
    const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){if(!form)headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:form?data:JSON.stringify(data),redirect:'manual'});
    for(const cookie of r.headers.getSetCookie()){const pair=cookie.split(';')[0],i=pair.indexOf('=');this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
    const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}
    return {status:r.status,headers:r.headers,text,json};
  }
  async session(){const r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;return r;}
  async login(role){await this.session();const r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
let r=await guest.request('GET','/api/v1/ai/article/providers');assert.equal(r.status,401);
r=await editor.request('GET','/api/v1/ai/article/providers');assert.equal(r.status,200);assert.equal(r.json.schema_ready,false);
r=await editor.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,200,r.text);assert.equal(r.json.migration_result.total_applied,1);
r=await admin.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,200,r.text);assert.equal(r.json.migration_result.total_applied,0);
r=await editor.request('GET','/api/v1/ai/article/providers');assert.equal(r.json.schema_ready,true);assert.deepEqual(r.json.providers.map(x=>x.id),['gemini','groq','openrouter','nvidia']);assert(r.json.providers.every(x=>!x.available));
r=await editor.request('POST','/api/v1/ai/article/generate',{provider:'gemini',title:'آزمایش',confirm_send:false,confirm_quota:false});assert.equal(r.status,422);
r=await editor.request('POST','/api/v1/ai/article/generate',{provider:'gemini',title:'آزمایش',confirm_send:true,confirm_quota:true});assert.equal(r.status,422);assert(!r.text.includes('secret'));
r=await guest.request('GET','/api/v1/advertising');assert.equal(r.status,401);
r=await editor.request('GET','/api/v1/advertising');assert.equal(r.status,403);
console.log('PASS: targeted migration is repeatable, AI defaults off, and management endpoints enforce roles');

const session=await guest.session();assert.match(session.headers.get('cache-control'),/no-store/);
const badGuest=new Client();
r=await badGuest.request('POST','/api/v1/public/advertising-inquiry',{name:'آزمایشی',phone:'09120000000',consent:true});assert.equal(r.status,403);
r=await guest.request('POST','/api/v1/public/advertising-inquiry',{name:'آزمایشی',phone:'09120000000',consent:false});assert.equal(r.status,422);
r=await guest.request('POST','/api/v1/public/advertising-inquiry',{name:'درخواست آزمایشی',phone:'09120000000',message:'پیام آزمایشی',consent:true});assert.equal(r.status,200,r.text);
r=await admin.request('GET','/api/v1/advertising');const inquiry=r.json.inquiries.find(i=>i.name==='درخواست آزمایشی');assert(inquiry);assert.equal(inquiry.status,'new');
r=await editor.request('POST','/api/v1/advertising/inquiry',{id:inquiry.id,operation:'handled'});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/advertising/inquiry',{id:inquiry.id,operation:'handled'});assert.equal(r.status,200);
r=await admin.request('POST','/api/v1/advertising/inquiry',{id:inquiry.id,operation:'delete'});assert.equal(r.status,200);
assert(!(await admin.request('GET','/api/v1/advertising')).json.inquiries.some(i=>i.id===inquiry.id));
console.log('PASS: public inquiry requires CSRF and consent; private admin can handle and delete it');

const fd=new FormData();fd.set('image',new Blob([await readFile('public_html/assets/img/favicon.png')],{type:'image/png'}),'test-only.png');fd.set('alt_text','تصویر آزمایشی');
r=await admin.request('POST','/api/v1/media/upload',fd,true);assert.equal(r.status,200,r.text);const mediaId=Number(r.json.media.id);
const timestamp=date=>{const p=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(date).map(x=>[x.type,x.value]));return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;};
const start=Date.now()+6000,end=start+6500;
const ad={title:'بنر ساختگی',alt_text:'بنر آزمایشی زمان‌بندی',media_id:mediaId,target_url:'/fixture-live-updated/',placement:'home',city_id:0,category_id:0,starts_at:timestamp(new Date(start)),ends_at:timestamp(new Date(end)),active:true,priority:0};
r=await editor.request('POST','/api/v1/advertising/banner',ad);assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,target_url:'javascript:alert(1)'});assert.equal(r.status,422);
r=await admin.request('POST','/api/v1/advertising/banner',ad);assert.equal(r.status,200,r.text);const adId=r.json.id;
r=await guest.request('GET','/');assert.equal(r.status,200);assert(!r.text.includes(ad.alt_text));assert(r.text.includes('hhx-ad-until:'));
await new Promise(resolve=>setTimeout(resolve,Math.max(0,start-Date.now()+1200)));
r=await guest.request('GET','/');assert.equal(r.status,200);assert(r.text.includes(ad.alt_text),'scheduled banner appears from cache at start without cron');assert.match(r.text,/rel="sponsored noopener noreferrer"/);
await new Promise(resolve=>setTimeout(resolve,Math.max(0,end-Date.now()+1200)));
r=await guest.request('GET','/');assert.equal(r.status,200);assert(!r.text.includes(ad.alt_text),'scheduled banner disappears from cache at end without cron');
let banner=(await admin.request('GET','/api/v1/advertising')).json.banners.find(b=>Number(b.id)===Number(adId));assert(banner);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,id:adId,version:Number(banner.version),starts_at:'',ends_at:'',active:false});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,id:adId,version:Number(banner.version),starts_at:'',ends_at:'',active:true});assert.equal(r.status,409,'stale banner edits must not override a newer version');
console.log('PASS: safe banner upload, scheduled cache boundaries, labels, role restrictions and stale-write protection');

const city=(await admin.request('GET','/api/v1/cities')).json.cities[0];const cat=(await admin.request('GET','/api/v1/categories')).json.categories[0];
const published=(await admin.request('GET','/api/v1/businesses?page=1&status=published')).json.businesses.find(b=>b.city_slug);
const article=(await admin.request('GET','/api/v1/articles')).json.articles.find(a=>a.status==='published');
assert(published && article);
for (const [slot,path] of [
  ['city',`/city/${city.slug}/`], ['category',`/city/${city.slug}/${cat.slug}/`],
  ['business',`/${published.city_slug}/${published.slug}/`], ['article',`/${article.slug}/`],
]) {
  const label=`بنر ساختگی ${slot}`;
  r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:label,alt_text:label,placement:slot,starts_at:'',ends_at:'',active:true});assert.equal(r.status,200,r.text);
  r=await guest.request('GET',path);assert.equal(r.status,200,`${slot} ${path}: ${r.text}`);assert(r.text.includes(label),`banner must appear in ${slot}`);
  for (const forbidden of ['href="/admin/"','پنل مدیریت','ورود مدیران','نقشه سایت (Sitemap)','Robots.txt']) {
    assert(!r.text.includes(forbidden), `public page ${path} must not contain ${forbidden}`);
  }
}
for (const seoPath of ['/sitemap.xml','/robots.txt']) {
  const seoRes=await guest.request('GET',seoPath);assert.equal(seoRes.status,200,`${seoPath} must remain reachable for crawlers`);
}
for (const homeSlot of ['home_below_hero','home_mid']) {
  r=await admin.request('POST','/api/v1/advertising/banner',{...ad,placement:homeSlot,city_id:Number(city.id),starts_at:'',ends_at:'',active:true});
  assert.equal(r.status,422,`${homeSlot} must reject city filter`);
}
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:'بنر زیر هیرو ۱',alt_text:'بنر زیر هیرو اول',placement:'home_below_hero',starts_at:'',ends_at:'',active:true,priority:50});assert.equal(r.status,200,r.text);
r=await guest.request('GET','/');assert.equal(r.status,200);
assert(r.text.includes('hx-ad-grid-1') && r.text.includes('بنر زیر هیرو اول'),'single active banner renders in hx-ad-grid-1');
for (const forbidden of ['href="/admin/"','پنل مدیریت','ورود مدیران','نقشه سایت (Sitemap)','Robots.txt']) {
  assert(!r.text.includes(forbidden), `home page must not contain ${forbidden}`);
}
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:'بنر زیر هیرو ۲',alt_text:'بنر زیر هیرو دوم',placement:'home_below_hero',starts_at:'',ends_at:'',active:true,priority:30});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:'بنر زیر هیرو ۳',alt_text:'بنر زیر هیرو سوم',placement:'home_below_hero',starts_at:'',ends_at:'',active:true,priority:10});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:'بنر میانی صفحه اصلی',alt_text:'بنر بین مقالات و کسب‌وکارها',placement:'home_mid',starts_at:'',ends_at:'',active:true,priority:20});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/advertising/banner',{...ad,title:'بنر بالای فوتر',alt_text:'بنر آزمایشی بالای فوتر',placement:'footer_above',starts_at:'',ends_at:'',active:true,priority:15});assert.equal(r.status,200,r.text);
r=await guest.request('GET','/');assert.equal(r.status,200);
assert(r.text.includes('hx-ad-grid-2'),'two active banners in slot must render hx-ad-grid-2');
assert(r.text.includes('بنر زیر هیرو اول') && r.text.includes('بنر زیر هیرو دوم'),'top 2 priority banners must render side by side');
assert(!r.text.includes('بنر زیر هیرو سوم'),'3rd banner in same slot must not exceed max 2 per slot');
assert(r.text.includes('hx-ad-wrap-inline') && r.text.includes('بنر بین مقالات و کسب‌وکارها'),'home_mid slot must render inline');
assert(r.text.includes('hx-ad-wrap-footer') && r.text.includes('بنر آزمایشی بالای فوتر'),'footer_above slot must render above footer');
assert(r.text.indexOf('بنر آزمایشی بالای فوتر') < r.text.indexOf('<footer class="hx-footer">'),'footer_above banner must appear before <footer>');
console.log('PASS: independent placements, 2-up banner grids, new slots (home_below_hero, home_mid, footer_above), and clean public header/footer verified');
const biz={name:'کسب و کار آزمایشی کیفیت',slug:'fixture-quality',city_id:Number(city.id),category_id:Number(cat.id),address:'آدرس آزمایشی',short_desc:'توضیح آزمایشی',phone:'09120000000',cover_media_id:mediaId,status:'draft',tier:'C'};
r=await admin.request('POST','/api/v1/businesses',biz);assert.equal(r.status,200,r.text);const id=Number(r.json.business.id);
const find=async()=>{const q=await admin.request('GET','/api/v1/businesses?page=1&q=fixture-quality');assert.equal(q.status,200,q.text);return q.json.businesses.find(b=>Number(b.id)===id);};
let found=await find();assert(found);assert(found.review_hash);assert.equal(found.review_current,false);
r=await editor.request('POST','/api/v1/businesses/data-review',{business_id:id,review_hash:found.review_hash,source_note:'منبع نمونه و اجازه تصویر آزمایشی',confirmed:true});assert.equal(r.status,200,r.text);
const oldHash=found.review_hash;found=await find();assert.equal(found.review_current,true);
r=await admin.request('POST','/api/v1/businesses',{...biz,id,address:'آدرس ویرایش‌شده آزمایشی'});assert.equal(r.status,200,r.text);
found=await find();assert.equal(found.review_current,false);assert.notEqual(found.review_hash,oldHash);
r=await editor.request('POST','/api/v1/businesses/data-review',{business_id:id,review_hash:oldHash,source_note:'منبع نمونه و اجازه تصویر آزمایشی',confirmed:true});assert.equal(r.status,409);
r=await admin.request('GET','/api/v1/businesses?page=1&quality=unreviewed&q=fixture-quality');assert.equal(r.status,200,r.text);assert(r.json.businesses.some(b=>Number(b.id)===id));
r=await admin.request('GET','/api/v1/businesses?page=1&quality=photo');assert.equal(r.status,200,r.text);assert(!r.json.businesses.some(b=>Number(b.id)===id));
console.log('PASS: human review records a source for an exact snapshot and becomes stale after a data change');
console.log('All AI, advertising, and quality HTTP checks passed with synthetic local fixtures.');
