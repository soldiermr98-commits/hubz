// Synthetic HTTP integration test for unified 5 categories across API endpoints,
// collector job creation by category_id, cross-category import candidate detection,
// and 1-click duplicate merge in ReviewQueueController.
import assert from 'node:assert/strict';

process.on('uncaughtException', (err) => {
  console.log(`::error title=unified_categories_dedupe_http_smoke::${String(err?.stack || err).replace(/\r?\n/g, '%0A')}`);
  process.exit(1);
});
process.on('unhandledRejection', (err) => {
  console.log(`::error title=unified_categories_dedupe_http_smoke::${String(err?.stack || err).replace(/\r?\n/g, '%0A')}`);
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
  throw new Error('A guarded loopback fixture server and disposable DB are required.');
}

const guard = await fetch(base + '/__article_fixture_guard__').then((r) => r.json());
assert.equal(guard.nonce, nonce);
assert.match(guard.database, /^hhx_test_[a-z0-9_]+$/);

class Client {
  cookies = new Map();
  csrf = '';
  async request(method, path, data) {
    const headers = {};
    if (this.cookies.size) {
      headers.Cookie = [...this.cookies].map(([k, v]) => `${k}=${v}`).join('; ');
    }
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
    for (const cookie of r.headers.getSetCookie()) {
      const pair = cookie.split(';')[0];
      const i = pair.indexOf('=');
      this.cookies.set(pair.slice(0, i), pair.slice(i + 1));
    }
    const text = await r.text();
    let json = null;
    try {
      json = JSON.parse(text);
    } catch {}
    return { status: r.status, headers: r.headers, text, json };
  }
  async login(role) {
    const me = await this.request('GET', '/api/v1/auth/me');
    this.csrf = me.json.csrf_token;
    const r = await this.request('POST', '/api/v1/auth/login', { username: 'fixture_' + role, password });
    assert.equal(r.status, 200, r.text);
    this.csrf = r.json.csrf_token;
  }
}

const admin = new Client();
await admin.login('admin');

// 1. Verify unified 5 categories between categories and packs in /api/v1/categories
const catRes = await admin.request('GET', '/api/v1/categories');
assert.equal(catRes.status, 200, catRes.text);

const canonicalSlugs = ['cafe-restaurant', 'beauty-salon', 'clinic-dental', 'villa-ecolodge', 'tourism-attraction'];
const canonicalNames = ['کافه و رستوران', 'سالن زیبایی', 'کلینیک و دندانپزشکی', 'ویلا و اقامتگاه', 'جاذبه گردشگری'];

for (let i = 0; i < canonicalSlugs.length; i++) {
  const cat = catRes.json.categories.find((c) => c.slug === canonicalSlugs[i]);
  assert.ok(cat, `Missing category ${canonicalSlugs[i]}`);
  assert.equal(cat.name_fa, canonicalNames[i]);

  const pack = catRes.json.packs.find((p) => Number(p.category_id) === Number(cat.id));
  assert.ok(pack, `Missing unified pack for category ${canonicalSlugs[i]}`);
  assert.equal(pack.name, canonicalNames[i], `Pack name must match canonical category name ${canonicalNames[i]}`);
  assert.equal(pack.category_name_fa, canonicalNames[i]);
}
console.log('PASS: /api/v1/categories exposes identical 5 canonical categories and synced packs');

// 2. Verify collector job creation directly via category_id
const villaCat = catRes.json.categories.find((c) => c.slug === 'villa-ecolodge');
const jobRes = await admin.request('POST', '/api/v1/collector/jobs', {
  city_id: 1,
  category_id: Number(villaCat.id),
  provider: 'osm',
});
assert.equal(jobRes.status, 200, jobRes.text);
const queueRes = await admin.request('GET', '/api/v1/collector/jobs');
assert.equal(queueRes.status, 200, queueRes.text);
const createdJob = queueRes.json.jobs.find((j) => Number(j.id) === Number(jobRes.json.job.id));
assert.ok(createdJob);
assert.equal(createdJob.pack_name, 'ویلا و اقامتگاه');
console.log('PASS: POST /api/v1/collector/jobs accepts category_id and displays unified category name');

// 3. Verify cross-category duplicate candidate detection & 1-click merge in review queue
const suffix = Date.now().toString(36);
const cafeCat = catRes.json.categories.find((c) => c.slug === 'cafe-restaurant');
const firstItem = {
  name: `رستوران ساحلی مرجان ${suffix}`,
  source: 'fixture_gmaps',
  source_id: `gmaps-marjan-${suffix}`,
  city: 'رشت',
  category: 'رستوران',
  address: 'رشت، بلوار معلم، پلاک ۴۰',
  lat: 37.2845,
  lng: 49.5812,
  phones: ['+981333221100'],
  rating: 4.6,
  reviews_count: 18,
  fetched_at: new Date(Date.now() - 3600_000).toISOString(),
};
const apply1 = await admin.request('POST', '/api/v1/import/json/apply', {
  options: {
    city_id: 1,
    category_id: Number(cafeCat.id),
    source_categories: ['رستوران'],
  },
  entries: [{ index: 0, item: firstItem, decision: 'new', candidate_ids: [] }],
});
assert.equal(apply1.status, 200, apply1.text);
const canonBizId = Number(apply1.json.results[0].business_id);
assert.ok(canonBizId > 0);

// Preview an overlapping record in a DIFFERENT category (villa-ecolodge) 25 meters away with core name 'ساحلی مرجان <suffix>'
const secondItem = {
  name: `کافه ساحلی مرجان ${suffix}`,
  source: 'fixture_osm',
  source_id: `osm-marjan-${suffix}`,
  city: 'رشت',
  category: 'اقامتگاه',
  address: 'رشت، بلوار معلم، جنب پلاک ۴۰',
  lat: 37.2847,
  lng: 49.5812,
  phones: ['+989118887766'],
  fetched_at: new Date(Date.now() - 1800_000).toISOString(),
};
const prev2 = await admin.request('POST', '/api/v1/import/json/preview', {
  options: {
    city_id: 1,
    category_id: Number(villaCat.id),
    source_categories: ['اقامتگاه'],
  },
  items: [secondItem],
});
assert.equal(prev2.status, 200, prev2.text);
assert.equal(prev2.json.rows[0].status, 'review', 'Cross-category nearby core-name match must surface as review candidate');
const candIds = prev2.json.rows[0].candidates.map((c) => Number(c.id));
assert.ok(candIds.includes(canonBizId), 'Canonical business in other category must appear in candidates');

// Force-insert secondItem as separate ('new' with explicit candidate_ids) to test 1-click merge in ReviewQueueController
const apply2 = await admin.request('POST', '/api/v1/import/json/apply', {
  options: {
    city_id: 1,
    category_id: Number(villaCat.id),
    source_categories: ['اقامتگاه'],
  },
  entries: [{ index: 0, item: secondItem, decision: 'new', candidate_ids: candIds }],
});
assert.equal(apply2.status, 200, apply2.text);
const dupBizId = Number(apply2.json.results[0].business_id);
assert.ok(dupBizId > 0 && dupBizId !== canonBizId);

// Find the pending review_queue item for dupBizId and execute 1-click merge into canonBizId
const rqRes = await admin.request('GET', `/api/v1/review-queue?status=pending&kind=all&q=${encodeURIComponent(suffix)}`);
assert.equal(rqRes.status, 200, rqRes.text);
const dupRqItem = rqRes.json.items.find((it) => Number(it.target_id) === dupBizId);
assert.ok(dupRqItem, 'Expected pending review_queue item for duplicate business');
assert.equal(dupRqItem.category_name, 'ویلا و اقامتگاه');

const mergeRes = await admin.request('POST', '/api/v1/review-queue/decide', {
  ids: [Number(dupRqItem.id)],
  decision: 'merge',
  merge_into_id: canonBizId,
});
assert.equal(mergeRes.status, 200, mergeRes.text);
assert.equal(mergeRes.json.processed_count, 1);

// Verify duplicate business row is gone and canonical business remains intact in its original category
const bizList = await admin.request('GET', `/api/v1/businesses?page=1&q=${encodeURIComponent(suffix)}`);
assert.equal(bizList.status, 200, bizList.text);
const remainingIds = bizList.json.businesses.map((b) => Number(b.id));
assert.ok(remainingIds.includes(canonBizId), 'Canonical business must remain');
assert.ok(!remainingIds.includes(dupBizId), 'Merged duplicate business must be removed');
console.log('PASS: Cross-category manual import candidate detection & 1-click ReviewQueue merge verified over HTTP');
