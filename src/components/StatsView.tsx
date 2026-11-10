import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { Download, LoaderCircle, RefreshCw, Search } from 'lucide-react';
import {
  AnalyticsDashboard, AnalyticsLogFilters, AnalyticsLogRow, AnalyticsTopRow,
  analyticsExportUrl, fetchAnalyticsDashboard, fetchAnalyticsLogs,
} from '../services/adminApi';

const TARGET_LABELS: Record<string, string> = { business: 'کسب‌وکار', article: 'مقاله', city: 'شهر', category: 'دسته‌بندی', banner: 'بنر تبلیغاتی' };
const EVENT_LABELS: Record<string, string> = { view: 'بازدید', call: 'تماس', map: 'نقشه', website: 'وب‌سایت', instagram: 'اینستاگرام', whatsapp: 'واتساپ', banner: 'بنر', search: 'جستجو' };
const SOURCE_LABELS: Record<string, string> = { direct: 'مستقیم', search: 'موتور جستجو', instagram: 'اینستاگرام', telegram: 'تلگرام', social: 'شبکه اجتماعی', internal: 'لینک داخلی', referral: 'ارجاع' };
const EVENT_TYPES = ['view', 'call', 'map', 'website', 'instagram', 'whatsapp', 'banner', 'search'];
const TARGET_TYPES = ['business', 'article', 'city', 'category', 'banner'];
const SOURCE_TYPES = ['direct', 'search', 'instagram', 'telegram', 'social', 'internal', 'referral'];

const inputCls = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const btn = 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold disabled:opacity-50';
const btnPrimary = `${btn} bg-blue-700 text-white hover:bg-blue-800`;
const btnGhost = `${btn} border border-blue-300 bg-white text-blue-800 hover:bg-blue-50`;

function fa(n: number | string): string {
  const num = typeof n === 'string' ? Number(n) : n;
  if (!Number.isFinite(num)) return '۰';
  return num.toLocaleString('fa-IR');
}

type Range = { days: number; from?: string; to?: string };

function RangeBar({ range, onChange }: { range: Range; onChange: (r: Range) => void }) {
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  return <div className="flex flex-wrap items-end gap-2">
    {[1, 7, 30, 90].map(d => (
      <button key={d} type="button" onClick={() => onChange({ days: d })}
        className={`${btn} ${range.days === d && !range.from ? 'bg-blue-700 text-white' : 'border border-slate-300 bg-white text-slate-700'}`}>
        {d === 1 ? 'امروز' : `${d.toLocaleString('fa-IR')} روز`}
      </button>
    ))}
    <div className="flex items-end gap-1 text-xs text-slate-600">
      <label>از <input type="date" value={from} onChange={e => setFrom(e.target.value)} className={`${inputCls} !w-36 mt-0.5`} /></label>
      <label>تا <input type="date" value={to} onChange={e => setTo(e.target.value)} className={`${inputCls} !w-36 mt-0.5`} /></label>
      <button type="button" className={btnGhost} onClick={() => { if (from && to) onChange({ days: 0, from, to }); }}>اعمال بازه</button>
    </div>
  </div>;
}

function SummaryCards({ totals, loading }: { totals: AnalyticsDashboard['totals'] | null; loading: boolean }) {
  const cards: Array<[string, number, string]> = [
    ['بازدید کل', totals?.views ?? 0, 'text-blue-700'],
    ['بازدیدکننده یکتا', totals?.uniques ?? 0, 'text-emerald-700'],
    ['کلیک تماس', totals?.call ?? 0, 'text-sky-700'],
    ['کلیک نقشه', totals?.map ?? 0, 'text-violet-700'],
    ['کلیک وب‌سایت', totals?.website ?? 0, 'text-cyan-700'],
    ['کلیک اینستاگرام', totals?.instagram ?? 0, 'text-pink-700'],
    ['کلیک واتساپ', totals?.whatsapp ?? 0, 'text-green-700'],
    ['کلیک بنر تبلیغاتی', totals?.banner ?? 0, 'text-amber-700'],
    ['جستجوی داخلی', totals?.searches ?? 0, 'text-slate-700'],
  ];
  return <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    {cards.map(([label, value, color]) => (
      <div key={label} className="rounded-xl border border-slate-200 bg-white p-4">
        <p className="text-xs text-slate-500">{label}</p>
        <p className={`mt-1 text-2xl font-extrabold tabular-nums ${color}`}>{loading ? '…' : fa(value)}</p>
      </div>
    ))}
  </div>;
}

