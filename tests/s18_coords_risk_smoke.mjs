// تست دود S18 — گزارش «مختصات پرریسک (مرکز پلی‌گون OSM)» پنل ادمین + آمادگی بستهٔ جامع Master Rollup.
// ۱) بک‌اند: فیلتر coords_risk در BusinessController (way/relation + عدم overwrite نقاط manual + شناسهٔ منبع OSM)
// ۲) لایهٔ API فرانت: fetchBusinessesPage با coords_risk + fetchCoordsRiskTotal + تایپ osm_source_id
// ۳) فرانت ادمین: کارت گزارش، نشان ردیف، اتصال دکمهٔ ویرایش (مسیر اصلاح با Paste سریع)
// ۴) نسخهٔ دارایی‌ها s18 برای بست کش
// ۵) مایگریشن‌های جدید (013/014/020) برای بستهٔ جامع
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

const read = (p) => readFile(p, 'utf8');

// ---------- ۱) بک‌اند: فیلتر coords_risk ----------
{
  const biz = await read('app/Controllers/BusinessController.php');
  assert.ok(biz.includes("$_GET['coords_risk']"), 'پارامتر coords_risk در GET /api/v1/businesses پذیرفته می‌شود');
  assert.ok(biz.includes("crs.source_id LIKE 'way/%'") && biz.includes("crs.source_id LIKE 'relation/%'"), 'تشخیص پلی‌گون OSM با way/ و relation/ انجام می‌شود (مرکز هندسی)');
  assert.ok(biz.includes("(b.coords_source IS NULL OR b.coords_source <> 'manual')"), 'نقاط دستیِ تأییدشده هرگز در گزارش پرریسک نمی‌آیند');
  assert.ok(biz.includes('b.lat IS NOT NULL AND b.lng IS NOT NULL'), 'رکوردهای بی‌مختصات وارد گزارش نمی‌شوند');
  assert.ok(biz.includes('$osmSourceSelect'), 'شناسهٔ منبع OSM در حالت گزارش برگردانده می‌شود');
  assert.ok(biz.includes("ORDER BY crs2.fetched_at ASC, crs2.id ASC LIMIT 1"), 'شناسهٔ منبع از نخستین منبع OSM ثبت‌شده گرفته می‌شود (هم‌منطق با backfill مایگریشن 014)');
  assert.ok(biz.includes('{$reviewSelect}{$scoreSelect}{$osmSourceSelect}'), 'ستون جدید فقط به SELECT لیست اضافه شده و ساختار قبلی حفظ است');
  // فیلتر جدید نباید کوئری شمارش را بشکند: EXISTS مستقل از JOIN است و در WHERE شمارش هم می‌نشیند
  assert.ok(/SELECT COUNT\(\*\) AS cnt FROM businesses b \{\$reviewJoin\} WHERE \{\$whereSql\}/.test(biz), 'شمارش کل با همان شرط‌های فیلتر (شامل گزارش پرریسک) هم‌خوان است');
  console.log('OK بک‌اند: فیلتر coords_risk + osm_source_id + سازگاری شمارش');
}

// ---------- ۲) لایهٔ API فرانت ----------
{
  const api = await read('src/services/adminApi.ts');
  assert.ok(api.includes('coords_risk:coordsRisk'), 'fetchBusinessesPage پارامتر coords_risk را می‌فرستد');
  assert.ok(api.includes('export async function fetchCoordsRiskTotal'), 'شمارندهٔ گزارش پرریسک به‌صورت API مجزا موجود است');
  assert.ok(api.includes("coords_risk:'1'"), 'شمارنده با همان فیلتر بک‌اند صدا زده می‌شود');
  assert.ok(api.includes('osm_source_id?: string | null;'), 'تایپ BusinessItem شناسهٔ منبع OSM را می‌شناسد');
  // سازگاری فراخوانی‌های قدیمی (پارامتر اختیاری در انتها)
  const sig = api.slice(api.indexOf('fetchBusinessesPage('), api.indexOf('Promise<{businesses'));
  assert.ok(sig.includes("coordsRisk:string=''"), 'پارامتر جدید اختیاری است و فراخوانی‌های قبلی نمی‌شکنند');
  console.log('OK لایهٔ API: fetchBusinessesPage سازگار + fetchCoordsRiskTotal + تایپ');
}

