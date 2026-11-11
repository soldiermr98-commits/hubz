// Synthetic jsdom smoke test for unified 5 categories across OSM collector & import
// and 1-click duplicate merge / borderline duplicate handling in ReviewQueueView.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';

const dom = new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>', {
  url: 'https://example.test/admin/',
  pretendToBeVisual: true,
});
Object.assign(globalThis, {
  window: dom.window,
  document: dom.window.document,
  IS_REACT_ACT_ENVIRONMENT: true,
});
Object.defineProperty(globalThis, 'navigator', {
  value: dom.window.navigator,
  configurable: true,
});
dom.window.confirm = () => true;

const { createRoot } = await import('react-dom/client');
const { CitiesAndCategoriesView } = await import('../src/components/CitiesAndCategoriesView');
const { ReviewQueueView } = await import('../src/components/ReviewQueueView');

const cities = [
  { id: 1, name_fa: 'رشت', slug: 'rasht', lat: 37.2808, lng: 49.5832, bbox_json: null, publish_mode: 'manual' as const, is_active: 1 },
  { id: 2, name_fa: 'لاهیجان', slug: 'lahijan', lat: 37.2071, lng: 50.0034, bbox_json: null, publish_mode: 'manual' as const, is_active: 1 },
];

const categories = [
  { id: 1, parent_id: null, name_fa: 'کافه و رستوران', slug: 'cafe-restaurant', sort: 1, is_active: 1 },
  { id: 2, parent_id: null, name_fa: 'سالن زیبایی', slug: 'beauty-salon', sort: 2, is_active: 1 },
  { id: 3, parent_id: null, name_fa: 'کلینیک و دندانپزشکی', slug: 'clinic-dental', sort: 3, is_active: 1 },
  { id: 4, parent_id: null, name_fa: 'ویلا و اقامتگاه', slug: 'villa-ecolodge', sort: 4, is_active: 1 },
  { id: 5, parent_id: null, name_fa: 'جاذبه گردشگری', slug: 'tourism-attraction', sort: 5, is_active: 1 },
];

const packs = [
  {
    id: 1,
    name: 'کافه و رستوران',
    category_id: 1,
    category_name_fa: 'کافه و رستوران',
    category_slug: 'cafe-restaurant',
    queries_json: JSON.stringify({ osm: ['amenity=cafe', 'amenity=restaurant', 'amenity=fast_food', 'amenity=ice_cream'] }),
    is_active: 1,
  },
  {
    id: 2,
    name: 'سالن زیبایی',
    category_id: 2,
    category_name_fa: 'سالن زیبایی',
    category_slug: 'beauty-salon',
    queries_json: JSON.stringify({ osm: ['shop=beauty', 'shop=hairdresser', 'shop=cosmetics'] }),
    is_active: 1,
  },
  {
    id: 3,
    name: 'کلینیک و دندانپزشکی',
    category_id: 3,
    category_name_fa: 'کلینیک و دندانپزشکی',
    category_slug: 'clinic-dental',
    queries_json: JSON.stringify({ osm: ['amenity=dentist', 'amenity=clinic', 'amenity=doctors', 'healthcare=clinic'] }),
    is_active: 1,
  },
  {
    id: 4,
    name: 'ویلا و اقامتگاه',
    category_id: 4,
    category_name_fa: 'ویلا و اقامتگاه',
    category_slug: 'villa-ecolodge',
    queries_json: JSON.stringify({ osm: ['tourism=hotel', 'tourism=guest_house', 'tourism=chalet', 'tourism=apartment'] }),
    is_active: 1,
  },
  {
    id: 5,
    name: 'جاذبه گردشگری',
    category_id: 5,
    category_name_fa: 'جاذبه گردشگری',
    category_slug: 'tourism-attraction',
    queries_json: JSON.stringify({ osm: ['tourism=attraction', 'tourism=museum', 'historic=monument', 'leisure=park'] }),
    is_active: 1,
  },
];

const capturedRequests: Array<{ url: string; method: string; body: any }> = [];

globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
  const url = String(input);
  const method = (init?.method || 'GET').toUpperCase();
  const body = init?.body ? JSON.parse(String(init.body)) : null;
  capturedRequests.push({ url, method, body });

  if (url === '/api/v1/collector/jobs' && method === 'GET') {
    return new Response(
      JSON.stringify({
        ok: true,
        jobs: [],
        providers: [{ provider: 'osm', enabled: 1, priority: 1, fail_streak: 0, paused_until: null, last_error: null }],
        collector_bearer_token: '',
        photo_policy: 'none',
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } }
    );
  }
  if (url === '/api/v1/collector/jobs' && method === 'POST') {
    return new Response(JSON.stringify({ ok: true, job: { id: 77 }, message: 'Job جدید در صف قرار گرفت.' }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  if (url === '/api/v1/category-packs' && method === 'POST') {
    return new Response(JSON.stringify({ ok: true, id: body?.id || 4, message: 'تنظیمات ذخیره شد.' }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  if (url.startsWith('/api/v1/review-queue?')) {
    return new Response(
      JSON.stringify({
        ok: true,
        items: [
          {
            id: 501,
            kind: 'duplicate',
            target_id: 205,
            reason_code: 'potential_duplicate',
            status: 'pending',
            decided_by: null,
            decided_at: null,
            created_at: '2026-11-05 10:00:00',
            business_name: 'کافه سرو گلسار',
            business_slug: 'cafe-sarv-golsar',
            business_address: 'رشت، گلسار، خیابان ۱۲۲',
            business_score: 0.72,
            business_tier: 'B',
            business_confidence: 'medium',
            business_category_id: 1,
            category_name: 'کافه و رستوران',
            city_name: 'رشت',
            payload_json: JSON.stringify({
              provider: 'osm',
              primary_candidate_id: 101,
              candidates: [
                {
                  id: 101,
                  name: 'رستوران سرو گلسار',
                  category_id: 1,
                  category_name: 'کافه و رستوران',
                  distance_meters: 95,
                  shared_phone: false,
                  same_core_name: true,
                },
              ],
            }),
          },
        ],
        change_logs: [],
        pending_counts: { business: 0, change: 0, duplicate: 1, claim: 0, report: 0, article: 0 },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } }
    );
  }
  if (url === '/api/v1/review-queue/decide' && method === 'POST') {
    return new Response(JSON.stringify({ ok: true, processed_count: 1, skipped_count: 0, message: 'اعمال شد.' }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    });
  }
  throw new Error(`Unhandled fetch in smoke test: ${method} ${url}`);
}) as typeof fetch;

const container = document.getElementById('root')!;
const root = createRoot(container);

// 1. Verify unified categories in CitiesAndCategoriesView
await act(async () => {
  root.render(
    <CitiesAndCategoriesView
      cities={cities}
      categories={categories}
      packs={packs}
      csrfToken="csrf-fixture"
      onRefreshData={async () => {}}
    />
  );
});

const html1 = container.innerHTML;
for (const canonicalName of ['کافه و رستوران', 'سالن زیبایی', 'کلینیک و دندانپزشکی', 'ویلا و اقامتگاه', 'جاذبه گردشگری']) {
  assert.ok(html1.includes(canonicalName), `Expected canonical category "${canonicalName}" in collector UI`);
}
for (const staleName of ['بسته کافه‌ها', 'بسته سالن‌های زیبایی', 'بسته کلینیک زیبایی', 'بسته ویلا، هتل']) {
  assert.ok(!html1.includes(staleName), `Unexpected legacy pack label "${staleName}" in collector UI`);
}
assert.ok(html1.includes('amenity=cafe · amenity=restaurant · amenity=fast_food · amenity=ice_cream'));

// Select category 4 (ویلا و اقامتگاه) and submit collector job
const selects = Array.from(container.querySelectorAll('select'));
const categorySelect = selects[1];
await act(async () => {
  categorySelect.value = '4';
  categorySelect.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
});
assert.ok(container.innerHTML.includes('tourism=hotel · tourism=guest_house · tourism=chalet · tourism=apartment'));

const jobSubmitBtn = Array.from(container.querySelectorAll('button')).find((b) =>
  b.textContent?.includes('ثبت Job در صف')
);
assert.ok(jobSubmitBtn, 'Expected job submit button');
await act(async () => {
  jobSubmitBtn!.closest('form')!.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
});

const jobPost = capturedRequests.find((r) => r.url === '/api/v1/collector/jobs' && r.method === 'POST');
assert.ok(jobPost, 'Expected POST /api/v1/collector/jobs');
assert.equal(jobPost!.body.category_id, 4);
assert.equal(jobPost!.body.pack_id, 4);

// Switch to cities_packs sub-tab and verify editing pack 4 preserves category_id=4
const managePacksTabBtn = Array.from(container.querySelectorAll('button')).find((b) =>
  b.textContent?.includes('مدیریت شهرها')
);
assert.ok(managePacksTabBtn);
await act(async () => {
  managePacksTabBtn!.click();
});
const editPackBtns = Array.from(container.querySelectorAll('button')).filter((b) =>
  b.textContent?.includes('ویرایش تگ‌ها')
);
assert.equal(editPackBtns.length, 5);
await act(async () => {
  editPackBtns[3].click(); // category 4 (ویلا و اقامتگاه)
});
const savePackBtn = Array.from(container.querySelectorAll('button')).find((b) =>
  b.textContent?.includes('ذخیره ویرایش تگ‌ها')
);
assert.ok(savePackBtn);
await act(async () => {
  savePackBtn!.closest('form')!.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
});
const packPost = capturedRequests.find((r) => r.url === '/api/v1/category-packs' && r.method === 'POST');
assert.ok(packPost);
assert.equal(packPost!.body.category_id, 4, 'Pack save must send actual category_id, not hardcoded 1');
console.log('PASS: CitiesAndCategoriesView unified 5 categories, OSM tag preview, job creation & pack category_id verified.');

// 2. Verify ReviewQueueView borderline duplicate candidate display & 1-click merge
await act(async () => {
  root.render(<ReviewQueueView csrfToken="csrf-fixture" isAdmin={true} onRefreshParent={async () => {}} />);
});

const rqHtml = container.innerHTML;
assert.ok(rqHtml.includes('احتمال تکراری با کسب‌وکار موجود در شهر'));
assert.ok(rqHtml.includes('دسته: کافه و رستوران'));
assert.ok(rqHtml.includes('رستوران سرو گلسار'));
assert.ok(rqHtml.includes('فاصله: 95 متر'));

const mergeCandidateBtn = Array.from(container.querySelectorAll('button')).find((b) =>
  b.textContent?.includes('ادغام در #101')
);
assert.ok(mergeCandidateBtn, 'Expected 1-click candidate merge button');
await act(async () => {
  mergeCandidateBtn!.click();
});
const mergeReq = capturedRequests.find(
  (r) => r.url === '/api/v1/review-queue/decide' && r.body?.decision === 'merge'
);
assert.ok(mergeReq, 'Expected merge decision request');
assert.deepEqual(mergeReq!.body, { ids: [501], decision: 'merge', merge_into_id: 101 });

const rejectDupBtn = Array.from(container.querySelectorAll('button')).find((b) =>
  b.textContent?.includes('مجزا است (رد هشدار)')
);
assert.ok(rejectDupBtn, 'Expected distinct/reject duplicate warning button');
await act(async () => {
  rejectDupBtn!.click();
});
const rejectReq = capturedRequests.find(
  (r) => r.url === '/api/v1/review-queue/decide' && r.body?.decision === 'rejected'
);
assert.ok(rejectReq);
assert.deepEqual(rejectReq!.body, { ids: [501], decision: 'rejected' });
console.log('PASS: ReviewQueueView duplicate candidate badge, 1-click merge & distinct dismissal verified.');

await act(async () => {
  root.unmount();
});
