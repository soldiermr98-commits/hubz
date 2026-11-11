import React, { useCallback, useEffect, useState } from 'react';
import { CheckCircle2, FolderOpen, Search, X } from 'lucide-react';
import {
  MediaLibraryItem,
  fetchMediaLibrary,
  formatBytesFa,
} from '../services/adminApi';

interface Props {
  open: boolean;
  onClose: () => void;
  onSelect: (item: MediaLibraryItem) => void;
  title?: string;
}

export const MediaLibraryPickerModal: React.FC<Props> = ({
  open,
  onClose,
  onSelect,
  title = 'انتخاب تصویر از کتابخانه رسانه (WebP)',
}) => {
  const [items, setItems] = useState<MediaLibraryItem[]>([]);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const load = useCallback(async (targetPage: number, q: string) => {
    setLoading(true);
    setError('');
    try {
      const res = await fetchMediaLibrary({ page: targetPage, per_page: 18, usage: 'all', q });
      setItems(res.items);
      setTotalPages(res.pagination.total_pages);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'خطا در دریافت کتابخانه رسانه');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) return;
    const t = window.setTimeout(() => {
      void load(page, query);
    }, 150);
    return () => window.clearTimeout(t);
  }, [open, page, query, load]);

  if (!open) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-label={title}
      className="fixed inset-0 z-50 bg-slate-950/60 flex items-center justify-center p-4"
    >
      <div className="bg-white rounded-xl border border-slate-200 max-w-4xl w-full max-h-[85vh] flex flex-col shadow-xl">
        <div className="flex items-center justify-between gap-3 p-4 border-b border-slate-200">
          <div className="flex items-center gap-2 font-bold text-sm text-slate-900">
            <FolderOpen className="w-4 h-4 text-blue-700" />
            <span>{title}</span>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="p-1.5 rounded-lg hover:bg-slate-100 text-slate-600"
            aria-label="بستن پنجره انتخاب تصویر"
          >
            <X className="w-4 h-4" />
          </button>
        </div>

        <div className="p-4 border-b border-slate-100">
          <div className="relative">
            <Search className="w-4 h-4 text-slate-400 absolute right-3 top-2.5" />
            <input
              type="text"
              value={query}
              onChange={(e) => {
                setPage(1);
                setQuery(e.target.value);
              }}
              placeholder="جستجو با شناسه، متن جایگزین، مسیر فایل یا نام کسب‌وکار/مقاله..."
              className="w-full pr-9 pl-3 py-2 text-xs border border-slate-300 rounded-lg"
            />
          </div>
        </div>

        <div className="p-4 overflow-y-auto flex-1">
          {error && (
            <p role="alert" className="p-3 rounded bg-rose-50 text-rose-800 text-xs mb-3">
              {error}
            </p>
          )}
          {loading ? (
            <p className="text-center text-xs text-slate-500 py-8">در حال بارگذاری تصاویر کتابخانه…</p>
          ) : items.length === 0 ? (
            <p className="text-center text-xs text-slate-500 py-8">تصویری در کتابخانه یافت نشد.</p>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              {items.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  onClick={() => {
                    onSelect(item);
                    onClose();
                  }}
                  className="text-right border border-slate-200 hover:border-blue-600 rounded-lg p-2.5 bg-slate-50/60 hover:bg-blue-50/40 transition flex flex-col gap-2"
                >
                  <img
                    src={item.file_path_480 || item.file_path_800 || item.file_path_1200}
                    alt={item.alt_text || `رسانه #${item.id}`}
                    className="w-full h-28 object-cover rounded border border-slate-200 bg-white"
                  />
                  <div className="text-[11px] space-y-0.5 w-full">
                    <div className="flex items-center justify-between font-bold text-slate-900">
                      <span>#{item.id} · WebP</span>
                      <span dir="ltr" className="font-mono text-slate-600">
                        {item.width}×{item.height}
                      </span>
                    </div>
                    <div className="text-slate-600 truncate">
                      {item.alt_text || 'بدون متن جایگزین'}
                    </div>
                    <div className="flex items-center justify-between text-[10px] text-slate-500">
                      <span>{formatBytesFa(item.total_bytes)} (۳ سایز)</span>
                      <span className="inline-flex items-center gap-1 text-blue-700 font-semibold">
                        <CheckCircle2 className="w-3 h-3" /> انتخاب
                      </span>
                    </div>
                  </div>
                </button>
              ))}
            </div>
          )}
        </div>

        <div className="p-3 border-t border-slate-200 flex items-center justify-between text-xs">
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
          <button
            type="button"
            onClick={onClose}
            className="px-3 py-1.5 rounded border border-slate-300 font-semibold text-slate-700"
          >
            انصراف
          </button>
        </div>
      </div>
    </div>
  );
};