function DailyBars({ series }: { series: AnalyticsDashboard['series'] }) {
  if (!series.length) return <p className="text-sm text-slate-500">در این بازه آماری ثبت نشده است.</p>;
  const max = Math.max(1, ...series.map(r => Number(r.views)));
  return <div className="space-y-1.5">
    {series.map(r => (
      <div key={r.day} className="flex items-center gap-2 text-xs" dir="rtl">
        <span className="w-24 shrink-0 tabular-nums text-slate-500">{r.day}</span>
        <div className="h-4 flex-1 rounded bg-slate-100">
          <div className="h-4 rounded bg-gradient-to-l from-blue-600 to-emerald-500" style={{ width: `${Math.max(2, Math.round(100 * Number(r.views) / max))}%` }} />
        </div>
        <span className="w-24 shrink-0 tabular-nums text-slate-700">{fa(r.views)} بازدید · {fa(r.uniques)} یکتا</span>
      </div>
    ))}
  </div>;
}

function TopTable({ rows, type, loading }: { rows: AnalyticsTopRow[]; type: string; loading: boolean }) {
  if (loading) return <p className="text-sm text-slate-500">در حال دریافت…</p>;
  if (!rows.length) return <p className="text-sm text-slate-500">در این بازه بازدیدی برای «{TARGET_LABELS[type]}» ثبت نشده است.</p>;
  return <div className="overflow-x-auto">
    <table className="w-full text-sm">
      <thead><tr className="text-xs text-slate-500">
        <th className="p-2 text-right">عنوان</th><th className="p-2">بازدید</th><th className="p-2">یکتا</th>
        <th className="p-2">تماس</th><th className="p-2">نقشه</th><th className="p-2">وب‌سایت</th>
        <th className="p-2">بنر</th><th className="p-2">جستجو</th>
      </tr></thead>
      <tbody>
        {rows.map(r => (
          <tr key={r.id} className="border-t border-slate-100 text-center">
            <td className="p-2 text-right font-semibold text-slate-800">{r.title || `#${r.id}`}</td>
            <td className="p-2 tabular-nums">{fa(r.views)}</td>
            <td className="p-2 tabular-nums">{fa(r.uniques)}</td>
            <td className="p-2 tabular-nums">{fa(r.call)}</td>
            <td className="p-2 tabular-nums">{fa(r.map)}</td>
            <td className="p-2 tabular-nums">{fa(r.website + r.instagram + r.whatsapp)}</td>
            <td className="p-2 tabular-nums">{fa(r.banner)}</td>
            <td className="p-2 tabular-nums">{fa(r.searches)}</td>
          </tr>
        ))}
      </tbody>
    </table>
  </div>;
}

