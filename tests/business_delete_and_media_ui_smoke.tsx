// Synthetic jsdom smoke test for bulk/single business deletion with SEO modes
// and the WebP Media Library manager (host paths, usage badges, dimension guides).
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

const { createRoot } = await import('react-dom/client');
const { BusinessesManagerView } = await import('../src/components/BusinessesManagerView');
const { MediaManagerView } = await import('../src/components/MediaManagerView');

const cities = [{ id: 1, name_fa: 'رشت', slug: 'rasht', lat: 37.28, lng: 49.58, bbox_json: null, publish_mode: 'manual' as const, is_active: 1 }];
const categories = [{ id: 2, parent_id: null, name_fa: 'کافه', slug: 'cafe', sort: 1, is_active: 1 }];

let businessesStore = [
  {
    id: 101,
    name: 'کافه تست پیش‌نویس',
    slug: 'test-draft-cafe',
    city_id: 1,
    city_name: 'رشت',
    city_slug: 'rasht',
    neighborhood_id: null,
    category_id: 2,
    category_name: 'کافه',
    address: 'گلسار',
    lat: 37.28,
    lng: 49.58,
    hours: '9-22',
    short_desc: 'توضیح تستی',
    status: 'draft' as const,
    tier: 'B' as const,
    cover_media_id: 56,
    cover_thumb_url: '/uploads/2026/11/draft-480.webp',
    contacts: [],
    warnings: [],
  },
  {
    id: 102,
    name: 'کافه لمیز رشت',
    slug: 'cafe-lamiz-rasht',
    city_id: 1,
    city_name: 'رشت',
    city_slug: 'rasht',
    neighborhood_id: null,
    category_id: 2,
    category_name: 'کافه',
    address: 'بلوار معلم',
    lat: 37.28,
    lng: 49.58,
    hours: '8-23',
    short_desc: 'کافه فعال',
    status: 'published' as const,
    tier: 'A' as const,
    cover_media_id: 55,
    cover_thumb_url: '/uploads/2026/11/lamiz-480.webp',
    contacts: [],
    warnings: [],
  },
];

let mediaStore = [
  {
    id: 55,
    file_path_1200: '/uploads/2026/11/lamiz-1200.webp',
    file_path_800: '/uploads/2026/11/lamiz-800.webp',
    file_path_480: '/uploads/2026/11/lamiz-480.webp',
    url_1200: '/uploads/2026/11/lamiz-1200.webp',
    url_800: '/uploads/2026/11/lamiz-800.webp',
    url_480: '/uploads/2026/11/lamiz-480.webp',
    host_path_1200: 'public_html/uploads/2026/11/lamiz-1200.webp',
    mime_type: 'image/webp',
    width: 1200,
    height: 800,
    alt_text: 'نمای کافه لمیز رشت',
    uploaded_by_name: 'مدیر سیستم',
    created_at: '2026-11-05 10:00:00',
    variants: {
      '1200': { web_path: '/uploads/2026/11/lamiz-1200.webp', host_path: 'public_html/uploads/2026/11/lamiz-1200.webp', exists: true, bytes: 82000, is_webp: true },
      '800': { web_path: '/uploads/2026/11/lamiz-800.webp', host_path: 'public_html/uploads/2026/11/lamiz-800.webp', exists: true, bytes: 46000, is_webp: true },
      '480': { web_path: '/uploads/2026/11/lamiz-480.webp', host_path: 'public_html/uploads/2026/11/lamiz-480.webp', exists: true, bytes: 22000, is_webp: true },
    },
    total_bytes: 150000,
    all_files_present: true,
    all_webp: true,
    usages: [{ type: 'business_cover' as const, label: 'کاور کسب‌وکار', entity_id: 102, title: 'کافه لمیز رشت', status: 'published' }],
    usage_count: 1,
    can_force_detach: true,
  },
  {
    id: 56,
    file_path_1200: '/uploads/2026/11/orphan-test-1200.webp',
    file_path_800: '/uploads/2026/11/orphan-test-800.webp',
    file_path_480: '/uploads/2026/11/orphan-test-480.webp',
    url_1200: '/uploads/2026/11/orphan-test-1200.webp',
    url_800: '/uploads/2026/11/orphan-test-800.webp',
    url_480: '/uploads/2026/11/orphan-test-480.webp',
    host_path_1200: 'public_html/uploads/2026/11/orphan-test-1200.webp',
    mime_type: 'image/webp',
    width: 1200,
    height: 800,
    alt_text: '',
    uploaded_by_name: 'مدیر سیستم',
    created_at: '2026-11-05 10:05:00',
    variants: {
      '1200': { web_path: '/uploads/2026/11/orphan-test-1200.webp', host_path: 'public_html/uploads/2026/11/orphan-test-1200.webp', exists: true, bytes: 60000, is_webp: true },
      '800': { web_path: '/uploads/2026/11/orphan-test-800.webp', host_path: 'public_html/uploads/2026/11/orphan-test-800.webp', exists: true, bytes: 35000, is_webp: true },
      '480': { web_path: '/uploads/2026/11/orphan-test-480.webp', host_path: 'public_html/uploads/2026/11/orphan-test-480.webp', exists: true, bytes: 15000, is_webp: true },
    },
    total_bytes: 110000,
    all_files_present: true,
    all_webp: true,
    usages: [],
    usage_count: 0,
    can_force_detach: true,
  },
];

