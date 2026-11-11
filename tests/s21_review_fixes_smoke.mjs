// تست دود S21 — بازبینی کامل سه گزارش کاربر پیش از انتشار:
// ۱) فرم درخواست تبلیغات: بدون اسکرول افقی ( honeypot بدون موقعیت خارج از صفحه ) + اینپوت‌های هم‌اندازه
// ۲) آمار: داشبورد مقاوم + سازگار با ONLY_FULL_GROUP_BY + اندپوینت «بررسی دیتابیس» + نمایش در پنل
// ۳) دیدگاه‌ها: بدون ورود به صندوق بازبینی؛ فقط در تب «دیدگاه‌ها» مدیریت می‌شوند + پاکسازی ردیف‌های قدیمی
// ۴) اعلان ایمیل: آخرین نتیجهٔ اعلان خودکار در پنل نمایش داده می‌شود
// ۵) نسخهٔ دارایی‌ها s21.0
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const read = (p) => readFile(p, 'utf8');

// ---------- ۱) فرم تبلیغات: اسکرول افقی و اندازهٔ اینپوت‌ها ----------
{
  const css = await read('public_html/assets/css/public.css');
  assert.ok(!css.includes('left: -10000px') && !css.includes('left:-10000px'), 'تکنیک left:-10000px حذف شده است (در RTL اسکرول افقی می‌ساخت)');
  assert.ok(/\.hx-ad-honeypot\s*\{[^}]*clip:\s*rect\(0,\s*0,\s*0,\s*0\)/.test(css), 'honeypot با clip استاندارد visually-hidden پنهان می‌شود');
  assert.ok(/\.hx-ad-honeypot\s*\{[^}]*clip-path:\s*inset\(50%\)/.test(css), 'honeypot با clip-path هم پوشانده شده است');
  assert.ok(/\.hx-modal-panel\s*\{[^}]*overflow-x:\s*hidden/.test(css), 'پنل مودال هرگز اسکرول افقی نمی‌گیرد');
  assert.ok(/\.hx-modal-panel\s*\{[^}]*overscroll-behavior:\s*contain/.test(css), 'اسکرول مودال به پس‌زمینه نشت نمی‌کند');
  assert.ok(css.includes('grid-template-columns: repeat(2, minmax(0, 1fr))'), 'ستون‌های فرم با minmax(0,1fr) هستند تا محتوای پهن سرریز نکند');
  assert.ok(css.includes('.hx-ad-form input:not([type="checkbox"])'), 'استایل اینپوت بدون اتکا به type صریح اعمال می‌شود');

  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes('<input type="text" name="name"'), 'اینپوت نام در فرم تبلیغات type="text" دارد (اندازهٔ پیش‌فرض مرورگر نمی‌گیرد)');
  assert.ok(psc.includes('public.css?v=s21.0') && psc.includes('public.js?v=s21.0'), 'نسخهٔ دارایی‌های عمومی s21.0 است');

  const js = await read('public_html/assets/js/public.js');
  assert.ok(js.includes('leaflet.css?v=s21') && js.includes('leaflet.js?v=s21'), 'لودر نقشه s21 است');
  assert.ok(!js.includes('در حال ارسال به صندوق بازبینی'), 'متن «ارسال به صندوق بازبینی» از رابط عمومی حذف شده است');
  assert.ok(js.includes('در حال ارسال دیدگاه…') && js.includes('در حال ارسال درخواست…'), 'متن وضعیت ارسال برای هر فرم درست است');
  console.log('OK فرم تبلیغات: بدون اسکرول افقی RTL + اینپوت‌های هم‌اندازه + متن وضعیت درست');
}

