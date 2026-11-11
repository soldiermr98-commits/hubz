import React, { useCallback, useEffect, useState } from 'react';
import { fetchAnalyticsSettings, saveAnalyticsSettings } from '../services/adminApi';

const inputCls = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';

/**
 * S9 — تنظیم «بازه نگهداری لاگ‌های جزئی (روز)» در تب تنظیمات.
 * آمار تجمیعی روزانه (سطح اول) همیشه ماندگار است؛ فقط لاگ جزئیات پاک‌سازی می‌شود.
 */
export const AnalyticsSettingsPanel: React.FC<{ csrfToken: string }> = ({ csrfToken }) => {
  const [days, setDays] = useState<number>(7);
  const [saved, setSaved] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<{ error: boolean; text: string } | null>(null);

  const load = useCallback(async () => {
    try {
      const d = await fetchAnalyticsSettings();
      setDays(d.raw_retention_days); setSaved(d.raw_retention_days); setMsg(null);
    } catch (e) {
      setMsg({ error: true, text: e instanceof Error ? e.message : 'خطا در خواندن تنظیمات آمار' });
    }
  }, []);

  useEffect(() => { void load(); }, [load]);

  const save = async (value: number) => {
    if (busy) return;
    setBusy(true); setMsg(null);
    try {
      const r = await saveAnalyticsSettings(value, csrfToken);
      setMsg({ error: !r.ok, text: r.ok ? (r.message || 'تنظیم شد.') : (r.error || 'ذخیره انجام نشد.') });
      if (r.ok) { setSaved(r.raw_retention_days ?? value); setDays(r.raw_retention_days ?? value); }
    } catch (e) {
      setMsg({ error: true, text: e instanceof Error ? e.message : 'خطا در ذخیره تنظیمات' });
    } finally { setBusy(false); }
  };

  return <section className="rounded-xl bg-white border border-slate-200 p-6 space-y-3">
    <h2 className="font-bold text-slate-900">بازه نگهداری لاگ‌های جزئی آمار</h2>
    <p className="text-sm leading-7 text-slate-600">
      لاگ جزئیات رویدادها (IP، منبع ورودی، دستگاه و…) پس از این بازه توسط کرون خودکار حذف می‌شود تا دیتابیس سبک بماند.
      <strong className="text-emerald-800"> آمار تجمیعی روزانه (بازدید، یکتا، کلیک‌ها) همیشه حفظ می‌شود و پاک نمی‌گردد.</strong>
    </p>
    <div className="flex flex-wrap items-end gap-3">
      <label className="text-sm">بازه نگهداری لاگ‌های جزئی (روز)
        <input aria-label="بازه نگهداری لاگ‌های جزئی (روز)" type="number" min={3} max={30} dir="ltr"
          className={`${inputCls} mt-1 w-28`} value={days}
          onChange={e => setDays(Math.max(3, Math.min(30, Number(e.target.value) || 3)))} />
      </label>
      {[3, 7, 14, 30].map(v => (
        <button key={v} type="button" onClick={() => setDays(v)}
          className={`rounded-lg border px-3 py-2 text-sm font-semibold ${days === v ? 'border-blue-700 bg-blue-50 text-blue-800' : 'border-slate-300 bg-white text-slate-600'}`}>
          {v.toLocaleString('fa-IR')} روز
        </button>
      ))}
      <button type="button" className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800 disabled:opacity-50"
        disabled={busy || days === saved} onClick={() => void save(days)}>ذخیره</button>
    </div>
    <p className="text-xs text-slate-500">مجاز: ۳ تا ۳۰ روز (پیشنهاد: ۷ روز). پاک‌سازی در هر اجرای کرون (حداکثر هر ساعت یک‌بار) انجام می‌شود.</p>
    {msg && <p role={msg.error ? 'alert' : 'status'} className={`text-sm ${msg.error ? 'text-rose-700' : 'text-emerald-700'}`}>{msg.text}</p>}
  </section>;
};
