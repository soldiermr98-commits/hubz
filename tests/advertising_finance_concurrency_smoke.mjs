// Real concurrent PHP processes against a guarded local disposable MySQL database.
// Provider callbacks and credentials are entirely synthetic; no payment network is contacted.
import assert from 'node:assert/strict';
import {randomBytes} from 'node:crypto';
import {spawn} from 'node:child_process';
const php=process.env.HHX_TEST_PHP||'',config=process.env.HHX_TEST_CONFIG||'';
if(process.env.HHX_ALLOW_TEST_FIXTURES!=='1'||!php.startsWith('/')||!config.startsWith('/')||process.env.HHX_PAYMENT_VAULT_KEY!=='AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=')throw new Error('Explicit local test PHP, DB and test-only vault required.');
const key=()=>randomBytes(32).toString('hex');
function run(input){return new Promise((resolve,reject)=>{
  const p=spawn(php,['tests/advertising_finance_concurrency_worker.php'],{env:process.env});let out='',err='';
  p.stdout.on('data',x=>out+=x);p.stderr.on('data',x=>err+=x);p.on('error',reject);
  p.on('close',code=>{if(code!==0)return reject(new Error(`worker exit ${code}: ${err||out}`));try{resolve(JSON.parse(out));}catch{return reject(new Error('non-JSON test worker response: '+out+err));}});
  p.stdin.end(JSON.stringify(input));
});}
const setup=await run({action:'setup'});assert(setup.one&&setup.four,JSON.stringify(setup));
const at=()=>Date.now()/1000+1;
let time=at();const simultaneous=await Promise.all([
  run({action:'manual',contract:setup.one,amount:'60000',key:key(),reference:'concurrent-'+key(),at:time}),
  run({action:'manual',contract:setup.one,amount:'60000',key:key(),reference:'concurrent-'+key(),at:time}),
]);
assert.equal(simultaneous.filter(x=>x.ok).length,1,'one over-balance concurrent record must fail');assert.equal(simultaneous.find(x=>!x.ok).code,409);
let sameKey=key(),sameRef='idempotent-'+key();time=at();const same=await Promise.all([
  run({action:'manual',contract:setup.two,amount:'40000',key:sameKey,reference:sameRef,at:time}),
  run({action:'manual',contract:setup.two,amount:'40000',key:sameKey,reference:sameRef,at:time}),
]);
assert(same.every(x=>x.ok),'same request must succeed or replay');assert.equal(same.filter(x=>x.result.replayed).length,1);
time=at();const mixed=await Promise.all([
  run({action:'manual',contract:setup.three,amount:'60000',key:key(),reference:'mixed-'+key(),at:time}),
  run({action:'link',contract:setup.three,key:key(),token:key(),at:time}),
]);
assert.equal(mixed.filter(x=>x.ok).length,1,'link and manual payment must serialize');assert.equal(mixed.find(x=>!x.ok).code,409);
const duplicateRef='duplicate-'+key()+'-123';const alternateRef=duplicateRef.toUpperCase().replace(/123$/, '۱۲۳');
time=at();const duplicate=await Promise.all([
  run({action:'manual',contract:setup.five,amount:'10000',key:key(),reference:duplicateRef,at:time}),
  run({action:'manual',contract:setup.six,amount:'10000',key:key(),reference:alternateRef,at:time}),
]);
assert.equal(duplicate.filter(x=>x.ok).length,1,'one collation-equivalent receipt must not credit two contracts');assert.equal(duplicate.find(x=>!x.ok).code,409);
time=at();const callback=await Promise.all([0,1].map(()=>run({action:'callback',token:setup.token,provider_id:setup.provider_id,order_id:setup.order_id,at:time})));
assert(callback.every(x=>x.ok));
time=at();const cross=await Promise.all([
  run({action:'callback',token:setup.token2,provider_id:setup.provider_id2,order_id:setup.order_id2,at:time}),
  run({action:'manual',contract:setup.eight,amount:'10000',key:key(),reference:('ref-'+setup.order_id2).toUpperCase(),at:time}),
]);
assert(cross[0].ok);assert(cross[1].ok || cross[1].code===409);
const state=await run({action:'inspect',contracts:[setup.one,setup.two,setup.three,setup.four,setup.five,setup.six,setup.seven,setup.eight],payment_id:setup.payment_id,payment_id2:setup.payment_id2});
assert.equal(state.contracts[setup.one].paid,60000);assert.equal(state.contracts[setup.two].paid,40000);
assert(state.contracts[setup.three].paid===60000 || (state.contracts[setup.three].paid===0&&state.contracts[setup.three].open),'mixed race never permits both');
assert.equal(state.contracts[setup.five].paid+state.contracts[setup.six].paid,10000);
assert.equal(state.contracts[setup.four].paid,30000);assert.equal(state.payment_state,'verified');assert.equal(state.verify_started,1,'concurrent callback starts verification only once');
assert((state.contracts[setup.seven].paid===30000 && state.contracts[setup.eight].paid===0 && state.payment_state2==='verified') ||
       (state.contracts[setup.seven].paid===0 && state.contracts[setup.eight].paid===10000 && state.payment_state2==='uncertain'),'manual and verified online references cannot both credit');
console.log('PASS: parallel native PHP/MySQL payments cannot over-credit; idempotent retries, link/manual races and duplicate callbacks serialize. No provider network calls.');