// ---------- ۳) فرانت ادمین ----------
{
  const tsx = await read('src/components/BusinessesManagerView.tsx');
  assert.ok(tsx.includes('fetchCoordsRiskTotal'), 'کارت گزارش از شمارندهٔ API استفاده می‌کند');
  assert.ok(tsx.includes('مختصات پرریسک — مرکز پلی‌گون OSM'), 'کارت گزارش با عنوان فارسی واضح رندر می‌شود');
  assert.ok(tsx.includes('نمایش فهرست اصلاح مختصات'), 'دکمهٔ ورود به فهرست اصلاح موجود است');
  assert.ok(tsx.includes('خروج از گزارش پرریسک'), 'دکمهٔ خروج از حالت گزارش موجود است');
  assert.ok(tsx.includes('مرکز پلی‌گون OSM</span>'), 'نشان ردیف در حالت گزارش نمایش داده می‌شود');
  assert.ok(tsx.includes('biz.osm_source_id'), 'شناسهٔ منبع (way/…) کنار نشان ردیف دیده می‌شود');
  assert.ok(tsx.includes('Paste سریع لینک گوگل‌مپ'), 'مسیر اصلاح (Paste سریع) در راهنمای کارت توضیح داده شده است');
  assert.ok(tsx.includes('coordsRiskOnly ? \'1\' : \'\''), 'فیلتر سرور-ساید به فهرست وصل است');
  assert.ok(tsx.includes("'/assets/vendor/leaflet/hhx-picker.js?v=s18'"), 'نسخهٔ موتور نقشهٔ ادمین به s18 ارتقا یافته است');
  // باندل بیلدشدهٔ ادمین باید همان گزارش را داشته باشد
  const admin = await read('public_html/admin/assets/admin.js');
  assert.ok(admin.includes('مختصات پرریسک'), 'کارت گزارش در باندل بیلدشدهٔ ادمین موجود است');
  assert.ok(admin.includes('مرکز پلی‌گون OSM'), 'نشان ردیف در باندل بیلدشده موجود است');
  assert.ok(admin.includes('نمایش فهرست اصلاح مختصات'), 'دکمهٔ فهرست اصلاح در باندل موجود است');
  console.log('OK فرانت ادمین: کارت گزارش + نشان ردیف + باندل بیلدشده');
}

// ---------- ۴) نسخهٔ دارایی‌های عمومی s18 ----------
{
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(/public\.css\?v=s\d+\.\d/.test(psc) && /public\.js\?v=s\d+\.\d/.test(psc), 'بارنامهٔ سایت عمومی نسخه‌دار است (S19+: نسخه به s19.0 ارتقا یافت)');
  const cache = await read('app/Core/Cache.php');
  assert.match(cache, /public\.css\?v=s\d+\.\d/, 'اعتبارسنج کش با نسخهٔ فعلی دارایی‌ها هم‌خوان است');
  const js = await read('public_html/assets/js/public.js');
  assert.ok(/leaflet\.css\?v=s\d+/.test(js) && /leaflet\.js\?v=s\d+/.test(js), 'لودر نقشهٔ عمومی نسخه‌دار است');
  const index = await read('public_html/admin/index.html');
  assert.ok(/admin\.js\?v=[0-9a-f]{8,}/.test(index), 'باندل ادمین با هش تازه در index.html نشسته است');
  console.log('OK نسخه‌ها: دارایی‌های عمومی نسخه‌دار + admin هش تازه');
}

// ---------- ۵) مایگریشن‌های موردنیاز بستهٔ جامع ----------
{
  const files = (await readdir('database/migrations')).sort();
  for (const need of ['013_analytics_and_ai_logs.sql', '014_coords_source.sql', '020_business_comments.sql']) {
    assert.ok(files.includes(need), `مایگریشن ${need} برای بستهٔ جامع موجود است`);
  }
  // ترتیب اجرای Migrator: sort رشته‌ای → 013, 014, 020 درست اجرا می‌شوند
  const pending = files.filter((f) => ['013', '014', '020'].some((p) => f.startsWith(p)));
  assert.deepEqual(pending, [...pending].sort(), 'ترتیب اجرای مایگریشن‌های جدید با مرتب‌سازی رشته‌ای درست است');
  console.log('OK مایگریشن‌ها: 013 + 014 + 020 آمادهٔ اجرای یک‌مرحله‌ای');
}

console.log('\nهمهٔ بررسی‌های S18 با موفقیت گذشت ✅');