// ---------- ۲) آمار: مقاوم‌سازی + بررسی دیتابیس ----------
{
  const svc = await read('app/Services/AnalyticsService.php');
  assert.ok(svc.includes('GROUP BY d.target_id, {$titleColumn}'), 'کوئری برترین‌ها ستون عنوان را در GROUP BY دارد (سازگار با ONLY_FULL_GROUP_BY/ماریاDB)');
  assert.ok(svc.includes("'business' => ['LEFT JOIN businesses b ON b.id = d.target_id', \"COALESCE(b.name, CONCAT('#', d.target_id))\", 'b.name']"), 'joinFor ستون GROUP BY عنوان را برمی‌گرداند');
  assert.ok(svc.includes('public static function diagnose('), 'سرویس بررسی سلامت دیتابیس آمار وجود دارد');
  assert.ok(svc.includes('DB::tableExists($table)'), 'بررسی سلامت وجود جدول‌ها را با tableExists چک می‌کند');
  assert.ok(svc.includes("'SELECT @@sql_mode AS m'"), 'بررسی سلامت حالت SQL سرور را گزارش می‌کند');
  assert.ok(svc.includes('schema_missing'), 'داشبورد جدول‌های ناموجود را گزارش می‌کند');
  assert.ok(svc.includes("$warnings[] = 'خواندن برترین‌های «' . \$type . '» ناموفق بود: ' . self::safeError(\$e);"), 'خطای هر نوع هدف داشبورد را نمی‌شکند و هشدار می‌شود');
  assert.ok(!svc.includes('$this->zeroTotals()'), 'هیچ $this در متد استاتیک داشبورد استفاده نشده است');

  const ctrl = await read('app/Controllers/AnalyticsController.php');
  assert.ok(ctrl.includes('public static function diagnose('), 'اندپوینت بررسی دیتابیس در کنترلر هست');
  assert.ok(ctrl.includes("'detail' => 'خطای سرور: '"), 'خطای ۵۰۰ داشبورد متن دقیق (detail) به مدیر می‌دهد');

  const api = await read('public_html/api/index.php');
  assert.ok(api.includes("'/api/v1/analytics/diagnose', [AnalyticsController::class, 'diagnose']"), 'روت GET /api/v1/analytics/diagnose ثبت شده است');

  const adminApi = await read('src/services/adminApi.ts');
  assert.ok(adminApi.includes('fetchAnalyticsDiagnose'), 'تابع فراخوانی بررسی دیتابیس در adminApi هست');
  assert.ok(adminApi.includes('err.detail = data.detail'), 'خطاهای سرور فیلد detail را به پنل می‌رسانند');

  const stats = await read('src/components/StatsView.tsx');
  assert.ok(stats.includes('DbCheckPanel'), 'پنل «بررسی دیتابیس» در صفحهٔ آمار هست');
  assert.ok(stats.includes('runWebMigrations'), 'ساخت جدول‌های ناموجود از خود صفحهٔ آمار ممکن است');
  assert.ok(stats.includes('warnings'), 'هشدارهای جزئی سرور در صفحهٔ آمار نمایش داده می‌شود');
  console.log('OK آمار: ONLY_FULL_GROUP_BY-safe + داشبورد مقاوم + بررسی دیتابیس در پنل');
}

// ---------- ۳) دیدگاه‌ها: خارج از صندوق بازبینی ----------
{
  const svc = await read('app/Services/CommentService.php');
  assert.ok(!svc.includes("INSERT INTO review_queue"), 'ثبت دیدگاه دیگر ردیفی در صندوق بازبینی نمی‌سازد');
  assert.ok(!svc.includes("UPDATE review_queue"), 'تایید/رد/حذف دیدگاه دیگر صندوق بازبینی را به‌روز نمی‌کند');
  assert.ok(svc.includes("DELETE FROM business_comments WHERE id = :id"), 'حذف دیدگاه همچنان واقعی و کامل است');
  assert.ok(svc.includes("INSERT INTO business_comments"), 'ثبت دیدگاه در جدول اختصاصی business_comments انجام می‌شود');

  const mig = await read('database/migrations/021_comments_out_of_review_queue.sql');
  assert.ok(mig.includes("DELETE FROM `review_queue` WHERE `kind` = 'comment';"), 'مایگریشن ۰۲۱ ردیف‌های قدیمی دیدگاه در صندوق بازبینی را پاک می‌کند');

  const psc = await read('app/Controllers/PublicSiteController.php');
  assert.ok(psc.includes("AdminMailService::notify('comment')"), 'اعلان ایمیلی دیدگاه جدید سر جای خود است و به تب دیدگاه‌ها اشاره می‌کند');
  console.log('OK دیدگاه‌ها: فقط در تب دیدگاه‌ها + پاکسازی ردیف‌های قدیمی صندوق بازبینی');
}

// ---------- ۴) اعلان ایمیل: وضعیت آخرین اعلان در پنل ----------
{
  const svc = await read('app/Services/AdminMailService.php');
  assert.ok(svc.includes('NOTIFY_STATE_KEY'), 'کلید جدا برای وضعیت اعلان وجود دارد');
  assert.ok(svc.includes('rememberNotify($event, \'skipped\''), 'رد شدن اعلان (غیرفعال بودن) ثبت می‌شود');
  assert.ok(svc.includes("'notify_state'=>self::notifyState()"), 'status شامل وضعیت آخرین اعلان است');
  assert.ok(svc.includes("$sent ? 'sent' : 'failed'"), 'نتیجهٔ ارسال (موفق/ناموفق) ثبت می‌شود');

  const panel = await read('src/components/AdminMailPanel.tsx');
  assert.ok(panel.includes('آخرین اعلان خودکار'), 'پنل ایمیل وضعیت آخرین اعلان را نشان می‌دهد');
  assert.ok(panel.includes('ارسال اعلان‌ها فعال باشد'), 'راهنمای روشن‌کردن کلید اعلان‌ها نمایش داده می‌شود');

  const adminApi = await read('src/services/adminApi.ts');
  assert.ok(adminApi.includes('AdminMailNotifyState'), 'تایپ وضعیت اعلان در adminApi هست');
  console.log('OK اعلان ایمیل: وضعیت آخرین اعلان عمومی در پنل قابل مشاهده است');
}

// ---------- ۵) اعتبارسنج کش ----------
{
  const cache = await read('app/Core/Cache.php');
  assert.ok(cache.includes('public.css?v=s21.0'), 'اعتبارسنج کش صفحات با s21.0 هم‌خوان است');
  console.log('OK کش: اعتبارسنج با نسخهٔ s21.0 هم‌خوان است');
}

console.log('S21 smoke test passed.');
