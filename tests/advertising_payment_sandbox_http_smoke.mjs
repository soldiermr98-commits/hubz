// Full HTTP exercise against the nonce-guarded fake-transport fixture only.
// Never send any request to an actual provider; fixture must advertise fake_payment=true.
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
const base=process.env.HHX_TEST_URL||'',nonce=process.env.HHX_TEST_NONCE||'',password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12)throw new Error('Guarded loopback fixture required.');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);assert.equal(guard.fake_payment,true,'actual provider transport is forbidden');
const key=()=>randomBytes(32).toString('hex');
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data,form=false,skipCsrf=false){const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){if(!form)headers['Content-Type']='application/json';if(!skipCsrf)headers['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:form?new URLSearchParams(data):JSON.stringify(data),redirect:'manual'});
    for(const c of r.headers.getSetCookie()){const part=c.split(';')[0],i=part.indexOf('=');this.cookies.set(part.slice(0,i),part.slice(i+1));}
    const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}return {status:r.status,headers:r.headers,text,json};}
  async login(role){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
assert.equal((await guest.request('GET','/api/v1/advertising/gateways')).status,401);
assert.equal((await editor.request('GET','/api/v1/advertising/gateways')).status,403);
let r=await admin.request('GET','/api/v1/advertising/finance');assert.equal(r.json.online_sandbox_enabled,true);assert.equal(r.json.schema_ready,true);
const cookie=[...admin.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
const insecure=await fetch(base+'/api/v1/advertising/gateways',{headers:{Cookie:cookie,'X-HHX-Test-Remote-Nonce':nonce}});assert.equal(insecure.status,400,'untrusted HTTP peer must not access gateway settings');
r=await admin.request('GET','/api/v1/advertising/gateways');assert.equal(r.status,200,r.text);const z=r.json.providers.find(x=>x.id==='zibal');const idp=r.json.providers.find(x=>x.id==='idpay');assert(z&&idp);
const config={provider:'zibal',mode:'sandbox',key:'zibal',version:Number(z.version),confirm_live:false,remove:false};
assert.equal((await editor.request('POST','/api/v1/advertising/gateways/save',config)).status,403);
assert.equal((await admin.request('POST','/api/v1/advertising/gateways/save',config,false,true)).status,403);
assert.equal((await admin.request('POST','/api/v1/advertising/gateways/save',{...config,mode:'live',key:'fake-live'})).status,422);
assert.equal((await admin.request('POST','/api/v1/advertising/gateways/save',{...config,confirm_live:true})).status,422);
r=await admin.request('POST','/api/v1/advertising/gateways/save',config);assert.equal(r.status,200,r.text);
assert.equal((await admin.request('POST','/api/v1/advertising/gateways/save',config)).status,409,'stale setting must fail');
const syntheticSecret='fixture-idpay-secret-'+key();
r=await admin.request('POST','/api/v1/advertising/gateways/save',{provider:'idpay',mode:'sandbox',key:syntheticSecret,version:Number(idp.version),confirm_live:false,remove:false});assert.equal(r.status,200,r.text);
r=await admin.request('GET','/api/v1/advertising/gateways');assert(!r.text.includes(syntheticSecret)&&!r.text.includes('secret_sealed'),'credentials are never returned');

r=await admin.request('POST','/api/v1/advertising/contracts',{request_key:key(),title:'پرداخت آزمایشی <script>',client_name:'فقط ساختگی',amount_toman:'100000',notes:'fixture'});assert.equal(r.status,200,r.text);const contract=Number(r.json.contract.id);
const token=key(),payload={contract_id:contract,amount_toman:'30000',provider:'zibal',hours:24,version:1,request_key:key(),link_token:token,confirmed:true};
assert.equal((await admin.request('POST','/api/v1/advertising/payments/online',{...payload,confirmed:false})).status,422);
assert.equal((await editor.request('POST','/api/v1/advertising/payments/online',payload)).status,403);
r=await admin.request('POST','/api/v1/advertising/payments/online',payload);assert.equal(r.status,200,r.text);const payment=Number(r.json.link.id);assert.equal(r.json.link.state,'link_ready');
assert.equal(r.json.link.url,'https://fixture.invalid/api/v1/public/advertising-payment/'+token);
r=await admin.request('POST','/api/v1/advertising/payments/online',payload);assert.equal(r.status,200);assert.equal(r.json.link.id,payment);assert(r.json.link.replayed);
assert.equal((await admin.request('POST','/api/v1/advertising/payments/online',{...payload,amount_toman:'30001'})).status,409);
let finance=(await admin.request('GET','/api/v1/advertising/finance')).json;assert(!JSON.stringify(finance).includes(token));assert(!JSON.stringify(finance).includes(syntheticSecret));
assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual',{contract_id:contract,amount_toman:'1000',method:'cash',reference:'fixture-'+key(),notes:'',request_key:key(),confirmed:true})).status,409,'unresolved sandbox blocks mutation');
const path='/api/v1/public/advertising-payment/'+token;
r=await guest.request('GET',path);assert.equal(r.status,200,r.text);assert.match(r.text,/آزمایشی/);assert(!r.text.includes('<script>'));assert.match(r.text,/&lt;script&gt;/);
assert.match(r.headers.get('cache-control'),/no-store/);assert.equal(r.headers.get('referrer-policy'),'no-referrer');assert.equal(r.headers.get('x-frame-options'),'DENY');assert.match(r.headers.get('content-security-policy'),/frame-ancestors 'none'/);
const match=r.text.match(/name="_csrf" value="([a-f0-9]{64})"/);assert(match);const publicCsrf=match[1];
assert.equal((await guest.request('POST',path,{},true,true)).status,403,'start requires CSRF');
r=await guest.request('POST',path,{_csrf:publicCsrf},true,true);assert.equal(r.status,303,r.text);assert.equal(r.headers.get('referrer-policy'),'origin');
const redirect=r.headers.get('location');assert.match(redirect,/^https:\/\/gateway\.zibal\.ir\/start\/[1-9][0-9]+$/);const track=redirect.split('/').pop();
r=await guest.request('GET',`/api/v1/public/advertising-payment/callback/${token}?trackId=9&success=1`);assert.equal(r.status,200);assert.match(r.text,/در انتظار بررسی/);
r=await guest.request('GET',`/api/v1/public/advertising-payment/callback/${token}?trackId=${track}&success=1`);assert.equal(r.status,200,r.text);assert.match(r.text,/آزمایشی/);assert.match(r.headers.get('cache-control'),/no-store/);assert.equal(r.headers.get('referrer-policy'),'no-referrer');
r=await guest.request('POST',`/api/v1/public/advertising-payment/callback/${token}`,{trackId:track,success:'1'},true,true);assert.match(r.text,/آزمایشی/);
finance=(await admin.request('GET','/api/v1/advertising/finance')).json;const p=finance.online_payments.find(x=>Number(x.id)===payment),c=finance.contracts.find(x=>Number(x.id)===contract);
assert.equal(p.state,'sandbox_verified');assert.equal(Number(c.paid_toman),0);assert.equal(Number(c.remaining_toman),100000);
assert.equal(finance.online_audit.filter(x=>Number(x.payment_id)===payment&&x.reason==='verification_started').length,1,'callback replay is idempotent');
console.log('PASS: guarded sandbox-only HTTP settings, CSRF/roles, encrypted credentials, link replay, security headers, server verification and zero live credit. No provider network.');
