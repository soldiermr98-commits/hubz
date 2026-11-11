// jsdom-only browser instrumentation test. No network or production data.
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {JSDOM} from 'jsdom';
const dom=new JSDOM(`<!doctype html><html><body>
<div class="hx-ad-grid hx-ad-grid-2">
  <a href="/" data-hx-banner-id="42"><img id="adimg" src="/img.webp" alt="test"></a>
  <a href="/" data-hx-banner-id="43"><img id="adimg2" src="/img2.webp" alt="test2"></a>
</div>
<a href="/" data-hx-banner-id="bad"><img src="/img.webp" alt="bad"></a>
</body></html>`,{url:'https://example.test/',runScripts:'outside-only',pretendToBeVisual:true});
const w=dom.window;const beacons=[];let observeCb,unobserved=0;
Object.defineProperty(w.navigator,'sendBeacon',{value:(url,body)=>{beacons.push({url,body:JSON.parse(body)});return true;}});
w.IntersectionObserver=class {constructor(cb){observeCb=cb;} observe(){} unobserve(){unobserved++;}};
const banner=w.document.querySelector('[data-hx-banner-id="42"]'),image=w.document.getElementById('adimg');
const banner2=w.document.querySelector('[data-hx-banner-id="43"]'),image2=w.document.getElementById('adimg2');
banner.getBoundingClientRect=()=>({width:100,height:50,top:10,left:10,right:110,bottom:60});
banner2.getBoundingClientRect=()=>({width:100,height:50,top:10,left:120,right:220,bottom:60});
Object.defineProperty(image,'complete',{value:false,configurable:true});Object.defineProperty(image,'naturalWidth',{value:0,configurable:true});
Object.defineProperty(image2,'complete',{value:false,configurable:true});Object.defineProperty(image2,'naturalWidth',{value:0,configurable:true});
w.eval(await readFile('public_html/assets/js/public.js','utf8'));
await new Promise(r=>setTimeout(r,10));assert.equal(typeof observeCb,'function');
observeCb([{target:banner,isIntersecting:true}]);assert.equal(beacons.length,0,'unloaded image is not a view');
Object.defineProperty(image,'complete',{value:true,configurable:true});Object.defineProperty(image,'naturalWidth',{value:800,configurable:true});
image.dispatchEvent(new w.Event('load'));assert.equal(beacons.length,1);assert.deepEqual(beacons[0],{url:'/api/v1/public/banner-event',body:{banner_id:42,event:'view'}});
observeCb([{target:banner,isIntersecting:true}]);assert.equal(beacons.length,1,'view counted once per element');assert.equal(unobserved,1);
Object.defineProperty(image2,'complete',{value:true,configurable:true});Object.defineProperty(image2,'naturalWidth',{value:800,configurable:true});
observeCb([{target:banner2,isIntersecting:true}]);assert.equal(beacons.length,2,'second banner in 2-up grid sends its own view');
assert.deepEqual(beacons[1],{url:'/api/v1/public/banner-event',body:{banner_id:43,event:'view'}});assert.equal(unobserved,2);
banner.dispatchEvent(new w.MouseEvent('click',{bubbles:true,button:0}));assert.equal(beacons.at(-1).body.event,'click');
banner.dispatchEvent(new w.MouseEvent('auxclick',{bubbles:true,button:1}));assert.equal(beacons.at(-1).body.event,'click');assert.equal(beacons.length,4);
banner2.dispatchEvent(new w.MouseEvent('click',{bubbles:true,button:0}));assert.deepEqual(beacons.at(-1),{url:'/api/v1/public/banner-event',body:{banner_id:43,event:'click'}});assert.equal(beacons.length,5);
const invalid=w.document.querySelector('[data-hx-banner-id="bad"]');invalid.dispatchEvent(new w.MouseEvent('click',{bubbles:true}));assert.equal(beacons.length,5,'invalid ID is not sent');
assert(beacons.every(x=>Object.keys(x.body).sort().join(',')==='banner_id,event'),'no identifying information');
dom.window.close();console.log('PASS: banner beacon waits for visible loaded image, sends one view plus clicks, rejects invalid IDs and carries no identifiers.');