function LogsPanel({ filters, onFiltersChange }: { filters: AnalyticsLogFilters; onFiltersChange: (f: AnalyticsLogFilters) => void }) {
  const [items, setItems] = useState<AnalyticsLogRow[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [q, setQ] = useState('');

  const load = useCallback(async (p: number) => {
    setLoading(true); setError('');
    try {
      const d = await fetchAnalyticsLogs({ ...filters, page: p, per_page: 25 });
      setItems(d.items || []); setPage(d.pagination.page); setTotalPages(d.pagination.total_pages); setTotal(d.pagination.total);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'خطا در دریافت لاگ‌ها');
    } finally { setLoading(false); }
  }, [filters]);

  useEffect(() => { void load(1); }, [load]);

  return <div className="space-y-3">
    <div className="flex flex-wrap items-end gap-2">
      <label className="text-xs text-slate-600">نوع رویداد
        <select className={`${inputCls} mt-0.5 w-36`} value={filters.event_type ?? ''} onChange={e => onFiltersChange({ ...filters, event_type: e.target.value || undefined })}>
          <option value="">همه</option>
          {EVENT_TYPES.map(t => <option key={t} value={t}>{EVENT_LABELS[t]}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-600">نوع هدف
        <select className={`${inputCls} mt-0.5 w-36`} value={filters.target_type ?? ''} onChange={e => onFiltersChange({ ...filters, target_type: e.target.value || undefined, target_id: undefined })}>
          <option value="">همه</option>
          {TARGET_TYPES.map(t => <option key={t} value={t}>{TARGET_LABELS[t]}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-600">منبع ورودی
        <select className={`${inputCls} mt-0.5 w-36`} value={filters.ref_source ?? ''} onChange={e => onFiltersChange({ ...filters, ref_source: e.target.value || undefined })}>
          <option value="">همه</option>
          {SOURCE_TYPES.map(t => <option key={t} value={t}>{SOURCE_LABELS[t]}</option>)}
        </select>
      </label>
      <label className="text-xs text-slate-600">جستجو (عنوان/IP/عبارت)
        <div className="flex gap-1 mt-0.5">
          <input className={`${inputCls} w-52`} value={q} onChange={e => setQ(e.target.value)} placeholder="مثلاً نام کسب‌وکار یا IP" />
          <button type="button" className={btnPrimary} onClick={() => onFiltersChange({ ...filters, q: q.trim() || undefined })}><Search className="h-4 w-4" /> بگرد</button>
        </div>
      </label>
      <a className={`${btn} border border-emerald-300 bg-emerald-50 text-emerald-800`} href={analyticsExportUrl('logs', filters)}>
        <Download className="h-4 w-4" /> خروجی اکسل لاگ‌ها
      </a>
    </div>
    {error && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
    <div className="overflow-x-auto rounded-xl border border-slate-200">
      <table className="w-full text-xs">
        <thead className="bg-slate-50 text-slate-500">
          <tr><th className="p-2 text-right">زمان</th><th className="p-2">رویداد</th><th className="p-2 text-right">هدف</th><th className="p-2">IP</th><th className="p-2">منبع</th><th className="p-2">دستگاه/مرورگر</th><th className="p-2">UTM</th><th className="p-2 text-right">عبارت جستجو</th></tr>
        </thead>
        <tbody>
          {loading && <tr><td colSpan={8} className="p-6 text-center text-slate-500">در حال دریافت…</td></tr>}
          {!loading && !items.length && <tr><td colSpan={8} className="p-6 text-center text-slate-500">لاگی در این بازه و فیلترها نیست؛ اگر صفحه تازه نصب شده، پس از بازدید واقعی سایت آمار اینجا ثبت می‌شود.</td></tr>}
          {items.map(r => (
            <tr key={r.id} className="border-t border-slate-100">
              <td className="p-2 text-right tabular-nums text-slate-600" dir="ltr">{r.occurred_at}</td>
              <td className="p-2 text-center"><span className="rounded bg-blue-50 px-2 py-0.5 font-semibold text-blue-800">{EVENT_LABELS[r.event_type] || r.event_type}</span></td>
              <td className="p-2 text-right">
                {r.target_type !== 'none' && <span className="font-semibold text-slate-800">{r.target_title || `#${r.target_id}`}</span>}
                <span className="text-slate-400"> {r.path && r.target_type !== 'none' ? `· ${r.path}` : r.path}</span>
              </td>
              <td className="p-2 text-center tabular-nums" dir="ltr">{r.ip_address}</td>
              <td className="p-2 text-center">{SOURCE_LABELS[r.ref_source] || r.ref_source}{r.ref_host ? <span className="block text-slate-400" dir="ltr">{r.ref_host}</span> : null}</td>
              <td className="p-2 text-center">{r.device === 'mobile' ? 'موبایل' : r.device === 'tablet' ? 'تبلت' : 'دسکتاپ'} · {r.browser}</td>
              <td className="p-2 text-center text-slate-500" dir="ltr">{[r.utm_source && `s=${r.utm_source}`, r.utm_medium && `m=${r.utm_medium}`, r.utm_campaign && `c=${r.utm_campaign}`].filter(Boolean).join(' ') || '—'}</td>
              <td className="p-2 text-right">{r.search_query || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    <div className="flex items-center justify-between text-sm text-slate-600">
      <span>مجموع: {fa(total)} ردیف · صفحه {fa(page)} از {fa(totalPages)}</span>
      <div className="flex gap-2">
        <button type="button" className={btnGhost} disabled={page <= 1 || loading} onClick={() => void load(page - 1)}>قبلی</button>
        <button type="button" className={btnGhost} disabled={page >= totalPages || loading} onClick={() => void load(page + 1)}>بعدی</button>
      </div>
    </div>
  </div>;
}

export const StatsView: React.FC<{ csrfToken: string }> = ({ csrfToken: _csrf }) => {
  const [range, setRange] = useState<Range>({ days: 7 });
  const [data, setData] = useState<AnalyticsDashboard | null>(null);
  const [topType, setTopType] = useState('business');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [logFilters, setLogFilters] = useState<AnalyticsLogFilters>({});

  const load = useCallback(async (r: Range) => {
    setLoading(true); setError('');
    try {
      const d = await fetchAnalyticsDashboard(r.days ? { days: r.days } : { from: r.from, to: r.to });
      setData(d);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'خطا در دریافت آمار');
    } finally { setLoading(false); }
  }, []);

  useEffect(() => { void load(range); }, [range, load]);

  const topRows = useMemo(() => data?.tops?.[topType] ?? [], [data, topType]);
  const exportFilters: AnalyticsLogFilters = useMemo(() => ({
    ...(range.days ? { days: range.days } : { from: range.from, to: range.to }),
    ...logFilters,
  }), [range, logFilters]);

  return <div className="mx-auto max-w-6xl space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div><h1 className="text-xl font-bold">آمار بازدید و کلیک</h1>
        <p className="mt-1 text-xs text-slate-500">تجمیع ماندگار روزانه + لاگ جزئیات رویدادها (IP، منبع، دستگاه) — ربات‌ها فیلتر می‌شوند. بازدیدکننده یکتا بر اساس هش ناشناس روزانه است.</p></div>
      <button type="button" className={btnPrimary} onClick={() => void load(range)} disabled={loading}><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} /> بازخوانی</button>
    </div>

    <section className="rounded-xl border border-slate-200 bg-white p-4"><RangeBar range={range} onChange={setRange} /></section>
    {error && <p role="alert" className="rounded bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}

    <SummaryCards totals={data?.totals ?? null} loading={loading} />

    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-3 font-bold">روند روزانه</h2>
      <DailyBars series={data?.series ?? []} />
    </section>

    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-bold">برترین‌ها</h2>
        <div className="flex gap-1">
          {TARGET_TYPES.map(t => (
            <button key={t} type="button" onClick={() => setTopType(t)}
              className={`${btn} !px-2.5 !py-1.5 text-xs ${topType === t ? 'bg-blue-700 text-white' : 'border border-slate-300 bg-white text-slate-600'}`}>{TARGET_LABELS[t]}</button>
          ))}
        </div>
      </div>
      <TopTable rows={topRows} type={topType} loading={loading} />
      <div className="mt-3">
        <a className={`${btn} border border-emerald-300 bg-emerald-50 text-emerald-800`} href={analyticsExportUrl('aggregate', exportFilters)}>
          <Download className="h-4 w-4" /> خروجی اکسل گزارش تجمیعی (برای مشتری)
        </a>
      </div>
    </section>

    <section className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-3 font-bold">لاگ جزئیات و ریز رویدادها</h2>
      <LogsPanel filters={logFilters} onFiltersChange={setLogFilters} />
    </section>
  </div>;
};
