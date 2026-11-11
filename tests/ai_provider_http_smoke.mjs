// Disposable local fixture ONLY: synthetic credentials and the guarded fake provider router.
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
const base=process.env.HHX_TEST_URL||'',nonce=process.env.HHX_TEST_NONCE||'',password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base)||nonce.length<16||password.length<12) throw new Error('Guarded local fixture server and disposable DB required.');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());
assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_[a-z0-9_]+$/);assert.equal(guard.fake_ai,true,'Fake provider transport is required; never contact a real provider.');
class Client {
  cookies=new Map();csrf='';
  constructor(origin=base){this.origin=origin;}
  async request(method,path,data,extra={}) {
    const headers={...extra};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');
    if(data!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=this.csrf;}
    const response=await fetch(this.origin+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data),redirect:'manual'});
    for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0],i=pair.indexOf('=');this.cookies.set(pair.slice(0,i),pair.slice(i+1));}
    const text=await response.text();let json=null;try{json=JSON.parse(text);}catch{}
    return {status:response.status,headers:response.headers,text,json};
  }
  async login(role){let r=await this.request('GET','/api/v1/auth/me');this.csrf=r.json.csrf_token;r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const admin=new Client(),editor=new Client(),guest=new Client();await admin.login('admin');await editor.login('editor');
let r=await guest.request('GET','/api/v1/ai/providers/manage');assert.equal(r.status,401);
r=await editor.request('GET','/api/v1/ai/providers/manage');assert.equal(r.status,403);
r=await admin.request('GET','/api/v1/ai/providers/manage');assert.equal(r.status,200,r.text);assert.match(r.headers.get('cache-control'),/no-store/);
let status=Object.fromEntries(r.json.providers.map(p=>[p.id,p]));assert.equal(status.groq.source,'file');assert(status.groq.key_configured);assert.equal(status.groq.model,'llama-3.1-8b-instant');assert(!r.text.includes('synthetic-file-only-key'));
assert.equal(status.groq.default_model,'openai/gpt-oss-120b');assert.deepEqual(status.groq.suggested_models.map(m=>m.id),['openai/gpt-oss-120b','qwen/qwen3.8-27b']);
assert.equal(status.nvidia.model,'nvidia/nemotron-3.5-lightning-30b-a3b');assert.equal(status.nvidia.available,false);assert(!r.text.includes('integrate.api.nvidia.com'));
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'groq',confirm_cost:true});assert.equal(r.status,409,'no network before quota tables exist');
r=await admin.request('POST','/api/v1/system/update-feature-schema',{});assert.equal(r.status,200,r.text);
const payload={provider:'gemini',model:'gemini-fixture',api_key:'synthetic-http-key',enabled:true,free_tier_confirmed:true,version:0};
r=await editor.request('POST','/api/v1/ai/providers/save',payload);assert.equal(r.status,403);
const actualCsrf=admin.csrf;admin.csrf='bad';r=await admin.request('POST','/api/v1/ai/providers/save',payload);assert.equal(r.status,403);admin.csrf=actualCsrf;
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,free_tier_confirmed:false});assert.equal(r.status,422);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'openrouter',model:'paid/model'});assert.equal(r.status,422);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'fake\r\ninjected'});assert.equal(r.status,422);
r=await admin.request('POST','/api/v1/ai/providers/save',payload);assert.equal(r.status,200,r.text);assert(!r.text.includes(payload.api_key));
if(process.env.HHX_TEST_PHP && process.env.HHX_TEST_CONFIG) {
  const result=execFileSync(process.env.HHX_TEST_PHP,['tests/ai_provider_db_inspect.php'],{env:process.env,encoding:'utf8'});
  assert.match(result,/PASS/);
}
r=await editor.request('GET','/api/v1/ai/article/providers');assert.equal(r.status,200,r.text);assert(r.json.providers.find(p=>p.id==='gemini').available);assert(!r.text.includes(payload.api_key));
r=await admin.request('GET','/api/v1/ai/providers/manage');status=Object.fromEntries(r.json.providers.map(p=>[p.id,p]));assert.equal(status.gemini.version,1);assert(status.gemini.key_configured);assert(!r.text.includes('synthetic-'));
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'',model:'gemini-fixture-2',version:1});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'synthetic-stale-key',version:1});assert.equal(r.status,409);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'synthetic-replacement-key',model:'gemini-fixture-2',version:2});assert.equal(r.status,200,r.text);
r=await editor.request('POST','/api/v1/ai/article/generate',{provider:'gemini',title:'عنوان آزمایشی',brief:'',facts:'',confirm_send:true,confirm_quota:true});
assert.equal(r.status,200,r.text);assert.equal(r.json.text,'synthetic-test-response');assert(!r.text.includes('synthetic-replacement-key'),'generation leaked the saved key');
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'',model:'gemini-fixture-error404',version:3});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'gemini',confirm_cost:true});
assert.equal(r.status,502,r.text);assert.match(r.json.error,/HTTP 404/);assert(!r.text.includes('synthetic-'),'error leaked provider body or key');
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,api_key:'',model:'gemini-fixture-2',version:4});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'gemini',confirm_cost:true});assert.equal(r.status,200,r.text);assert(!r.text.includes('synthetic-'));
// Preserve and encrypt the existing file key while explicitly changing each Groq model.
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'groq',model:'openai/gpt-oss-120b',api_key:'',version:0});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'groq',confirm_cost:true});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'groq',model:'qwen/qwen3.8-27b',api_key:'',version:1});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'groq',confirm_cost:true});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'openrouter',model:'openrouter/free',api_key:'synthetic-router-key',version:0});assert.equal(r.status,200,r.text);
const generate={title:'عنوان آزمایشی',brief:'',facts:'',confirm_send:true,confirm_quota:true};
r=await editor.request('POST','/api/v1/ai/article/generate',{...generate,provider:'openrouter'});assert.equal(r.status,200,r.text);assert.equal(r.json.model,'openrouter/free');
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'nvidia',model:'nvidia/nemotron-3.5-lightning-30b-a3b',api_key:'synthetic-nvidia-key',version:0});assert.equal(r.status,200,r.text);assert(!r.text.includes('synthetic-'));
if(process.env.HHX_TEST_PHP && process.env.HHX_TEST_CONFIG) assert.match(execFileSync(process.env.HHX_TEST_PHP,['tests/ai_provider_db_inspect.php'],{env:process.env,encoding:'utf8'}),/PASS/);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'nvidia',confirm_cost:true});assert.equal(r.status,200,r.text);assert(!r.text.includes('synthetic-'));
r=await editor.request('POST','/api/v1/ai/article/generate',{...generate,provider:'nvidia'});assert.equal(r.status,200,r.text);assert.equal(r.json.model,'nvidia/nemotron-3.5-lightning-30b-a3b');
r=await admin.request('POST','/api/v1/ai/providers/save',{...payload,provider:'nvidia',model:'vendor/custom-nvidia-model',api_key:'synthetic-nvidia-replacement',version:1});assert.equal(r.status,200,r.text);
r=await editor.request('POST','/api/v1/ai/article/generate',{...generate,provider:'nvidia'});assert.equal(r.status,200,r.text);assert.equal(r.json.model,'vendor/custom-nvidia-model');assert(!r.text.includes('synthetic-nvidia-'),'generation leaked a key');
r=await editor.request('POST','/api/v1/ai/article/generate',{...generate,provider:'groq'});assert.equal(r.status,200,r.text);assert.equal(r.json.model,'qwen/qwen3.8-27b');
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'gemini',confirm_cost:false});assert.equal(r.status,422);
r=await editor.request('POST','/api/v1/ai/providers/test',{provider:'gemini',confirm_cost:true});assert.equal(r.status,403);
r=await admin.request('POST','/api/v1/ai/providers/test',{provider:'gemini',confirm_cost:true});assert.equal(r.status,429,'all provider tests share the same per-user quota');
r=await editor.request('POST','/api/v1/ai/article/generate',{...generate,provider:'gemini'});assert.equal(r.status,429,'generation shares the same per-user quota across providers');
r=await admin.request('POST','/api/v1/ai/providers/remove',{provider:'nvidia',version:2,confirm:true});assert.equal(r.status,200,r.text);
r=await admin.request('POST','/api/v1/ai/providers/remove',{provider:'groq',version:2,confirm:true});assert.equal(r.status,200,r.text);
r=await editor.request('GET','/api/v1/ai/article/providers');assert(!r.json.providers.find(p=>p.id==='groq').available,'removing legacy override must not re-enable file key');assert(!r.json.providers.find(p=>p.id==='nvidia').available,'removed NVIDIA key should not remain available');
r=await admin.request('POST','/api/v1/ai/providers/remove',{provider:'gemini',version:5,confirm:true});assert.equal(r.status,200,r.text);
r=await admin.request('GET','/api/v1/ai/providers/manage');status=Object.fromEntries(r.json.providers.map(p=>[p.id,p]));assert(!status.gemini.key_configured && !status.gemini.available);
// The guarded test-only router simulates a non-loopback peer; some sandboxes proxy
// their non-loopback IP back into localhost and otherwise invalidate this test.
r=await admin.request('GET','/api/v1/ai/providers/manage',undefined,{'X-Forwarded-Proto':'https','X-HHX-Test-Remote-Nonce':nonce});
assert.equal(r.status,400,'untrusted forwarded HTTPS header must not bypass transport security');
console.log('PASS: real local DB and HTTP role/CSRF checks, encrypted-key lifecycle, legacy removal, shared quotas, and HTTPS enforcement; no external calls.');
