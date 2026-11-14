import React, { useCallback, useEffect, useState } from 'react';
import {
  AiTaskRow, enqueueAiTasks, fetchAiTasks, runAiTasksBatch,
} from '../services/adminApi';

const STATUSES: Record<AiTaskRow['status'], { label:string; cls:string }> = {
  queued: { label:'در صف', cls:'bg-amber-50 text-amber-800' },
  claimed: { label:'در حال اجرا', cls:'bg-blue-50 text-blue-800' },
  done: { label:'انجام شد', cls:'bg-emerald-50 text-emerald-700' },
  failed: { label:'ردشده (گاردریل)', cls:'bg-rose-50 text-rose-700' },
};

/** S24 — پنل صف کارهای هوش مصنوعی بدون توهم: ثبت، اجرای دسته‌ای و وضعیت ai_tasks */
export const AiTasksQueuePanel: React.FC<{csrfToken:string}> = ({ csrfToken }) => {
  const [tasks, setTasks] = useState<AiTaskRow[]>([]);
  const [stats, setStats] = useState({ queued:0, claimed:0, done:0, failed:0 });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [expanded, setExpanded] = useState(false);

  const load = useCallback(async () => {
    try {
      const d = await fetchAiTasks();
      setTasks(d.tasks || []);
      setStats(d.stats || { queued:0, claimed:0, done:0, failed:0 });
      setError('');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'خطا در دریافت وضعیت صف.');
    }
  }, []);
  useEffect(() => { void load(); }, [load]);

  const enqueue = async () => {
    if (busy) return;
    if (!window.confirm('ثبت دسته‌ای: برای کسب‌وکارهای برتر (حداکثر ۲۵) و مقالات جدید تسک تولید متن ساخته می‌شود؟ اجرا جداگانه است.')) return;
    setBusy(true); setNotice(''); setError('');
    try {
      const r = await enqueueAiTasks('batch_all', 'business_text', 0, csrfToken);
      setNotice(r.ok ? (r.message || 'تسک‌ها ثبت شدند.') : (r.error || 'ثبت انجام نشد.'));
      await load();
    } finally { setBusy(false); }
  };

  const runBatch = async () => {
    if (busy) return;
    if (stats.queued + stats.claimed === 0) {
      if (!window.confirm('صف خالی است. اجرای دسته‌ای چند تسک تازه برای نمونه می‌سازد و پردازش می‌کند؛ این یک درخواست واقعی به سرویس هوش مصنوعی است و سهمیه می‌برد. ادامه؟')) return;
    } else if (!window.confirm('اجرای فوری حداکثر ۱۰ تسک صف؛ هر تسک یک درخواست واقعی به سرویس هوش مصنوعی است و سهمیه می‌برد. ادامه؟')) return;
    setBusy(true); setNotice(''); setError('');
    try {
      const r = await runAiTasksBatch(10, csrfToken);
      setNotice(r.ok ? (r.message || 'پردازش انجام شد.') : (r.error || 'اجرای دسته‌ای ناموفق بود.'));
      await load();
    } finally { setBusy(false); }
  };

  return <section className="rounded-xl bg-white border border-slate-200 p-6 space-y-4" aria-label="صف کارهای هوش مصنوعی">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h2 className="font-bold text-slate-900">صف کارهای هوش مصنوعی بدون توهم</h2>
      <span className="text-xs text-slate-500">{tasks.length} تسک اخیر نمایش داده می‌شود</span>
    </div>
    <p className="text-sm text-slate-600 leading-7">
      متن کسب‌وکارها و پیش‌نویس مقاله‌ها فقط از فیلدهای راستی‌آزمایی‌شدهٔ دیتابیس ساخته می‌شوند و خروجی هر تسک قبل از ثبت با گاردریل ضد توهم سنجیده می‌شود؛ خروجی نامعتبر ثبت نمی‌شود و در ستون «ردشده» با دلیل دیده می‌شود.
    </p>
    <div className="grid grid-cols-2 sm:grid-cols-4 gap-3" role="group" aria-label="آمار صف">
      {(['queued','claimed','done','failed'] as const).map(k => <div key={k} className={`rounded-lg p-3 text-center ${STATUSES[k].cls}`}>
        <p className="text-xl font-bold tabular-nums">{stats[k]}</p>
        <p className="text-xs">{STATUSES[k].label}</p>
      </div>)}
    </div>
    <div className="flex flex-wrap gap-2">
      <button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 disabled:opacity-50" disabled={busy} onClick={() => void enqueue()}>ثبت دسته‌ای تسک</button>
      <button type="button" className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50" disabled={busy} onClick={() => void runBatch()}>اجرای فوری دسته‌ای (۱۰ تسک)</button>
      <button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm disabled:opacity-50" disabled={busy} onClick={() => void load()}>به‌روزرسانی وضعیت</button>
      <button type="button" className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" onClick={() => setExpanded(v => !v)}>{expanded ? 'بستن فهرست' : 'نمایش فهرست تسک‌ها'}</button>
    </div>
    <p className="text-xs text-amber-900 leading-6">توجه: هر تسک یک درخواست واقعی به سرویس انتخابی است و ممکن است سهمیهٔ رایگان را مصرف کند. اجرای خودکار شبانه عمداً فعال نیست تا سهمیه بدون اطلاع مدیر مصرف نشود.</p>
    {notice && <p role="status" className="text-sm text-emerald-700">{notice}</p>}
    {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
    {expanded && <div className="max-h-96 overflow-y-auto rounded-lg border border-slate-200" style={{ scrollbarWidth:'thin' }}>
      <table className="w-full text-sm">
        <thead className="bg-slate-50 sticky top-0"><tr className="text-right text-xs text-slate-500">
          <th className="p-2">#</th><th className="p-2">نوع</th><th className="p-2">هدف</th><th className="p-2">وضعیت</th><th className="p-2">تلاش</th><th className="p-2">به‌روزرسانی</th>
        </tr></thead>
        <tbody className="divide-y">
          {tasks.length === 0 && <tr><td colSpan={6} className="p-4 text-center text-slate-500 text-xs">صف خالی است؛ با «ثبت دسته‌ای تسک» شروع کنید.</td></tr>}
          {tasks.map(t => <tr key={t.id} className="hover:bg-slate-50">
            <td className="p-2 tabular-nums text-slate-400">{t.id}</td>
            <td className="p-2 text-xs">{t.type === 'business_text' ? 'متن کسب‌وکار' : 'پیش‌نویس مقاله'}</td>
            <td className="p-2 max-w-[220px] truncate" title={t.target_title || String(t.target_id)}>{t.target_title || `#${t.target_id}`}</td>
            <td className="p-2"><span className={`rounded px-1.5 py-0.5 text-xs font-semibold ${STATUSES[t.status]?.cls || 'bg-slate-100'}`}>{STATUSES[t.status]?.label || t.status}</span>{t.status==='failed' && t.error && <small className="block text-rose-600 mt-1" title={t.error}>{t.error.slice(0,60)}</small>}</td>
            <td className="p-2 tabular-nums text-slate-500">{t.attempts}</td>
            <td className="p-2 text-xs text-slate-400" dir="ltr">{t.updated_at}</td>
          </tr>)}
        </tbody>
      </table>
    </div>}
  </section>;
};