const json = (d: unknown) =>
  new Response(JSON.stringify(d), {
    status: 200,
    headers: { 'content-type': 'application/json' },
  });

let lastDeletePayload: any = null;
let lastMediaDeletePayload: any = null;
let lastMediaUpdatePayload: any = null;

globalThis.fetch = async (input, options = {}) => {
  const url = String(input);
  if (url.startsWith('/api/v1/businesses?')) {
    return json({
      ok: true,
      businesses: businessesStore,
      total: businessesStore.length,
      page: 1,
      per_page: 50,
      data_review_schema_ready: true,
    });
  }
  if (url === '/api/v1/businesses/delete') {
    assert.equal(options.method, 'POST');
    assert.equal(new Headers(options.headers).get('X-CSRF-Token'), 'csrf-test-token');
    lastDeletePayload = JSON.parse(String(options.body));
    const delSet = new Set<number>(lastDeletePayload.ids);
    businessesStore = businessesStore.filter((b) => !delSet.has(b.id));
    return json({
      ok: true,
      deleted_ids: lastDeletePayload.ids,
      deleted_count: lastDeletePayload.ids.length,
      message: '۲ کسب‌وکار با موفقیت حذف شد. برای ۱ کسب‌وکار منتشرشده قانون سئوی 410 Gone ثبت شد.',
    });
  }
  if (url.startsWith('/api/media-library.php?') && (!options.method || options.method === 'GET')) {
    return json({
      ok: true,
      items: mediaStore,
      pagination: { page: 1, per_page: 24, total: mediaStore.length, total_pages: 1 },
      summary: {
        total_db_records: mediaStore.length,
        used_db_records: mediaStore.filter((m) => m.usage_count > 0).length,
        unused_db_records: mediaStore.filter((m) => m.usage_count === 0).length,
        missing_files_records: 0,
        non_webp_db_records: 0,
        uploads_host_root: 'public_html/uploads',
        disk_webp_files: mediaStore.length * 3,
        disk_non_webp_files: 0,
        disk_orphan_files: 0,
        disk_total_bytes: 260000,
      },
    });
  }
  if (url === '/api/media-library.php?action=delete') {
    assert.equal(options.method, 'POST');
    assert.equal(new Headers(options.headers).get('X-CSRF-Token'), 'csrf-test-token');
    lastMediaDeletePayload = JSON.parse(String(options.body));
    const delSet = new Set<number>(lastMediaDeletePayload.ids || []);
    mediaStore = mediaStore.filter((m) => !delSet.has(m.id));
    return json({
      ok: true,
      result: { deleted_ids: [...delSet], deleted_count: delSet.size, deleted_files_count: delSet.size * 3, freed_bytes: 110000, blocked: [] },
      message: '۱ رکورد تصویر از دیتابیس و ۳ فایل WebP از هاست حذف شد.',
    });
  }
  if (url === '/api/media-library.php?action=update') {
    assert.equal(options.method, 'POST');
    lastMediaUpdatePayload = JSON.parse(String(options.body));
    return json({ ok: true, message: 'متن جایگزین (Alt) تصویر ذخیره شد.' });
  }
  throw new Error('Unexpected URL in smoke test: ' + url);
};

const wait = (ms = 260) => new Promise((r) => setTimeout(r, ms));
const root = createRoot(document.getElementById('root')!);
dom.window.confirm = () => true;

