// تست دود S17 — بدون شبکه و بدون PHP؛ اعتبارسنجی استاتیک + اجرای واقعی پارسر مختصات.
// ۱) منطق helper مسیریابی: دکمهٔ اصلی گوگل با مختصات (و destination_place_id فقط وقتی Place ID هست)
// ۲) لینک‌های نشان/بلد/OSM در MapLinks و popup نقشه + اتصال همهٔ دکمه‌ها به بیکن آمار
// ۳) پارسر Paste سریع: مختصات خام، لینک @lat,lng، لینک !3d/!4d، ارقام فارسی، رد کردن ورودی نامعتبر
// ۴) قفل مختصات دستی در PipelineService / ReviewQueueController / BusinessController
// ۵) مایگریشن 014 بدون تداخل شماره با مایگریشن‌های موجود
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

const read = (p) => readFile(p, 'utf8');

// ---------- ۱) MapLinks: URL سازها ----------
{
  const src = await read('app/Services/MapLinks.php');
  assert.ok(src.includes('&destination='), 'URL اصلی مسیریابی گوگل با destination مختصات ساخته می‌شود');
  assert.ok(src.includes('&destination_place_id='), 'وقتی Place ID موجود باشد destination_place_id اضافه می‌شود');
  assert.ok(src.includes('https://nshn.ir/?lat='), 'لینک نشان دقیقاً با قالب nshn.ir/?lat=..&lng=.. ساخته می‌شود');
  assert.ok(src.includes('https://balad.ir/location?latitude='), 'لینک بلد با قالب balad.ir/location ساخته می‌شود');
  assert.ok(src.includes('https://www.openstreetmap.org/?mlat='), 'لینک OpenStreetMap حفظ شده است');
  assert.ok(src.includes('maps/search/?api=1&query='), 'جستجوی متنی فقط به‌عنوان لینک کمکی در سرویس وجود دارد');
  assert.ok(/destination_place_id.*placeId.*!==.*''|&destination_place_id=' \. rawurlencode/.test(src) || src.includes("rawurlencode($placeId)"), 'Place ID قبل از الحاق encode می‌شود');
  // اهمیت امنیتی: coord باید با %.7F (بدون وابستگی به locale) فرمت شود
  assert.ok(src.includes("sprintf('%.7F'"), 'مختصات با %.7F فرمت می‌شود (مقاوم به locale سرور)');
  console.log('OK MapLinks: لینک‌های گوگل/نشان/بلد/OSM + encode امن');
}

// ---------- ۲) کنترلر عمومی: دکمهٔ اصلی مختصات‌محور، جستجوی متنی ثانویه، بیکن آمار ----------
{
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes("MapLinks::googlePlaceId((int)$biz['id'])"), 'Place ID گوگل در صفحه جزئیات استخراج می‌شود');
  assert.ok(!psc.includes('https://www.google.com/maps/dir/?api=1&destination='), 'لینک مستقیم قدیمی گوگل‌مپ از PHP حذف شده و همه از helper می‌آید');
  assert.ok(psc.includes("self::mapActionLinks($biz, $googlePlaceId, 'business_detail', 'hero')"), 'دکمهٔ اصلی هیرو از helper واحد می‌آید');
  assert.ok(psc.includes("'business_detail', 'sidebar'"), 'سایدبار مجموعهٔ کامل دکمه‌ها را می‌گیرد');
  assert.ok(psc.includes("'compact'"), 'کارت‌ها از نسخهٔ فشردهٔ helper استفاده می‌کنند');
  assert.ok(psc.includes("جستجوی نام در Google Maps"), 'دکمهٔ کمکی جستجوی نام موجود است');
  assert.ok(psc.includes('مسیریابی با نشان') && psc.includes('مسیریابی با بلد'), 'دکمه‌های نشان و بلد کنار گوگل رندر می‌شوند');
  assert.ok(psc.includes("data-hx-track=\"map\""), 'همهٔ لینک‌های نقشه با data-hx-track="map" به بیکن آمار وصل‌اند');
  assert.ok(psc.includes('data-provider="\' . $provider . \'"') || psc.includes("data-provider='\" . $provider"), 'برچسب پروایدر روی هر دکمه ثبت می‌شود');
  // جستجوی متنی هرگز نباید دکمهٔ اصلی (variant hero) باشد
  const heroSection = psc.slice(psc.indexOf("if ($variant === 'compact')"), psc.indexOf("if ($variant === 'sidebar')"));
  assert.ok(!heroSection.includes('googleSearch'), 'جستجوی متنی در دکمهٔ اصلی (hero/compact) نیست');
  // markers: business id برای بیکن پاپ‌آپ
  assert.ok(psc.includes("'id'       => (int)$b['id'],"), 'مارکرهای نقشه شامل شناسهٔ کسب‌وکار هستند (بیکن پاپ‌آپ)');
  // نسخهٔ دارایی‌ها دست‌کم s21.0 برای ریست کش و OPcache (ارتقاهای S18→S21)
  assert.ok(psc.includes('public.css?v=s21.0') && psc.includes('public.js?v=s21.0'), 'نسخهٔ دارایی‌ها به s21.0 ارتقا یافته است');
  console.log('OK PublicSiteController: منطق دکمهٔ اصلی/کمکی + بیکن + نسخهٔ s21.0');
}

