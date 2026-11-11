import React, { useEffect, useState } from 'react';
import {
  MapPin,
  Plus,
  Edit3,
  CheckCircle2,
  AlertTriangle,
  Sparkles,
  Layers,
  Tag,
  Play,
  RefreshCw,
  KeyRound,
  Copy,
  Check,
  Server,
} from 'lucide-react';
import {
  CategoryItem,
  CategoryPackItem,
  CityItem,
  CollectorJobItem,
  CollectorJobResult,
  ProviderStateItem,
  createCollectorJob,
  fetchCollectorQueueState,
  saveCategoryItem,
  saveCategoryPackItem,
  saveCityItem,
  suggestLatinSlug,
  triggerCollectorJobRun,
  updateCollectorProvider,
} from '../services/adminApi';

interface CitiesAndCategoriesViewProps {
  cities: CityItem[];
  categories: CategoryItem[];
  packs: CategoryPackItem[];
  csrfToken: string;
  onRefreshData: () => Promise<void>;
}

export const CitiesAndCategoriesView: React.FC<CitiesAndCategoriesViewProps> = ({
  cities,
  categories,
  packs,
  csrfToken,
  onRefreshData,
}) => {
  // تب‌های داخلی صفحه «شهرها و جمع‌آوری»
  const [subTab, setSubTab] = useState<'collector_queue' | 'cities_packs'>('collector_queue');

  // وضعیت صف جمع‌آوری (مرحله S2) — یکپارچه با دسته‌بندی‌های اصلی سایت
  const [jobs, setJobs] = useState<CollectorJobItem[]>([]);
  const [providers, setProviders] = useState<ProviderStateItem[]>([]);
  const [selectedCityId, setSelectedCityId] = useState<number>(cities[0]?.id || 1);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number>(categories[0]?.id || 1);
  const [selectedProvider, setSelectedProvider] = useState<string>('osm');
  const [customQuery, setCustomQuery] = useState<string>('');
  const [queueMsg, setQueueMsg] = useState<{ type: 'ok' | 'err'; text: string } | null>(null);
  const [runnerLogs, setRunnerLogs] = useState<string[]>([]);
  const [runningWorker, setRunningWorker] = useState<boolean>(false);

  // ۱. افزودن سریع شهر فقط با یک فیلد نام (قانون بخش ۷ و ۸ UX)
  const [quickCityName, setQuickCityName] = useState<string>('');
  const [editingCity, setEditingCity] = useState<CityItem | null>(null);
  const [cityMsg, setCityMsg] = useState<{ type: 'ok' | 'err'; text: string; field?: string } | null>(null);

  // ۲. افزودن/ویرایش دسته‌بندی
  const [catId, setCatId] = useState<number>(0);
  const [catNameFa, setCatNameFa] = useState<string>('');
  const [catSlug, setCatSlug] = useState<string>('');
  const [catSort, setCatSort] = useState<number>(categories.length + 1);
  const [catMsg, setCatMsg] = useState<{ type: 'ok' | 'err'; text: string; field?: string } | null>(null);

  // ۳. افزودن/ویرایش بسته جمع‌آوری OSM متصل به دسته‌بندی
  const [packId, setPackId] = useState<number>(0);
  const [packCategoryId, setPackCategoryId] = useState<number>(categories[0]?.id || 1);
  const [packName, setPackName] = useState<string>('');
  const [packQueriesJson, setPackQueriesJson] = useState<string>(
    '{\n  "osm": ["amenity=cafe", "amenity=restaurant", "amenity=fast_food"],\n  "neshan": ["کافه و رستوران", "کافه", "رستوران"]\n}'
  );
  const [packMsg, setPackMsg] = useState<{ type: 'ok' | 'err'; text: string; field?: string } | null>(null);

  const loadQueueData = async () => {
    try {
      const state = await fetchCollectorQueueState();
      setJobs(state.jobs); setProviders(state.providers);
    } catch (e) { setQueueMsg({type:'err', text:e instanceof Error ? e.message : 'خطا در دریافت صف'}); }
  };

  useEffect(() => {
    loadQueueData();
  }, []);

  const handleCreateJob = async (e: React.FormEvent) => {
    e.preventDefault();
    setQueueMsg(null);

    const matchedPack = packs.find((p) => Number(p.category_id) === Number(selectedCategoryId))
      || packs.find((p) => Number(p.id) === Number(selectedCategoryId));

    const res = await createCollectorJob(
      {
        city_id: selectedCityId,
        category_id: selectedCategoryId,
        pack_id: matchedPack ? Number(matchedPack.id) : undefined,
        provider: selectedProvider,
        custom_query: customQuery,
      },
      csrfToken
    );

    if (!res.ok) {
      setQueueMsg({ type: 'err', text: res.error || 'خطا در ثبت Job جمع‌آوری' });
      return;
    }

    setCustomQuery('');
    setQueueMsg({ type: 'ok', text: res.message || 'Job جدید در صف قرار گرفت.' });
    await loadQueueData();
  };

  const handleRunNextJobNow = async () => {
    setRunningWorker(true);
    setQueueMsg(null);

    const res = await triggerCollectorJobRun(csrfToken);
    setRunnerLogs(res.logLines);
    setQueueMsg({type:res.ok ? 'ok' : 'err', text:res.logLines[0]});
    setRunningWorker(false);

    await Promise.all([loadQueueData(), onRefreshData()]);
  };

  const handleToggleProvider = async (prov: ProviderStateItem, clearPause = false) => {
    const nextEnabled = clearPause ? 1 : prov.enabled === 1 ? 0 : 1;
    const res = await updateCollectorProvider(
      {
        provider: prov.provider,
        enabled: nextEnabled,
        priority: prov.priority,
        clear_pause: clearPause,
      },
      csrfToken
    );
    if (res.ok) {
      setQueueMsg({type:'ok', text:res.message});
      await loadQueueData();
    } else setQueueMsg({type:'err', text:res.error || 'خطا در تغییر وضعیت منبع'});
  };

  const handleQuickAddCity = async (e: React.FormEvent) => {
    e.preventDefault();
    setCityMsg(null);

    const res = await saveCityItem(
      {
        name_fa: quickCityName,
        publish_mode: 'manual',
        is_active: 1,
      },
      csrfToken
    );

    if (!res.ok) {
      setCityMsg({ type: 'err', text: res.error || 'خطا در ثبت شهر', field: res.field });
      return;
    }

    setQuickCityName('');
    setCityMsg({ type: 'ok', text: res.message || 'شهر جدید با موفقیت اضافه شد.' });
    await onRefreshData();
  };

  const handleSaveEditedCity = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingCity) return;
    setCityMsg(null);

    const res = await saveCityItem(editingCity, csrfToken);
    if (!res.ok) {
      setCityMsg({ type: 'err', text: res.error || 'خطا در ویرایش شهر', field: res.field });
      return;
    }

    setEditingCity(null);
    setCityMsg({ type: 'ok', text: res.message || 'ویرایش شهر ذخیره شد.' });
    await onRefreshData();
  };

  const handleSaveCategory = async (e: React.FormEvent) => {
    e.preventDefault();
    setCatMsg(null);

    const res = await saveCategoryItem(
      {
        id: catId > 0 ? catId : undefined,
        name_fa: catNameFa,
        slug: catSlug,
        sort: catSort,
        is_active: 1,
      },
      csrfToken
    );

    if (!res.ok) {
      setCatMsg({ type: 'err', text: res.error || 'خطا در ذخیره دسته‌بندی', field: res.field });
      return;
    }

    setCatId(0);
    setCatNameFa('');
    setCatSlug('');
    setCatSort(categories.length + 2);
    setCatMsg({ type: 'ok', text: res.message || 'دسته‌بندی ذخیره شد.' });
    await onRefreshData();
  };

  const handleSavePack = async (e: React.FormEvent) => {
    e.preventDefault();
    setPackMsg(null);

    const targetCat = categories.find((c) => Number(c.id) === Number(packCategoryId));
    const res = await saveCategoryPackItem(
      {
        id: packId > 0 ? packId : undefined,
        category_id: packCategoryId,
        name: packName.trim() || targetCat?.name_fa || '',
        queries_json: packQueriesJson,
        is_active: 1,
      },
      csrfToken
    );

    if (!res.ok) {
      setPackMsg({ type: 'err', text: res.error || 'خطا در ذخیره تنظیمات جمع‌آوری دسته', field: res.field });
      return;
    }

    setPackId(0);
    setPackName('');
    setPackMsg({ type: 'ok', text: res.message || 'تنظیمات تگ‌های جمع‌آوری ذخیره شد.' });
    await onRefreshData();
  };

  const activeCategories = categories.filter((c) => Number(c.is_active) === 1);
  const selectedPackPreview = packs.find((p) => Number(p.category_id) === Number(selectedCategoryId))
    || packs.find((p) => Number(p.id) === Number(selectedCategoryId));
  let selectedOsmTags: string[] = [];
  if (selectedPackPreview?.queries_json) {
    try {
      const parsedQ = JSON.parse(selectedPackPreview.queries_json);
      if (Array.isArray(parsedQ?.osm)) selectedOsmTags = parsedQ.osm.map(String);
    } catch {
      selectedOsmTags = [];
    }
  }

  // محاسبه آمار کلی پیشرفت صف (در حال اجرا / یافت‌شده / تکراری / خطا)
  const queueStats = jobs.reduce(
    (acc, j) => {
      if (j.status === 'running' || j.status === 'claimed') acc.running++;
      if (j.status === 'queued') acc.queued++;
      if (j.result_json) {
        try {
          const parsed: CollectorJobResult = JSON.parse(j.result_json);
          acc.found += Number(parsed.found || 0);
          acc.inserted += Number(parsed.inserted || 0);
          acc.duplicates += Number(parsed.duplicates || 0);
          acc.errors += Number(parsed.errors || 0);
        } catch {
          // ignore
        }
      }
      if (j.status === 'failed') acc.errors++;
      return acc;
    },
    { queued: 0, running: 0, found: 0, inserted: 0, duplicates: 0, errors: 0 }
  );

  return (
    <div className="space-y-6">
      {/* کنترل تب داخلی صفحه شهرها و جمع‌آوری */}
      <div className="flex items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-3">
        <div className="flex items-center gap-1 p-1 bg-slate-100 rounded-lg">
          <button
            type="button"
            onClick={() => setSubTab('collector_queue')}
            className={`px-4 py-2 text-xs font-semibold rounded-md transition-colors whitespace-nowrap shrink-0 ${
              subTab === 'collector_queue'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            صف جمع‌آوری خودکار (Collector Queue - S2)
          </button>
          <button
            type="button"
            onClick={() => setSubTab('cities_packs')}
            className={`px-4 py-2 text-xs font-semibold rounded-md transition-colors whitespace-nowrap shrink-0 ${
              subTab === 'cities_packs'
                ? 'bg-white text-slate-900 shadow-sm'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            مدیریت شهرها، دسته‌ها و بسته‌های دسته (S1)
          </button>
        </div>

        <button
          type="button"
          onClick={loadQueueData}
          className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-700 bg-slate-50 border border-slate-200 rounded-lg hover:bg-slate-100"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          به‌روزرسانی وضعیت صف
        </button>
      </div>

      {subTab === 'collector_queue' ? (
        <div className="space-y-6">
          {/* کارت‌های پیشرفت لحظه‌ای (در حال اجرا / یافت‌شده / تکراری / خطا) طبق بخش ۹ */}
          <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div className="bg-white border border-slate-200 rounded-lg p-4">
              <div className="text-xs text-slate-500">در صف / در حال اجرا</div>
              <div className="text-xl font-bold text-slate-900 mt-1 font-mono tabular-nums">
                {queueStats.queued} در صف · {queueStats.running} فعال
              </div>
            </div>
            <div className="bg-white border border-slate-200 rounded-lg p-4">
              <div className="text-xs text-slate-500">کل یافت‌شده (Found)</div>
              <div className="text-xl font-bold text-slate-900 mt-1 font-mono tabular-nums">
                {queueStats.found}
              </div>
            </div>
            <div className="bg-white border border-slate-200 rounded-lg p-4">
              <div className="text-xs text-slate-500">ثبت جدید (Inserted)</div>
              <div className="text-xl font-bold text-emerald-700 mt-1 font-mono tabular-nums">
                {queueStats.inserted}
              </div>
            </div>
            <div className="bg-white border border-slate-200 rounded-lg p-4">
              <div className="text-xs text-slate-500">تکراری / ادغام منبع</div>
              <div className="text-xl font-bold text-amber-700 mt-1 font-mono tabular-nums">
                {queueStats.duplicates}
              </div>
            </div>
            <div className="bg-white border border-slate-200 rounded-lg p-4">
              <div className="text-xs text-slate-500">خطا (Errors)</div>
              <div className="text-xl font-bold text-rose-700 mt-1 font-mono tabular-nums">
                {queueStats.errors}
              </div>
            </div>
          </div>

          {queueMsg && (
            <div
              className={`p-4 rounded-lg border text-xs font-semibold flex items-center justify-between ${
                queueMsg.type === 'ok'
                  ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                  : 'bg-rose-50 border-rose-200 text-rose-800'
              }`}
            >
              <span>{queueMsg.text}</span>
              <button type="button" onClick={() => setQueueMsg(null)} className="underline">
                بستن
              </button>
            </div>
          )}

          {/* فرم ساخت Job جدید یکپارچه با دسته‌بندی‌های اصلی */}
          <section className="bg-white border border-slate-200 rounded-xl p-6">
            <h2 className="text-base font-bold text-slate-900 pb-4 mb-4 border-b border-slate-200">
              ۱. افزودن کار جمع‌آوری به صف (یکپارچه با دسته‌بندی‌های اصلی سایت و جمع‌آوری و ورود)
            </h2>

            <form onSubmit={handleCreateJob} className="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
              <div>
                <label className="block text-xs font-semibold text-slate-700">انتخاب شهر</label>
                <select
                  value={selectedCityId}
                  onChange={(e) => setSelectedCityId(Number(e.target.value))}
                  className="mt-1 w-full px-3 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                >
                  {cities.filter((c) => Number(c.is_active) === 1).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.name_fa} ({c.slug})
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700">دسته‌بندی (مشترک در کل سایت)</label>
                <select
                  value={selectedCategoryId}
                  onChange={(e) => setSelectedCategoryId(Number(e.target.value))}
                  className="mt-1 w-full px-3 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                >
                  {(activeCategories.length > 0 ? activeCategories : categories).map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.name_fa}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="block text-xs font-semibold text-slate-700">پرووایدر</label>
                <select
                  value={selectedProvider}
                  onChange={(e) => setSelectedProvider(e.target.value)}
                  className="mt-1 w-full px-3 py-2.5 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                >
                  <option value="osm">OpenStreetMap (osm)</option>
                  <option value="neshan">نشان (نیازمند کلید و بررسی شرایط)</option>
                </select>
              </div>

              <div>
                <button
                  type="submit"
                  className="w-full py-2.5 px-4 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-bold rounded-lg transition-colors"
                >
                  + ثبت Job در صف (Queued)
                </button>
              </div>
            </form>

            <div className="mt-3 rounded-lg bg-slate-50 border border-slate-200 px-3.5 py-2.5 text-xs text-slate-600 leading-6">
              {selectedOsmTags.length > 0 && selectedProvider === 'osm' && (
                <div>
                  <strong className="text-slate-800">تگ‌های فعال OpenStreetMap برای این دسته:</strong>{' '}
                  <span className="font-mono text-emerald-800" dir="ltr">{selectedOsmTags.join(' · ')}</span>
                </div>
              )}
              <div>
                کسب‌وکارهای دریافتی مستقیماً در همین دسته‌بندی ذخیره می‌شوند و در کل شهر (بدون وابستگی به دسته، بر اساس نام، شماره تماس و فاصله زیر ۸۰ متر) با ورودی‌های Google Maps / CSV / JSON تطبیق تکراری می‌شوند.
              </div>
            </div>
          </section>

          {/* جدول صف Jobها + دکمه اجرای مستقیم چرخه Pull -> Ingest */}
          <section className="bg-white border border-slate-200 rounded-xl p-6 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-4 pb-4 border-b border-slate-200">
              <div>
                <h2 className="text-base font-bold text-slate-900">
                  ۲. لیست صف Jobهای جمع‌آوری (collector_jobs)
                </h2>
                <p className="text-xs text-slate-500 mt-1">
                  اجرای کار روی همین هاست انجام می‌شود. با ثبت کرون، کارهای صف هر پنج دقیقه بررسی می‌شوند. تغییر خودکار منبع انجام نمی‌شود.
                </p>
              </div>

              <div className="flex items-center gap-2">
                <button
                  type="button"
                  disabled={runningWorker}
                  onClick={() => handleRunNextJobNow()}
                  className="inline-flex items-center gap-1.5 px-4 py-2 bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold rounded-lg disabled:opacity-50"
                >
                  <Play className="w-3.5 h-3.5" />
                  {runningWorker ? 'در حال اجرا...' : 'اجرای کار بعدی روی هاست'}
                </button>
              </div>
            </div>

            {runnerLogs.length > 0 && (
              <div className="p-4 bg-slate-900 text-slate-100 rounded-lg text-xs font-mono space-y-1" dir="ltr">
                {runnerLogs.map((ln, idx) => (
                  <div key={idx}>{ln}</div>
                ))}
              </div>
            )}

            <div className="overflow-x-auto">
              <table className="w-full text-sm border-collapse">
                <thead>
                  <tr className="border-b border-slate-200 text-slate-500 text-xs">
                    <th className="py-2.5 px-3 text-right font-semibold">شناسه</th>
                    <th className="py-2.5 px-3 text-right font-semibold">شهر و دسته‌بندی</th>
                    <th className="py-2.5 px-3 text-right font-semibold">پرووایدر</th>
                    <th className="py-2.5 px-3 text-right font-semibold">وضعیت</th>
                    <th className="py-2.5 px-3 text-right font-semibold">نتیجه (یافت‌شده / جدید / تکراری / خطا)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {jobs.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="py-8 text-center text-xs text-slate-500">
                        هیچ Job در صف ثبت نشده است. از فرم بالا شهر و دسته‌بندی مورد نظر (مثلاً «رشت + کافه و رستوران») را ثبت کنید.
                      </td>
                    </tr>
                  ) : (
                    jobs.map((j) => {
                      let parsedRes: CollectorJobResult | null = null;
                      if (j.result_json) {
                        try {
                          parsedRes = JSON.parse(j.result_json);
                        } catch {
                          parsedRes = null;
                        }
                      }
                      return (
                        <tr key={j.id} className="hover:bg-slate-50">
                          <td className="py-3 px-3 font-mono text-xs tabular-nums">#{j.id}</td>
                          <td className="py-3 px-3">
                            <span className="font-semibold text-slate-900">{j.city_name || `شهر #${j.city_id}`}</span>
                            <span className="mx-1.5 text-slate-300">·</span>
                            <span className="text-xs text-slate-600">{j.pack_name || 'جستجوی سفارشی'}</span>
                          </td>
                          <td className="py-3 px-3 font-mono text-xs text-slate-700">{j.provider}</td>
                          <td className="py-3 px-3 text-xs font-semibold">
                            {j.status === 'done' && <span className="text-emerald-700">تکمیل‌شده (done)</span>}
                            {j.status === 'queued' && <span className="text-amber-700">در صف (queued)</span>}
                            {j.status === 'running' && <span className="text-blue-700">در حال اجرا (running)</span>}
                            {j.status === 'failed' && <span className="text-rose-700">خطا (failed)</span>}
                            {j.status === 'paused' && <span className="text-amber-700">متوقف (paused)</span>}
                          </td>
                          <td className="py-3 px-3 text-xs">
                            {parsedRes ? (
                              <div className="space-y-1">
                                <span className="font-mono tabular-nums text-slate-700">
                                  یافت‌شده: {parsedRes.found} · جدید: {parsedRes.inserted}
                                  {parsedRes.pending_review !== undefined ? ` · بازبینی: ${parsedRes.pending_review}` : ''}{' '}
                                  · تکراری: {parsedRes.duplicates} · خطا: {parsedRes.errors}
                                </span>
                                {parsedRes.warning && <div className="text-amber-700">{parsedRes.warning}</div>}
                                {parsedRes.last_error && (
                                  <div className="text-[11px] text-rose-700 font-mono">
                                    جزئیات خطا: {parsedRes.last_error}
                                  </div>
                                )}
                              </div>
                            ) : j.error ? (
                              <span className="text-amber-800">{j.error}</span>
                            ) : (
                              <span className="text-slate-400">منتظر اجرای ورکر روی هاست</span>
                            )}
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>
          </section>

          {/* وضعیت پرووایدرها و توکن Bearer */}
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section className="bg-white border border-slate-200 rounded-xl p-6">
              <h2 className="text-base font-bold text-slate-900 flex items-center gap-2 pb-4 mb-4 border-b border-slate-200">
                <Server className="w-4 h-4 text-emerald-700" />
                ۳. وضعیت منابع (توقف ۲۴ ساعته در پاسخ 403 یا 429)
              </h2>
              <div className="divide-y divide-slate-200 text-xs">
                {providers.map((p) => (
                  <div key={p.provider} className="py-3 flex items-center justify-between gap-3">
                    <div>
                      <span className="font-mono font-bold text-slate-900">{p.provider}</span>
                      <span className="mx-2 text-slate-300">·</span>
                      <span className="text-slate-500">اولویت {p.priority}</span>
                      {p.paused_until && (
                        <div className="text-rose-700 mt-1">
                          متوقف تا ۲۴ ساعت ({p.paused_until}) — {p.last_error}
                        </div>
                      )}
                    </div>
                    <div className="flex items-center gap-2 shrink-0">
                      {p.paused_until && (
                        <button
                          type="button"
                          onClick={() => handleToggleProvider(p, true)}
                          className="px-2.5 py-1 bg-amber-100 text-amber-900 rounded font-semibold"
                        >
                          رفع توقف
                        </button>
                      )}
                      <button
                        type="button"
                        onClick={() => handleToggleProvider(p, false)}
                        className={`px-3 py-1 rounded font-semibold ${
                          p.enabled === 1
                            ? 'bg-emerald-50 text-emerald-800 border border-emerald-200'
                            : 'bg-slate-100 text-slate-500'
                        }`}
                      >
                        {p.enabled === 1 ? 'فعال' : 'غیرفعال'}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </section>

            <section className="bg-white border border-slate-200 rounded-xl p-6 text-sm text-slate-600 leading-7">
              منبع OSM بدون کلید قابل استفاده است. برای نشان، پیش از فعال‌سازی باید کلید و شرایط استفاده را روی هاست تنظیم و بررسی کنید.
              در صورت خطا منبع به‌طور خودکار عوض نمی‌شود.
            </section>
          </div>
        </div>
      ) : (
        /* تب مدیریت شهرها، دسته‌ها و بسته‌های دسته (S1) */
        <div className="space-y-8">
          <section className="bg-white border border-slate-200 rounded-xl p-6">
            <div className="flex flex-wrap items-center justify-between gap-4 pb-4 mb-5 border-b border-slate-200">
              <div>
                <h2 className="text-base font-bold text-slate-900 flex items-center gap-2">
                  <MapPin className="w-4 h-4 text-emerald-700" />
                  ۱. شهرهای استان گیلان (افزودن فوری فقط با یک فیلد نام)
                </h2>
              </div>
            </div>

            {cityMsg && (
              <div
                className={`mb-5 p-3.5 rounded-lg border text-xs flex items-center justify-between gap-4 ${
                  cityMsg.type === 'ok'
                    ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                    : 'bg-rose-50 border-rose-200 text-rose-800'
                }`}
              >
                <span className="flex items-center gap-2">
                  {cityMsg.type === 'ok' ? (
                    <CheckCircle2 className="w-4 h-4 text-emerald-700 shrink-0" />
                  ) : (
                    <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                  )}
                  {cityMsg.text}
                </span>
              </div>
            )}

            <form onSubmit={handleQuickAddCity} className="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-6">
              <div className="flex-1">
                <input
                  id="city_name_fa"
                  type="text"
                  value={quickCityName}
                  onChange={(e) => setQuickCityName(e.target.value)}
                  placeholder="نام فارسی شهر جدید را بنویسید (مثلاً: لنگرود، تالش، آستارا)..."
                  className="w-full px-4 py-2.5 text-sm bg-slate-50 border border-slate-300 rounded-lg focus:bg-white focus:outline-none focus:border-emerald-700"
                />
              </div>
              <button
                type="submit"
                className="inline-flex items-center justify-center gap-2 px-5 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-sm font-semibold rounded-lg transition-colors whitespace-nowrap shrink-0"
              >
                <Plus className="w-4 h-4" />
                افزودن فوری شهر
              </button>
            </form>

            <div className="overflow-x-auto">
              <table className="w-full text-sm border-collapse">
                <thead>
                  <tr className="border-b border-slate-200 text-slate-500 text-xs">
                    <th className="py-2.5 px-3 text-right font-semibold">نام شهر</th>
                    <th className="py-2.5 px-3 text-right font-semibold">اسلاگ (URL)</th>
                    <th className="py-2.5 px-3 text-right font-semibold">مختصات (Lat, Lng)</th>
                    <th className="py-2.5 px-3 text-right font-semibold">حالت انتشار خودکار</th>
                    <th className="py-2.5 px-3 text-left font-semibold">عملیات</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {cities.map((city) => (
                    <tr key={city.id} className="hover:bg-slate-50">
                      <td className="py-3 px-3 font-semibold text-slate-900">{city.name_fa}</td>
                      <td className="py-3 px-3 font-mono text-xs text-slate-600" dir="ltr">
                        /city/{city.slug}/
                      </td>
                      <td className="py-3 px-3 font-mono text-xs text-slate-600 tabular-nums" dir="ltr">
                        {city.lat != null && city.lng != null ? `${Number(city.lat).toFixed(4)}, ${Number(city.lng).toFixed(4)}` : '—'}
                      </td>
                      <td className="py-3 px-3 text-xs text-slate-700">{city.publish_mode}</td>
                      <td className="py-3 px-3 text-left">
                        <button
                          type="button"
                          onClick={() => setEditingCity(city)}
                          className="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 hover:text-emerald-900"
                        >
                          <Edit3 className="w-3.5 h-3.5" />
                          ویرایش
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {editingCity && (
              <form
                onSubmit={handleSaveEditedCity}
                className="mt-6 p-5 bg-slate-50 border border-slate-200 rounded-lg space-y-4"
              >
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  <div>
                    <label className="block text-xs font-semibold text-slate-700">نام فارسی</label>
                    <input
                      type="text"
                      value={editingCity.name_fa}
                      onChange={(e) => setEditingCity({ ...editingCity, name_fa: e.target.value })}
                      className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-slate-700">اسلاگ لاتین</label>
                    <input
                      type="text"
                      dir="ltr"
                      value={editingCity.slug}
                      onChange={(e) => setEditingCity({ ...editingCity, slug: e.target.value })}
                      className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                    />
                  </div>
                  <div className="text-xs text-slate-700">حالت انتشار: دستی</div>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3" dir="ltr">
                  <label className="text-xs">Latitude <input type="number" step="any" value={editingCity.lat ?? ''} onChange={e=>setEditingCity({...editingCity,lat:e.target.value === '' ? null : Number(e.target.value)})} className="w-full border rounded p-2" /></label>
                  <label className="text-xs">Longitude <input type="number" step="any" value={editingCity.lng ?? ''} onChange={e=>setEditingCity({...editingCity,lng:e.target.value === '' ? null : Number(e.target.value)})} className="w-full border rounded p-2" /></label>
                  <label className="text-xs">Bbox [S,W,N,E] <input value={editingCity.bbox_json ?? ''} onChange={e=>setEditingCity({...editingCity,bbox_json:e.target.value})} className="w-full border rounded p-2" /></label>
                </div>
                <p className="text-xs text-slate-600">محدوده را پیش از جمع‌آوری روی نقشه و با داده معتبر بررسی کنید.</p>
                <div className="flex justify-end gap-2">
                  <button
                    type="button"
                    onClick={() => setEditingCity(null)}
                    className="px-4 py-2 text-xs text-slate-600"
                  >
                    انصراف
                  </button>
                  <button
                    type="submit"
                    className="px-5 py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg"
                  >
                    ذخیره تغییرات شهر
                  </button>
                </div>
              </form>
            )}
          </section>

          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <section className="bg-white border border-slate-200 rounded-xl p-6">
              <h2 className="text-base font-bold text-slate-900 flex items-center gap-2 pb-4 mb-4 border-b border-slate-200">
                <Tag className="w-4 h-4 text-emerald-700" />
                ۲. دسته‌بندی‌های یکپارچه سایت، جمع‌آوری و ورود
              </h2>
              {catMsg && <div className={`mb-3 text-xs ${catMsg.type === 'err' ? 'text-rose-700' : 'text-emerald-800'}`}>{catMsg.text}</div>}
              <form onSubmit={handleSaveCategory} className="space-y-3 mb-4 p-4 bg-slate-50 border border-slate-200 rounded-lg">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <input
                    type="text"
                    value={catNameFa}
                    onChange={(e) => {
                      setCatNameFa(e.target.value);
                      if (!catId) setCatSlug(suggestLatinSlug(e.target.value, 'cat'));
                    }}
                    placeholder="نام فارسی دسته"
                    className="px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                  <input
                    type="text"
                    dir="ltr"
                    value={catSlug}
                    onChange={(e) => setCatSlug(e.target.value)}
                    placeholder="slug"
                    className="px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div className="flex justify-end gap-2">
                  {catId > 0 && (
                    <button
                      type="button"
                      onClick={() => { setCatId(0); setCatNameFa(''); setCatSlug(''); }}
                      className="px-3 py-2 text-xs text-slate-600"
                    >
                      انصراف
                    </button>
                  )}
                  <button type="submit" className="px-4 py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg">
                    {catId > 0 ? 'ذخیره ویرایش دسته' : 'افزودن دسته'}
                  </button>
                </div>
              </form>
              <div className="divide-y divide-slate-200 text-sm">
                {categories.map((cat) => (
                  <div key={cat.id} className="py-2.5 flex items-center justify-between gap-2">
                    <span>
                      <strong>{cat.name_fa}</strong> · <code className="font-mono text-xs">{cat.slug}</code>
                    </span>
                    <button
                      type="button"
                      onClick={() => { setCatId(cat.id); setCatNameFa(cat.name_fa); setCatSlug(cat.slug); setCatSort(cat.sort); }}
                      className="text-xs text-emerald-700 hover:text-emerald-900 font-medium"
                    >
                      ویرایش
                    </button>
                  </div>
                ))}
              </div>
            </section>

            <section className="bg-white border border-slate-200 rounded-xl p-6">
              <h2 className="text-base font-bold text-slate-900 flex items-center gap-2 pb-4 mb-4 border-b border-slate-200">
                <Layers className="w-4 h-4 text-emerald-700" />
                ۳. تنظیمات تگ‌های OSM هر دسته‌بندی (۱ به ۱ متصل به دسته‌ها)
              </h2>
              {packMsg && <div className={`mb-3 text-xs ${packMsg.type === 'err' ? 'text-rose-700' : 'text-emerald-800'}`}>{packMsg.text}</div>}
              <form onSubmit={handleSavePack} className="space-y-3 mb-4 p-4 bg-slate-50 border border-slate-200 rounded-lg">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                  <label className="text-xs font-semibold text-slate-700">
                    دسته‌بندی متصل
                    <select
                      value={packCategoryId}
                      onChange={(e) => {
                        const cid = Number(e.target.value);
                        setPackCategoryId(cid);
                        const found = categories.find((c) => Number(c.id) === cid);
                        if (found && !packId) setPackName(found.name_fa);
                      }}
                      className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                    >
                      {categories.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name_fa}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="text-xs font-semibold text-slate-700">
                    عنوان نمایشی (پیش‌فرض هم‌نام با دسته)
                    <input
                      type="text"
                      value={packName}
                      onChange={(e) => setPackName(e.target.value)}
                      placeholder="خالی بگذارید تا دقیقاً هم‌نام دسته شود"
                      className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                    />
                  </label>
                </div>
                <textarea
                  rows={3}
                  dir="ltr"
                  value={packQueriesJson}
                  onChange={(e) => setPackQueriesJson(e.target.value)}
                  className="w-full px-3 py-2 text-xs font-mono bg-white border border-slate-300 rounded-lg"
                />
                <div className="flex justify-end gap-2">
                  {packId > 0 && (
                    <button
                      type="button"
                      onClick={() => { setPackId(0); setPackName(''); }}
                      className="px-3 py-2 text-xs text-slate-600"
                    >
                      انصراف
                    </button>
                  )}
                  <button type="submit" className="px-4 py-2 bg-slate-900 text-white text-xs font-semibold rounded-lg">
                    {packId > 0 ? 'ذخیره ویرایش تگ‌ها' : 'ذخیره تنظیمات دسته'}
                  </button>
                </div>
              </form>
              <div className="divide-y divide-slate-200 text-xs">
                {packs.map((pk) => (
                  <div key={pk.id} className="py-2.5 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="font-bold text-slate-900">
                        {pk.category_name_fa || pk.name}
                        {pk.category_slug && <span className="font-mono font-normal text-slate-500 mr-1.5" dir="ltr">({pk.category_slug})</span>}
                      </div>
                      <div className="font-mono text-slate-500 mt-0.5 break-all" dir="ltr">
                        {pk.queries_json}
                      </div>
                    </div>
                    <button
                      type="button"
                      onClick={() => {
                        setPackId(pk.id);
                        setPackCategoryId(Number(pk.category_id || pk.id || 1));
                        setPackName(pk.name);
                        setPackQueriesJson(pk.queries_json);
                      }}
                      className="text-emerald-700 hover:text-emerald-900 font-medium shrink-0"
                    >
                      ویرایش تگ‌ها
                    </button>
                  </div>
                ))}
              </div>
            </section>
          </div>
        </div>
      )}
    </div>
  );
};
