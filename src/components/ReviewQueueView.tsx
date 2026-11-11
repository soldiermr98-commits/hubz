import React, { useCallback, useEffect, useState } from 'react';
import {
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Play,
  RefreshCw,
  CheckSquare,
  Square,
  GitCompare,
  Sparkles,
  Keyboard,
} from 'lucide-react';
import {
  ChangeLogItem,
  ReviewQueueItem,
  decideReviewQueueItems,
  fetchReviewQueueState,
} from '../services/adminApi';

interface Props {
  csrfToken: string;
  onRefreshParent: () => Promise<void>;
  isAdmin: boolean;
}

const KIND_TABS: Array<{ key: string; label: string }> = [
  { key: 'all', label: 'همه موارد' },
  { key: 'business', label: 'کسب‌وکارهای مشکوک / تناقض منبع' },
  { key: 'change', label: 'تغییرات شماره و آدرس' },
  { key: 'duplicate', label: 'تکراری‌های مرزی' },
  { key: 'article', label: 'تغییرات مقالهٔ ارسال‌شده برای بررسی' },
  { key: 'claim', label: 'ادعای مالکیت' },
  { key: 'report', label: 'گزارش خطای کاربران' },
];

const REASON_LABELS: Record<string, { label: string; badgeClass: string }> = {
  manual_review: { label: 'در انتظار بررسی دستی', badgeClass: 'bg-blue-50 text-blue-800 border-blue-200' },
  missing_valid_phone: {
    label: 'بدون شماره تلفن معتبر (+98)',
    badgeClass: 'bg-amber-50 text-amber-800 border-amber-200',
  },
  missing_address_or_coords: {
    label: 'فاقد آدرس یا مختصات جغرافیایی',
    badgeClass: 'bg-amber-50 text-amber-800 border-amber-200',
  },
  low_bayesian_score: {
    label: 'امتیاز بیزی پایین‌تر از آستانه (۳.۸۵)',
    badgeClass: 'bg-amber-50 text-amber-800 border-amber-200',
  },
  permanently_closed: {
    label: 'علامت‌خورده به‌عنوان دائماً بسته',
    badgeClass: 'bg-rose-50 text-rose-800 border-rose-200',
  },
  source_conflict: {
    label: 'تناقض منبع (فاصله ۲ منبع ≥ ۱۰۰ متر)',
    badgeClass: 'bg-rose-50 text-rose-800 border-rose-200',
  },
  phone_changed: {
    label: 'تغییر شماره تماس در منبع جدید',
    badgeClass: 'bg-sky-50 text-sky-800 border-sky-200',
  },
  address_changed: {
    label: 'تغییر آدرس در منبع جدید',
    badgeClass: 'bg-sky-50 text-sky-800 border-sky-200',
  },
  city_mode_manual: {
    label: 'شهر در حالت انتشار دستی (manual)',
    badgeClass: 'bg-slate-100 text-slate-700 border-slate-300',
  },
  single_source_ready: {
    label: 'تک‌منبعی معتبر — آماده تأیید و انتشار (امتیاز بیزی ≥ ۳.۸۵)',
    badgeClass: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  },
  bayes_score_above_threshold: {
    label: 'انتشار خودکار (امتیاز بیزی ≥ ۳.۸۵ + تلفن و لوکیشن معتبر)',
    badgeClass: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  },
  multi_source_verified: {
    label: 'انتشار خودکار چندمنبعی (تأیید حداقل ۲ منبع مستقل)',
    badgeClass: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  },
  legacy_revalidation: { label: 'محتوای قدیمی نیازمند بازبینی مجدد', badgeClass: 'bg-amber-50 text-amber-800 border-amber-200' },
  potential_duplicate: {
    label: 'احتمال تکراری با کسب‌وکار موجود در شهر',
    badgeClass: 'bg-amber-50 text-amber-900 border-amber-300',
  },
  owner_claim_request: {
    label: 'درخواست ادعای مالکیت کسب‌وکار (مرحله S6)',
    badgeClass: 'bg-blue-50 text-blue-800 border-blue-200',
  },
  user_error_report: {
    label: 'گزارش خطای اطلاعات توسط کاربر (مرحله S6)',
    badgeClass: 'bg-purple-50 text-purple-800 border-purple-200',
  },
};

