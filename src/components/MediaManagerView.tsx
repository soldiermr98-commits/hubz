import React, { useCallback, useEffect, useState } from 'react';
import {
  CheckCircle2,
  Copy,
  HardDrive,
  Image as ImageIcon,
  Info,
  RefreshCw,
  Save,
  Search,
  Trash2,
  Upload,
  AlertTriangle,
} from 'lucide-react';
import {
  MEDIA_DIMENSION_GUIDES,
  MediaLibraryItem,
  MediaLibrarySummary,
  deleteMediaItems,
  fetchMediaLibrary,
  formatBytesFa,
  updateMediaAltText,
  uploadMediaWebp,
} from '../services/adminApi';

interface Props {
  csrfToken: string;
  isAdmin: boolean;
}

export const MediaManagerView: React.FC<Props> = ({ csrfToken, isAdmin }) => {
  const [items, setItems] = useState<MediaLibraryItem[]>([]);
  const [summary, setSummary] = useState<MediaLibrarySummary | null>(null);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [usageFilter, setUsageFilter] = useState<'all' | 'used' | 'unused' | 'missing_files'>('all');
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ error: boolean; text: string } | null>(null);

  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [detachOnDelete, setDetachOnDelete] = useState(false);
  const [forceOnDelete, setForceOnDelete] = useState(false);

  const [uploadAlt, setUploadAlt] = useState('');
  const [uploading, setUploading] = useState(false);

  const [editingAltId, setEditingAltId] = useState<number | null>(null);
  const [editingAltValue, setEditingAltValue] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetchMediaLibrary({
        page,
        per_page: 24,
        usage: usageFilter,
        q: query,
      });
      setItems(res.items);
      setSummary(res.summary);
      setTotal(res.pagination.total);
      setTotalPages(res.pagination.total_pages);
      setSelectedIds((prev) => prev.filter((id) => res.items.some((item) => item.id === id)));
    } catch (e) {
      setMessage({
        error: true,
        text: e instanceof Error ? e.message : 'خطا در دریافت اطلاعات کتابخانه رسانه',
      });
    } finally {
      setLoading(false);
    }
  }, [page, usageFilter, query]);

  useEffect(() => {
    const t = window.setTimeout(() => {
      void load();
    }, 150);
    return () => window.clearTimeout(t);
  }, [load]);

  const handleDirectUpload = async (file?: File) => {
    if (!file) return;
    setUploading(true);
    setMessage(null);
    const res = await uploadMediaWebp(file, uploadAlt, csrfToken);
    setUploading(false);
    if (!res.ok || !res.media) {
      setMessage({ error: true, text: res.error || 'آپلود تصویر ناموفق بود.' });
      return;
    }
    const origBytes = Number(res.media.original_bytes || file.size || 0);
    const isGif = res.media.mime_type === 'image/gif';
    if (isGif) {
      setUploadAlt('');
      setMessage({
        error: false,
        text: `GIF متحرک #${res.media.id} با حفظ کامل انیمیشن در مسیر ${res.media.host_dir || 'public_html/uploads'} ذخیره شد${res.media.gif_optimized ? ' و به‌صورت خودکار بهینه گردید' : ''}.`,
      });
      setPage(1);
      await load();
      return;
    }
    const webp1200 = Number(res.media.webp_1200_bytes || 0);
    const savingsText =
      origBytes > 0 && webp1200 > 0
        ? ` (فایل اصلی ${formatBytesFa(origBytes)} ← نسخه اصلی WebP تنها ${formatBytesFa(webp1200)})`
        : '';
    setUploadAlt('');
    setMessage({
      error: false,
      text: `تصویر #${res.media.id} با موفقیت به ۳ نسخه WebP بهینه تبدیل و در مسیر ${res.media.host_dir || 'public_html/uploads'} ذخیره شد${savingsText}.`,
    });
    setPage(1);
    await load();
  };

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((item) => item !== id) : [...prev, id]
    );
  };

  const selectAllPage = () => {
    const pageIds = items.map((i) => i.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));
    if (allSelected) {
      setSelectedIds((prev) => prev.filter((id) => !pageIds.includes(id)));
    } else {
      setSelectedIds((prev) => Array.from(new Set([...prev, ...pageIds])));
    }
  };

  const selectUnusedOnPage = () => {
    const unusedIds = items.filter((i) => i.usage_count === 0).map((i) => i.id);
    setSelectedIds(unusedIds);
  };

  const handleDeleteIds = async (targetIds: number[], forceDetach: boolean, forceBanners: boolean = false) => {
    if (!isAdmin || targetIds.length === 0 || busy) return;
    const usedSelected = items.filter((i) => targetIds.includes(i.id) && i.usage_count > 0);
    const bannerLocked = usedSelected.filter((i) => (i.usages || []).some((u) => u.type === 'advertising_banner'));
    if (bannerLocked.length > 0 && !forceBanners) {
      setMessage({
        error: true,
        text: `${bannerLocked.length} تصویر انتخاب‌شده به بنر تبلیغاتی متصل است. برای حذف کامل بدون هیچ اثر باقی‌مانده، تیک «حذف کامل اجباری (شامل جداسازی از بنرها)» را فعال کنید؛ بنر متصل غیرفعال و تصویر از دیتابیس و هاست کامل حذف می‌شود.`,
      });
      return;
    }
    if (usedSelected.length > 0 && !forceDetach && !forceBanners) {
      setMessage({
        error: true,
        text: `${usedSelected.length} تصویر انتخاب‌شده در حال حاضر در کسب‌وکار، مقاله یا بنر استفاده شده‌اند. اگر می‌خواهید از کسب‌وکار/مقاله جدا شوند و حذف گردند، تیک «جداسازی خودکار و حذف» را فعال کنید.`,
      });
      return;
    }

    const confirmMsg = forceBanners
      ? `آیا از حذف کامل و دائمی ${targetIds.length} تصویر مطمئن هستید؟ رکورد دیتابیس، فایل‌های روی هاست و اتصال بنرها/کسب‌وکارها/مقالات همگی حذف یا جداسازی می‌شوند؛ بنرهای متصل غیرفعال خواهند شد. این عملیات غیرقابل بازگشت است و هیچ اثری باقی نمی‌ماند.`
      : forceDetach
      ? `آیا از حذف دائمی ${targetIds.length} تصویر از دیتابیس و حذف فیزیکی فایل‌های آن‌ها از هاست (همراه با جداسازی از کسب‌وکارها/مقالات) مطمئن هستید؟ این عملیات غیرقابل بازگشت است.`
      : `آیا از حذف دائمی ${targetIds.length} تصویر بلااستفاده از دیتابیس و حذف فیزیکی فایل‌های آن‌ها از هاست (public_html/uploads) مطمئن هستید؟`;

    if (!window.confirm(confirmMsg)) return;

    setBusy(true);
    setMessage(null);
    const res = await deleteMediaItems(
      { ids: targetIds, detach_usages: forceDetach || forceBanners, force: forceBanners },
      csrfToken
    );
    setBusy(false);

    if (!res.ok) {
      setMessage({ error: true, text: res.error || 'حذف تصویر انجام نشد.' });
      return;
    }

    setSelectedIds((prev) => prev.filter((id) => !targetIds.includes(id)));
    setDetachOnDelete(false);
    setForceOnDelete(false);
    setMessage({ error: false, text: res.message || 'تصاویر انتخاب‌شده به‌طور کامل حذف شدند.' });
    await load();
  };

  const handlePurgeOrphans = async () => {
    if (!isAdmin || busy) return;
    if (
      !window.confirm(
        'فایل‌های سرگردان در پوشه public_html/uploads که هیچ رکوردی در جدول media دیتابیس ندارند حذف شوند؟ (تصاویر متصل به دیتابیس حفظ می‌شوند.)'
      )
    ) {
      return;
    }
    setBusy(true);
    setMessage(null);
    const res = await deleteMediaItems({ purge_orphans: true }, csrfToken);
    setBusy(false);
    setMessage({ error: !res.ok, text: res.message || res.error || 'انجام شد.' });
    if (res.ok) await load();
  };

  const handleSaveAlt = async (id: number) => {
    setBusy(true);
    const res = await updateMediaAltText(id, editingAltValue, csrfToken);
    setBusy(false);
    if (!res.ok) {
      setMessage({ error: true, text: res.error || 'ذخیره متن جایگزین ناموفق بود.' });
      return;
    }
    setEditingAltId(null);
    setMessage({ error: false, text: res.message || 'متن جایگزین ذخیره شد.' });
    await load();
  };

  const copyText = async (val: string) => {
    try {
      await navigator.clipboard.writeText(val);
      setMessage({ error: false, text: `مسیر «${val}» کپی شد.` });
    } catch {
      /* clipboard may be unavailable */
    }
  };

  return (
    <div className="space-y-6 max-w-6xl mx-auto">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-bold text-slate-900 flex items-center gap-2">
            <ImageIcon className="w-5 h-5 text-blue-700" />
            مدیریت رسانه و تصاویر بهینه هاست (WebP / GIF)
          </h1>
          <p className="text-xs text-slate-600 mt-1 leading-6">
            مشاهده شفاف تمام تصاویر جدول <code className="font-mono">media</code> در دیتابیس، مسیر دقیق ذخیره‌سازی روی هاست DirectAdmin (<code className="font-mono">public_html/uploads/YYYY/MM/</code>)، محل استفاده در سایت و پاک‌سازی امن تصاویر بلااستفاده.
          </p>
        </div>
        <button
          type="button"
          onClick={() => void load()}
          disabled={loading}
          className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg border border-slate-300 bg-white text-xs font-semibold hover:bg-slate-50"
        >
          <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
          تازه‌سازی وضعیت دیسک و دیتابیس
        </button>
      </div>

      {message && (
        <div
          role={message.error ? 'alert' : 'status'}
          className={`p-3.5 rounded-lg border text-sm flex items-center justify-between gap-3 ${
            message.error
              ? 'bg-rose-50 border-rose-200 text-rose-900'
              : 'bg-emerald-50 border-emerald-200 text-emerald-900'
          }`}
        >
          <span>{message.text}</span>
          <button
            type="button"
            onClick={() => setMessage(null)}
            className="text-xs underline shrink-0"
          >
            بستن
          </button>
        </div>
      )}

      {/* خلاصه وضعیت دیتابیس و دیسک هاست */}
      {summary && (
        <section className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
          <div className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="text-xs text-slate-500">رکوردهای جدول media در دیتابیس</div>
            <div className="text-lg font-bold text-slate-900 mt-1">
              {summary.total_db_records.toLocaleString('fa-IR')} تصویر
            </div>
            <div className="text-[11px] text-slate-600 mt-1">
              {summary.used_db_records.toLocaleString('fa-IR')} در حال استفاده ·{' '}
              <span className="text-amber-800 font-semibold">
                {summary.unused_db_records.toLocaleString('fa-IR')} بلااستفاده
              </span>
            </div>
          </div>

          <div className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="text-xs text-slate-500 flex items-center gap-1">
              <HardDrive className="w-3.5 h-3.5 text-blue-700" />
              فضای اشغال‌شده در پوشه uploads هاست
            </div>
            <div className="text-lg font-bold text-slate-900 mt-1">
              {formatBytesFa(summary.disk_total_bytes)}
            </div>
            <div className="text-[11px] text-slate-600 mt-1 font-mono" dir="ltr">
              {summary.uploads_host_root}/YYYY/MM/
            </div>
          </div>

          <div className="bg-white border border-slate-200 rounded-xl p-4">
            <div className="text-xs text-slate-500">وضعیت فرمت بهینه روی دیسک (WebP / GIF)</div>
            <div className="text-lg font-bold text-emerald-700 mt-1">
              {summary.disk_webp_files.toLocaleString('fa-IR')} فایل بهینه
            </div>
            <div className="text-[11px] text-slate-600 mt-1">
              {summary.disk_non_webp_files === 0 ? (
                <span className="text-emerald-700">۱۰۰٪ خروجی‌ها بهینه هستند (WebP یا GIF متحرک)</span>
              ) : (
                <span className="text-amber-800">
                  {summary.disk_non_webp_files.toLocaleString('fa-IR')} فایل قدیمی غیربهینه در دیسک
                </span>
              )}
            </div>
          </div>

          <div className="bg-white border border-slate-200 rounded-xl p-4 flex flex-col justify-between">
            <div>
              <div className="text-xs text-slate-500">فایل‌های بدون رکورد دیتابیس (Orphan)</div>
              <div className="text-lg font-bold text-slate-900 mt-1">
                {summary.disk_orphan_files.toLocaleString('fa-IR')} فایل
              </div>
            </div>
            {isAdmin && summary.disk_orphan_files > 0 ? (
              <button
                type="button"
                disabled={busy}
                onClick={() => void handlePurgeOrphans()}
                className="mt-2 text-xs font-semibold text-rose-700 hover:underline text-right"
              >
                پاک‌سازی فایل‌های بدون رکورد از هاست
              </button>
            ) : (
              <div className="text-[11px] text-emerald-700 mt-1">دیسک و دیتابیس هم‌تراز هستند</div>
            )}
          </div>
        </section>
      )}

      {/* راهنمای مرجع ابعاد پیشنهادی برای تمام بخش‌های سایت + آپلود مستقیم WebP */}
      <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        <div className="flex items-center gap-2 font-bold text-sm text-slate-900">
          <Info className="w-4 h-4 text-blue-700" />
          <span>جدول ابعاد پیشنهادی بخش‌های سایت و سیاست تبدیل خودکار (WebP برای ثابت‌ها، GIF بهینه برای متحرک‌ها)</span>
        </div>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
          {Object.values(MEDIA_DIMENSION_GUIDES).map((g) => (
            <div key={g.slot} className="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-1">
              <div className="font-bold text-slate-900">{g.title}</div>
              <div className="text-blue-800 font-semibold">
                ابعاد پیشنهادی: <span dir="ltr" className="font-mono">{g.recommended_px}</span> پیکسل
              </div>
              <div className="text-slate-600">نسبت: {g.aspect_ratio}</div>
              <div className="text-slate-600">{g.fit_mode}</div>
            </div>
          ))}
        </div>

        <div className="pt-3 border-t border-slate-200 flex flex-wrap items-end gap-3">
          <label className="flex-1 min-w-[220px] text-xs font-semibold text-slate-700">
            متن جایگزین (Alt) برای تصویر جدید (پیشنهادی برای سئو)
            <input
              type="text"
              maxLength={220}
              value={uploadAlt}
              onChange={(e) => setUploadAlt(e.target.value)}
              placeholder="مثلاً: نمای داخلی کافه لمیز رشت"
              className="mt-1 w-full px-3 py-2 text-sm border border-slate-300 rounded-lg bg-white"
            />
          </label>
          <label className="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold cursor-pointer">
            <Upload className="w-4 h-4" />
            <span>{uploading ? 'در حال پردازش و بهینه‌سازی تصویر…' : 'آپلود تصویر جدید (WebP خودکار · پشتیبانی GIF متحرک)'}</span>
            <input
              type="file"
              accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/avif"
              disabled={uploading}
              onChange={(e) => {
                void handleDirectUpload(e.target.files?.[0]);
                e.target.value = '';
              }}
              className="sr-only"
            />
          </label>
        </div>
      </section>

      {/* فیلتر، جستجو و عملیات حذف چندتایی */}
      <section className="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div className="md:col-span-2 relative">
            <Search className="w-4 h-4 text-slate-400 absolute right-3 top-2.5" />
            <input
              type="text"
              value={query}
              onChange={(e) => {
                setPage(1);
                setQuery(e.target.value);
              }}
              placeholder="جستجو بر اساس شناسه (#ID)، متن جایگزین، مسیر فایل هاست یا نام کسب‌وکار / مقاله / بنر..."
              className="w-full pr-9 pl-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg focus:bg-white"
            />
          </div>
          <div>
            <select
              aria-label="فیلتر وضعیت مصرف تصویر"
              value={usageFilter}
              onChange={(e) => {
                setPage(1);
                setUsageFilter(e.target.value as typeof usageFilter);
              }}
              className="w-full px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg"
            >
              <option value="all">همه تصاویر ثبت‌شده</option>
              <option value="used">فقط تصاویر در حال استفاده در سایت</option>
              <option value="unused">فقط تصاویر بلااستفاده (قابل حذف امن)</option>
              <option value="missing_files">تصاویر دارای کسری فایل روی دیسک</option>
            </select>
          </div>
        </div>

        {isAdmin && (
          <div className="flex flex-wrap items-center justify-between gap-3 p-3 rounded-lg bg-slate-50 border border-slate-200 text-xs">
            <div className="flex flex-wrap items-center gap-2">
              <button
                type="button"
                onClick={selectAllPage}
                className="px-3 py-1.5 rounded border border-slate-300 bg-white font-semibold hover:bg-slate-100"
              >
                انتخاب / لغو همه این صفحه
              </button>
              <button
                type="button"
                onClick={selectUnusedOnPage}
                className="px-3 py-1.5 rounded border border-slate-300 bg-white font-semibold hover:bg-slate-100"
              >
                انتخاب فقط بلااستفاده‌های این صفحه
              </button>
              <span className="text-slate-600">
                انتخاب‌شده: <strong>{selectedIds.length.toLocaleString('fa-IR')}</strong> مورد
              </span>
            </div>

            {selectedIds.length > 0 && (
              <div className="flex flex-wrap items-center gap-3">
                <label className="inline-flex items-center gap-1.5 text-slate-700">
                  <input
                    type="checkbox"
                    checked={detachOnDelete}
                    onChange={(e) => setDetachOnDelete(e.target.checked)}
                  />
                  <span>جداسازی خودکار از کسب‌وکار/مقاله در صورت استفاده</span>
                </label>
                <label className="inline-flex items-center gap-1.5 text-rose-800 font-semibold">
                  <input
                    type="checkbox"
                    checked={forceOnDelete}
                    onChange={(e) => setForceOnDelete(e.target.checked)}
                  />
                  <span>حذف کامل اجباری (شامل جداسازی از بنرها)</span>
                </label>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void handleDeleteIds(selectedIds, detachOnDelete, forceOnDelete)}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-rose-700 hover:bg-rose-800 text-white font-bold disabled:opacity-50"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  حذف {selectedIds.length.toLocaleString('fa-IR')} تصویر از دیتابیس و هاست
                </button>
              </div>
            )}
          </div>
        )}

        {loading ? (
          <p className="text-center text-sm text-slate-500 py-10">در حال خواندن جدول media و بررسی فایل‌های هاست…</p>
        ) : items.length === 0 ? (
          <p className="text-center text-sm text-slate-500 py-10">هیچ تصویری با این فیلتر یافت نشد.</p>
        ) : (
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            {items.map((item) => {
              const isSelected = selectedIds.includes(item.id);
              const isEditingAlt = editingAltId === item.id;
              return (
                <article
                  key={item.id}
                  className={`rounded-xl border p-4 space-y-3 transition ${
                    isSelected
                      ? 'border-blue-600 bg-blue-50/30'
                      : 'border-slate-200 bg-white'
                  }`}
                >
                  <div className="flex gap-3.5">
                    {isAdmin && (
                      <input
                        type="checkbox"
                        aria-label={`انتخاب تصویر #${item.id}`}
                        checked={isSelected}
                        onChange={() => toggleSelect(item.id)}
                        className="mt-1 w-4 h-4 shrink-0"
                      />
                    )}
                    <a
                      href={item.file_path_1200 || item.file_path_800 || item.file_path_480}
                      target="_blank"
                      rel="noreferrer"
                      className="shrink-0"
                    >
                      <img
                        src={item.file_path_480 || item.file_path_800 || item.file_path_1200}
                        alt={item.alt_text || `رسانه #${item.id}`}
                        className="w-28 h-24 object-cover rounded-lg border border-slate-200 bg-slate-100"
                      />
                    </a>
                    <div className="flex-1 min-w-0 space-y-1 text-xs">
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-bold text-slate-900 text-sm">
                          رسانه #{item.id}
                        </span>
                        <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200">
                          {item.mime_type} · <span dir="ltr">{item.width}×{item.height}</span>
                        </span>
                      </div>
                      <div className="text-slate-600">
                        مجموع حجم ۳ سایز روی هاست: <strong>{formatBytesFa(item.total_bytes)}</strong> · ثبت: {item.created_at}
                      </div>
                      <div className="flex flex-wrap items-center gap-1.5 pt-0.5">
                        {item.usage_count === 0 ? (
                          <span className="px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 font-semibold">
                            بلااستفاده در سایت (قابل حذف امن)
                          </span>
                        ) : (
                          item.usages.map((u, idx) => (
                            <span
                              key={`${u.type}-${u.entity_id}-${idx}`}
                              className="px-2 py-0.5 rounded bg-blue-50 text-blue-900 border border-blue-200"
                            >
                              {u.label}: {u.title || `#${u.entity_id}`}
                            </span>
                          ))
                        )}
                      </div>
                    </div>
                  </div>

                  {/* مسیر دقیق روی هاست DirectAdmin */}
                  <div className="rounded-lg bg-slate-50 border border-slate-200 p-2.5 text-[11px] space-y-1">
                    <div className="flex items-center justify-between gap-2 text-slate-700 font-semibold">
                      <span>مسیر دقیق فایل‌ها روی هاست (DirectAdmin):</span>
                      {item.all_files_present ? (
                        <span className="inline-flex items-center gap-1 text-emerald-700">
                          <CheckCircle2 className="w-3.5 h-3.5" /> هر ۳ سایز موجود
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1 text-rose-700">
                          <AlertTriangle className="w-3.5 h-3.5" /> کسری فایل روی دیسک
                        </span>
                      )}
                    </div>
                    {(['1200', '800', '480'] as const).map((sz) => {
                      const v = item.variants[sz];
                      if (!v) return null;
                      return (
                        <div
                          key={sz}
                          className="flex items-center justify-between gap-2 font-mono text-[10.5px] text-slate-600"
                          dir="ltr"
                        >
                          <span className="truncate" title={v.host_path}>
                            [{sz}w] {v.host_path}
                          </span>
                          <span className="shrink-0 flex items-center gap-1.5">
                            <span className={v.exists ? 'text-emerald-700' : 'text-rose-700'}>
                              {v.exists ? formatBytesFa(v.bytes) : 'ناموجود'}
                            </span>
                            {v.host_path && (
                              <button
                                type="button"
                                onClick={() => void copyText(v.host_path)}
                                title="کپی مسیر فایل هاست"
                                className="p-0.5 hover:text-blue-700"
                              >
                                <Copy className="w-3 h-3" />
                              </button>
                            )}
                          </span>
                        </div>
                      );
                    })}
                  </div>

                  {/* ویرایش Alt و عملیات حذف تکی */}
                  <div className="flex flex-wrap items-center justify-between gap-2 pt-1 text-xs">
                    {isEditingAlt ? (
                      <div className="flex items-center gap-1.5 flex-1">
                        <input
                          type="text"
                          value={editingAltValue}
                          onChange={(e) => setEditingAltValue(e.target.value)}
                          maxLength={220}
                          placeholder="متن جایگزین (Alt) تصویر..."
                          className="flex-1 px-2.5 py-1 border border-slate-300 rounded text-xs"
                        />
                        <button
                          type="button"
                          disabled={busy}
                          onClick={() => void handleSaveAlt(item.id)}
                          className="inline-flex items-center gap-1 px-2.5 py-1 rounded bg-emerald-700 text-white font-semibold"
                        >
                          <Save className="w-3 h-3" /> ذخیره
                        </button>
                        <button
                          type="button"
                          onClick={() => setEditingAltId(null)}
                          className="px-2 py-1 rounded border text-slate-600"
                        >
                          انصراف
                        </button>
                      </div>
                    ) : (
                      <div className="text-slate-600 truncate max-w-[65%]">
                        <span className="font-semibold">متن Alt:</span>{' '}
                        {item.alt_text || <span className="text-slate-400">ثبت نشده</span>}{' '}
                        <button
                          type="button"
                          onClick={() => {
                            setEditingAltId(item.id);
                            setEditingAltValue(item.alt_text || '');
                          }}
                          className="text-blue-700 hover:underline mr-1 font-semibold"
                        >
                          ویرایش
                        </button>
                      </div>
                    )}

                    {isAdmin && (
                      <button
                        type="button"
                        disabled={busy}
                        title={
                          (item.usages || []).some((u) => u.type === 'advertising_banner')
                            ? 'حذف کامل اجباری: بنر متصل غیرفعال و تصویر بدون هیچ اثر باقی‌مانده حذف می‌شود'
                            : 'حذف تصویر از دیتابیس و هاست'
                        }
                        onClick={() =>
                          void handleDeleteIds(
                            [item.id],
                            item.usage_count > 0,
                            (item.usages || []).some((u) => u.type === 'advertising_banner')
                          )
                        }
                        className="inline-flex items-center gap-1 px-2.5 py-1 rounded border border-rose-200 text-rose-700 hover:bg-rose-50 font-semibold disabled:opacity-40"
                      >
                        <Trash2 className="w-3.5 h-3.5" />
                        {(item.usages || []).some((u) => u.type === 'advertising_banner')
                          ? 'حذف کامل (جداسازی از بنر)'
                          : item.usage_count > 0
                          ? 'جداسازی و حذف'
                          : 'حذف از هاست'}
                      </button>
                    )}
                  </div>
                </article>
              );
            })}
          </div>
        )}

        <div className="flex items-center justify-between pt-3 border-t border-slate-100 text-xs text-slate-600">
          <span>کل نتایج فیلتر فعلی: {total.toLocaleString('fa-IR')} مورد</span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              disabled={page <= 1 || loading}
              onClick={() => setPage((p) => p - 1)}
              className="px-3 py-1 rounded border disabled:opacity-40"
            >
              قبلی
            </button>
            <span>
              صفحه {page} از {totalPages}
            </span>
            <button
              type="button"
              disabled={page >= totalPages || loading}
              onClick={() => setPage((p) => p + 1)}
              className="px-3 py-1 rounded border disabled:opacity-40"
            >
              بعدی
            </button>
          </div>
        </div>
      </section>
    </div>
  );
};
