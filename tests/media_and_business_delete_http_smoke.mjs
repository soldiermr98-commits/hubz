// Synthetic data ONLY: guarded loopback fixture server and hhx_test_* DB.
// End-to-end HTTP smoke test for WebP Media Library + SEO-safe Business Deletion (410/301).
import assert from 'node:assert/strict';
import zlib from 'node:zlib';

process.on('uncaughtException', (err) => {
  const msg = String(err?.stack || err).replace(/\r?\n/g, '%0A');
  console.log(`::error title=media_http_smoke::${msg}`);
  process.exit(1);
});
process.on('unhandledRejection', (err) => {
  const msg = String(err?.stack || err).replace(/\r?\n/g, '%0A');
  console.log(`::error title=media_http_smoke::${msg}`);
  process.exit(1);
});

const base = process.env.HHX_TEST_URL || '';
const nonce = process.env.HHX_TEST_NONCE || '';
const password = process.env.HHX_TEST_PASSWORD || '';
if (
  process.env.HHX_ALLOW_TEST_FIXTURES !== '1' ||
  !/^http:\/\/(127\.0\.0\.1|localhost):\d+$/.test(base) ||
  nonce.length < 16 ||
  password.length < 12
) {
  throw new Error('Use the guarded loopback fixture server, test database, and synthetic credentials only.');
}

const guard = await fetch(base + '/__article_fixture_guard__').then((r) => r.json());
assert.equal(guard.nonce, nonce);
assert.match(guard.database, /^hhx_test_[a-z0-9_]+$/);

function crc32(buf) {
  let c = 0xffffffff;
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i];
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1));
  }
  return (c ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeAndData = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(typeAndData), 0);
  return Buffer.concat([len, typeAndData, crc]);
}

