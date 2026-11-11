// Integration test against tests/article_fixture_router.php and its disposable DB.
// It deliberately publishes/renames synthetic fixtures; never point it at a real site.
import assert from 'node:assert/strict';

const base = process.env.HHX_TEST_URL || '';
const nonce = process.env.HHX_TEST_NONCE || '';
const password = process.env.HHX_TEST_PASSWORD || '';
if (process.env.HHX_ALLOW_TEST_FIXTURES !== '1' || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base) || nonce.length < 16 || password.length < 12) {
  throw new Error('Use a loopback fixture server with HHX_ALLOW_TEST_FIXTURES=1, HHX_TEST_URL, HHX_TEST_NONCE, and HHX_TEST_PASSWORD.');
}
const guard = await fetch(base + '/__article_fixture_guard__').then(r=>r.json());
assert.equal(guard.nonce, nonce, 'fixture nonce must match');
assert.match(guard.database, /^hhx_test_[a-z0-9_]+$/, 'fixture server must force a test database');

class Client {
  cookies = new Map(); csrf = '';
  async request(method, path, data) {
    const headers = {};
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if (data !== undefined) { headers['Content-Type']='application/json'; headers['X-CSRF-Token']=this.csrf; }
    const r = await fetch(base+path, {method, headers, body:data===undefined?undefined:JSON.stringify(data), redirect:'manual'});
    for (const cookie of r.headers.getSetCookie()) {
      const pair=cookie.split(';')[0], index=pair.indexOf('='); this.cookies.set(pair.slice(0,index),pair.slice(index+1));
    }
    const text=await r.text(); let json=null; try {json=JSON.parse(text);} catch {}
    return {status:r.status,headers:r.headers,text,json};
  }
  async login(role) {
    let r=await this.request('GET','/api/v1/auth/me'); this.csrf=r.json.csrf_token;
    r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});
    assert.equal(r.status,200, r.text); this.csrf=r.json.csrf_token;
  }
}
const admin=new Client(), editor=new Client(), guest=new Client();
await admin.login('admin'); await editor.login('editor');
const list=async client=>(await client.request('GET','/api/v1/articles')).json.articles;
const queue=async ()=>(await admin.request('GET','/api/v1/review-queue?kind=article&status=pending')).json.items;
const ok = message => console.log('PASS:',message);

let r=await admin.request('GET','/api/v1/articles');
assert.equal(r.json.schema_ready,false); assert.equal(r.json.articles.length,2);
r=await admin.request('POST','/api/v1/system/migrate',{});
assert.equal(r.status,500); assert.match(r.json.error,/003/);
assert.equal((await admin.request('GET','/fixture-live/')).status,200);
assert.equal((await admin.request('GET','/fixture-draft/')).status,404);
r=await admin.request('POST','/api/v1/system/update-article-schema',{});
assert.equal(r.status,200);assert.equal(r.json.migration_result.total_applied,1);
r=await admin.request('POST','/api/v1/system/update-article-schema',{});
assert.equal(r.status,200);assert.equal(r.json.migration_result.total_applied,0);
assert.equal((await admin.request('GET','/fixture-live/')).status,200);
assert.equal((await admin.request('GET','/fixture-draft/')).status,404);
r=await editor.request('POST','/api/v1/system/update-article-tags-schema',{});
assert.equal(r.status,403,'only an admin may alter the article-tag schema');
r=await admin.request('POST','/api/v1/system/update-article-tags-schema',{});
assert.equal(r.status,200,r.text);assert.equal(r.json.migration_result.total_applied,1);
r=await admin.request('POST','/api/v1/system/update-article-tags-schema',{});
assert.equal(r.status,200,r.text);assert.equal(r.json.migration_result.total_applied,0);
r=await admin.request('GET','/api/v1/articles');assert.equal(r.json.article_tags_ready,true);
assert.equal(r.json.articles.find(a=>a.slug==='fixture-live').status,'published');
let picker=(await admin.request('GET','/api/v1/businesses?page=1&article_picker=1')).json.businesses;
assert(picker.some(b=>Number(b.id)===1),'published business with valid context should be selectable for an article');
assert(!picker.some(b=>Number(b.id)===3),'draft business must never be selectable as a sponsor/article item');
r=await admin.request('POST','/api/v1/businesses',{name:'Synthetic queue search target',slug:'synthetic-queue-search-target',city_id:1,category_id:1,status:'pending_review',tier:'B'});
assert.equal(r.status,200,r.text);const queueBusinessId=Number(r.json.business.id);
let searched=(await admin.request('GET','/api/v1/review-queue?status=pending&kind=business&q=synthetic-queue-search-target')).json.items;
assert.equal(searched.length,1);assert.equal(Number(searched[0].target_id),queueBusinessId);const queueItemId=Number(searched[0].id);
searched=(await admin.request('GET','/api/v1/review-queue?status=pending&kind=business&q=no-such-review-target')).json.items;
assert(!searched.some(i=>Number(i.target_id)===queueBusinessId));
r=await admin.request('POST','/api/v1/review-queue/decide',{ids:[queueItemId],decision:'rejected'});
assert.equal(r.status,200,r.text);
r=await editor.request('POST','/api/v1/businesses/publish',{business_id:queueBusinessId});assert.equal(r.status,403,'editors must never use the direct publication action');
r=await admin.request('POST','/api/v1/businesses/publish',{business_id:queueBusinessId});assert.equal(r.status,200,r.text);assert.equal(r.json.business.status,'published');
const persistedBusiness=(await admin.request('GET',`/api/v1/businesses?page=1&status=published&q=synthetic-queue-search-target`)).json.businesses;
assert(persistedBusiness.some(b=>Number(b.id)===queueBusinessId && b.status==='published'),'admin publication must persist and be visible in the published list');
const schedule=(await admin.request('GET','/api/v1/import/apify/status')).json.schedule;
assert.equal(Number(schedule.enabled),1);assert.equal(schedule.task_id,'fixture-only-never-run');
ok('targeted migration is repeatable and preserves existing publication states and schedule; unrecorded 003 is blocked');

