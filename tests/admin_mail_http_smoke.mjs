import assert from 'node:assert/strict';
process.on('uncaughtException',e=>{console.log(`::error title=admin_mail_http::${String(e.stack||e).replace(/\r?\n/g,'%0A')}`);process.exit(1)});
const base=process.env.HHX_TEST_URL||'', nonce=process.env.HHX_TEST_NONCE||'', password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)||nonce.length<16||password.length<12)throw Error('guarded loopback fixture required');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data,csrf=this.csrf){const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');if(data!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data)});for(const c of r.headers.getSetCookie()){const [pair]=c.split(';');const n=pair.indexOf('=');this.cookies.set(pair.slice(0,n),pair.slice(n+1));}const text=await r.text();return {status:r.status,text,json:JSON.parse(text)};}
  async login(role){this.csrf=(await this.request('GET','/api/v1/auth/me')).json.csrf_token;const r=await this.request('POST','/api/v1/auth/login',{username:'fixture_'+role,password});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;}
}
const anonymous=new Client();assert.equal((await anonymous.request('GET','/api/v1/admin-mail')).status,401);
const editor=new Client();await editor.login('editor');assert.equal((await editor.request('GET','/api/v1/admin-mail')).status,403);
const admin=new Client();await admin.login('admin');let r=await admin.request('GET','/api/v1/admin-mail');assert.equal(r.status,200,r.text);assert.equal(r.json.settings.version,0);
const settings={enabled:false,method:'smtp',recipient:'admin@example.test',from:'site@example.test',host:'smtp.example.test',port:587,security:'tls',username:'fixture',password:'synthetic-secret',version:0};
r=await admin.request('POST','/api/v1/admin-mail',settings,'bad-token');assert.equal(r.status,403,r.text);
r=await admin.request('POST','/api/v1/admin-mail',settings);assert.equal(r.status,200,r.text);
r=await admin.request('GET','/api/v1/admin-mail');assert.equal(r.json.settings.password_configured,true);assert.equal(r.json.settings.version,1);assert(!r.text.includes('synthetic-secret'));
r=await admin.request('POST','/api/v1/admin-mail',settings);assert.equal(r.status,409,r.text);
console.log('PASS: admin mail HTTP admin-only access, CSRF, redaction and stale-update protection.');