export const ReviewQueueView: React.FC<Props> = ({ csrfToken, onRefreshParent, isAdmin }) => {
  const [items, setItems] = useState<ReviewQueueItem[]>([]);
  const [changeLogs, setChangeLogs] = useState<ChangeLogItem[]>([]);
  const [pendingCounts, setPendingCounts] = useState<Record<string, number>>({ all: 0 });
  const [statusFilter, setStatusFilter] = useState<'pending' | 'approved' | 'rejected'>('pending');
  const [kindFilter, setKindFilter] = useState<string>('all');
  const [queueSearchInput, setQueueSearchInput] = useState('');
  const [queueSearch, setQueueSearch] = useState('');
  const [activeSubView, setActiveSubView] = useState<'queue' | 'changelog'>('queue');

  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [focusedIndex, setFocusedIndex] = useState<number>(0);
  const [loading, setLoading] = useState<boolean>(false);
  const [feedback, setFeedback] = useState<{ type: 'ok' | 'err' | null; text: string }>({
    type: null,
    text: '',
  });

  const loadQueue = useCallback(async () => {
    setLoading(true);
    try {
      const data = await fetchReviewQueueState(statusFilter, kindFilter, queueSearch);
      setItems(data.items); setChangeLogs(data.change_logs); setPendingCounts(data.pending_counts);
    } catch (e) { setFeedback({type:'err',text:e instanceof Error ? e.message : 'خطا در دریافت صف'}); }
    setSelectedIds([]);
    setFocusedIndex(0);
    setLoading(false);
  }, [statusFilter, kindFilter, queueSearch]);

  useEffect(() => {
    const timer=window.setTimeout(()=>setQueueSearch(queueSearchInput.trim()),250);
    return ()=>window.clearTimeout(timer);
  }, [queueSearchInput]);

  useEffect(() => {
    loadQueue();
  }, [loadQueue]);

  const handleDecision = useCallback(
    async (ids: number[], decision: 'approved' | 'rejected' | 'merge', mergeIntoId?: number) => {
      if (ids.length === 0 || !isAdmin) return;
      if (decision === 'approved' && items.some(item=>item.kind==='article' && ids.includes(item.id)) && !window.confirm('پیش‌نمایش خصوصی مقاله را بررسی کرده‌اید؟ با تأیید، نسخه در سایت منتشر می‌شود.')) return;
      if (decision === 'merge' && mergeIntoId && !window.confirm(`آیا از ادغام این رکورد تکراری با کسب‌وکار اصلی #${mergeIntoId} و انتقال منابع آن اطمینان دارید؟`)) return;
      const res = await decideReviewQueueItems(ids, decision, csrfToken, mergeIntoId);
      if (!res.ok) {
        setFeedback({ type: 'err', text: res.error || 'خطا در ثبت تصمیم بازبینی' });
        return;
      }
      setFeedback({ type: 'ok', text: res.message });
      await loadQueue();
      await onRefreshParent();
    },
    [csrfToken, isAdmin, items, loadQueue, onRefreshParent]
  );

  // میانبرهای کیبورد برای سرعت بالای مدیر (J/K حرکت بین ردیف‌ها، A تأیید، R رد، Space انتخاب)
  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      const tag = (e.target as HTMLElement)?.tagName?.toLowerCase();
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
      if (activeSubView !== 'queue' || items.length === 0) return;

      if (e.key === 'j' || e.key === 'J' || e.key === 'ArrowDown') {
        e.preventDefault();
        setFocusedIndex((prev) => Math.min(items.length - 1, prev + 1));
      } else if (e.key === 'k' || e.key === 'K' || e.key === 'ArrowUp') {
        e.preventDefault();
        setFocusedIndex((prev) => Math.max(0, prev - 1));
      } else if (e.key === ' ') {
        e.preventDefault();
        const current = items[focusedIndex];
        if (!current) return;
        setSelectedIds((prev) =>
          prev.includes(current.id) ? prev.filter((id) => id !== current.id) : [...prev, current.id]
        );
      } else if ((e.key === 'a' || e.key === 'A') && statusFilter === 'pending') {
        e.preventDefault();
        const current = items[focusedIndex];
        if (current) {
          handleDecision([current.id], 'approved');
        }
      } else if ((e.key === 'r' || e.key === 'R') && statusFilter === 'pending') {
        e.preventDefault();
        const current = items[focusedIndex];
        if (current) {
          handleDecision([current.id], 'rejected');
        }
      }
    };

    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [activeSubView, items, focusedIndex, statusFilter, handleDecision]);

  const toggleSelectAll = () => {
    if (selectedIds.length === items.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(items.map((i) => i.id));
    }
  };

  const toggleSelectOne = (id: number) => {
    setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  };

  return (
    <div className="space-y-6">
      {/* هدر صندوق بازبینی و کارت اجرای بنچمارک ۱۰۰ رکورد مرحله S3 */}
      <div className="bg-white border border-slate-200 rounded-xl p-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-lg font-bold text-slate-900">
              صندوق بازبینی متمرکز و پایپ‌لاین هوشمند (مرحله S3)
            </h1>
            <p className="text-xs text-slate-600 mt-1">
              اطلاعات جمع‌آوری‌شده قبل از انتشار به بررسی دستی نیاز دارند. منبع و اطلاعات ناقص را بررسی کنید.
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2.5">
            <button
              type="button"
              onClick={loadQueue}
              className="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg"
            >
              <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
              بروزرسانی
            </button>
          </div>
        </div>

      </div>

      {feedback.type && (
        <div
          className={`p-4 rounded-lg border text-xs font-semibold flex items-center justify-between ${
            feedback.type === 'ok'
              ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
              : 'bg-rose-50 border-rose-200 text-rose-800'
          }`}
        >
          <div className="flex items-center gap-2">
            {feedback.type === 'ok' ? (
              <CheckCircle2 className="w-4 h-4 text-emerald-700 shrink-0" />
            ) : (
              <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            )}
            <span>{feedback.text}</span>
          </div>
          <button
            type="button"
            onClick={() => setFeedback({ type: null, text: '' })}
            className="underline"
          >
            بستن
          </button>
        </div>
      )}

      {/* سوئیچ بین صندوق بازبینی و جدول تاریخچه تغییرات (change_log) */}
      <div className="bg-white border border-slate-200 rounded-xl p-6 space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-slate-200">
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={() => setActiveSubView('queue')}
              className={`px-4 py-2 rounded-lg text-xs font-semibold transition-colors ${
                activeSubView === 'queue'
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
              }`}
            >
              صندوق بازبینی ({pendingCounts.all || 0} در انتظار)
            </button>
            <button
              type="button"
              onClick={() => setActiveSubView('changelog')}
              className={`inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-xs font-semibold transition-colors ${
                activeSubView === 'changelog'
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
              }`}
            >
              <GitCompare className="w-3.5 h-3.5" />
              تاریخچه تغییرات آدرس و تلفن (change_log: {changeLogs.length})
            </button>
          </div>

          {isAdmin && <div className="flex items-center gap-2 text-[11px] text-slate-500 bg-slate-50 px-3 py-1.5 rounded-lg border border-slate-200">
            <Keyboard className="w-3.5 h-3.5 text-slate-600" />
            <span>
              میانبر کیبورد: <kbd className="font-mono font-bold">J/K</kbd> حرکت ·{' '}
              <kbd className="font-mono font-bold">Space</kbd> انتخاب ·{' '}
              <kbd className="font-mono font-bold text-emerald-700">A</kbd> تأیید سریع ·{' '}
              <kbd className="font-mono font-bold text-rose-700">R</kbd> رد سریع
            </span>
          </div>}
        </div>

        {activeSubView === 'queue' ? (
          <>
            {/* فیلتر نوع آیتم و وضعیت + دکمه‌های عملیات گروهی (Bulk Actions) */}
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="flex flex-wrap items-center gap-1.5">
                {KIND_TABS.map((t) => {
                  const cnt = pendingCounts[t.key] ?? 0;
                  const active = kindFilter === t.key;
                  return (
                    <button
                      key={t.key}
                      type="button"
                      onClick={() => setKindFilter(t.key)}
                      className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                        active
                          ? 'bg-emerald-700 text-white font-semibold'
                          : 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                      }`}
                    >
                      {t.label}{' '}
                      <span className="font-mono text-[11px] opacity-85">({cnt})</span>
                    </button>
                  );
                })}
              </div>

              <div className="flex items-center gap-2">
                <select
                  value={statusFilter}
                  onChange={(e) =>
                    setStatusFilter(e.target.value as 'pending' | 'approved' | 'rejected')
                  }
                  className="px-3 py-1.5 text-xs bg-white border border-slate-300 rounded-lg"
                >
                  <option value="pending">وضعیت: در انتظار بررسی (pending)</option>
                  <option value="approved">وضعیت: تأییدشده (approved)</option>
                  <option value="rejected">وضعیت: ردشده (rejected)</option>
                </select>

                {isAdmin && selectedIds.length > 0 && statusFilter === 'pending' && (
                  <div className="flex items-center gap-2">
                    <button
                      type="button"
                      onClick={() => handleDecision(selectedIds, 'approved')}
                      className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold rounded-lg"
                    >
                      <CheckCircle2 className="w-3.5 h-3.5" />
                      تأیید گروهی ({selectedIds.length} مورد)
                    </button>
                    <button
                      type="button"
                      onClick={() => handleDecision(selectedIds, 'rejected')}
                      className="inline-flex items-center gap-1.5 px-3.5 py-1.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold rounded-lg"
                    >
                      <XCircle className="w-3.5 h-3.5" />
                      رد گروهی ({selectedIds.length} مورد)
                    </button>
                  </div>
                )}
              </div>
            </div>
            <div className="rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-xs leading-6 text-blue-900">
              پیش‌نویس‌ها در این صندوق نیستند. پیش‌نویس کسب‌وکار را در «کسب‌وکارها» با فیلتر وضعیت پیدا کنید و برای تأیید به صندوق ارسال کنید؛ مقالهٔ پیش‌نویس هم در «مقاله‌ها» است. این صندوق فقط موارد ارسال‌شده برای بازبینی را نشان می‌دهد.
            </div>
            <label className="block">
              <span className="sr-only">جست‌وجو در صندوق بازبینی</span>
              <input type="search" aria-label="جست‌وجو در صندوق بازبینی" value={queueSearchInput} onChange={e=>setQueueSearchInput(e.target.value)} className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm" placeholder="جست‌وجو با نام کسب‌وکار، شناسه، دلیل یا جزئیات…"/>
            </label>

            {/* جدول آیتم‌های صندوق بازبینی */}
            <div className="overflow-x-auto border border-slate-200 rounded-lg">
              <table className="w-full text-xs border-collapse">
                <thead>
                  <tr className="bg-slate-50 border-b border-slate-200 text-slate-600">
                    <th className="py-3 px-3 text-right w-10">
                      <button
                        type="button"
                        onClick={toggleSelectAll}
                        className="text-slate-500 hover:text-slate-800"
                        title="انتخاب همه"
                      >
                        {items.length > 0 && selectedIds.length === items.length ? (
                          <CheckSquare className="w-4 h-4 text-emerald-700" />
                        ) : (
                          <Square className="w-4 h-4" />
                        )}
                      </button>
                    </th>
                    <th className="py-3 px-3 text-right font-semibold">شناسه / نوع</th>
                    <th className="py-3 px-3 text-right font-semibold">کسب‌وکار / موجودیت هدف</th>
                    <th className="py-3 px-3 text-right font-semibold">علت ارجاع به صندوق بازبینی</th>
                    <th className="py-3 px-3 text-right font-semibold">جزئیات فنی و مقایسه مقادیر</th>
                    <th className="py-3 px-3 text-right font-semibold">امتیاز بیزی و Tier</th>
                    <th className="py-3 px-3 text-left font-semibold">تصمیم سریع</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {items.length === 0 ? (
                    <tr>
                      <td colSpan={7} className="py-10 text-center text-slate-500">
                        {queueSearch ? `موردی برای «${queueSearch}» با فیلترهای انتخاب‌شده پیدا نشد.` : 'هیچ موردی با این فیلتر در صندوق بازبینی وجود ندارد. پیش‌نویس‌ها تا وقتی برای بررسی ارسال نشوند اینجا دیده نمی‌شوند.'}
                      </td>
                    </tr>
                  ) : (
                    items.map((item, idx) => {
                      const isSelected = selectedIds.includes(item.id);
                      const isFocused = idx === focusedIndex;
                      let payload: Record<string, any> = {};
                      try {
                        const raw = item.payload_json;
                        const parsed = typeof raw === 'string' ? JSON.parse(raw || '{}') : raw;
                        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                          payload = parsed;
                        }
                      } catch {
                        payload = {};
                      }

                      const reasonMeta = REASON_LABELS[item.reason_code] || {
                        label: item.reason_code,
                        badgeClass: 'bg-slate-100 text-slate-700 border-slate-300',
                      };

                      const title =
                        item.business_name || payload.business_name || payload.title || payload.name || `#${item.target_id}`;

                      return (
                        <tr
                          key={item.id}
                          onClick={() => setFocusedIndex(idx)}
                          className={`transition-colors ${
                            isFocused ? 'bg-emerald-50/40' : 'hover:bg-slate-50'
                          }`}
                        >
                          <td className="py-3 px-3">
                            <button
                              type="button"
                              onClick={(e) => {
                                e.stopPropagation();
                                toggleSelectOne(item.id);
                              }}
                              className="text-slate-500 hover:text-slate-900"
                            >
                              {isSelected ? (
                                <CheckSquare className="w-4 h-4 text-emerald-700" />
                              ) : (
                                <Square className="w-4 h-4" />
                              )}
                            </button>
                          </td>
                          <td className="py-3 px-3 font-mono tabular-nums text-slate-700">
                            <div>#{item.id}</div>
                            <div className="text-[11px] text-slate-500">{item.kind}</div>
                          </td>
                          <td className="py-3 px-3">
                            <div className="font-bold text-slate-900">{title}</div>
                            <div className="text-[11px] text-slate-500 mt-0.5">
                              {item.city_name ? `شهر: ${item.city_name}` : ''}{' '}
                              {item.category_name ? `· دسته: ${item.category_name}` : ''}{' '}
                              {payload.provider ? `· منبع: ${payload.provider}` : ''}
                            </div>
                            {item.kind === 'article' && <a className="text-blue-700 underline text-xs" target="_blank" rel="noreferrer" href={`/api/v1/articles/preview/${item.target_id}`}>مشاهده پیش‌نمایش خصوصی مقاله</a>}
                            {typeof payload.source_url === 'string' && /^https:\/\/www\.openstreetmap\.org\/(node|way|relation)\/\d+$/.test(payload.source_url) && <a className="text-blue-700 underline text-xs" target="_blank" rel="noreferrer" href={payload.source_url}>مشاهده منبع OpenStreetMap</a>}
                            {Array.isArray(payload.raw_phones) && payload.raw_phones.length > 0 && <div className="text-xs text-slate-500">شماره در منبع: {payload.raw_phones.join('، ')}</div>}
                          </td>
                          <td className="py-3 px-3">
                            <span
                              className={`inline-block px-2.5 py-1 rounded border text-[11px] font-semibold ${reasonMeta.badgeClass}`}
                            >
                              {reasonMeta.label}
                            </span>
                          </td>
                          <td className="py-3 px-3 text-[11px] text-slate-700 max-w-sm">
                            {item.reason_code === 'source_conflict' && (
                              <div className="space-y-1">
                                <div className="font-semibold text-rose-700">
                                  اختلاف فاصله مکانی: {payload.distance_meters ?? 'نامشخص'} متر (≥ ۱۰۰ متر)
                                </div>
                                {payload.old_address && (
                                  <div className="text-slate-500">قبلی: {payload.old_address}</div>
                                )}
                                {payload.new_address && (
                                  <div className="text-slate-800">جدید: {payload.new_address}</div>
                                )}
                              </div>
                            )}
                            {item.kind === 'change' && (
                              <div className="space-y-1">
                                <div>
                                  فیلد تغییریافته:{' '}
                                  <strong className="font-mono">{payload.field}</strong>
                                </div>
                                <div className="text-rose-700 line-through" dir="ltr">
                                  {payload.old_value}
                                </div>
                                <div className="text-emerald-700 font-semibold" dir="ltr">
                                  {payload.new_value}
                                </div>
                              </div>
                            )}
                            {item.kind === 'claim' && (
                              <div className="space-y-1">
                                <div className="font-semibold text-blue-900">
                                  متقاضی مالکیت: {payload.claimant_name || '—'}
                                </div>
                                <div className="font-mono text-emerald-700" dir="ltr">
                                  تلفن احراز: {payload.claimant_phone || '—'}
                                </div>
                                {payload.note && (
                                  <div className="text-slate-600">توضیح: {payload.note}</div>
                                )}
                              </div>
                            )}
                            {item.kind === 'report' && (
                              <div className="space-y-1">
                                <div className="font-semibold text-purple-900">پیام گزارش کاربر:</div>
                                <div className="text-slate-700 bg-purple-50/60 border border-purple-100 rounded p-1.5">
                                  {payload.report_message || '—'}
                                </div>
                              </div>
                            )}
                            {(() => {
                              const dupCands = Array.isArray(payload.candidates)
                                ? payload.candidates
                                : Array.isArray(payload.duplicate_candidates)
                                ? payload.duplicate_candidates
                                : [];
                              if (dupCands.length === 0) return null;
                              return (
                                <div className="space-y-1.5 mb-1.5">
                                  {dupCands.map((cand: any, cIdx: number) => (
                                    <div key={cand.id || cIdx} className="rounded border border-amber-200 bg-amber-50/80 px-2 py-1.5 text-amber-950">
                                      <div className="font-semibold">
                                        مشابه با #{cand.id} «{cand.name}»
                                        {cand.category_name ? ` (${cand.category_name})` : ''}
                                      </div>
                                      <div className="text-[10px] text-amber-800 mt-0.5 flex flex-wrap items-center justify-between gap-1.5">
                                        <span>
                                          {cand.distance_meters !== null && cand.distance_meters !== undefined
                                            ? `فاصله: ${cand.distance_meters} متر`
                                            : 'بدون مختصات دقیق'}
                                          {cand.shared_phone ? ' · تلفن مشترک' : ''}
                                          {cand.same_core_name ? ' · نام هسته یکسان' : ''}
                                        </span>
                                        {item.status === 'pending' && isAdmin && Number(cand.id) > 0 && (
                                          <button
                                            type="button"
                                            onClick={(e) => {
                                              e.stopPropagation();
                                              handleDecision([item.id], 'merge', Number(cand.id));
                                            }}
                                            className="px-2 py-0.5 bg-amber-700 hover:bg-amber-800 text-white rounded text-[10px] font-semibold"
                                          >
                                            ادغام در #{cand.id}
                                          </button>
                                        )}
                                      </div>
                                    </div>
                                  ))}
                                </div>
                              );
                            })()}
                            {item.reason_code !== 'source_conflict' &&
                              item.kind !== 'change' &&
                              item.kind !== 'claim' &&
                              item.kind !== 'report' && item.kind !== 'article' && (
                                <div>
                                  {item.business_address || payload.address || 'بدون آدرس ثبت‌شده'}
                                </div>
                              )}
                          </td>
                          <td className="py-3 px-3 font-mono tabular-nums">
                            {item.business_score || payload.score ? (
                              <div>
                                <span className="font-bold text-slate-900">
                                  {Number(item.business_score ?? payload.score).toFixed(2)}
                                </span>{' '}
                                <span className="text-[11px] text-slate-500">
                                  (Tier {item.business_tier || 'C'} ·{' '}
                                  {item.business_confidence || 'low'})
                                </span>
                              </div>
                            ) : (
                              <span className="text-slate-400">—</span>
                            )}
                          </td>
                          <td className="py-3 px-3 text-left whitespace-nowrap">
                            {item.status === 'pending' && !isAdmin ? <span className="text-slate-500">در انتظار مدیر</span> : item.status === 'pending' ? (
                              <div className="inline-flex items-center gap-1.5">
                                {item.kind === 'duplicate' ? (
                                  <>
                                    <button
                                      type="button"
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        handleDecision([item.id], 'merge', Number(payload.primary_candidate_id || 0));
                                      }}
                                      className="px-2.5 py-1.5 bg-amber-700 hover:bg-amber-800 text-white rounded text-[11px] font-semibold"
                                      title="ادغام در کسب‌وکار اصلی و انتقال منابع"
                                    >
                                      ادغام با کسب‌وکار اصلی
                                    </button>
                                    <button
                                      type="button"
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        handleDecision([item.id], 'rejected');
                                      }}
                                      className="px-2.5 py-1.5 bg-slate-100 hover:bg-emerald-50 hover:text-emerald-800 text-slate-700 rounded text-[11px] font-semibold"
                                      title="رد هشدار تکراری (کسب‌وکار مجزاست)"
                                    >
                                      مجزا است (رد هشدار)
                                    </button>
                                  </>
                                ) : (
                                  <>
                                    <button
                                      type="button"
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        handleDecision([item.id], 'approved');
                                      }}
                                      className="px-2.5 py-1.5 bg-emerald-700 hover:bg-emerald-800 text-white rounded text-[11px] font-semibold"
                                      title="میانبر کیبورد: A"
                                    >
                                      تأیید و انتشار (A)
                                    </button>
                                    <button
                                      type="button"
                                      onClick={(e) => {
                                        e.stopPropagation();
                                        handleDecision([item.id], 'rejected');
                                      }}
                                      className="px-2.5 py-1.5 bg-slate-100 hover:bg-rose-50 hover:text-rose-700 text-slate-700 rounded text-[11px] font-semibold"
                                      title="میانبر کیبورد: R"
                                    >
                                      رد (R)
                                    </button>
                                  </>
                                )}
                              </div>
                            ) : (
                              <span
                                className={`font-semibold ${
                                  item.status === 'approved' ? 'text-emerald-700' : 'text-rose-700'
                                }`}
                              >
                                {item.status === 'approved' ? 'تأییدشده' : 'ردشده'}
                              </span>
                            )}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </>
        ) : (
          /* جدول تاریخچه تغییرات شماره و آدرس (change_log) */
          <div className="overflow-x-auto border border-slate-200 rounded-lg">
            <table className="w-full text-xs border-collapse">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-slate-600">
                  <th className="py-3 px-4 text-right font-semibold">شناسه</th>
                  <th className="py-3 px-4 text-right font-semibold">کسب‌وکار</th>
                  <th className="py-3 px-4 text-right font-semibold">فیلد</th>
                  <th className="py-3 px-4 text-right font-semibold">مقدار قبلی</th>
                  <th className="py-3 px-4 text-right font-semibold">مقدار جدید</th>
                  <th className="py-3 px-4 text-right font-semibold">منبع</th>
                  <th className="py-3 px-4 text-right font-semibold">وضعیت اعمال</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {changeLogs.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-8 text-center text-slate-500">
                      هنوز تغییری در جدول change_log ثبت نشده است.
                    </td>
                  </tr>
                ) : (
                  changeLogs.map((cl) => (
                    <tr key={cl.id} className="hover:bg-slate-50">
                      <td className="py-3 px-4 font-mono tabular-nums">#{cl.id}</td>
                      <td className="py-3 px-4 font-bold text-slate-900">
                        {cl.business_name || `کسب‌وکار #${cl.business_id}`}
                      </td>
                      <td className="py-3 px-4 font-mono">{cl.field}</td>
                      <td className="py-3 px-4 text-rose-700 line-through">{cl.old_value}</td>
                      <td className="py-3 px-4 text-emerald-700 font-semibold">{cl.new_value}</td>
                      <td className="py-3 px-4 font-mono">{cl.source}</td>
                      <td className="py-3 px-4">
                        {cl.status === 'auto_applied' && (
                          <span className="text-emerald-700 font-semibold">
                            اعمال خودکار (توافق ≥۲ منبع مستقل)
                          </span>
                        )}
                        {cl.status === 'pending' && (
                          <span className="text-amber-700 font-semibold">
                            در انتظار تأیید در صندوق بازبینی
                          </span>
                        )}
                        {cl.status === 'approved' && (
                          <span className="text-emerald-700 font-semibold">تأییدشده توسط مدیر</span>
                        )}
                        {cl.status === 'rejected' && (
                          <span className="text-rose-700 font-semibold">ردشده</span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};
