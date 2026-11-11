import assert from 'node:assert/strict';
process.on('uncaughtException',e=>{console.log(`::error title=staff_http::${String(e.stack||e).replace(/\r?\n/g,'%0A')}`);process.exit(1)});
const base=process.env.HHX_TEST_URL||'',nonce=process.env.HHX_TEST_NONCE||'',password=process.env.HHX_TEST_PASSWORD||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!/^http:\/\/127\.0\.0\.1:\d+$/.test(base)||nonce.length<16||password.length<12)throw Error('guarded loopback fixture required');
const guard=await fetch(base+'/__article_fixture_guard__').then(r=>r.json());assert.equal(guard.nonce,nonce);assert.match(guard.database,/^hhx_test_/);
class Client {
  cookies=new Map();csrf='';
  async request(method,path,data,csrf=this.csrf){const headers={};if(this.cookies.size)headers.Cookie=[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ');if(data!==undefined){headers['Content-Type']='application/json';headers['X-CSRF-Token']=csrf;}
    const r=await fetch(base+path,{method,headers,body:data===undefined?undefined:JSON.stringify(data)});for(const c of r.headers.getSetCookie()){const [pair]=c.split(';');const n=pair.indexOf('=');this.cookies.set(pair.slice(0,n),pair.slice(n+1));}const text=await r.text();return {status:r.status,text,json:JSON.parse(text)};}
  async login(username,pwd=password){this.csrf=(await this.request('GET','/api/v1/auth/me')).json.csrf_token;const r=await this.request('POST','/api/v1/auth/login',{username,password:pwd});assert.equal(r.status,200,r.text);this.csrf=r.json.csrf_token;return r;}
}
const admin=new Client();await admin.login('fixture_admin');const editor=new Client();await editor.login('fixture_editor');
assert.equal((await editor.request('GET','/api/v1/staff')).status,403);
const staffName='spec'+Date.now().toString(36);const staffPassword='fixture-staff-password';
const data={id:0,username:staffName,display_name:'همکار آزمایشی',password:staffPassword,is_active:true,permissions:['articles']};
assert.equal((await admin.request('POST','/api/v1/staff',data,'invalid')).status,403);
let r=await admin.request('POST','/api/v1/staff',data);assert.equal(r.status,200,r.text);const id=r.json.id;
const staff=new Client();r=await staff.login('hhx_staff_'+staffName,staffPassword);assert.deepEqual(r.json.user.permissions,['articles']);
assert.equal((await staff.request('GET','/api/v1/articles')).status,200);
assert.equal((await staff.request('GET','/api/v1/businesses')).status,403);
assert.equal((await staff.request('GET','/api/v1/admin-mail')).status,403);
assert.equal((await staff.request('POST','/api/v1/review-queue/decide',{})).status,403);
assert.equal((await staff.request('POST','/api/v1/staff',data)).status,403);
r=await admin.request('POST','/api/v1/staff',{...data,id,username:'',password:'',permissions:['businesses']});assert.equal(r.status,200,r.text);
assert.equal((await staff.request('GET','/api/v1/articles')).status,403,'permission revoked immediately');
assert.equal((await staff.request('GET','/api/v1/businesses')).status,200,'new permission takes effect without re-login');
r=await admin.request('POST','/api/v1/staff',{...data,id,username:'',password:'new-fixture-password',permissions:['businesses']});assert.equal(r.status,200,r.text);
assert.equal((await staff.request('GET','/api/v1/businesses')).status,401,'password reset revokes session');
await staff.login('hhx_staff_'+staffName,'new-fixture-password');
r=await admin.request('POST','/api/v1/staff',{...data,id,username:'',password:'',is_active:false,permissions:['businesses']});assert.equal(r.status,200,r.text);
assert.equal((await staff.request('GET','/api/v1/businesses')).status,401,'deactivation revokes session');
console.log('PASS: staff HTTP access controls, CSRF, permission changes and session revocation.');
