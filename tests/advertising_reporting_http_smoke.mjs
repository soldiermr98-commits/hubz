// Backend reporting smoke test; guarded loopback server and disposable DB only.
// HHX_TEST_MEDIA_ID may select an existing synthetic fixture image when GD is unavailable;
// in that mode this test does not exercise media upload.
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const base=process.env.HHX_TEST_URL||'', nonce=process.env.HHX_TEST_NONCE||'', password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12) throw new Error('Guarded loopback fixture server and disposable DB required.');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data,form=false){
    const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){if(!form)headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:form?data:JSON.stringify(data),redirect:'manual'});
    for(const cookie of r.headers.getSetCookie()){const pair=cookie.split(';')[0],i=pair.indexOf('=');this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
    const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}return {status:r.status,text,json};
  }
  async login(role){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
let r=await admin.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,200,r.text);
r=await editor.request('POST','/api/v1/system/update-advertising-schema',{});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/system/update-advertising-schema',{});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/system/update-advertising-schema',{});assert.equal(r.status,200,r.text);assert.equal(r.json.migration_result.total_applied,0);
assert.equal((await admin.request('GET','/api/v1/advertising')).json.reporting_schema_ready,true);
let mediaId;
if(process.env.HHX_TEST_MEDIA_ID){
  assert.match(process.env.HHX_TEST_MEDIA_ID,/^[1-9][0-9]*$/);mediaId=Number(process.env.HHX_TEST_MEDIA_ID);
} else {
  const fd=new FormData();fd.set('image',new Blob([await readFile('public_html/assets/img/favicon.png')],{type:'image/png'}),'fixture.png');fd.set('alt_text','بنر ساختگی');
  r=await admin.request('POST','/api/v1/media/upload',fd,true);assert.equal(r.status,200,r.text);mediaId=Number(r.json.media.id);
}
const payload={title:'گزارش ساختگی',alt_text:'بنر ساختگی',media_id:mediaId,target_url:'/fixture/',placement:'home',city_id:0,category_id:0,starts_at:'',ends_at:'',active:true,priority:0};
r=await admin.request('POST','/api/v1/advertising/banner',payload);assert.equal(r.status,200,r.text);const id=Number(r.json.id);
const banner=async()=>(await admin.request('GET','/api/v1/advertising')).json.banners.find(b=>Number(b.id)===id);
assert.equal(Number((await banner()).views_total),0);
for(const event of ['view','click']){r=await guest.request('POST','/api/v1/public/banner-event',{banner_id:id,event});assert.equal(r.status,204,r.text);}
assert.equal(Number((await banner()).views_total),1);assert.equal(Number((await banner()).clicks_total),1);
r=await guest.request('POST','/api/v1/public/banner-event',{banner_id:id,event:'unexpected'});assert.equal(r.status,422);
let b=await banner();r=await admin.request('POST','/api/v1/advertising/banner',{...payload,id,version:Number(b.version),active:false});assert.equal(r.status,200,r.text);
r=await guest.request('POST','/api/v1/public/banner-event',{banner_id:id,event:'view'});assert.equal(r.status,204);b=await banner();assert.equal(Number(b.views_total),1,'inactive banners cannot increment');
r=await admin.request('POST','/api/v1/advertising/banner/delete',{id,version:Number(b.version)});assert.equal(r.status,200,r.text);assert.equal(await banner(),undefined);
console.log('PASS: targeted reporting migration is repeatable/admin-only; direct synthetic events count only active banners.');
if(process.env.HHX_TEST_MEDIA_ID)console.log('NOTE: preseeded synthetic media was used; this run did not test uploads.');
console.log('NOTE: this HTTP test does not contact gateways or validate behavior on the real host.');
