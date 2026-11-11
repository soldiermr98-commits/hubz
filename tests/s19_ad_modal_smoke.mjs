// تست دود S19 — مودال ریسپانسیو درخواست تبلیغات + صفحه اختصاصی /advertise + سوال امنیتی + رفع تله ایمیل اعلان‌ها.
// ۱) بک‌اند: verifyQuestion در ثبت درخواست تبلیغات + API سوال تازه + صفحه /advertise
// ۲) فرانت عمومی: مودال، فچ سوال، CTAهای data-hx-ad-modal، رویدادهای موفقیت/نو‌سازی سوال
// ۳) ایمیل: هشدار «اعلان‌ها خاموش» در تست پنل + لاگ دلیل ردشدن notify
// ۴) روت‌ها و نسخهٔ دارایی‌ها s19.0
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (p) => readFile(p, 'utf8');

// ---------- ۱) بک‌اند ----------
{
  const adv = await read('app/Controllers/AdvertisingController.php');
  assert.ok(adv.includes('\\App\\Services\\CommentService::verifyQuestion('), 'ثبت درخواست تبلیغات سوال امنیتی امضاشده را بررسی می‌کند');
  assert.ok(adv.includes("پاسخ سوال امنیتی درست نیست یا منقضی شده است"), 'پیام خطای سوال امنیتی واضح است');

  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes('public static function adQuestion('), 'اندپوینت سوال امنیتی مودال وجود دارد');
  assert.ok(psc.includes('public static function advertisePage('), 'صفحه اختصاصی /advertise وجود دارد');
  assert.ok(psc.includes('private static function adFormInner('), 'رندرکننده مشترک فرم تبلیغات وجود دارد');
  assert.ok(psc.includes('CommentService::generateQuestion(0)'), 'سوال امنیتی از همان سازوکار دیدگاه‌ها ساخته می‌شود');
  assert.ok(psc.includes(" AdvertisingService::schemaReady()") || psc.includes('AdvertisingService::schemaReady()'), 'صفحه تبلیغات به آمادگی جدول تبلیغات گره خورده است');
  assert.ok(psc.includes('data-hx-ad-modal'), 'CTAهای سایت به مودال وصل‌اند');
  assert.ok(psc.includes('id="hx-ad-modal"'), 'مارکاپ مودال در لایوت رندر می‌شود');
  assert.ok(psc.includes('self::adFormInner(null)'), 'فرم مودال روی صفحات کش‌شده سوال آماده ندارد (سوال هنگام باز شدن گرفته می‌شود)');
  assert.ok(psc.includes('hx-ad-cta-card'), 'کارت CTA فوتر جایگزین details قدیمی شده است');
  assert.ok(!psc.includes('<details class="hx-ad-contact"'), 'details قدیمی فرم تبلیغات حذف شده است');
  assert.ok(psc.includes('href="/advertise" data-hx-ad-modal'), 'لینک‌های CTA هم مسیر واقعی دارند (بدون JS به صفحه اختصاصی می‌روند) هم مودال را باز می‌کنند');

  const cache = await read('app/Core/Cache.php');
  assert.ok(cache.includes('public.css?v=s20.0'), 'اعتبارسنج کش دست‌کم s20.0 را می‌پذیرد (از S19 به بعد ارتقا یافته)');

  console.log('OK بک‌اند: سوال امنیتی تبلیغات + صفحه /advertise + مودال در لایوت');
}

// ---------- ۲) فرانت عمومی ----------
{
  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes("getElementById('hx-ad-modal')"), 'مودال در public.js مدیریت می‌شود');
  assert.ok(js.includes("fetch('/api/v1/public/ad-question'"), 'سوال امنیتی هنگام باز شدن مودال فچ می‌شود');
  assert.ok(js.includes("data-hx-ad-close"), 'بستن با کلیک روی پس‌زمینه و دکمه بستن کار می‌کند');
  assert.ok(js.includes("ev.key === 'Escape'"), 'بستن با Escape کار می‌کند');
  assert.ok(js.includes("document.documentElement.style.overflow = 'hidden'"), 'قفل اسکرول پس‌زمینه هنگام باز بودن مودال');
  assert.ok(js.includes("hx:ad-success"), 'رویداد موفقیت تبلیغات به مودال وصل است');
  assert.ok(js.includes("hx:ad-refresh-question"), 'خطای سوال منقضی باعث گرفتن سوال تازه می‌شود');
  assert.ok(js.includes("prefers-reduced-motion: reduce"), 'حرکت با ترجیح کاربر هم‌خوان است');

  const css = await read('public_html/assets/css/public.css');
  assert.ok(css.includes('.hx-modal-panel') && css.includes('.hx-ad-cta-card') && css.includes('.hx-advert-hero'), 'استایل مودال/کارت/صفحه در CSS موجود است');
  assert.ok(css.includes('@media (max-width: 640px)') && css.includes('.hx-modal-panel { max-width: none;'), 'مودال در موبایل به حالت شیت پایین صفحه می‌رود');

  console.log('OK فرانت: مودال + فچ سوال + دسترس‌پذیری پایه');
}

// ---------- ۳) ایمیل اعلان‌ها ----------
{
  const ctrl = await read('app/Controllers/AdminMailController.php');
  assert.ok(ctrl.includes('ارسال اعلان‌ها فعال باشد'), 'تست پنل وقتی اعلان‌ها خاموش است هشدار می‌دهد');
  const svc = await read('app/Services/AdminMailService.php');
  assert.ok(svc.includes('notifications disabled in settings'), 'دلیل ردشدن بی‌صدای notify در لاگ سرور ثبت می‌شود');
  console.log('OK ایمیل: هشدار کلید فعال‌سازی + لاگ دلیل');
}

// ---------- ۴) روت‌ها و نسخه‌ها ----------
{
  const index = await read('public_html/index.php');
  assert.ok(index.includes("'/advertise', [PublicSiteController::class, 'advertisePage']"), 'روت /advertise قبل از catch-all ثبت شده است');
  const api = await read('public_html/api/index.php');
  assert.ok(api.includes("'/api/v1/public/ad-question', [PublicSiteController::class, 'adQuestion']"), 'روت API سوال امنیتی ثبت شده است');
  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes('public.css?v=s20.0') && psc.includes('public.js?v=s20.0'), 'بارنامه دارایی‌ها دست‌کم s20.0 است (از S19 به بعد ارتقا یافته)');
  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes('leaflet.css?v=s20') && js.includes('leaflet.js?v=s20'), 'لودر نقشه دست‌کم s20 است');
  console.log('OK روت‌ها و نسخه‌ها: /advertise + ad-question + s20.0');
}

console.log('\nهمه بررسی‌های S19 با موفقیت گذشت');
