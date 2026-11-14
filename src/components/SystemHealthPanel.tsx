import React, { useCallback, useEffect, useState } from 'react';
import { fetchSystemBackups, fetchSystemHealth, runSystemBackup, SystemBackupRow, SystemHealthCheck } from '../services/adminApi';

const CHECK_STYLES: Record<SystemHealthCheck['status'], { cls:string; label:string }> = {
  ok: { cls:'bg-emerald-50 text-emerald-700 border-emerald-200', label:'سالم' },
  warn: { cls:'bg-amber-50 text-amber-800 border-amber-200', label:'هشدار' },
  error: { cls:'bg-rose-50 text-rose-700 border-rose-200', label:'مشکل' },
};

function fmtBytes(n:number):string {
  if (n < 1024) return `${n} B`;
  if (n < 1048576) return `${(n/1024).toFixed(1)} KB`;
  if (n < 1073741824) return `${(n/1048576).toFixed(2)} MB`;
  return `${(n/1073741824).toFixed(2)} GB`;
}

/** S24 — کارت سلامت سیستم و بکاپ خودکار (تنظیمات، فقط مدیر ارشد) */
export const SystemHealthPanel: React.FC<{csrfToken:string}> = ({ csrfToken }) => {
  const [health, setHealth] = useState<{ checks:SystemHealthCheck[]; generated_at:string } | null>(null);
  const [backups, setBackups] = useState<SystemBackupRow[]>([]);
  const [directory, setDirectory] = useState('');
  const [retention, setRetention] = useState(14);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [showBackups, setShowBackups] = useState(false);

  const load = useCallback(async () => {
    try {
      const h = await fetchSystemHealth();
      setHealth({ checks: h.checks || [], generated_at: h.generated_at });
      setError('');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'خطا در بررسی سلامت سیستم.');
    }
    try {
      const b = await fetchSystemBackups();
      setBackups(b.backups || []);
      setDirectory(b.directory || '');
      setRetention(b.retention_days || 14);
    } catch { setBackups([]); }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const backupNow = async () => {
    if (busy) return;
    if (!window.confirm('بکاپ کامل دیتابیس (خروجی SQL فشرده) در storage/backups ساخته شود؟ در سرورهای شلوغ ممکن است چند ثانیه طول بکشد.')) return;
    setBusy(true); setNotice(''); setError('');
    try {
      const r = await runSystemBackup(csrfToken);
      setNotice(r.ok ? (r.message || 'بکاپ ساخته شد.') : (r.error || 'ساخت بکاپ ناموفق بود.'));
      await load();
    } finally { setBusy(false); }
  };

  return <section className="rounded-xl bg-white border border-slate-200 p-6 space-y-4" aria-label="سلامت سیستم و بکاپ">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h2 className="font-bold text-slate-900">سلامت سیستم و بکاپ خودکار</h2>
      {health && <span className="text-xs text-slate-400" dir="ltr">{health.generated_at}</span>}
    </div>
    <p className="text-sm text-slate-600 leading-7">
      پایش وضعیت دیتابیس، کرون، صف هوش مصنوعی و تازگی بکاپ‌ها. بکاپ روزانه خودکار هنگام اجرای کرون (فایل <code dir="ltr">cron/worker.php</code>) انجام می‌شود و خروجی‌ها در پوشهٔ <code dir="ltr">storage/backups</code> با نگهداری {retention} روز ذخیره می‌شوند.
    </p>
    <div className="flex flex-wrap gap-2">
      <button type="button" className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={busy} onClick={() => void backupNow()}>بکاپ دستی الان</button>
      <button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-50" disabled={busy} onClick={() => void load()}>بررسی دوباره سلامت</button>
      <button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" onClick={() => setShowBackups(v => !v)}>{showBackups ? 'بستن فهرست بکاپ‌ها' : 'نمایش بکاپ‌ها'}</button>
    </div>
    {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
    {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
    <ul className="space-y-2">
      {(health?.checks || []).map(c => <li key={c.id} className={`rounded-lg border p-3 text-sm ${CHECK_STYLES[c.status].cls}`}>
        <span className="font-bold">{CHECK_STYLES[c.status].label} — {c.label}</span>
        <span className="block text-xs mt-1 opacity-90">{c.detail}</span>
      </li>)}
      {!health && !error && <li className="text-sm text-slate-400">در حال بررسی…</li>}
    </ul>
    {showBackups && <div className="max-h-96 overflow-y-auto rounded-lg border border-slate-200" style={{ scrollbarWidth:'thin' }}>
      <table className="w-full text-sm">
        <thead className="bg-slate-50 sticky top-0"><tr className="text-right text-xs text-slate-500">
          <th className="p-2">فایل</th><th className="p-2">حجم</th><th className="p-2">جداول</th><th className="p-2">منبع</th><th className="p-2">وضعیت</th><th className="p-2">زمان</th>
        </tr></thead>
        <tbody className="divide-y">
          {backups.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500 text-xs">هیچ بکاپی ثبت نشده؛ الان یکی بسازید یا کرون را فعال کنید.</td></tr>}
          {backups.map(b => <tr key={b.id} className="hover:bg-slate-50">
            <td className="p-2 text-xs break-all" dir="ltr">{b.filename}</td>
            <td className="p-2 tabular-nums">{fmtBytes(Number(b.size_bytes) || 0)}</td>
            <td className="p-2 tabular-nums text-slate-500">{b.tables_count}</td>
            <td className="p-2 text-xs">{b.trigger_source === 'cron' ? 'کرون' : b.trigger_source === 'panel' ? 'پنل' : 'CLI'}</td>
            <td className="p-2 text-xs"><span className={`rounded px-1.5 py-0.5 font-semibold ${b.status === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>{b.status === 'ok' ? 'موفق' : 'ناموفق'}</span></td>
            <td className="p-2 text-xs text-slate-400" dir="ltr">{b.created_at}</td>
          </tr>)}
        </tbody>
      </table>
      {directory && <p className="p-3 text-xs text-slate-500">محل ذخیره روی هاست: <code dir="ltr">{directory}</code> — دانلود دوره‌ای این فایل‌ها به خارج از هاست (مثلاً حداکثر هفتگی) به‌عنوان نسخهٔ دوم پشتیبان توصیه می‌شود.</p>}
    </div>}
  </section>;
};
