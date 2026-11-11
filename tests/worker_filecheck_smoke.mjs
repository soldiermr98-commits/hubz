// Tests the standalone offline verifier against real ZIP/source bytes in memory.
// No network, browser server, live config or site files are used.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { basename, resolve } from 'node:path';
import { execFileSync } from 'node:child_process';
import { webcrypto } from 'node:crypto';
import vm from 'node:vm';

const root = process.cwd();
const release = resolve(root, process.env.HHX_TEST_CANDIDATE_DIR || 'release-files/after-rc2-worker-cache');
const html = readFileSync(resolve(release, 'CHECK-FILES-OFFLINE.html'), 'utf8');
assert.match(html, /connect-src 'none'/);
assert(!/<(?:iframe|img|link|form)\b/i.test(html), 'no remote resources or form uploads');
assert(!/\b(?:fetch|XMLHttpRequest|WebSocket|sendBeacon)\s*\(/.test(html), 'no network APIs');
const scripts = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.equal(scripts.length, 1);

class Element {
  constructor(value='') { this.value=value; this.children=[]; this.className=''; this.listeners={}; this._text=''; }
  set textContent(text) { this._text=String(text); this.children=[]; }
  get textContent() { return this._text; }
  appendChild(child) { this.children.push(child); }
  addEventListener(event, handler) { this.listeners[event]=handler; }
}
const elements = Object.fromEntries(['phase','files','reset','message','zip','warning','rows'].map(x=>[x,new Element()]));
elements.phase.value='baseline';
const document = {getElementById:id=>elements[id], createElement:()=>new Element()};
const ctx=vm.createContext({document,crypto:webcrypto,Uint8Array,Map,Array,Object});
vm.runInContext(scripts[0][1] + '\nglobalThis.fixture = {expected, seen, readFiles, currentState, render};', ctx);
const f=ctx.fixture;
const file=(name, bytes)=>({name:basename(name),size:bytes.length,arrayBuffer:async()=>Uint8Array.from(bytes).buffer});
const git=(rev, path)=>execFileSync('git',['show',`${rev}:${path}`],{cwd:root});
const base='17be5bb6d0f5d75c7d2aee159ac00b573f101ef9';
const target='b34c09a3b35d9f55286a4357f1b56d700c37d6ce';
const zip=file(f.expected.zipName,readFileSync(resolve(release,f.expected.zipName)));

let readPrivate=false;
await f.readFiles([{name:'config.local.php',size:10,arrayBuffer:async()=>{readPrivate=true;throw Error('must never be read');}}]);
assert.equal(readPrivate,false,'unrecognized private filenames must not even be opened');
assert.equal(f.currentState().missing,13);
await f.readFiles([zip,...Object.keys(f.expected.baseline).map(path=>file(path,git(base,path)))]);
let state=f.currentState();
assert.equal(state.matching,13);assert.equal(state.missing,0);assert.equal(state.different,0);assert.equal(state.zip,'match');
await f.readFiles([file('Cache.php',Buffer.from('synthetic corrupt file'))]);
state=f.currentState();assert.equal(state.different,1);assert.match(elements.message.textContent,/توقف/);
await f.readFiles([file('app/Core/Cache.php',git(base,'app/Core/Cache.php'))]);
await f.readFiles([file('WorkerLock.php',git(target,'app/Core/WorkerLock.php'))]);
assert.equal(f.currentState().newFileSeen,true);assert.match(elements.warning.textContent,/توقف/);
let oversizedRead=false;
await f.readFiles([{name:f.expected.zipName,size:11*1024*1024,arrayBuffer:async()=>{oversizedRead=true;return new ArrayBuffer(0);}}]);
assert.equal(oversizedRead,false);assert.equal(f.currentState().zip,'different');

f.seen.clear();elements.phase.value='target';f.render();
await f.readFiles([zip,...Object.keys(f.expected.target).map(path=>file(path,git(target,path)))]);
state=f.currentState();assert.equal(state.matching,14);assert.equal(state.missing,0);assert.equal(state.different,0);assert.equal(state.zip,'match');
assert.match(elements.message.textContent,/بررسی‌های دستی/);
console.log('PASS: offline checker validates both phases and ZIP, rejects corrupt/oversized data and ignores private filenames.');
