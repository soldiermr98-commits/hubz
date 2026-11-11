// Synthetic data ONLY: a guarded loopback fixture server and hhx_test_* DB.
// This test explicitly publishes fake articles, never a real site's content.
import assert from 'node:assert/strict';

const base=process.env.HHX_TEST_URL||'';
const nonce=process.env.HHX_TEST_NONCE||'';
const password=process.env.HHX_TEST_PASSWORD||'';
const noDom=process.env.HHX_TEST_NO_DOM==='1';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12){
  throw new Error('Use the guarded loopback fixture server, test database, and synthetic credentials only.');
}
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());
assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data){
    const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data),redirect:'manual'});
    for(const c of r.headers.getSetCookie()){const pair=c.split(';')[0],i=pair.indexOf('=');this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
    const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}
    return {status:r.status,headers:r.headers,text,json};
  }
  async login(role){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;
    r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
let r=await admin.request('GET','/api/v1/articles');assert.equal(r.status,200,r.text);
assert.equal(r.json.rich_editor_available,!noDom,'API must report the capability of this PHP web process');
if(!r.json.schema_ready){
  r=await admin.request('POST','/api/v1/system/update-article-schema',{});assert.equal(r.status,200,r.text);
}
if(!r.json.article_tags_ready){
  r=await admin.request('POST','/api/v1/system/update-article-tags-schema',{});assert.equal(r.status,200,r.text);
  r=await admin.request('GET','/api/v1/articles');assert.equal(r.json.article_tags_ready,true);
}
const list=async()=>(await admin.request('GET','/api/v1/articles')).json.articles;
const slug='fixture-rich-editor-smoke';
const marker='<!--hhx-rich-v1-->';

if(noDom){
  let article=(await list()).find(a=>a.slug===slug);assert(article,'DOM phase must create the synthetic article first');
  const previous=(article.revision?.data||article).intro_html;
  assert(previous.startsWith(marker));
  r=await admin.request('POST','/api/v1/articles',{id:article.id,edit_token:article.edit_token,intro_html:previous+'<p>تغییر غیرمجاز</p>',status:'draft'});
  assert.equal(r.status,422,r.text);
  let after=(await list()).find(a=>a.id===article.id);
  assert.equal((after.revision?.data||after).intro_html,previous,'rejected rich edit must not alter the saved text');
  r=await admin.request('POST','/api/v1/articles',{id:after.id,edit_token:after.edit_token,meta_title:'تنظیم ساختگی',status:'draft'});
  assert.equal(r.status,200,r.text);after=r.json.article;
  assert.equal(after.revision.data.intro_html,previous,'unrelated draft must preserve rich bytes with no DOM');
  r=await guest.request('GET',`/${slug}/?test_no_dom=1`);assert.equal(r.status,200,r.text);
  const prose=r.text.match(/<div class="hx-prose"[^>]*>([\s\S]*?)<\/div>/)?.[1]||'';
  assert(prose.includes('متن قالب‌دار فارسی'),'fallback must still expose readable article text');
  assert(!prose.includes('<strong>')&&!prose.includes('<script')&&!prose.includes('javascript:'),'fallback must never emit untrusted HTML');
  console.log('PASS: no-DOM API guard, unchanged rich draft, and safe public text fallback');
  process.exit(0);
}

assert(!(await list()).some(a=>a.slug===slug),'test slug must be unused in this disposable DB');
const oldText='متن عمومی قدیمی\n\n## تیتر قبلی\nمتن <strong>بدون قالب</strong>';
r=await admin.request('POST','/api/v1/articles',{
  title_fa:'مقاله قالب‌دار آزمایشی',slug,intro_html:oldText,status:'published',tags:['سفر','طبیعت'],
  items:[{business_id:1,is_sponsored:0,custom_desc_html:'توضیح عمومی ساختگی'},{business_id:3,is_sponsored:1,sponsor_label:'خصوصی'}],
});
assert.equal(r.status,200,r.text);let article=r.json.article;
assert.equal(article.intro_html,oldText);assert.deepEqual(article.tags.map(t=>t.name_fa).sort(),['سفر','طبیعت'].sort());assert.equal(article.item_display['1'].eligibility,'eligible');
assert.equal(article.item_display['3'].eligibility,'not_published');assert.equal(article.item_display['3'].business_status,'draft');
r=await guest.request('GET',`/${slug}/`);assert.equal(r.status,200,r.text);
assert(r.text.includes('متن عمومی قدیمی')&&r.text.includes('توضیح عمومی ساختگی'));
assert(r.text.includes('موضوع‌های مقاله')&&r.text.includes('سفر')&&r.text.includes('طبیعت'),'published article topic tags must be visible publicly');
assert(r.text.includes('جدول مقایسه')&&!r.text.includes('کسب‌وکار خصوصی'),'unpublished business must be excluded');

const items=article.items.map(i=>Number(i.business_id)===1?{...i,is_sponsored:1,sponsor_label:'حامی پیش‌نویس'}:i);
r=await editor.request('POST','/api/v1/articles',{id:article.id,edit_token:article.edit_token,items,status:'draft'});
assert.equal(r.status,200,r.text);article=r.json.article;
assert.equal(article.revision.data.intro_html,oldText,'sponsor-only edit must not rewrite legacy content');
assert.deepEqual(article.revision.data.tags.slice().sort(),['سفر','طبیعت'].sort(),'sponsor-only edit must preserve selected topic tags');
assert.equal(article.revision.data.items[0].is_sponsored,1);
r=await guest.request('GET',`/${slug}/`);assert(r.text.includes('متن عمومی قدیمی')&&!r.text.includes('حامی پیش‌نویس'));
assert(r.text.includes('موضوع‌های مقاله')&&r.text.includes('سفر')&&r.text.includes('طبیعت'),'private sponsor revision must not alter public topic tags');
r=await editor.request('GET',`/api/v1/articles/preview/${article.id}`);assert.equal(r.status,200,r.text);
assert(r.text.includes('حامی پیش‌نویس')&&!r.text.includes('کسب‌وکار خصوصی'));
r=await guest.request('GET',`/api/v1/articles/preview/${article.id}`);assert.equal(r.status,401);assert.match(r.headers.get('cache-control'),/no-store/);
console.log('PASS: read-only business eligibility and a sponsor-only private revision preserve the public snapshot');

const unsafe=marker+'<h2 style="text-align:center">تیتر فارسی</h2><p onclick="alert(1)">متن قالب‌دار فارسی <strong>پررنگ</strong> '
  +'<a href="https://example.test/path?x=1&amp;y=2">پیوند امن</a> <a href="&#106;avascript:alert(2)">لینک ناسالم</a>'
  +'<script>alert(3)</script><svg onload="alert(4)"></svg></p></div><p>متن پس از تگ نامتوازن</p><ul><li><p>یک</p></li></ul>';
r=await editor.request('POST','/api/v1/articles',{id:article.id,edit_token:article.edit_token,intro_html:unsafe,tags:['بازبینی خصوصی'],status:'review'});
assert.equal(r.status,200,r.text);article=r.json.article;
assert.equal(article.status,'published');assert.equal(article.revision.status,'review');
const clean=article.revision.data.intro_html;assert(clean.startsWith(marker));
assert.deepEqual(article.revision.data.tags,['بازبینی خصوصی'],'review revision should carry its own topic tags');
for(const good of ['تیتر فارسی','<strong>پررنگ</strong>','پیوند امن','متن پس از تگ نامتوازن','<ul>'])assert(clean.includes(good),good);
for(const bad of ['onclick','javascript:','<script','<svg','alert(3)'])assert(!clean.toLowerCase().includes(bad.toLowerCase()),bad);
r=await editor.request('GET',`/api/v1/articles/preview/${article.id}`);assert.equal(r.status,200,r.text);
assert(r.text.includes('<h2 style="text-align: center">تیتر فارسی</h2>')&&r.text.includes('<strong>پررنگ</strong>'));
assert(r.text.includes('بازبینی خصوصی'),'private preview should render working-copy topic tags');
assert(!r.text.includes('javascript:alert(2)')&&!r.text.includes('onclick="alert(1)"'));
r=await guest.request('GET',`/${slug}/`);assert(r.text.includes('متن عمومی قدیمی')&&!r.text.includes('متن قالب‌دار فارسی'));
assert(r.text.includes('سفر')&&!r.text.includes('بازبینی خصوصی'),'review tag must remain private until approved');
let q=(await admin.request('GET','/api/v1/review-queue?kind=article&status=pending')).json.items.find(i=>Number(i.target_id)===Number(article.id));assert(q);
r=await editor.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'approved'});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/review-queue/decide',{ids:[q.id],decision:'approved'});assert.equal(r.status,200,r.text);assert.equal(r.json.processed_count,1);
r=await guest.request('GET',`/${slug}/`);assert.equal(r.status,200,r.text);
assert(r.text.includes('متن قالب‌دار فارسی')&&r.text.includes('<strong>پررنگ</strong>'));
assert(r.text.includes('بازبینی خصوصی')&&!r.text.includes('>سفر</span>'),'approval must publish the new tag set');
assert(!r.text.includes('javascript:alert(2)')&&!r.text.includes('کسب‌وکار خصوصی'));
article=(await list()).find(a=>a.id===article.id);assert.equal(article.intro_html,clean);
console.log('PASS: server-side allowlist, private preview, editor review, and admin-only publication of synthetic rich content');