r=await guest.request('GET','/api/v1/articles/preview/2');
assert.equal(r.status,401);assert.match(r.headers.get('cache-control'),/no-store/);assert.match(r.headers.get('x-robots-tag'),/noindex/);
r=await editor.request('GET','/api/v1/articles/preview/2');
assert.equal(r.status,200);assert.match(r.headers.get('cache-control'),/private.*no-store/);assert.match(r.text,/متن پیش‌نویس خصوصی/);
assert(!((await guest.request('GET','/sitemap.xml')).text.includes('/fixture-draft/')));
ok('draft preview requires a panel session, is no-store/noindex, and is absent from public pages and sitemap');

// A sponsorship is attached to an article item, not a separate banner. Only a
// published article and published business can show it publicly.
const sponsorLabel='حامی ویژه آزمایشی';
const sponsorTable=(html)=>{
  const table=html.match(/<table class="hx-table">([\s\S]*?)<\/table>/)?.[1];
  assert(table,'article comparison table is missing');
  return table;
};
const checkSponsor=(html,active)=>{
  const table=sponsorTable(html);
  const first=table.indexOf('کافه آزمایشی'),second=table.indexOf('کافه دوم');
  assert(first>=0 && second>=0,'both test businesses must be present');
  assert.equal(second<first,active,'active sponsor is pinned before organic item');
  assert.equal(table.includes(sponsorLabel),active,'label appears only in the active window');
};
r=await admin.request('POST','/api/v1/articles',{
  title_fa:'مقاله اسپانسر آزمایشی',slug:'fixture-sponsor',intro_html:'متن آزمایشی',status:'draft',
  items:[{business_id:1,rank_num:1},{business_id:2,rank_num:2,is_sponsored:1,sponsor_label:sponsorLabel,sponsor_start:'2000-01-01T00:00',sponsor_end:'2099-01-01T00:00'}],
});
assert.equal(r.status,200,r.text); let sponsored=r.json.article;
assert.equal((await guest.request('GET','/fixture-sponsor/')).status,404);
checkSponsor((await editor.request('GET',`/api/v1/articles/preview/${sponsored.id}`)).text,true);
const sponsorSummary=async()=>(await editor.request('GET','/api/v1/analytics/summary')).json.sponsored_items.find(x=>Number(x.article_id)===Number(sponsored.id));
assert.equal((await sponsorSummary()).display_status,'article_unpublished');
r=await admin.request('POST','/api/v1/articles',{id:sponsored.id,edit_token:sponsored.edit_token,status:'published'});
assert.equal(r.status,200,r.text); sponsored=r.json.article;
assert.equal(Number(sponsored.items[1].is_sponsored),1);
assert.equal(sponsored.items[1].sponsor_label,sponsorLabel);
assert.match(sponsored.items[1].sponsor_start,/^2000-01-01 00:00/);
assert.match(sponsored.items[1].sponsor_end,/^2099-01-01 00:00/);
checkSponsor((await guest.request('GET','/fixture-sponsor/')).text,true);
assert.equal((await sponsorSummary()).display_status,'active');
const expiredItems=sponsored.items.map(it=>Number(it.business_id)===2?{...it,sponsor_end:'2001-01-01T00:00'}:it);
r=await admin.request('POST','/api/v1/articles',{id:sponsored.id,edit_token:sponsored.edit_token,status:'draft',items:expiredItems});
assert.equal(r.status,200,r.text); sponsored=r.json.article;
checkSponsor((await guest.request('GET','/fixture-sponsor/')).text,true);
checkSponsor((await editor.request('GET',`/api/v1/articles/preview/${sponsored.id}`)).text,false);
r=await admin.request('POST','/api/v1/articles',{id:sponsored.id,edit_token:sponsored.edit_token,status:'published'});
assert.equal(r.status,200,r.text); sponsored=r.json.article;
checkSponsor((await guest.request('GET','/fixture-sponsor/')).text,false);
assert.equal((await sponsorSummary()).display_status,'ended');
const futureItems=sponsored.items.map(it=>Number(it.business_id)===2?{...it,sponsor_start:'2099-01-01T00:00',sponsor_end:null}:it);
r=await admin.request('POST','/api/v1/articles',{id:sponsored.id,edit_token:sponsored.edit_token,status:'draft',items:futureItems});
assert.equal(r.status,200,r.text); sponsored=r.json.article;
checkSponsor((await editor.request('GET',`/api/v1/articles/preview/${sponsored.id}`)).text,false);
assert.equal(sponsored.revision.data.items[1].sponsor_label,sponsorLabel);
ok('sponsorship label and ordering respect publication and time windows, without changing the live version while drafting');

