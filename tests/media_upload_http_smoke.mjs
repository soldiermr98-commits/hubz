// Real GD upload checks against an isolated fixture server and disposable DB.
// Never use a real installation. This creates synthetic media files/rows and
// optionally exercises test-only GD fault injection, without provider network.
import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {readFile, readdir, realpath} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import path from 'node:path';

const base = process.env.HHX_TEST_URL || '';
const nonce = process.env.HHX_TEST_NONCE || '';
const password = process.env.HHX_TEST_PASSWORD || '';
const stageInput = process.env.HHX_TEST_STAGE_ROOT || '';
const fault = process.env.HHX_TEST_MEDIA_FAULT || '';
if (process.env.HHX_ALLOW_TEST_FIXTURES !== '1' || !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base) ||
    nonce.length < 16 || password.length < 12 || !path.isAbsolute(stageInput) ||
    !['', 'no_gd', 'no_webp', 'second_write', 'truncated_success', 'db_insert'].includes(fault)) throw new Error('Explicit loopback and synthetic fixture guards required.');
const stage = await realpath(stageInput);
const temp = await realpath(tmpdir());
if (!stage.startsWith(temp + path.sep) || (await readFile(path.join(stage, 'storage/installed.lock'), 'utf8')) !== 'synthetic-installed-lock\n') {
  throw new Error('The stage must be a temporary synthetic overlay fixture, not a real installation.');
}
const uploadDir = await realpath(path.join(stage, 'public_html/uploads'));
if (!uploadDir.startsWith(stage + path.sep)) throw new Error('The uploads directory must be inside the synthetic stage.');
const guard = await fetch(base + '/__article_fixture_guard__').then(r => r.json());
assert.equal(guard.nonce, nonce); assert.match(guard.database, /^hhx_test_[a-z0-9_]+$/);
assert.equal(guard.test_root_digest, createHash('sha256').update(stage + ':' + nonce).digest('hex'), 'server must use this exact synthetic stage');
const config = await realpath(path.join(stage, 'config/config.local.php'));
assert.equal(guard.test_config_digest, createHash('sha256').update(config + ':' + nonce).digest('hex'), 'server must use this exact synthetic configuration');

class Client {
  cookies = new Map();
  csrf = '';
  async request(method, url, data, csrf = true) {
    const headers = {};
    if (this.cookies.size) headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    let body;
    if (data !== undefined) {
      if (csrf) headers['X-CSRF-Token'] = this.csrf;
      if (data instanceof FormData) body = data;
      else { headers['Content-Type'] = 'application/json'; body = JSON.stringify(data); }
    }
    const r = await fetch(base + url, {method, headers, body, redirect: 'manual'});
    for (const cookie of r.headers.getSetCookie()) {
      const pair = cookie.split(';')[0], i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
    }
    const text = await r.text(); let json;
    try { json = JSON.parse(text); } catch { throw new Error(`HTTP ${r.status}: response is not JSON`); }
    return {status: r.status, json};
  }
  async session() {
    const r = await this.request('GET', '/api/v1/auth/me');
    this.csrf = r.json.csrf_token; assert(this.csrf);
  }
  async login(role) {
    await this.session();
    const r = await this.request('POST', '/api/v1/auth/login', {username: 'fixture_' + role, password});
    assert.equal(r.status, 200); this.csrf = r.json.csrf_token;
  }
  async upload(bytes, name = 'fixture.png', type = 'image/png', alt = 'تصویر آزمایشی', csrf = true) {
    const data = new FormData();
    if (bytes !== null) data.set('image', new Blob([bytes], {type}), name);
    if (Array.isArray(alt)) for (const value of alt) data.append('alt_text[]', value);
    else data.set('alt_text', alt);
    return this.request('POST', '/api/v1/media/upload', data, csrf);
  }
}