// ---------- ۳) Cache و public.js ----------
{
  const cache = await read('app/Core/Cache.php');
  assert.ok(cache.includes('public.css?v=s21.0'), 'کش صفحات با نسخهٔ s21.0 ناسازگارها را باطل می‌کند');
  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes('/assets/vendor/leaflet/leaflet.js?v=s21'), 'نسخهٔ موتور نقشه در lazy-loader به s21 ارتقا یافته است');
  const leaflet = await read('public_html/assets/vendor/leaflet/leaflet.js');
  assert.ok(leaflet.includes('https://nshn.ir/?lat=') && leaflet.includes('https://balad.ir/location?latitude='), 'پاپ‌آپ نقشه لینک نشان و بلد دارد');
  assert.ok(leaflet.includes("'map_popup'"), 'لینک‌های پاپ‌آپ با source-page نقشه‌ای رهگیری می‌شوند');
  assert.ok(leaflet.includes("a.setAttribute('data-hx-track', 'map')"), 'لینک‌های پاپ‌آپ به بیکن آمار وصل‌اند');
  const picker = await read('public_html/assets/vendor/leaflet/hhx-picker.js');
  assert.ok(!/unpkg|jsdelivr|cdn\.|fonts\.googleapis/.test(picker), 'موتور انتخاب مکان ادمین هیچ ارجاع بیرونی ندارد');
  assert.ok(picker.includes('tile.openstreetmap.org'), 'تایل‌های نقشهٔ ادمین از OSM با CSP سازگار است');
  console.log('OK Cache/public.js/leaflet/hhx-picker');
}

// ---------- ۴) پارسر Paste سریع: تست اجرای واقعی در tests/s17_coords_parse_smoke.tsx ----------
{
  const ts = await read('src/services/coordsParse.ts');
  assert.ok(ts.includes('export function parseCoordsFromPaste'), 'پارسر مختصات یافت می‌شود');
  assert.ok(ts.includes('!3d'), 'الگوی لینک !3dLAT!4dLNG پشتیبانی می‌شود');
  assert.ok(ts.includes('@'), 'الگوی لینک @lat,lng پشتیبانی می‌شود');
  assert.ok(ts.includes('decodeURIComponent'), 'لینک‌های URL-encode پشتیبانی می‌شوند');
  assert.ok(ts.includes('FA_DIGITS'), 'ارقام فارسی/عربی تبدیل می‌شوند');
  assert.ok(ts.includes('Math.abs(lat) > 90'), 'محدودهٔ اعتبار مختصات بررسی می‌شود');
  console.log('OK پارسر Paste (استاتیک) — تست اجرایی جدا در s17_coords_parse_smoke.tsx');
}