let oldDraft=(await list(editor)).find(item=>item.slug==='fixture-draft');
r=await editor.request('POST','/api/v1/articles',{id:oldDraft.id,edit_token:oldDraft.edit_token,title_fa:'پیش‌نویس ویرایش‌شده',items:oldDraft.items,status:'draft'});
assert.equal(r.status,200,r.text);
assert.equal(r.json.article.meta_title,'سئوی پیش‌نویس');
assert.equal(r.json.article.items[1].highlights,'legacy-invalid-json');
assert.equal(r.json.article.items[1].sponsor_label,null);
ok('saving an old draft keeps custom SEO, unparseable legacy highlights, and null sponsor data');

const before=(await list(editor)).length;
r=await editor.request('POST','/api/v1/articles',{title_fa:'مقاله تست جدید',intro_html:'متن اول\n\n## تیتر آزمایشی\nمتن <script>alert(42)</script>',status:'draft'});
assert.equal(r.status,200,r.text);let a=r.json.article;const id=a.id;assert.equal(a.city_id,null);assert.equal(a.category_id,null);
r=await editor.request('GET',`/api/v1/articles/preview/${id}`);
assert.equal(r.status,200);assert.match(r.text,/<h2>تیتر آزمایشی<\/h2>/);assert(!r.text.includes('<script>alert(42)</script>'));
r=await editor.request('POST','/api/v1/articles',{id,edit_token:a.edit_token,title_fa:'مقاله تست ویرایش‌شده',intro_html:'متن دوم',status:'draft'});
assert.equal(r.status,200,r.text);a=r.json.article;assert.equal(a.id,id);assert.equal((await list(editor)).length,before+1);
assert.equal((await guest.request('GET',`/${a.slug}/`)).status,404);
r=await editor.request('POST','/api/v1/articles',{id,edit_token:a.edit_token,status:'published'});
assert.equal(r.status,200);a=r.json.article;assert.equal(a.status,'review');
let q=(await queue()).find(item=>Number(item.target_id)===Number(id));assert(q);
r=await editor.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'approved'});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'approved'});assert.equal(r.json.processed_count,1);
r=await guest.request('GET',`/${a.slug}/`);assert.equal(r.status,200);assert(r.text.includes('متن دوم'));
assert((await guest.request('GET','/sitemap.xml')).text.includes(`/${a.slug}/`));
ok('two saves do not duplicate; editor can submit but only admin can publish, and HTML is escaped');

