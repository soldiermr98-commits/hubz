// تست دود S20 — فرم دیدگاه درون‌صفحه‌ای (بدون تب جدید) + اصلاح ستاره‌ها + حذف کامل رکوردها از دیتابیس.
// ۱) صفحهٔ کسب‌وکار: فرم دیدگاه درون‌صفحه‌ای، بدون target=_blank، سوال امنیتی تازه از API
// ۲) ستاره‌ها: data-star-value + ویجت با بازخورد عددی + پرشدن تا انتخاب با کلاس‌های JS
// ۳) حذف کامل: مقاله (اندپوینت + سرویس تراکنشی + دکمهٔ پنل) و پاکسازی business_comments در حذف کسب‌وکار
// ۴) روت‌ها، سایت‌مپ و نسخهٔ دارایی‌ها s20.0
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (p) => readFile(p, 'utf8');

// ---------- ۱) فرم دیدگاه درون‌صفحه‌ای ----------
{
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(!psc.includes('ثبت دیدگاه شما (تب جدید)'), 'دکمهٔ «تب جدید» از صفحهٔ کسب‌وکار حذف شده است');
  assert.ok(!psc.includes('ثبت دیدگاه (تب جدید)'), 'دکمهٔ «تب جدید» بخش دیدگاه‌ها هم حذف شده است');
  assert.ok(!psc.includes('target="_blank" rel="noopener" href="<?= self::e($baseUrl) ?>/comment/'), 'لینک تب جدید به صفحهٔ /comment/ دیگر وجود ندارد');
  assert.ok(psc.includes('id="hx-inline-comment"'), 'کارت فرم دیدگاه درون‌صفحه‌ای در صفحهٔ کسب‌وکار رندر می‌شود');
  assert.ok(psc.includes('data-hx-inline-comment-form'), 'فرم درون‌صفحه‌ای با نشانگر اختصاصی مشخص شده است');
  assert.ok(psc.includes('data-hx-open-comment-form'), 'دکمه‌های ثبت دیدگاه به بازکردن فرم درون‌صفحه‌ای وصل‌اند');
  assert.ok(psc.includes('public static function commentQuestion('), 'اندپوینت سوال امنیتی فرم دیدگاه وجود دارد');
  assert.ok(psc.includes("CommentService::generateQuestion(0)"), 'سوال امنیتی از سازوکار HMAC مشترک ساخته می‌شود');
  assert.ok(psc.includes('private static function starWidget('), 'ویجت ستارهٔ مشترک (صفحهٔ اختصاصی + درون‌صفحه‌ای) وجود دارد');
  // صفحهٔ اختصاصی /comment/ به‌عنوان مسیر پشتیبان بدون‌جاوااسکریپت باقی می‌ماند
  assert.ok(psc.includes('public static function commentPage('), 'صفحهٔ اختصاصی /comment/ به‌عنوان مسیر پشتیبان باقی است');
  assert.ok(psc.includes('رفتن به فرم ثبت دیدگاه'), 'بدون جاوااسکریپت، لینک جایگزین به صفحهٔ اختصاصی وجود دارد');

  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes("fetch('/api/v1/public/comment-question'"), 'سوال امنیتی فرم دیدگاه هنگام باز شدن فچ می‌شود');
  assert.ok(js.includes('[data-hx-open-comment-form]'), 'بازکردن فرم درون‌صفحه‌ای در public.js مدیریت می‌شود');
  assert.ok(js.includes('hx:comment-refresh-question'), 'خطای سوال منقضی دیدگاه باعث گرفتن سوال تازه می‌شود');
  assert.ok(js.includes("form[data-hx-inline-comment-form]"), 'رویداد موفقیت دیدگاه برای فرم درون‌صفحه‌ای هم کار می‌کند');

  const css = await read('public_html/assets/css/public.css');
  assert.ok(css.includes('.hx-inline-comment') && css.includes('.hx-inline-comment[hidden]'), 'استایل کارت درون‌صفحه‌ای در CSS موجود است');

  const api = await read('public_html/api/index.php');
  assert.ok(api.includes("'/api/v1/public/comment-question', [PublicSiteController::class, 'commentQuestion']"), 'روت API سوال امنیتی دیدگاه ثبت شده است');
  console.log('OK فرم دیدگاه درون‌صفحه‌ای: بدون تب جدید + سوال تازه + مسیر پشتیبان بدون‌JS');
}

// ---------- ۲) ستاره‌ها ----------
{
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes('data-star-value='), 'هر ستاره مقدار عددی صریح دارد');
  assert.ok(psc.includes('aria-label="امتیاز'), 'هر ستاره aria-label دارد (دسترس‌پذیری)');
  assert.ok(psc.includes('data-hx-star-value'), 'بازخورد عددی زندهٔ امتیاز در ویجت هست');

  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes('initStarWidgets'), 'راه‌اندازی ویجت ستاره‌ها در public.js وجود دارد');
  assert.ok(js.includes("'is-on'") && js.includes("'is-hover'"), 'پرشدن تا انتخاب کاربر با کلاس‌های is-on/is-hover انجام می‌شود');
  assert.ok(js.includes("امتیاز شما: ' + faDigits[v] + ' از ۵"), 'بازخورد عددی فارسی پس از انتخاب نمایش داده می‌شود');

  const css = await read('public_html/assets/css/public.css');
  assert.ok(css.includes('.hx-star.is-on .hx-icon') && css.includes('.hx-star.is-hover .hx-icon'), 'استایل پرشدن ستاره‌ها (is-on/is-hover) در CSS هست');
  assert.ok(css.includes('.hx-star-value[data-active="1"]'), 'حالت فعال بازخورد عددی استایل دارد');
  console.log('OK ستاره‌ها: مقدار صریح + پرشدن کامل تا انتخاب + بازخورد عددی');
}