function makeSolidPng(width = 8, height = 8) {
  const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(width, 0);
  ihdr.writeUInt32BE(height, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // RGBA
  const row = Buffer.alloc(1 + width * 4);
  for (let x = 0; x < width; x++) {
    row[1 + x * 4 + 0] = 30;
    row[1 + x * 4 + 1] = 140;
    row[1 + x * 4 + 2] = 90;
    row[1 + x * 4 + 3] = 255;
  }
  const raw = Buffer.concat(Array.from({ length: height }, () => row));
  const idat = zlib.deflateSync(raw);
  return Buffer.concat([sig, pngChunk('IHDR', ihdr), pngChunk('IDAT', idat), pngChunk('IEND', Buffer.alloc(0))]);
}

class Client {
  cookies = new Map();
  csrf = '';

  cookieHeader() {
    return [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
  }

  captureCookies(r) {
    for (const c of r.headers.getSetCookie()) {
      const pair = c.split(';')[0];
      const i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
    }
  }

  async request(method, path, data) {
    const headers = {};
    if (this.cookies.size) headers.Cookie = this.cookieHeader();
    if (data !== undefined) {
      headers['Content-Type'] = 'application/json';
      headers['X-CSRF-Token'] = this.csrf;
    }
    const r = await fetch(base + path, {
      method,
      headers,
      body: data === undefined ? undefined : JSON.stringify(data),
      redirect: 'manual',
    });
    this.captureCookies(r);
    const text = await r.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {}
    return { status: r.status, headers: r.headers, text, json };
  }

  async uploadPng(altText, pngBuf) {
    const fd = new FormData();
    fd.append('image', new Blob([pngBuf], { type: 'image/png' }), 'synthetic.png');
    fd.append('alt_text', altText);
    const headers = { 'X-CSRF-Token': this.csrf };
    if (this.cookies.size) headers.Cookie = this.cookieHeader();
    const r = await fetch(base + '/api/v1/media/upload', { method: 'POST', headers, body: fd });
    this.captureCookies(r);
    const text = await r.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {}
    return { status: r.status, text, json };
  }

  async login(role) {
    let r = await this.request('GET', '/api/v1/auth/me');
    this.csrf = r.json.csrf_token;
    r = await this.request('POST', '/api/v1/auth/login', { username: 'fixture_' + role, password });
    assert.equal(r.status, 200, r.text);
    this.csrf = r.json.csrf_token;
  }
}

const admin = new Client();
await admin.login('admin');

// 1. Verify BOTH /api/media-library.php (OPcache-fresh physical endpoint) AND GET /api/v1/media return HTTP 200 JSON
let r = await admin.request('GET', '/api/media-library.php?page=1&per_page=24&usage=all&q=');
assert.equal(r.status, 200, `GET /api/media-library.php failed: ${r.text}`);
assert.equal(r.json?.ok, true, r.text);
assert(Array.isArray(r.json.items));
assert.equal(Object.keys(r.json.dimension_guides || {}).length, 3);

r = await admin.request('GET', '/api/v1/media?page=1&per_page=24&usage=all&q=');
assert.equal(r.status, 200, `GET /api/v1/media failed: ${r.text}`);
assert.equal(r.json?.ok, true, r.text);

// 2. Upload PNG -> auto WebP conversion -> verify listed on disk & DB
const pngBuf = makeSolidPng(128, 128);
const up = await admin.uploadPng('کاور آزمایشی حامی‌هاب', pngBuf);
assert.equal(up.status, 200, `Upload failed: ${up.text}`);
assert.equal(up.json?.ok, true, up.text);
const mediaId = up.json.media.id;
const path1200 = up.json.media.file_path_1200;
assert.equal(up.json.media.mime_type, 'image/webp');
assert.match(path1200, /^\/uploads\/.+\.webp$/);

// Verify physical WebP file is served from public_html/uploads
const webpFetch = await fetch(base + path1200);
assert.equal(webpFetch.status, 200);

// Verify media item appears in GET /api/media-library.php search with all_files_present === true
r = await admin.request('GET', `/api/media-library.php?page=1&per_page=24&usage=unused&q=${encodeURIComponent('آزمایشی')}`);
assert.equal(r.status, 200, r.text);
const listed = r.json.items.find((m) => m.id === mediaId);
assert(listed, 'Uploaded WebP item must appear in unused media filter');
assert.equal(listed.all_files_present, true);
assert.equal(listed.all_webp, true);
assert.equal(listed.variants?.['1200']?.exists, true);
assert.match(listed.host_path_1200, /^public_html\/uploads\/.+\.webp$/);

// 3. Update Alt text via OPcache-fresh endpoint
r = await admin.request('POST', '/api/media-library.php?action=update', { id: mediaId, alt_text: 'متن جایگزین جدید' });
assert.equal(r.status, 200, r.text);
assert.equal(r.json?.ok, true);

// 4. Lookup valid city & category from taxonomy for synthetic businesses
const citiesRes = await admin.request('GET', '/api/v1/cities');
assert.equal(citiesRes.status, 200, citiesRes.text);
const cityId = citiesRes.json.cities[0].id;
const citySlug = citiesRes.json.cities[0].slug;
const catsRes = await admin.request('GET', '/api/v1/categories');
assert.equal(catsRes.status, 200, catsRes.text);
const categoryId = catsRes.json.categories[0].id;

const suffix = Date.now().toString(36);
const slug410 = `seo-gone-${suffix}`;
const slug301 = `seo-moved-${suffix}`;
const slugTarget = `seo-target-${suffix}`;

r = await admin.request('POST', '/api/v1/businesses', {
  name: 'کسب‌وکار آزمایشی ۴۱۰',
  slug: slug410,
  city_id: cityId,
  category_id: categoryId,
  cover_media_id: mediaId,
  status: 'published',
});
assert.equal(r.status, 200, r.text);
const id410 = r.json.business.id;

// Deleting media without detach_usages while attached to business must return 409
r = await admin.request('POST', '/api/media-library.php?action=delete', { ids: [mediaId], detach_usages: false });
assert.equal(r.status, 409, r.text);

r = await admin.request('POST', '/api/v1/businesses', {
  name: 'کسب‌وکار آزمایشی ۳۰۱',
  slug: slug301,
  city_id: cityId,
  category_id: categoryId,
  status: 'published',
});
assert.equal(r.status, 200, r.text);
const id301 = r.json.business.id;

r = await admin.request('POST', '/api/v1/businesses', {
  name: 'کسب‌وکار مقصد ۳۰۱',
  slug: slugTarget,
  city_id: cityId,
  category_id: categoryId,
  status: 'published',
});
assert.equal(r.status, 200, r.text);
const idTarget = r.json.business.id;

// 5. SEO-safe delete with 410 Gone (auto_seo on published business)
r = await admin.request('POST', '/api/v1/businesses/delete', {
  ids: [id410],
  seo_mode: 'auto_seo',
});
assert.equal(r.status, 200, r.text);
assert.equal(r.json?.ok, true);

for (const goneUrl of [`${base}/${citySlug}/${slug410}`, `${base}/b/${slug410}`]) {
  const gonePage = await fetch(goneUrl, { redirect: 'manual' });
  assert.equal(gonePage.status, 410, `Deleted business URL ${goneUrl} must return HTTP 410 Gone`);
}

// 6. SEO-safe delete with 301 Redirect to replacement published business
r = await admin.request('POST', '/api/v1/businesses/delete', {
  ids: [id301],
  seo_mode: 'redirect_business',
  redirect_business_id: idTarget,
});
assert.equal(r.status, 200, r.text);
assert.equal(r.json?.ok, true);

for (const movedUrl of [`${base}/${citySlug}/${slug301}`, `${base}/b/${slug301}`]) {
  const movedPage = await fetch(movedUrl, { redirect: 'manual' });
  assert.equal(movedPage.status, 301, `Redirected business URL ${movedUrl} must return HTTP 301`);
  assert.match(movedPage.headers.get('location') || '', new RegExp(`/${citySlug}/${slugTarget}/?$`));
}

// Sitemap must exclude deleted slugs and include live target
const sitemap = await fetch(`${base}/sitemap.xml`).then((res) => res.text());
assert(!sitemap.includes(slug410), '410 business must not remain in sitemap');
assert(!sitemap.includes(slug301), '301 business must not remain in sitemap');
assert(sitemap.includes(`/${citySlug}/${slugTarget}`), 'Live target business must appear in sitemap');

// Clean up target business too
r = await admin.request('POST', '/api/v1/businesses/delete', { ids: [idTarget], seo_mode: 'purge_clean' });
assert.equal(r.status, 200, r.text);

// 7. Delete WebP media item from DB and physical disk via OPcache-fresh endpoint
r = await admin.request('POST', '/api/media-library.php?action=delete', { ids: [mediaId], detach_usages: false });
assert.equal(r.status, 200, r.text);
assert.equal(r.json?.result?.deleted_count, 1);
assert.equal(r.json?.result?.deleted_files_count, 3);

const webpAfter = await fetch(base + path1200);
assert.equal(webpAfter.status, 404, 'Physical WebP file must be removed from public_html/uploads');

console.log('PASS: media library WebP lifecycle and SEO 410/301 business deletion HTTP smoke checks passed.');