try {
  // 1. Editor (non-admin) must NOT see business delete controls
  await act(async () => {
    root.render(
      <BusinessesManagerView
        cities={cities}
        neighborhoods={[]}
        categories={categories}
        csrfToken="csrf-test-token"
        onRefreshData={async () => {}}
        isAdmin={false}
      />
    );
    await wait();
  });
  assert(!document.body.textContent?.includes('انتخاب پیش‌نویس‌های این صفحه'));

  // 2. Admin multi-select + SEO-safe deletion modal + cover dimension guide
  await act(async () => {
    root.render(
      <BusinessesManagerView
        cities={cities}
        neighborhoods={[]}
        categories={categories}
        csrfToken="csrf-test-token"
        onRefreshData={async () => {}}
        isAdmin={true}
      />
    );
    await wait();
  });
  assert(document.body.textContent?.includes('کافه تست پیش‌نویس'));
  assert(document.body.textContent?.includes('کافه لمیز رشت'));

  const selectAllBtn = [...document.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('انتخاب / لغو همه این صفحه')
  );
  assert(selectAllBtn, 'Select all button must exist for admin');
  await act(async () => {
    selectAllBtn.click();
    await wait(30);
  });

  const bulkDeleteBtn = [...document.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('حذف انتخاب‌شده‌ها')
  );
  assert(bulkDeleteBtn, 'Bulk delete button must appear when rows are selected');
  await act(async () => {
    bulkDeleteBtn.click();
    await wait(30);
  });

  const dialog = document.querySelector('[role="dialog"]');
  assert(dialog, 'SEO delete dialog must open');
  assert(dialog.textContent?.includes('پیش‌نویس / منتشرنشده: ۱ مورد'));
  assert(dialog.textContent?.includes('منتشرشده در سایت عمومی: ۱ مورد'));
  assert(dialog.textContent?.includes('HTTP 410 (Gone)'));

  const confirmDeleteBtn = [...dialog.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('تأیید و حذف نهایی')
  );
  assert(confirmDeleteBtn);
  await act(async () => {
    confirmDeleteBtn.click();
    await wait();
  });

  assert.deepEqual(lastDeletePayload, {
    ids: [101, 102],
    seo_mode: 'auto_seo',
    delete_unused_media: true,
  });

  // Check business cover dimension guide in New Business form
  const newBizBtn = [...document.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('افزودن کسب‌وکار جدید')
  );
  assert(newBizBtn);
  await act(async () => {
    newBizBtn.click();
    await wait(30);
  });
  assert(document.body.textContent?.includes('1200 × 800'));
  console.log('PASS: BusinessesManagerView single/bulk SEO deletion & cover dimension guide verified.');

  // 3. MediaManagerView host paths, usage badges, dimension guides, and unused WebP deletion
  await act(async () => {
    root.render(<MediaManagerView csrfToken="csrf-test-token" isAdmin={true} />);
  });
  await act(async () => {
    await wait(220);
  });
  await act(async () => {
    await wait(20);
  });

  const mediaText = document.body.textContent || '';
  assert(mediaText.includes('public_html/uploads/2026/11/lamiz-1200.webp'));
  assert(mediaText.includes('کاور کسب‌وکار: کافه لمیز رشت'));
  assert(mediaText.includes('بلااستفاده در سایت (قابل حذف امن)'));
  assert(mediaText.includes('1200 × 800'));
  assert(mediaText.includes('1200 × 630'));
  assert(mediaText.includes('1200 × 250'));

  const selectUnusedBtn = [...document.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('انتخاب فقط بلااستفاده‌های این صفحه')
  );
  assert(selectUnusedBtn);
  await act(async () => {
    selectUnusedBtn.click();
    await wait(30);
  });

  const deleteSelectedMediaBtn = [...document.querySelectorAll('button')].find((b) =>
    b.textContent?.includes('حذف ۱ تصویر از دیتابیس و هاست')
  );
  assert(deleteSelectedMediaBtn);
  await act(async () => {
    deleteSelectedMediaBtn.click();
    await wait();
  });

  assert.deepEqual(lastMediaDeletePayload, {
    ids: [56],
    detach_usages: false,
  });

  // Edit alt_text on remaining image #55
  const editAltBtn = [...document.querySelectorAll('button')].find((b) => b.textContent?.trim() === 'ویرایش');
  assert(editAltBtn);
  await act(async () => {
    editAltBtn.click();
    await wait(30);
  });
  const saveAltBtn = [...document.querySelectorAll('button')].find((b) => b.textContent?.includes('ذخیره'));
  assert(saveAltBtn);
  await act(async () => {
    saveAltBtn.click();
    await wait();
  });
  assert.equal(lastMediaUpdatePayload?.id, 55);
  console.log('PASS: MediaManagerView host paths, DB usages, dimension guides, and WebP deletion verified.');
} finally {
  await act(async () => root.unmount());
  dom.window.close();
}