// ---------- ۳) حذف کامل رکوردها ----------
{
  // مقاله: اندپوینت + سرویس + پنل
  const art = await read('app/Controllers/ArticleController.php');
  assert.ok(art.includes('public static function destroy('), 'اندپوینت حذف مقاله وجود دارد');
  assert.ok(art.includes("Auth::requireRole(['admin'])"), 'حذف مقاله فقط برای ادمین است');
  assert.ok(art.includes('Csrf::verifyOrFail($req)'), 'حذف مقاله محافظت CSRF دارد');
  assert.ok(art.includes('Cache::flushAll()'), 'پس از حذف مقاله کش عمومی پاک می‌شود');

  const svc = await read('app/Services/ArticleService.php');
  assert.ok(svc.includes('public static function deleteArticle('), 'سرویس حذف تراکنشی مقاله وجود دارد');
  for (const table of ['article_items', 'article_faqs', 'article_tag_links', 'article_revisions', 'article_extras', 'article_url_aliases', 'events_daily']) {
    assert.ok(svc.includes(`FROM ${table} WHERE article_id`), `حذف کامل شامل جدول ${table} است`);
  }
  assert.ok(svc.includes("DELETE FROM review_queue WHERE kind='article' AND target_id=:id"), 'حذف کامل شامل ردیف‌های صف بازبینی مقاله است');
  assert.ok(svc.includes('DELETE FROM articles WHERE id=:id'), 'خود رکورد مقاله واقعاً از دیتابیس پاک می‌شود');
  assert.ok(svc.includes('FOR UPDATE'), 'حذف مقاله با قفل ردیفی تراکنش انجام می‌شود');

  const api = await read('public_html/api/index.php');
  assert.ok(api.includes("'/api/v1/articles/delete', [ArticleController::class, 'destroy']"), 'روت POST /api/v1/articles/delete ثبت شده است');

  const adminApi = await read('src/services/adminApi.ts');
  assert.ok(adminApi.includes("action('/api/v1/articles/delete'"), 'تابع حذف مقاله در adminApi وجود دارد');

  const view = await read('src/components/ArticlesManagerView.tsx');
  assert.ok(view.includes('deleteArticleItem'), 'دکمهٔ حذف مقاله از adminApi استفاده می‌کند');
  assert.ok(view.includes('removeArticle'), 'هندلر حذف مقاله در پنل وجود دارد');
  assert.ok(view.includes('برای همیشه از دیتابیس حذف شود؟'), 'تأیید حذف مقاله صریحاً بازگشت‌ناپذیری را می‌گوید');

  // کسب‌وکار: پاکسازی دیدگاه‌ها هنگام حذف
  const biz = await read('app/Controllers/BusinessController.php');
  assert.ok(biz.includes("DELETE FROM business_comments WHERE business_id IN"), 'حذف کسب‌وکار دیدگاه‌های آن را هم واقعاً پاک می‌کند');
  assert.ok(biz.includes("DELETE FROM businesses WHERE id IN"), 'حذف کسب‌وکار همچنان Hard-Delete است');
  console.log('OK حذف کامل: مقاله (اندپوینت/سرویس/پنل) + پاکسازی دیدگاه‌های کسب‌وکار حذف‌شده');
}

// ---------- ۴) لینک فوتر، سایت‌مپ و نسخه‌ها ----------
{
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes('<a href="/advertise">درخواست تبلیغات</a>'), 'لینک واقعی صفحهٔ تبلیغات در فوتر وجود دارد (برای ایندکس)');
  assert.ok(psc.includes("['loc' => $baseUrl . '/advertise', 'priority' => '0.6', 'changefreq' => 'monthly']"), 'صفحهٔ /advertise در سایت‌مپ ثبت شده است');
  // مودال پاپ‌آپ همچنان در هدر و کارت فوتر فعال است
  assert.ok(psc.includes('data-hx-ad-modal'), 'پاپ‌آپ درخواست تبلیغات حفظ شده است');
  assert.ok(psc.includes('id="hx-ad-modal"'), 'مارکاپ مودال تبلیغات باقی است');

  const cache = await read('app/Core/Cache.php');
  assert.ok(cache.includes('public.css?v=s20.0'), 'اعتبارسنج کش با s20.0 هم‌خوان است');
  assert.ok(psc.includes('public.css?v=s20.0') && psc.includes('public.js?v=s20.0'), 'بارنامه دارایی‌ها s20.0 است');
  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes('leaflet.css?v=s20') && js.includes('leaflet.js?v=s20'), 'لودر نقشه s20 است');
  console.log('OK فوتر/سایت‌مپ/نسخه‌ها: لینک واقعی + حفظ پاپ‌آپ + s20.0');
}

console.log('\nهمه بررسی‌های S20 با موفقیت گذشت');
