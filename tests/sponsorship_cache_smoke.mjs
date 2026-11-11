// Synthetic integration test: only the guarded loopback fixture server and a disposable local DB.
import assert from 'node:assert/strict';
const base=process.env.HHX_TEST_URL||'', nonce=process.env.HHX_TEST_NONCE||'', password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12) {
  throw new Error('Use the guarded loopback test server and disposable hhx_test_* database.');
}
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());
assert.equal(guard.nonce,nonce); assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);
class Client {
  cookies=new Map(); csrf='';
  async request(method,path,data) {
    const headers={};
    if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data),redirect:'manual'});
    for(const cookie of r.headers.getSetCookie()){const pair=cookie.split(';')[0],i=pair.indexOf('=');this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
    const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}
    return {status:r.status,text,json};
  }
  async login(){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;r=await this.request('POST','/api/v1/auth/login',{username:'fixture_admin',password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const timestamp=(date)=>{
  const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(date).map(p=>[p.type,p.value]));
  return `${parts.year}-${parts.month}-${parts.day} ${parts.hour}:${parts.minute}:${parts.second}`;
};
const admin=new Client(), guest=new Client(); await admin.login();
const endMs=Date.now()+10000, end=timestamp(new Date(endMs));
let r=await admin.request('POST','/api/v1/articles',{
  title_fa:'تست کش اسپانسر',slug:'fixture-sponsor-cache',intro_html:'متن ساختگی',status:'draft',
  items:[{business_id:1,rank_num:1},{business_id:2,rank_num:2,is_sponsored:1,sponsor_label:'اسپانسر موقت آزمایشی',sponsor_start:null,sponsor_end:end}],
});
assert.equal(r.status,200,r.text);let article=r.json.article;
r=await admin.request('POST','/api/v1/articles',{id:article.id,edit_token:article.edit_token,status:'published'});
assert.equal(r.status,200,r.text);
r=await guest.request('GET','/fixture-sponsor-cache/');assert.equal(r.status,200);assert(r.text.includes('اسپانسر موقت آزمایشی'),'active sponsor is initially cached');
await new Promise(resolve=>setTimeout(resolve,Math.max(0,endMs-Date.now()+1500)));
// Expiration must happen at the boundary without waiting for maintenance.
r=await guest.request('GET','/fixture-sponsor-cache/');
assert.equal(r.status,200);assert(!r.text.includes('اسپانسر موقت آزمایشی'),'expired sponsor is absent even before maintenance');
const table=r.text.match(/<table class="hx-table">([\s\S]*?)<\/table>/)?.[1]||'';
assert(table.indexOf('کافه آزمایشی')>=0 && table.indexOf('کافه دوم')>=0);
assert(table.indexOf('کافه آزمایشی')<table.indexOf('کافه دوم'),'normal order is restored after expiration');
r=await admin.request('POST','/api/v1/system/cron-run',{});
assert.equal(r.status,200,r.text);assert(r.json.sponsor_transitions>=1,'maintenance detected transition');assert(r.json.flushed_files>=1,'maintenance invalidated the refreshed synthetic page');
console.log('PASS: a cached synthetic article expires at its sponsor boundary even without maintenance');