const previous=article.intro_html,stale=article.edit_token;
r=await editor.request('POST','/api/v1/articles',{id:article.id,edit_token:stale,meta_title:'تغییر خصوصی',status:'draft'});
assert.equal(r.status,200,r.text);article=r.json.article;assert.equal(article.revision.data.intro_html,previous);
r=await editor.request('POST','/api/v1/articles',{id:article.id,edit_token:stale,intro_html:marker+'<p>نوشته قدیمی متعارض</p>',status:'draft'});
assert.equal(r.status,409,r.text);
const current=(await list()).find(a=>a.id===article.id);assert.equal(current.revision.data.intro_html,previous);
r=await admin.request('POST','/api/v1/articles',{title_fa:'مقاله خالی ساختگی',intro_html:marker+'<p>&nbsp;<br></p>',status:'published'});
assert.equal(r.status,422,r.text);
const deep=marker+'<blockquote>'.repeat(70)+'x'+'</blockquote>'.repeat(70);
r=await editor.request('POST','/api/v1/articles',{id:current.id,edit_token:current.edit_token,intro_html:deep,status:'draft'});
assert.equal(r.status,422,r.text);
assert.equal(((await list()).find(a=>a.id===article.id)).revision.data.intro_html,previous);
console.log('PASS: rich content survives unrelated edits, stale writes and invalid markup are rejected, and empty content cannot publish');
