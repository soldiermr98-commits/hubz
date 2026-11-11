import React, { useCallback, useEffect, useState } from 'react';
import { LoaderCircle, MessageSquareText, RefreshCw, Search } from 'lucide-react';
import { CommentItem, CommentListResponse, fetchComments, moderateComment } from '../services/adminApi';

const STATUS_TABS: Array<{ id: 'pending' | 'approved' | 'rejected' | 'all'; label: string }> = [
  { id: 'pending', label: 'در انتظار تایید' },
  { id: 'approved', label: 'تاییدشده' },
  { id: 'rejected', label: 'ردشده' },
  { id: 'all', label: 'همه' },
];

function stars(n: number): string {
  return '★'.repeat(Math.max(1, Math.min(5, n))) + '☆'.repeat(5 - Math.max(1, Math.min(5, n)));
}

function faDate(v: string): string {
  return (v || '').slice(0, 16).replace('T', ' ');
}

/** S16 — تب مدیریت دیدگاه‌ها: تایید، رد و حذف دیدگاه‌های کاربران */
export function CommentsView({ csrfToken }: { csrfToken: string }) {
  const [status, setStatus] = useState<'pending' | 'approved' | 'rejected' | 'all'>('pending');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CommentListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [msg, setMsg] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetchComments(status, q, page);
      setData(res);
    } catch (e) {
      setMsg(e instanceof Error ? e.message : 'خواندن دیدگاه‌ها ناموفق بود.');
    } finally {
      setLoading(false);
    }
  }, [status, q, page]);

  useEffect(() => { void load(); }, [load]);

  const act = async (id: number, action: 'approve' | 'reject' | 'delete') => {
    const labels: Record<string, string> = { approve: 'تایید این دیدگاه', reject: 'رد این دیدگاه', delete: 'حذف کامل این دیدگاه' };
    if (!window.confirm(`${labels[action]}؟ این عملیات در صفحه عمومی بلافاصله اعمال می‌شود.`)) return;
    setBusyId(id); setMsg('');
    const res = await moderateComment(id, action, csrfToken);
    setMsg(res.ok ? (res.message || 'انجام شد.') : (res.error || 'عملیات ناموفق بود.'));
    setBusyId(null);
    await load();
  };

  const counts = data?.counts;

  return <div className="space-y-4">
    <section className="bg-white border border-slate-200 rounded-xl p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <MessageSquareText className="w-5 h-5 text-blue-700" aria-hidden="true" />
          <h1 className="font-bold text-lg">دیدگاه‌های کسب‌وکارها</h1>
        </div>
        <button onClick={() => void load()} className="flex items-center gap-2 text-sm border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50">
          <RefreshCw className="w-4 h-4" aria-hidden="true" /> بازخوانی
        </button>
      </div>
      <p className="text-xs text-slate-500 mt-2">دیدگاه‌های تازه با وضعیت «در انتظار تایید» می‌آیند؛ پس از تایید، با نشان «تایید حامی‌هاب» در صفحه کسب‌وکار نمایش داده می‌شوند و کش صفحات عمومی خودکار پاک می‌شود.</p>
      <div className="flex flex-wrap items-center gap-2 mt-3">
        {STATUS_TABS.map(t => (
          <button key={t.id} onClick={() => { setStatus(t.id); setPage(1); }}
            className={`rounded-lg px-3 py-1.5 text-sm font-semibold border transition-colors ${status === t.id ? 'bg-blue-700 text-white border-blue-700' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50'}`}>
            {t.label}{counts && t.id !== 'all' ? ` (${counts[t.id]})` : ''}
          </button>
        ))}
        <div className="relative ms-auto">
          <Search className="w-4 h-4 absolute top-2.5 start-2.5 text-slate-400" aria-hidden="true" />
          <input value={q} onChange={e => { setQ(e.target.value); setPage(1); }} placeholder="جستجو در متن، نام کاربر یا کسب‌وکار"
            className="border border-slate-300 rounded-lg ps-8 pe-3 py-2 text-sm w-64" aria-label="جستجوی دیدگاه" />
        </div>
      </div>
    </section>

    {msg && <p role="status" className="bg-blue-50 text-blue-900 border border-blue-200 rounded-lg p-3 text-sm">{msg}</p>}

    {loading ? (
      <div className="p-12 text-center text-slate-500" role="status" aria-live="polite">
        <LoaderCircle className="w-7 h-7 mx-auto mb-3 animate-spin text-blue-700" aria-hidden="true" /> در حال خواندن دیدگاه‌ها…
      </div>
    ) : !data || data.items.length === 0 ? (
      <div className="bg-white border border-slate-200 rounded-xl p-10 text-center text-slate-500 text-sm">
        دیدگاهی در این وضعیت یافت نشد.
      </div>
    ) : (
      <div className="space-y-3">
        {data.items.map((c: CommentItem) => (
          <article key={c.id} className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <strong className="text-slate-900">{c.name}</strong>
                  <span className="text-amber-500 tracking-widest" dir="ltr" aria-label={`امتیاز ${c.rating} از ۵`}>{stars(c.rating)}</span>
                  <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${c.status === 'pending' ? 'bg-amber-50 text-amber-800 border border-amber-200' : c.status === 'approved' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200'}`}>
                    {c.status === 'pending' ? 'در انتظار تایید' : c.status === 'approved' ? 'تاییدشده' : 'ردشده'}
                  </span>
                  <span className="text-xs text-slate-400">{faDate(c.created_at)}</span>
                </div>
                <p className="text-xs text-slate-500 mt-1">
                  کسب‌وکار: <a className="text-blue-700 hover:underline" href={`/${c.city_slug}/${c.business_slug}/`} target="_blank" rel="noopener noreferrer">{c.business_name}</a> · شهر: {c.city_name_fa}
                </p>
                <p className="text-sm text-slate-700 mt-2 leading-7 whitespace-pre-line">{c.body}</p>
                {c.reviewed_at && <p className="text-xs text-slate-400 mt-1">آخرین بررسی: {faDate(c.reviewed_at)}</p>}
              </div>
              <div className="flex flex-wrap gap-2 shrink-0">
                {c.status !== 'approved' && <button disabled={busyId === c.id} onClick={() => void act(c.id, 'approve')} className="rounded-lg bg-emerald-700 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">تایید</button>}
                {c.status !== 'rejected' && <button disabled={busyId === c.id} onClick={() => void act(c.id, 'reject')} className="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100 disabled:opacity-50">رد</button>}
                <button disabled={busyId === c.id} onClick={() => void act(c.id, 'delete')} className="rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50">حذف</button>
              </div>
            </div>
          </article>
        ))}
        {data.total > data.per_page && (
          <div className="flex items-center justify-between bg-white border border-slate-200 rounded-xl p-3 text-sm">
            <span className="text-slate-500">صفحه {data.page} · مجموع {data.total} دیدگاه</span>
            <div className="flex gap-2">
              <button disabled={data.page <= 1} onClick={() => setPage(p => Math.max(1, p - 1))} className="border border-slate-300 rounded-lg px-3 py-1.5 disabled:opacity-40">صفحه قبل</button>
              <button disabled={data.page * data.per_page >= data.total} onClick={() => setPage(p => p + 1)} className="border border-slate-300 rounded-lg px-3 py-1.5 disabled:opacity-40">صفحه بعد</button>
            </div>
          </div>
        )}
      </div>
    )}
  </div>;
}