let live=(await list(editor)).find(x=>x.slug==='fixture-live');
assert.equal(live.meta_title,'عنوان سئوی محفوظ');assert.equal(live.items[0].pros[0],'مزیت محفوظ');assert.equal(live.items[0].cons[0],'عیب محفوظ');
r=await editor.request('POST','/api/v1/articles',{id:live.id,edit_token:live.edit_token,title_fa:'ویرایش خصوصی',intro_html:'متن خصوصی جدید',status:'draft'});
assert.equal(r.status,200,r.text);live=r.json.article;assert.equal(live.status,'published');assert.equal(live.revision.status,'draft');
assert((await guest.request('GET','/fixture-live/')).text.includes('متن زنده اصلی'));
assert((await editor.request('GET',`/api/v1/articles/preview/${live.id}`)).text.includes('متن خصوصی جدید'));
r=await editor.request('POST','/api/v1/articles',{id:live.id,edit_token:live.edit_token,status:'review'});assert.equal(r.status,200);live=r.json.article;
q=(await queue()).find(item=>Number(item.target_id)===Number(live.id));
r=await admin.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'rejected'});assert.equal(r.json.processed_count,1);
live=(await list(editor)).find(x=>x.id===live.id);assert.equal(live.status,'published');assert.equal(live.revision.status,'draft');assert.equal(live.revision.data.intro_html,'متن خصوصی جدید');
assert((await guest.request('GET','/fixture-live/')).text.includes('متن زنده اصلی'));
r=await editor.request('POST','/api/v1/articles',{id:live.id,edit_token:live.edit_token,slug:'fixture-live-updated',status:'review'});assert.equal(r.status,200);live=r.json.article;
q=(await queue()).find(item=>Number(item.target_id)===Number(live.id));
r=await admin.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'approved'});assert.equal(r.json.processed_count,1);
r=await guest.request('GET','/fixture-live/');assert.equal(r.status,301);assert.equal(r.headers.get('location'),'/fixture-live-updated/');
r=await guest.request('GET','/fixture-live-updated/');assert.equal(r.status,200);assert(r.text.includes('متن خصوصی جدید'));assert(r.text.includes('مزیت محفوظ'));assert(r.text.includes('پرسش آزمایشی'));
live=(await list(editor)).find(x=>x.id===live.id);assert.equal(live.meta_title,'عنوان سئوی محفوظ');assert.equal(JSON.parse(live.items[0].highlights).custom_legacy,'keep');assert.equal(live.items[0].cons[0],'عیب محفوظ');
const stale=live.edit_token;
r=await editor.request('POST','/api/v1/articles',{id:live.id,edit_token:stale,intro_html:'ویرایش تازه',status:'draft'});assert.equal(r.status,200);
r=await editor.request('POST','/api/v1/articles',{id:live.id,edit_token:stale,intro_html:'نباید ذخیره شود',status:'draft'});assert.equal(r.status,409);
ok('live version survives edits and rejection; approval preserves hidden fields, old article URL, and stale-write protection');

r=await guest.request('GET','/b/fixture-cafe/');assert.equal(r.status,301);assert.equal(r.headers.get('location'),'/rasht/fixture-cafe/');
r=await guest.request('GET','/rasht/fixture-cafe/');assert.equal(r.status,200);assert(r.text.includes('fixture-approved.webp'));assert(!r.text.includes('fixture-rejected.webp'));assert(r.text.includes('/rasht/fixture-cafe/'));
r=await guest.request('GET','/lahijan/fixture-cafe/');assert.equal(r.status,404);
r=await admin.request('POST','/api/v1/businesses',{id:1,name:'کافه آزمایشی',slug:'fixture-renamed',city_id:2,category_id:1,status:'published',tier:'C'});assert.equal(r.status,200,r.text);
for (const path of ['/b/fixture-cafe/','/rasht/fixture-cafe/','/b/fixture-renamed/']) {
  r=await guest.request('GET',path);assert.equal(r.status,301,path);assert.equal(r.headers.get('location'),'/lahijan/fixture-renamed/');
}
r=await guest.request('GET','/sitemap.xml');assert(r.text.includes('/lahijan/fixture-renamed/'));assert(!r.text.includes('/b/fixture-renamed/'));
r=await admin.request('POST','/api/v1/cities',{id:2,name_fa:'لاهیجان',slug:'fixture-lahijan',is_active:1});assert.equal(r.status,200,r.text);
r=await guest.request('GET','/lahijan/fixture-renamed/');assert.equal(r.status,301);assert.equal(r.headers.get('location'),'/fixture-lahijan/fixture-renamed/');
r=await admin.request('POST','/api/v1/businesses',{name:'آدرس جایگزین',slug:'fixture-cafe',city_id:1,category_id:1,status:'published',tier:'C'});assert.equal(r.status,200);assert.notEqual(r.json.business.slug,'fixture-cafe');
r=await guest.request('GET','/b/fixture-cafe/');assert.equal(r.headers.get('location'),'/fixture-lahijan/fixture-renamed/');
ok('city-first canonical links, 301 aliases, city changes, slug reservation, and approved gallery are consistent');
console.log('All HTTP article and city URL checks passed using synthetic local fixtures.');
