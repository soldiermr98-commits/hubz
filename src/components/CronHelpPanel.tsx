import React from 'react';

export function CronHelpPanel() {
  return <section className="rounded-xl border border-amber-200 bg-amber-50 p-5 space-y-3" aria-label="راهنمای کرون هاست">
    <h2 className="font-bold text-amber-950">زمان‌بندی روی هاست (Cron)</h2>
    <p className="text-sm leading-7 text-amber-900">بدون کرون، جمع‌آوری و نگهداری زمان‌بندی‌شده خودکار اجرا نمی‌شوند. اعلان‌های ایمیلی درخواست تبلیغات و گزارش خطا هنگام ثبت ارسال می‌شوند و به کرون نیاز ندارند. این بخش کرون را نصب یا فعال نمی‌کند.</p>
    <ol className="list-decimal space-y-2 pr-5 text-sm leading-7 text-amber-950"><li>در DirectAdmin مسیر کامل پروژه و مسیر PHP نسخهٔ CLI را از شرکت هاست بپرسید.</li><li>در بخش Cron Jobs زمان‌بندی هر پنج دقیقه را انتخاب کنید و دستور زیر را با مسیرهای واقعی جایگزین کنید.</li><li>دسترسی نوشتن پوشهٔ لاگ و نتیجهٔ اولین اجرا را بررسی کنید؛ فایل‌های تنظیمات و لاگ نباید عمومی باشند.</li></ol>
    <pre dir="ltr" className="overflow-x-auto rounded-lg bg-slate-900 p-3 text-xs leading-6 text-white"><code>*/5 * * * * /path/to/php /path/to/project/cron/worker.php &gt;&gt; /path/to/project/storage/logs/cron.log 2&gt;&amp;1</code></pre>
    <p className="text-xs leading-6 text-amber-900">مسیرهای بالا نمونه‌اند و قابل کپی مستقیم نیستند. اجرای دستی نگهداری در داشبورد جای زمان‌بندی خودکار و اجرای صف جمع‌آوری را نمی‌گیرد.</p>
  </section>;
}
