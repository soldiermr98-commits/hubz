// Synthetic ledger only. Use the guarded local fixture server and disposable hhx_test_* DB.
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
const base=process.env.HHX_TEST_URL||'',nonce=process.env.HHX_TEST_NONCE||'',password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12) throw new Error('Guarded loopback test DB required.');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data){const h={};if(this.cookies.size)h.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');if(data!==undefined){h['Content-Type']='application/json';h['X-CSRF-Token']=this.csrf;}
    const r=await fetch(base+path,{method,headers:h,body:data===undefined?undefined:JSON.stringify(data),redirect:'manual'});for(const c of r.headers.getSetCookie()){const p=c.split(';')[0],i=p.indexOf('=');this.cookies.set(p.slice(0,i),p.slice(i+1));}const text=await r.text();let json=null;try{json=JSON.parse(text);}catch{}return {status:r.status,json,text,headers:r.headers};}
  async login(role){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const key=()=>randomBytes(32).toString('hex');
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
let r=await admin.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/system/update-advertising-schema',{});assert.equal(r.status,200,r.text);
assert.equal((await guest.request('GET','/api/v1/advertising/finance')).status,401);assert.equal((await editor.request('GET','/api/v1/advertising/finance')).status,403);
// The incomplete online implementation must not be reachable by accident.
assert.equal((await admin.request('GET','/api/v1/advertising/gateways')).status,404);
assert.equal((await admin.request('POST','/api/v1/advertising/payments/online',{})).status,404);
assert.equal((await guest.request('GET','/api/v1/public/advertising-payment/'+key())).status,404);
assert.equal((await guest.request('GET','/api/v1/public/advertising-payment/callback/'+key())).status,404);
const input={request_key:key(),title:'قرارداد ساختگی',client_name:'مشتری ساختگی',client_phone:'',banner_id:0,starts_at:'2026-11-01',ends_at:'2026-12-01',amount_toman:'۱۰۰۰۰۰',notes:'فقط آزمایش'};
assert.equal((await editor.request('POST','/api/v1/advertising/contracts',input)).status,403);
assert.equal((await admin.request('POST','/api/v1/advertising/contracts',{...input,amount_toman:1.5})).status,422);
r=await admin.request('POST','/api/v1/advertising/contracts',input);assert.equal(r.status,200,r.text);const id=Number(r.json.contract.id);assert(!r.json.contract.replayed);
r=await admin.request('POST','/api/v1/advertising/contracts',input);assert.equal(r.status,200,r.text);assert.equal(Number(r.json.contract.id),id);assert(r.json.contract.replayed);
assert.equal((await admin.request('POST','/api/v1/advertising/contracts',{...input,title:'متفاوت'})).status,409);
const listing=async()=>(await admin.request('GET','/api/v1/advertising/finance')).json;
let data=await listing(), c=data.contracts.find(x=>Number(x.id)===id);assert(c);assert.equal(Number(c.amount_toman),100000);assert.equal(Number(c.remaining_toman),100000);
const pay={contract_id:id,amount_toman:'40000',method:'bank_transfer',reference:'receipt-fixture-'+key().slice(0,16),notes:'',request_key:key(),confirmed:true};
assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual',{...pay,confirmed:false})).status,422);
r=await admin.request('POST','/api/v1/advertising/payments/manual',pay);assert.equal(r.status,200,r.text);const payment=Number(r.json.payment.id);
r=await admin.request('POST','/api/v1/advertising/payments/manual',pay);assert.equal(r.status,200,r.text);assert.equal(Number(r.json.payment.id),payment);assert(r.json.payment.replayed);
assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual',{...pay,amount_toman:'40001'})).status,409);
assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual',{...pay,request_key:key(),amount_toman:'60001'})).status,409);
data=await listing();c=data.contracts.find(x=>Number(x.id)===id);assert.equal(Number(c.paid_toman),40000);assert.equal(Number(c.remaining_toman),60000);
let status={contract_id:id,version:Number(c.version),status:'completed',reason:'تکمیل قرارداد ساختگی',confirmed:true};
assert.equal((await admin.request('POST','/api/v1/advertising/contracts/status',status)).status,409);
r=await admin.request('POST','/api/v1/advertising/payments/manual',{...pay,request_key:key(),amount_toman:'60000',reference:'receipt-fixture-'+key().slice(0,16),method:'cash'});assert.equal(r.status,200,r.text);
assert.equal((await admin.request('POST','/api/v1/advertising/contracts/status',status)).status,409,'stale status version must fail');
data=await listing();c=data.contracts.find(x=>Number(x.id)===id);assert.equal(Number(c.paid_toman),100000);assert.equal(Number(c.remaining_toman),0);
status.version=Number(c.version);r=await admin.request('POST','/api/v1/advertising/contracts/status',status);assert.equal(r.status,200,r.text);
let voidData={contract_id:id,payment_id:payment,reason:'اصلاح رسید ساختگی',confirmed:true};assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual/void',voidData)).status,409);
data=await listing();c=data.contracts.find(x=>Number(x.id)===id);r=await admin.request('POST','/api/v1/advertising/contracts/status',{...status,version:Number(c.version),status:'active',reason:'بازگشایی برای اصلاح'});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/advertising/payments/manual/void',voidData);assert.equal(r.status,200,r.text);
data=await listing();c=data.contracts.find(x=>Number(x.id)===id);assert.equal(Number(c.remaining_toman),40000);assert.equal(data.manual_payments.find(x=>Number(x.id)===payment).status,'voided');assert.equal(data.status_log.filter(x=>Number(x.contract_id)===id).length,2);
r=await admin.request('POST','/api/v1/advertising/payments/manual',pay);assert.equal(r.status,200,r.text);assert(r.json.payment.replayed);assert.equal(r.json.payment.status,'voided');
assert.equal((await admin.request('POST','/api/v1/advertising/payments/manual',{...pay,request_key:key(),amount_toman:'1000'})).status,409,'a voided reference must not be reused');
console.log('PASS: admin-only contracts, integer toman, idempotent and unique manual receipts, immutable terms, versioning, audited status and void operations.');