async function fileSet(dir = uploadDir, relative = '') {
  const result = [];
  for (const entry of await readdir(dir, {withFileTypes: true})) {
    const rel = path.join(relative, entry.name);
    if (entry.isDirectory()) result.push(...await fileSet(path.join(dir, entry.name), rel));
    else if (entry.isFile() && /^img_[a-f0-9]{16}_(480|800|1200)\.webp$/.test(entry.name)) result.push(rel);
  }
  return result.sort();
}
function dimensions(bytes) {
  assert.equal(bytes.toString('ascii', 0, 4), 'RIFF'); assert.equal(bytes.toString('ascii', 8, 12), 'WEBP');
  const chunk = bytes.toString('ascii', 12, 16);
  if (chunk === 'VP8X') return [bytes.readUIntLE(24, 3) + 1, bytes.readUIntLE(27, 3) + 1];
  if (chunk === 'VP8L') {
    assert.equal(bytes[20], 0x2f);
    return [1 + bytes[21] + ((bytes[22] & 0x3f) << 8), 1 + (bytes[22] >> 6) + (bytes[23] << 2) + ((bytes[24] & 0x0f) << 10)];
  }
  assert.equal(chunk, 'VP8 '); assert.equal(bytes.subarray(23, 26).toString('hex'), '9d012a');
  return [bytes.readUInt16LE(26) & 0x3fff, bytes.readUInt16LE(28) & 0x3fff];
}
async function verifyMedia(r, originalWidth, originalHeight) {
  assert.equal(r.status, 200, JSON.stringify(r.json)); assert.equal(r.json.ok, true);
  assert.equal(r.json.media.mime_type, 'image/webp');
  assert.equal(Number(r.json.media.width), Math.min(originalWidth, 800));
  const tokenPaths = [];
  for (const width of [480, 800, 1200]) {
    const rel = r.json.media[`file_path_${width}`];
    assert.match(rel, new RegExp(`^/uploads/\\d{4}/\\d{2}/img_[a-f0-9]{16}_${width}\\.webp$`));
    const absolute = await realpath(path.join(stage, 'public_html', rel.slice(1)));
    assert(absolute.startsWith(uploadDir + path.sep));
    const bytes = await readFile(absolute); assert(bytes.length > 20);
    const outWidth = Math.min(originalWidth, width);
    assert.deepEqual(dimensions(bytes), [outWidth, Math.max(1, Math.round(originalHeight * outWidth / originalWidth))]);
    tokenPaths.push(absolute);
  }
  assert.equal(new Set(tokenPaths).size, 3);
}
function withGiantPngHeader(source) {
  const bytes = Buffer.from(source);
  assert.equal(bytes.toString('ascii', 12, 16), 'IHDR');
  bytes.writeUInt32BE(20000, 16); bytes.writeUInt32BE(20000, 20);
  // Repair the IHDR CRC; IDAT remains the original small image. A safe server
  // must reject the declared dimensions before asking GD to decode it.
  let crc = 0xffffffff;
  for (const value of bytes.subarray(12, 29)) {
    crc ^= value;
    for (let i = 0; i < 8; i++) crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
  }
  bytes.writeUInt32BE((crc ^ 0xffffffff) >>> 0, 29);
  return bytes;
}
const png = await readFile(path.join(stage, 'public_html/assets/img/logo-hx-dark.png'));
const webp = await readFile(path.join(stage, 'public_html/assets/img/logo-hx-card.webp'));
const jpeg = Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBQYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCACAAIADASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAj/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFgEBAQEAAAAAAAAAAAAAAAAAAAUI/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAwDAQACEQMRAD8AnsBpFHAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAf//Z', 'base64');
const tiny = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAABGdBTUEAALGPC/xhBQAAACBjSFJNAAB6JgAAgIQAAPoAAACA6AAAdTAAAOpgAAA6mAAAF3CculE8AAAABlBMVEX/AAD///9BHTQRAAAAAWJLR0QB/wIt3gAAAAd0SU1FB+oLCBIYFE4MsIAAAAAKSURBVAjXY2AAAAACAAHiIbwzAAAAJXRFWHRkYXRlOmNyZWF0ZQAyMDI2LTExLTA4VDE4OjI0OjIwKzAwOjAwa1U0/gAAACV0RVh0ZGF0ZTptb2RpZnkAMjAyNi0xMS0wOFQxODoyNDoyMCswMDowMBoIjEIAAAAASUVORK5CYII=', 'base64');
const admin = new Client(); await admin.login('admin');
if (fault) {
  const before = await fileSet();
  const result = await admin.upload(png);
  assert.equal(result.status, 422, `fault ${fault} must not report success`);
  assert.equal(result.json.ok, false);
  const expected = {no_gd:'افزونه GD روی سرور فعال نیست', no_webp:'پشتیبانی WebP', second_write:'ذخیره تصویر WebP کامل نشد',
    truncated_success:'فایل WebP تولیدشده معتبر نیست', db_insert:'امکان ذخیره تصویر وجود ندارد؛ وضعیت سرور و پایگاه داده را بررسی کنید.'};
  assert(String(result.json.error).includes(expected[fault]), `wrong error for synthetic ${fault}`);
  assert(!JSON.stringify(result.json).includes('SQLSTATE') && !JSON.stringify(result.json).includes('synthetic database insertion failure'), 'internal database error must not be exposed');
  assert.deepEqual(await fileSet(), before, `fault ${fault} must clean partial outputs`);
  console.log(`PASS: synthetic ${fault} fails closed and leaves no media files.`);
  process.exit(0);
}
const editor = new Client(), guest = new Client(); await editor.login('editor'); await guest.session();
assert.equal((await guest.upload(png)).status, 401);
assert.equal((await admin.upload(png, 'test.png', 'image/png', 'test', false)).status, 403);
await verifyMedia(await admin.upload(png), 1254, 1254);
await verifyMedia(await editor.upload(jpeg, 'fixture.jpg', 'image/jpeg'), 128, 128);
await verifyMedia(await admin.upload(webp, 'fixture.webp', 'image/webp'), 480, 480);
const beforeInvalid = await fileSet();
for (const [data, name, type, alt] of [
  [null, 'none.png', 'image/png', 'test'],
  [Buffer.from('not-an-image'), 'fake.png', 'image/png', 'test'],
  [tiny, 'small.png', 'image/png', 'test'],
  [Buffer.alloc(6 * 1024 * 1024, 1), 'huge.png', 'image/png', 'test'],
  [png, 'alt.png', 'image/png', 'a'.repeat(256)],
  [png, 'alt-array.png', 'image/png', ['test']],
  [withGiantPngHeader(png), 'oversized-dimensions.png', 'image/png', 'test'],
]) {
  const result = await admin.upload(data, name, type, alt);
  assert.equal(result.status, 422, `invalid ${name} must fail`);
  assert.equal(result.json.ok, false);
  assert(!JSON.stringify(result.json).includes('SQLSTATE'), 'database details must not be exposed');
  assert.deepEqual(await fileSet(), beforeInvalid, `invalid ${name} must not leave generated files`);
}
console.log('PASS: GD processes PNG/JPEG/WebP into verified WebP sizes; roles, CSRF, bad files and resource limits fail safely.');
console.log('NOTE: synthetic local fixture only; no claim about the real host or provider network.');