// ---------- ۵) قفل مختصات دستی در سه نقطهٔ بک‌اند ----------
{
  const pipe = await read('app/Services/PipelineService.php');
  assert.ok(pipe.includes("($existing['coords_source'] ?? null) === 'manual'"), 'ایمپورت روی مختصات manual پیشنهاد نمی‌دهد');
  assert.ok(pipe.includes('!$coordsLocked'), 'هر دو شاخهٔ پیشنهاد مختصات با قفل محافظت شده‌اند');
  assert.ok(pipe.includes('coords_source'), 'منشأ مختصات در INSERT جدید ثبت می‌شود');
  const rq = await read('app/Controllers/ReviewQueueController.php');
  assert.ok(rq.includes("($target['coords_source'] ?? null) === 'manual'"), 'صف بازبینی هم روی manual اعمال نمی‌کند');
  assert.ok(rq.includes('coords_source=COALESCE(:src,coords_source)'), 'منشأ ایمپورت‌محور روی اعمال پیشنهاد ثبت می‌شود');
  const biz = await read('app/Controllers/BusinessController.php');
  assert.ok(biz.includes("$coordsSource = 'manual'") && biz.includes('$coordsVerifiedAt = date('), 'ذخیرهٔ تغییر مختصات در ادمین منبع manual می‌نویسد');
  assert.ok(biz.includes('$coordsChanged') && biz.includes('$coordsCleared'), 'ذخیرهٔ بدون تغییر نقطه، منشأ قبلی را دست نمی‌زند');
  assert.ok(biz.includes("b.coords_source,b.coords_verified_at"), 'مقدار قبلی منشأ برای حفظ آن خوانده می‌شود');
  console.log('OK قفل manual: PipelineService + ReviewQueue + BusinessController');
}

// ---------- ۶) مایگریشن 014 و نبود تداخل شماره ----------
{
  const files = (await readdir('database/migrations')).sort();
  assert.ok(files.includes('014_coords_source.sql'), 'مایگریشن 014 موجود است');
  const others = files.filter((f) => /^014_/.test(f));
  assert.equal(others.length, 1, 'هیچ مایگریشن تکراری 014 وجود ندارد');
  const mig = await read('database/migrations/014_coords_source.sql');
  assert.ok(mig.includes('ADD COLUMN `coords_source` VARCHAR(20)'), 'ستون coords_source اضافه می‌شود');
  assert.ok(mig.includes('ADD COLUMN `coords_verified_at` DATETIME'), 'ستون coords_verified_at اضافه می‌شود');
  assert.ok(mig.includes('information_schema.COLUMNS'), 'مایگریشن تکرارپذیر است (بررسی ستون قبل از ALTER)');
  // 013 و 020 نباید با 014 هم‌نام شوند
  assert.ok(files.includes('013_analytics_and_ai_logs.sql'), '013 آمار دست‌نخورده است');
  assert.ok(files.includes('020_business_comments.sql'), '020 دیدگاه‌ها دست‌نخورده است');
  // از S21 به بعد مایگریشن 021 (خروج دیدگاه‌ها از صندوق بازبینی) مجاز است؛ بعد از آن جهش نامنظم ممنوع
  assert.ok(files.includes('021_comments_out_of_review_queue.sql'), 'مایگریشن 021 خروج دیدگاه‌ها از صندوق بازبینی موجود است');
  assert.ok(!files.some((f) => /^02[2-9]/.test(f)), 'شماره‌گذاری بدون جهش نامنظم از 021 فراتر نرفته است');
  console.log('OK مایگریشن 014: ستون‌ها + تکرارپذیری + بدون تداخل شماره');
}

// ---------- ۷) ادمین بیلد‌شده: فیلد Paste و نقشه در bundle نهایی ----------
{
  const admin = await read('public_html/admin/assets/admin.js');
  assert.ok(admin.includes('hhx-picker'), 'باندل ادمین به موتور نقشهٔ سلف‌هاست ارجاع دارد');
  assert.ok(admin.includes('استخراج و اعمال'), 'دکمهٔ Paste سریع در باندل موجود است');
  assert.ok(admin.includes('coords_source'), 'نمایش منشأ مختصات در باندل موجود است');
  const api = await read('src/services/adminApi.ts');
  assert.ok(api.includes('coords_source?: string | null'), 'تایپ BusinessItem منشأ مختصات را می‌شناسد');
  console.log('OK ادمین بیلد‌شده: نقشه + Paste + منشأ مختصات');
}

console.log('\nهمهٔ بررسی‌های S17 با موفقیت گذشت ✅');
