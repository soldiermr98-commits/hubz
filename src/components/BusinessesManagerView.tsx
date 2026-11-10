import React, { useEffect, useState } from 'react';
import {
  Store,
  Plus,
  Edit3,
  Upload,
  Sparkles,
  CheckCircle2,
  AlertTriangle,
  ArrowUpRight,
  FolderOpen,
  Image as ImageIcon,
  ShieldCheck,
  Trash2,
  X,
} from 'lucide-react';
import {
  BusinessItem,
  BusinessSeoDeleteMode,
  CategoryItem,
  CityItem,
  NeighborhoodItem,
  computeBusinessWarnings,
  deleteBusinesses,
  fetchArticleBusinesses,
  fetchBusinessesPage,
  formatBytesFa,
  publishSavedBusiness,
  normalizeIranPhone,
  recordBusinessDataReview,
  saveBusinessItem,
  setBusinessPromotion,
  suggestLatinSlug,
  uploadMediaWebp,
} from '../services/adminApi';
import { MediaDimensionGuideBox } from './MediaDimensionGuideBox';
import { MediaLibraryPickerModal } from './MediaLibraryPickerModal';

interface BusinessesManagerViewProps {
  cities: CityItem[];
  neighborhoods: NeighborhoodItem[];
  categories: CategoryItem[];
  csrfToken: string;
  onRefreshData: () => Promise<void>;
  isAdmin: boolean;
}

export const BusinessesManagerView: React.FC<BusinessesManagerViewProps> = ({
  cities,
  neighborhoods,
  categories,
  csrfToken,
  onRefreshData,
  isAdmin,
}) => {
  const [mode, setMode] = useState<'list' | 'form'>('list');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [cityFilter, setCityFilter] = useState<number>(0);
  const [qualityFilter, setQualityFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [publishIntent, setPublishIntent] = useState(false);
  const [reviewReady, setReviewReady] = useState(false);
  const [reviewTarget, setReviewTarget] = useState<BusinessItem|null>(null);
  const [reviewNote, setReviewNote] = useState('');
  const [reviewConfirmed, setReviewConfirmed] = useState(false);
  const [reviewBusy, setReviewBusy] = useState(false);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [pageBusinesses, setPageBusinesses] = useState<BusinessItem[]>([]);
  const [listError, setListError] = useState('');
  const [refreshList, setRefreshList] = useState(0);
  const [promotionBusy, setPromotionBusy] = useState(0);

  // انتخاب چندتایی و حذف امن سئو (مخصوص ادمین)
  type DeleteTargetSummary = Pick<BusinessItem, 'id' | 'name' | 'slug' | 'status'>;
  const [selectedMap, setSelectedMap] = useState<Record<number, DeleteTargetSummary>>({});
  const [deleteTargets, setDeleteTargets] = useState<DeleteTargetSummary[] | null>(null);
  const [seoMode, setSeoMode] = useState<BusinessSeoDeleteMode>('auto_seo');
  const [deleteUnusedMedia, setDeleteUnusedMedia] = useState<boolean>(true);
  const [replaceQuery, setReplaceQuery] = useState<string>('');
  const [replaceCandidates, setReplaceCandidates] = useState<BusinessItem[]>([]);
  const [replaceBusinessId, setReplaceBusinessId] = useState<number>(0);
  const [deleteBusy, setDeleteBusy] = useState<boolean>(false);
  const [deleteError, setDeleteError] = useState<string>('');
  const [mediaPickerOpen, setMediaPickerOpen] = useState<boolean>(false);

  useEffect(() => {
    if (!deleteTargets || seoMode !== 'redirect_business') return;
    let cancelled = false;
    const t = window.setTimeout(async () => {
      try {
        const targetIds = new Set(deleteTargets.map((b) => Number(b.id)));
        const res = await fetchArticleBusinesses(1, replaceQuery, 0, 0);
        if (!cancelled) {
          setReplaceCandidates(res.businesses.filter((b) => !targetIds.has(Number(b.id))));
        }
      } catch {
        if (!cancelled) setReplaceCandidates([]);
      }
    }, 200);
    return () => {
      cancelled = true;
      window.clearTimeout(t);
    };
  }, [deleteTargets, seoMode, replaceQuery]);

  const toggleSelectBusiness = (biz: BusinessItem) => {
    const id = Number(biz.id);
    setSelectedMap((prev) => {
      const next = { ...prev };
      if (next[id]) {
        delete next[id];
      } else {
        next[id] = biz;
      }
      return next;
    });
  };

  const toggleSelectAllPage = () => {
    const allOnPageSelected =
      pageBusinesses.length > 0 && pageBusinesses.every((b) => Boolean(selectedMap[Number(b.id)]));
    setSelectedMap((prev) => {
      const next = { ...prev };
      if (allOnPageSelected) {
        pageBusinesses.forEach((b) => {
          delete next[Number(b.id)];
        });
      } else {
        pageBusinesses.forEach((b) => {
          next[Number(b.id)] = b;
        });
      }
      return next;
    });
  };

  const selectDraftsOnPage = () => {
    setSelectedMap((prev) => {
      const next = { ...prev };
      pageBusinesses
        .filter((b) => b.status !== 'published')
        .forEach((b) => {
          next[Number(b.id)] = b;
        });
      return next;
    });
  };

  const openDeleteDialog = (targets: DeleteTargetSummary[]) => {
    if (!isAdmin || targets.length === 0) return;
    setDeleteTargets(targets);
    setSeoMode('auto_seo');
    setDeleteUnusedMedia(true);
    setReplaceQuery('');
    setReplaceBusinessId(0);
    setDeleteError('');
  };

  const confirmDeleteBusinesses = async () => {
    if (!deleteTargets || deleteTargets.length === 0 || deleteBusy) return;
    const ids = deleteTargets.map((b) => Number(b.id));
    if (seoMode === 'redirect_business' && replaceBusinessId <= 0) {
      setDeleteError('لطفاً یک کسب‌وکار منتشرشده را به‌عنوان مقصد ریدایرکت ۳۰۱ انتخاب کنید.');
      return;
    }

    setDeleteBusy(true);
    setDeleteError('');
    const res = await deleteBusinesses(
      {
        ids,
        seo_mode: seoMode,
        redirect_business_id: seoMode === 'redirect_business' ? replaceBusinessId : undefined,
        delete_unused_media: deleteUnusedMedia,
      },
      csrfToken
    );
    setDeleteBusy(false);

    if (!res.ok) {
      setDeleteError(res.error || 'حذف کسب‌وکارها انجام نشد.');
      return;
    }

    setSelectedMap((prev) => {
      const next = { ...prev };
      ids.forEach((id) => delete next[id]);
      return next;
    });
    setDeleteTargets(null);
    setSaveSuccess(res.message || 'حذف کسب‌وکارها با موفقیت انجام شد.');
    if (mode === 'form' && ids.includes(Number(bizId))) {
      setMode('list');
    }
    setRefreshList((v) => v + 1);
    await onRefreshData();
  };
  useEffect(() => {
    if (mode !== 'list') return;
    let cancelled = false;
    const timer = window.setTimeout(async () => {
      try {
        const data = await fetchBusinessesPage(page, cityFilter, searchQuery, qualityFilter, statusFilter);
        if (!cancelled) { setPageBusinesses(data.businesses); setTotal(data.total); setReviewReady(!!data.data_review_schema_ready); setListError(''); }
      } catch (e) { if (!cancelled) { setPageBusinesses([]); setListError(e instanceof Error ? e.message : 'خطا در دریافت فهرست'); } }
    }, 200);
    return () => {cancelled=true;window.clearTimeout(timer);};
  }, [mode, page, cityFilter, searchQuery, qualityFilter, statusFilter, refreshList]);

  const togglePromotion = async (biz:BusinessItem) => {
    setPromotionBusy(biz.id);setListError('');
    const result = await setBusinessPromotion(biz.id, !Number(biz.promoted), csrfToken);
    setPromotionBusy(0);
    if (!result.ok) {setListError(result.error || 'تغییر جایگاه ویژه ناموفق بود.');return;}
    setSaveSuccess(result.message);setRefreshList(v=>v+1);
  };

  const openDataReview = (biz:BusinessItem) => {
    setReviewTarget(biz); setReviewNote(biz.review_source_note || ''); setReviewConfirmed(false); setListError('');
  };
  const submitDataReview = async () => {
    if (!reviewTarget?.review_hash || !reviewConfirmed || reviewBusy) return;
    setReviewBusy(true);
    const result = await recordBusinessDataReview({business_id:Number(reviewTarget.id), review_hash:reviewTarget.review_hash, source_note:reviewNote, confirmed:true}, csrfToken);
    setReviewBusy(false);
    if (!result.ok) { setListError(result.error || 'ثبت بازبینی ناموفق بود.'); return; }
    setReviewTarget(null); setSaveSuccess(result.message); setRefreshList(v=>v+1);
  };

  // فیلدهای فرم یک‌صفحه‌ای کسب‌وکار (بخش ۷ قوانین UX)
  const [bizId, setBizId] = useState<number>(0);
  const [name, setName] = useState<string>('');
  const [slug, setSlug] = useState<string>('');
  const [cityId, setCityId] = useState<number>(cities[0]?.id || 1);
  const [neighborhoodId, setNeighborhoodId] = useState<number | null>(null);
  const [categoryId, setCategoryId] = useState<number>(categories[0]?.id || 1);
  const [address, setAddress] = useState<string>('');
  const [lat, setLat] = useState<string>('');
  const [lng, setLng] = useState<string>('');
  const [hours, setHours] = useState<string>('');
  const [shortDesc, setShortDesc] = useState<string>('');
  const [phone, setPhone] = useState<string>('');
  const [instagram, setInstagram] = useState<string>('');
  const [whatsapp, setWhatsapp] = useState<string>('');
  const [website, setWebsite] = useState<string>('');
  const [status, setStatus] = useState<BusinessItem['status']>('draft');
  const [tier, setTier] = useState<BusinessItem['tier']>('B');
  const [coverMediaId, setCoverMediaId] = useState<number | null>(null);
  const [coverPreviewUrl, setCoverPreviewUrl] = useState<string | null>(null);
  const [mediaFeedback, setMediaFeedback] = useState<string>('');
  const [uploadingMedia, setUploadingMedia] = useState<boolean>(false);

  // خطای کنار فیلد + لینک «برو به فیلد» (بخش ۷ قوانین UX)
  const [formError, setFormError] = useState<{ text: string; field?: string } | null>(null);
  const [saveSuccess, setSaveSuccess] = useState<string>('');

  const openNewBusinessForm = () => {
    setBizId(0);
    setName('');
    setSlug('');
    setCityId(cities[0]?.id || 1);
    setNeighborhoodId(null);
    setCategoryId(categories[0]?.id || 1);
    setAddress('');
    setLat('');
    setLng('');
    setHours('');
    setShortDesc('');
    setPhone('');
    setInstagram('');
    setWhatsapp('');
    setWebsite('');
    setStatus('draft');
    setTier('B');
    setCoverMediaId(null);
    setCoverPreviewUrl(null);
    setMediaFeedback('');
    setFormError(null);
    setPublishIntent(false);
    setMode('form');
  };

  const openEditBusinessForm = (biz: BusinessItem) => {
    setBizId(biz.id);
    setName(biz.name);
    setSlug(biz.slug);
    setCityId(biz.city_id);
    setNeighborhoodId(biz.neighborhood_id);
    setCategoryId(biz.category_id);
    setAddress(biz.address || '');
    setLat(biz.lat ? String(biz.lat) : '');
    setLng(biz.lng ? String(biz.lng) : '');
    setHours(biz.hours || '');
    setShortDesc(biz.short_desc || '');
    const primaryPhone = biz.contacts.find((c) => c.type === 'phone' || c.type === 'mobile')?.value || '';
    const ig = biz.contacts.find((c) => c.type === 'instagram')?.value || '';
    const wa = biz.contacts.find((c) => c.type === 'whatsapp')?.value || '';
    const web = biz.contacts.find((c) => c.type === 'website')?.value || '';
    setPhone(primaryPhone);
    setInstagram(ig);
    setWhatsapp(wa);
    setWebsite(web);
    setStatus(biz.status);
    setTier(biz.tier);
    setCoverMediaId(biz.cover_media_id);
    setCoverPreviewUrl(biz.cover_thumb_url || null);
    setMediaFeedback('');
    setFormError(null);
    setPublishIntent(false);
    setMode('form');
  };

  const prepareBusinessForPublication = (biz:BusinessItem) => {
    const warnings = biz.warnings?.length ? `\n\nپیش از انتشار این موارد را بررسی کنید: ${biz.warnings.join('، ')}` : '';
    if (!window.confirm(`«${biz.name}» پیش‌نویس است و اکنون در سایت نمایش داده نمی‌شود. برای انتشار، وضعیت آن را آماده می‌کنیم؛ تا زمانی که در فرم دکمه «ذخیره فوری کسب‌وکار» را نزنید، تغییری روی سایت اعمال نمی‌شود.${warnings}`)) return;
    openEditBusinessForm(biz);
    setStatus('published');
    setPublishIntent(true);
  };

  const scrollToField = (fieldName: string) => {
    const el = document.getElementById(`biz_field_${fieldName}`);
    if (el) {
      el.scrollIntoView({ behavior: 'smooth', block: 'center' });
      el.focus();
    }
  };

  // محاسبه زنده چک‌لیست غیرمسدودکننده در فرم (هیچ قانونی ذخیره را قفل نمی‌کند)
  const liveWarnings = computeBusinessWarnings(
    {
      cover_media_id: coverMediaId,
      lat: lat !== '' ? Number(lat) : null,
      lng: lng !== '' ? Number(lng) : null,
      address,
      short_desc: shortDesc,
    },
    phone.trim() ? [{ type: 'phone', value: phone, is_primary: 1, source: 'manual' }] : []
  );

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploadingMedia(true);
    setMediaFeedback('');
    setFormError(null);

    const res = await uploadMediaWebp(file, name || 'تصویر کسب‌وکار', csrfToken);
    setUploadingMedia(false);

    if (!res.ok || !res.media) {
      setFormError({ text: res.error || 'خطا در پردازش تصویر WebP', field: 'cover_media_id' });
      return;
    }

    setCoverMediaId(res.media.id);
    setCoverPreviewUrl(res.previewUrl || res.media.file_path_480);
    const origBytes = Number(res.media.original_bytes || file.size || 0);
    const webp1200 = Number(res.media.webp_1200_bytes || 0);
    const sizeInfo =
      origBytes > 0 && webp1200 > 0
        ? ` (${formatBytesFa(origBytes)} ← WebP بهینه ${formatBytesFa(webp1200)})`
        : '';
    setMediaFeedback(
      (res.message || 'تصویر با موفقیت به WebP تبدیل شد.') + sizeInfo
    );
  };

  const handleSubmitBusiness = async (e: React.FormEvent) => {
    e.preventDefault();
    setFormError(null);
    setSaveSuccess('');

    const res = await saveBusinessItem(
      {
        id: bizId > 0 ? bizId : undefined,
        name,
        slug,
        city_id: cityId,
        neighborhood_id: neighborhoodId,
        category_id: categoryId,
        address,
        lat,
        lng,
        hours,
        short_desc: shortDesc,
        phone,
        instagram,
        whatsapp,
        website,
        status,
        tier,
        cover_media_id: coverMediaId,
        cover_thumb_url: coverPreviewUrl,
      },
      csrfToken
    );

    if (!res.ok) {
      setFormError({ text: res.error || 'خطا در ذخیره کسب‌وکار', field: res.field });
      if (res.field) {
        scrollToField(res.field);
      }
      return;
    }

    let successMessage = res.message || 'کسب‌وکار با موفقیت ذخیره شد.';
    // Verify the actual saved value. If an admin's full-form save reports a
    // different status, use a minimal admin-only endpoint after all form data
    // has been saved, then require an explicit published response.
    if (isAdmin && status === 'published' && res.business?.status !== 'published') {
      const savedId = Number(res.business?.id || bizId);
      if (!savedId) {
        setFormError({ text: 'ذخیره انجام شد، اما شناسهٔ کسب‌وکار برای تأیید انتشار دریافت نشد.', field: 'status' });
        return;
      }
      const publication = await publishSavedBusiness(savedId, csrfToken);
      if (!publication.ok || publication.business?.status !== 'published') {
        setFormError({ text: publication.error || 'اطلاعات ذخیره شد، اما سرور انتشار را تأیید نکرد. فهرست را تازه کنید و وضعیت را بررسی کنید.', field: 'status' });
        return;
      }
      successMessage = 'اطلاعات ذخیره و وضعیت منتشرشده توسط سرور تأیید شد.';
    }

    setSaveSuccess(successMessage);
    setPublishIntent(false);
    await onRefreshData();
    setMode('list');
  };

  const filteredBusinesses = pageBusinesses;

  const cityNeighborhoods = neighborhoods.filter((n) => n.city_id === Number(cityId));

  return (
    <div className="space-y-6">
      {saveSuccess && (
        <div className="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm flex items-center justify-between">
          <span className="flex items-center gap-2 font-medium">
            <CheckCircle2 className="w-4 h-4 text-emerald-700 shrink-0" />
            {saveSuccess}
          </span>
          <button
            type="button"
            onClick={() => setSaveSuccess('')}
            className="text-xs text-emerald-800 underline"
          >
            بستن
          </button>
        </div>
      )}

      {mode === 'list' ? (
        <section className="bg-white border border-slate-200 rounded-xl p-6">
          <div className="flex flex-wrap items-center justify-between gap-4 pb-5 mb-5 border-b border-slate-200">
            <div>
              <h2 className="text-base font-bold text-slate-900 flex items-center gap-2">
                <Store className="w-4 h-4 text-emerald-700" />
                فهرست کسب‌وکارها ({total} مورد)
              </h2>
              <p className="text-xs text-slate-500 mt-1">
پیش‌نویس‌ها را با فیلتر «وضعیت انتشار» پیدا کنید؛ آن‌ها به‌تنهایی وارد صندوق بازبینی نمی‌شوند. فقط موارد «در انتظار بازبینی» در صندوق هستند. پیش‌نویس را ویرایش کنید و وضعیت «منتشرشده» را انتخاب و ذخیره کنید.
              </p>
            </div>

            <button
              type="button"
              onClick={openNewBusinessForm}
              className="inline-flex items-center gap-2 px-4 py-2.5 bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold rounded-lg transition-colors whitespace-nowrap shrink-0"
            >
              <Plus className="w-4 h-4" />
              افزودن کسب‌وکار جدید
            </button>
          </div>

          {/* نوار جستجو و فیلتر شهر */}
          <div className="grid grid-cols-1 sm:grid-cols-4 gap-3 mb-5">
            <div className="sm:col-span-2">
              <input
                type="text"
                value={searchQuery}
                onChange={(e) => {setPage(1);setSearchQuery(e.target.value);}}
                placeholder="جستجو بر اساس نام فارسی، اسلاگ یا آدرس..."
                className="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg focus:bg-white focus:outline-none focus:border-emerald-700"
              />
            </div>
            <div>
              <select
                value={cityFilter}
                onChange={(e) => {setPage(1);setCityFilter(Number(e.target.value));}}
                className="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg"
              >
                <option value={0}>همه شهرهای گیلان</option>
                {cities.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name_fa}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <select aria-label="فیلتر وضعیت انتشار کسب‌وکار" value={statusFilter} onChange={e=>{setPage(1);setStatusFilter(e.target.value);setReviewTarget(null);}} className="w-full px-3.5 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg">
                <option value="">همه وضعیت‌ها</option>
                <option value="draft">پیش‌نویس</option>
                <option value="pending_review">در انتظار بازبینی</option>
                <option value="published">منتشرشده</option>
                <option value="hidden">مخفی</option>
                <option value="rejected">ردشده</option>
              </select>
            </div>
          </div>

          <label className="block text-sm mb-4">اولویت تکمیل اطلاعات <select aria-label="فیلتر کیفیت اطلاعات" value={qualityFilter} onChange={e=>{setPage(1);setReviewTarget(null);setQualityFilter(e.target.value);}} className="mr-2 px-3 py-2 text-sm bg-slate-50 border border-slate-300 rounded-lg">
            <option value="">همه کسب‌وکارها</option><option value="incomplete">هر نقص در اطلاعات اصلی</option><option value="photo">بدون عکس قابل انتخاب</option><option value="description">بدون توضیح</option><option value="phone">بدون شماره تماس</option><option value="category">بدون دسته معتبر</option><option value="address">بدون آدرس</option><option value="unreviewed">بازبینی ثبت‌نشده یا قدیمی</option>
          </select></label>
          {!reviewReady&&<p className="text-xs text-amber-800 bg-amber-50 p-3 mb-4 rounded">برای ثبت نتیجه بازبینی، مدیر باید پس از پشتیبان‌گیری جدول‌های بخش جدید را در تنظیمات یا تبلیغات آماده کند. فیلترهای نقص همچنان کار می‌کنند.</p>}
          {listError && <p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 mb-4 rounded">{listError}</p>}

          {isAdmin && (
            <div className="mb-4 p-3 rounded-lg border border-slate-200 bg-slate-50 flex flex-wrap items-center justify-between gap-3 text-xs">
              <div className="flex flex-wrap items-center gap-2">
                <button
                  type="button"
                  onClick={toggleSelectAllPage}
                  className="px-3 py-1.5 rounded border border-slate-300 bg-white font-semibold hover:bg-slate-100"
                >
                  انتخاب / لغو همه این صفحه
                </button>
                <button
                  type="button"
                  onClick={selectDraftsOnPage}
                  className="px-3 py-1.5 rounded border border-slate-300 bg-white font-semibold hover:bg-slate-100"
                >
                  انتخاب پیش‌نویس‌های این صفحه
                </button>
                {Object.keys(selectedMap).length > 0 && (
                  <button
                    type="button"
                    onClick={() => setSelectedMap({})}
                    className="px-2.5 py-1.5 rounded text-slate-600 hover:underline"
                  >
                    پاک کردن انتخاب‌ها
                  </button>
                )}
                <span className="text-slate-600">
                  انتخاب‌شده: <strong>{Object.keys(selectedMap).length.toLocaleString('fa-IR')}</strong> مورد
                </span>
              </div>

              {Object.keys(selectedMap).length > 0 && (
                <button
                  type="button"
                  onClick={() => openDeleteDialog(Object.values(selectedMap))}
                  className="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-rose-700 hover:bg-rose-800 text-white font-bold"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  حذف انتخاب‌شده‌ها ({Object.keys(selectedMap).length.toLocaleString('fa-IR')} مورد) با محافظت سئو
                </button>
              )}
            </div>
          )}

          {reviewTarget&&<section className="mb-4 p-4 rounded-lg border border-blue-200 bg-blue-50/50 space-y-3" aria-label="ثبت بازبینی اطلاعات">
            <h3 className="font-bold">بازبینی انسانی «{reviewTarget.name}»</h3>
            <p className="text-xs leading-6 text-slate-700">پیش از تأیید، موارد زیر را با منبع واقعی تطبیق دهید و مجوز استفاده از تصویر را بررسی کنید. ذخیره معمولی، اطلاعات را تأییدشده محسوب نمی‌کند.</p>
            <div className="grid sm:grid-cols-2 gap-3 text-sm"><div className="space-y-1"><p>شهر / دسته: {reviewTarget.city_name || 'نامشخص'} / {reviewTarget.category_name || 'نامشخص'}</p><p>آدرس: {reviewTarget.address || 'ثبت نشده'}</p><p>تلفن: <span dir="ltr">{reviewTarget.contacts.find(c=>c.type==='phone'||c.type==='mobile')?.value || 'ثبت نشده'}</span></p><p>توضیح: {reviewTarget.short_desc || 'ثبت نشده'}</p></div>{reviewTarget.cover_thumb_url?<img src={reviewTarget.cover_thumb_url} alt="تصویر مورد بررسی" className="max-w-full max-h-36 rounded border"/>:<p>عکس قابل انتخاب ثبت نشده است.</p>}</div>
            <label className="block text-sm">منبع اطلاعات و وضعیت مجوز تصویر<textarea aria-label="منبع بازبینی و مجوز تصویر" className="w-full border rounded p-2 mt-1" rows={3} maxLength={1000} value={reviewNote} onChange={e=>setReviewNote(e.target.value)} placeholder="منبع، تاریخ بررسی و چگونگی اجازه استفاده از تصویر را ثبت کنید."/></label>
            <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={reviewConfirmed} onChange={e=>setReviewConfirmed(e.target.checked)}/> اطلاعات بالا و اجازه استفاده از تصویر را شخصاً بررسی کرده‌ام.</label>
            <div className="flex gap-2"><button type="button" disabled={!reviewReady||!reviewConfirmed||reviewNote.trim().length<8||reviewBusy} onClick={()=>void submitDataReview()} className="px-3 py-2 rounded bg-blue-700 text-white text-sm disabled:opacity-50">ثبت بازبینی همین اطلاعات</button><button type="button" className="px-3 py-2 rounded border text-sm" onClick={()=>setReviewTarget(null)}>بستن</button></div>
          </section>}
          <div className="overflow-x-auto">
            <table className="w-full text-sm border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 text-xs">
                  {isAdmin && (
                    <th className="py-3 px-2 text-center w-10">
                      <input
                        type="checkbox"
                        aria-label="انتخاب همه کسب‌وکارهای این صفحه"
                        checked={
                          filteredBusinesses.length > 0 &&
                          filteredBusinesses.every((b) => Boolean(selectedMap[Number(b.id)]))
                        }
                        onChange={toggleSelectAllPage}
                        className="w-4 h-4"
                      />
                    </th>
                  )}
                  <th className="py-3 px-3 text-right font-semibold">نام و مسیر (شهر / کسب‌وکار)</th>
                  <th className="py-3 px-3 text-right font-semibold">شهر · محله · دسته</th>
                  <th className="py-3 px-3 text-right font-semibold">وضعیت و Tier</th>
                  <th className="py-3 px-3 text-right font-semibold">چک‌لیست غیرمسدودکننده</th>
                  <th className="py-3 px-3 text-left font-semibold">عملیات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {filteredBusinesses.map((biz) => (
                  <tr
                    key={biz.id}
                    className={`hover:bg-slate-50 ${
                      selectedMap[Number(biz.id)] ? 'bg-blue-50/40' : ''
                    }`}
                  >
                    {isAdmin && (
                      <td className="py-3.5 px-2 text-center">
                        <input
                          type="checkbox"
                          aria-label={`انتخاب ${biz.name}`}
                          checked={Boolean(selectedMap[Number(biz.id)])}
                          onChange={() => toggleSelectBusiness(biz)}
                          className="w-4 h-4"
                        />
                      </td>
                    )}
                    <td className="py-3.5 px-3">
                      <div className="font-semibold text-slate-900">{biz.name}</div>
                      <div className="font-mono text-xs text-slate-500 mt-0.5" dir="ltr">
                        /{biz.city_slug || 'city'}/{biz.slug}/
                      </div>
                    </td>
                    <td className="py-3.5 px-3 text-xs text-slate-600">
                      <span>{biz.city_name}</span>
                      {biz.neighborhood_name && (
                        <>
                          <span className="mx-1.5">·</span>
                          <span>{biz.neighborhood_name}</span>
                        </>
                      )}
                      <span className="mx-1.5">·</span>
                      <span>{biz.category_name}</span>
                    </td>
                    <td className="py-3.5 px-3 text-xs">
                      <span
                        className={`font-semibold ${
                          biz.status === 'published'
                            ? 'text-emerald-700'
                            : biz.status === 'pending_review'
                            ? 'text-amber-700'
                            : biz.status === 'rejected' ? 'text-rose-700' : 'text-slate-600'
                        }`}
                      >
                        {biz.status === 'published'
                          ? 'منتشرشده'
                          : biz.status === 'pending_review'
                          ? 'در انتظار بازبینی'
                          : biz.status === 'hidden' ? 'مخفی' : biz.status === 'rejected' ? 'ردشده' : 'پیش‌نویس'}
                      </span>
                      <span className="mx-1.5 text-slate-300">·</span>
                      <span className="font-mono text-slate-700">Tier {biz.tier}</span>
                    </td>
                    <td className="py-3.5 px-3 text-xs text-slate-600">
                      {biz.warnings && biz.warnings.length > 0 ? (
                        <span className="text-amber-800">{biz.warnings.join(' · ')}</span>
                      ) : (
                        <span className="text-emerald-700 font-medium">نقص ظاهری شناسایی نشد</span>
                      )}
                      <span className={`block mt-1 ${biz.review_current?'text-blue-700':'text-amber-800'}`}>{biz.review_current ? `بازبینی انسانی ثبت‌شده · ${biz.reviewed_at || ''}` : biz.reviewed_at ? 'تغییر کرده؛ نیازمند بازبینی دوباره' : 'بازبینی انسانی ثبت نشده'}</span>
                    </td>
                    <td className="py-3.5 px-3 text-left">
                      <button
                        type="button"
                        onClick={() => openEditBusinessForm(biz)}
                        className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700 hover:text-emerald-900"
                      >
                        <Edit3 className="w-3.5 h-3.5" />
                        ویرایش
                      </button>
                      {isAdmin && biz.status === 'draft' && <button type="button" onClick={()=>prepareBusinessForPublication(biz)} className="block mt-2 text-xs font-bold text-emerald-800 hover:underline">انتشار پیش‌نویس</button>}
                      <button type="button" disabled={!reviewReady} onClick={()=>openDataReview(biz)} className="block mt-2 text-xs font-semibold text-blue-700 hover:underline disabled:opacity-50">بازبینی اطلاعات</button>
                      {isAdmin && <button type="button" disabled={promotionBusy===biz.id} onClick={()=>togglePromotion(biz)} className="block mt-2 text-xs font-semibold text-blue-700 hover:underline disabled:opacity-50">
                        {Number(biz.promoted) ? 'حذف از جایگاه ویژه' : 'قرار دادن در جایگاه ویژه'}
                      </button>}
                      {!!Number(biz.promoted) && <span className="block mt-1 text-xs text-amber-700">جایگاه ویژه فعال است</span>}
                      {isAdmin && (
                        <button
                          type="button"
                          onClick={() => openDeleteDialog([biz])}
                          className="inline-flex items-center gap-1 mt-2 text-xs font-semibold text-rose-700 hover:underline"
                        >
                          <Trash2 className="w-3 h-3" />
                          حذف
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex gap-3 items-center mt-4 text-sm">
            <button type="button" disabled={page<=1} onClick={()=>setPage(p=>p-1)} className="px-3 py-1 rounded border disabled:opacity-40">قبلی</button>
            <span>صفحه {page} از {Math.max(1,Math.ceil(total/50))}</span>
            <button type="button" disabled={page*50>=total} onClick={()=>setPage(p=>p+1)} className="px-3 py-1 rounded border disabled:opacity-40">بعدی</button>
          </div>
        </section>
      ) : (
        /* فرم یک‌صفحه‌ای ثبت/ویرایش کسب‌وکار با بخش‌های ثابت و دکمه ذخیره Sticky (بخش ۷ قوانین UX) */
        <form onSubmit={handleSubmitBusiness} className="space-y-6 pb-20 relative">
          <div className="bg-white border border-slate-200 rounded-xl p-6 space-y-6">
            <div className="flex items-center justify-between pb-4 border-b border-slate-200">
              <div>
                <h2 className="text-base font-bold text-slate-900">
                  {bizId > 0 ? `ویرایش کسب‌وکار «${name}»` : 'ثبت سریع کسب‌وکار جدید (فرم یک‌صفحه‌ای)'}
                </h2>
                <p className="text-xs text-slate-500 mt-1">
                  هیچ فیلدی (به‌جز نام، شهر و دسته) ذخیره را قفل نمی‌کند؛ موارد ناقص در چک‌لیست غیرمسدودکننده پایین نمایش داده می‌شوند.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setMode('list')}
                className="px-3.5 py-1.5 text-xs font-semibold text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200"
              >
                بازگشت به لیست
              </button>
            </div>

            {/* بنر خطا با لینک «برو به فیلد» */}
            {formError && (
              <div className="p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-900 text-xs flex items-center justify-between gap-4">
                <div className="flex items-center gap-2.5">
                  <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
                  <span className="font-semibold">{formError.text}</span>
                </div>
                {formError.field && (
                  <button
                    type="button"
                    onClick={() => scrollToField(formError.field!)}
                    className="inline-flex items-center gap-1 underline font-bold text-rose-800 whitespace-nowrap shrink-0"
                  >
                    برو به فیلد مربوطه
                    <ArrowUpRight className="w-3.5 h-3.5" />
                  </button>
                )}
              </div>
            )}

            {publishIntent && <div role="status" className="p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-900 text-sm"><strong>آمادهٔ انتشار:</strong> وضعیت روی «منتشرشده» تنظیم شده، اما هنوز چیزی عمومی نشده است. اطلاعات را مرور کنید و دکمهٔ «ذخیره فوری کسب‌وکار» را بزنید تا منتشر شود.</div>}

            {/* چک‌لیست غیرمسدودکننده بالای فرم */}
            <div className="p-4 rounded-lg bg-slate-50 border border-slate-200">
              <div className="text-xs font-bold text-slate-800 mb-1.5">
                چک‌لیست غیرمسدودکننده کیفیت داده (مانع ذخیره نمی‌شود):
              </div>
              {liveWarnings.length === 0 ? (
                <div className="text-xs text-emerald-700 font-semibold">
                  تمام اطلاعات اصلی (عکس، شماره تماس، مختصات، آدرس و توضیح) کامل است.
                </div>
              ) : (
                <div className="text-xs text-amber-800 leading-relaxed">
                  {liveWarnings.join('  ·  ')}
                </div>
              )}
            </div>

            {/* بخش ثابت ۱: هویت، شهر و دسته‌بندی */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۱. اطلاعات پایه، شهر و اسلاگ
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label htmlFor="biz_field_name" className="block text-xs font-semibold text-slate-800">
                    نام کسب‌وکار (فارسی - الزامی)
                  </label>
                  <input
                    id="biz_field_name"
                    type="text"
                    value={name}
                    onChange={(e) => {
                      setName(e.target.value);
                      if (!bizId) {
                        setSlug(suggestLatinSlug(e.target.value, 'biz'));
                      }
                    }}
                    placeholder="مثلاً: کافه لمیز رشت"
                    className={`mt-1 w-full px-3.5 py-2.5 text-sm bg-white border rounded-lg focus:outline-none ${
                      formError?.field === 'name'
                        ? 'border-rose-500 bg-rose-50/30'
                        : 'border-slate-300 focus:border-emerald-700'
                    }`}
                  />
                  {formError?.field === 'name' && (
                    <p className="text-xs text-rose-600 mt-1">{formError.text}</p>
                  )}
                </div>

                <div>
                  <div className="flex items-center justify-between">
                    <label htmlFor="biz_field_slug" className="block text-xs font-semibold text-slate-800">
                      اسلاگ لاتین (مسیر: <code className="font-mono">/{cities.find(c=>Number(c.id)===Number(cityId))?.slug || 'city'}/{slug || '...'}/</code>)
                    </label>
                    <button
                      type="button"
                      onClick={() => setSlug(suggestLatinSlug(name, 'biz'))}
                      className="text-[11px] font-medium text-emerald-700 hover:underline flex items-center gap-1"
                    >
                      <Sparkles className="w-3 h-3" />
                      پیشنهاد خودکار اسلاگ
                    </button>
                  </div>
                  <input
                    id="biz_field_slug"
                    type="text"
                    dir="ltr"
                    value={slug}
                    onChange={(e) => setSlug(e.target.value)}
                    placeholder="cafe-lamiz-rasht"
                    className={`mt-1 w-full px-3.5 py-2.5 text-sm font-mono bg-white border rounded-lg focus:outline-none ${
                      formError?.field === 'slug'
                        ? 'border-rose-500 bg-rose-50/30'
                        : 'border-slate-300 focus:border-emerald-700'
                    }`}
                  />
                  {formError?.field === 'slug' && (
                    <p className="text-xs text-rose-600 mt-1">{formError.text}</p>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                  <label htmlFor="biz_field_city_id" className="block text-xs font-semibold text-slate-800">
                    شهر
                  </label>
                  <select
                    id="biz_field_city_id"
                    value={cityId}
                    onChange={(e) => {
                      setCityId(Number(e.target.value));
                      setNeighborhoodId(null);
                    }}
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    {cities.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name_fa}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label htmlFor="biz_field_neighborhood_id" className="block text-xs font-semibold text-slate-800">
                    محله (اختیاری)
                  </label>
                  <select
                    id="biz_field_neighborhood_id"
                    value={neighborhoodId || ''}
                    onChange={(e) =>
                      setNeighborhoodId(e.target.value ? Number(e.target.value) : null)
                    }
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="">— انتخاب محله —</option>
                    {cityNeighborhoods.map((n) => (
                      <option key={n.id} value={n.id}>
                        {n.name_fa}
                      </option>
                    ))}
                  </select>
                </div>

                <div>
                  <label htmlFor="biz_field_category_id" className="block text-xs font-semibold text-slate-800">
                    دسته‌بندی
                  </label>
                  <select
                    id="biz_field_category_id"
                    value={categoryId}
                    onChange={(e) => setCategoryId(Number(e.target.value))}
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    {categories.map((cat) => (
                      <option key={cat.id} value={cat.id}>
                        {cat.name_fa}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
            </div>

            {/* بخش ثابت ۲: تماس، شبکه‌های اجتماعی و آدرس */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۲. شماره تماس، آدرس و موقعیت نقشه (نرمال‌سازی خودکار به +98 و 013)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-800">
                    تلفن ثابت یا موبایل اصلی
                  </label>
                  <input
                    type="text"
                    dir="ltr"
                    value={phone}
                    onChange={(e) => setPhone(e.target.value)}
                    onBlur={() => setPhone(normalizeIranPhone(phone))}
                    placeholder="01333112233 یا 0911..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">پیج اینستاگرام</label>
                  <input
                    type="text"
                    dir="ltr"
                    value={instagram}
                    onChange={(e) => setInstagram(e.target.value)}
                    placeholder="cafe.rasht"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">شماره واتساپ</label>
                  <input
                    type="text"
                    dir="ltr"
                    value={whatsapp}
                    onChange={(e) => setWhatsapp(e.target.value)}
                    onBlur={() => setWhatsapp(normalizeIranPhone(whatsapp))}
                    placeholder="+98911..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">وب‌سایت</label>
                  <input
                    type="url"
                    dir="ltr"
                    value={website}
                    onChange={(e) => setWebsite(e.target.value)}
                    placeholder="https://..."
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="md:col-span-2">
                  <label className="block text-xs font-semibold text-slate-800">آدرس دقیق پستی</label>
                  <input
                    type="text"
                    value={address}
                    onChange={(e) => setAddress(e.target.value)}
                    placeholder="مثلاً: رشت، گلسار، بلوار دیلمان، نبش خیابان آذراندامی"
                    className="mt-1 w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">ساعت کاری</label>
                  <input
                    type="text"
                    value={hours}
                    onChange={(e) => setHours(e.target.value)}
                    placeholder="۸ صبح تا ۱۱ شب"
                    className="mt-1 w-full px-3.5 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                  <label className="block text-xs font-semibold text-slate-800">عرض جغرافیایی (Lat)</label>
                  <input
                    type="number"
                    step="any"
                    dir="ltr"
                    value={lat}
                    onChange={(e) => setLat(e.target.value)}
                    placeholder="37.2808"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">طول جغرافیایی (Lng)</label>
                  <input
                    type="number"
                    step="any"
                    dir="ltr"
                    value={lng}
                    onChange={(e) => setLng(e.target.value)}
                    placeholder="49.5830"
                    className="mt-1 w-full px-3 py-2 text-sm font-mono bg-white border border-slate-300 rounded-lg"
                  />
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">وضعیت انتشار</label>
                  <select
                    value={status}
                    onChange={(e) => {setStatus(e.target.value as BusinessItem['status']);setPublishIntent(false);}}
                    className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="published">منتشرشده (published)</option>
                    <option value="pending_review">در انتظار بازبینی (pending_review)</option>
                    <option value="draft">پیش‌نویس (draft)</option>
                    <option value="hidden">مخفی (hidden)</option>
                  </select>
                </div>
                <div>
                  <label className="block text-xs font-semibold text-slate-800">رده کیفی (Tier)</label>
                  <select
                    value={tier}
                    onChange={(e) => setTier(e.target.value as BusinessItem['tier'])}
                    className="mt-1 w-full px-3 py-2 text-sm bg-white border border-slate-300 rounded-lg"
                  >
                    <option value="A">Tier A (۲۰٪ برتر)</option>
                    <option value="B">Tier B (استاندارد)</option>
                    <option value="C">Tier C (پایه)</option>
                  </select>
                </div>
              </div>
            </div>

            {/* بخش ثابت ۳: توضیح کوتاه و آپلود تصویر WebP با GD */}
            <div className="space-y-4">
              <h3 className="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">
                ۳. توضیح کوتاه و تصویر کاور (تبدیل خودکار به WebP در سایزهای ۴۸۰ / ۸۰۰ / ۱۲۰۰)
              </h3>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div className="md:col-span-2">
                  <label className="block text-xs font-semibold text-slate-800">
                    توضیح کوتاه معرفی کسب‌وکار (در مرحله S5 توسط AI هم قابل تولید است)
                  </label>
                  <textarea
                    rows={4}
                    value={shortDesc}
                    onChange={(e) => setShortDesc(e.target.value)}
                    placeholder="توضیح مستند بر اساس اطلاعات واقعی کسب‌وکار..."
                    className="mt-1 w-full px-3.5 py-2.5 text-sm bg-white border border-slate-300 rounded-lg"
                  />
                </div>

                <div id="biz_field_cover_media_id" className="space-y-2">
                  <label className="block text-xs font-semibold text-slate-800">
                    تصویر کاور (تبدیل خودکار ۱۰۰٪ به WebP · حداکثر ۸MB)
                  </label>
                  <MediaDimensionGuideBox slot="business_cover" compact />
                  <div className="p-4 border border-dashed border-slate-300 rounded-lg bg-slate-50 text-center space-y-2">
                    {coverPreviewUrl ? (
                      <div className="space-y-2">
                        <img
                          src={coverPreviewUrl}
                          alt={name || 'کاور'}
                          referrerPolicy="no-referrer"
                          className="w-full h-28 object-cover rounded border border-slate-200"
                        />
                        <div className="text-[11px] text-emerald-700 font-medium">
                          {mediaFeedback || `رسانه #${coverMediaId} آماده است`}
                        </div>
                      </div>
                    ) : (
                      <div className="py-3 text-xs text-slate-500 flex flex-col items-center gap-1.5">
                        <ImageIcon className="w-6 h-6 text-slate-400" />
                        <span>بدون عکس (جایگزین خودکار با آیکون دسته)</span>
                      </div>
                    )}

                    <div className="flex flex-wrap items-center justify-center gap-2 pt-1">
                      <label className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-300 rounded-md text-xs font-semibold text-slate-700 hover:bg-slate-100 cursor-pointer">
                        <Upload className="w-3.5 h-3.5" />
                        <span>{uploadingMedia ? 'در حال بهینه‌سازی تصویر...' : 'انتخاب تصویر (بهینه‌سازی خودکار · GIF متحرک حفظ می‌شود)'}</span>
                        <input
                          type="file"
                          accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/avif"
                          onChange={handleFileChange}
                          className="hidden"
                        />
                      </label>
                      <button
                        type="button"
                        onClick={() => setMediaPickerOpen(true)}
                        className="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-slate-300 rounded-md text-xs font-semibold text-slate-700 hover:bg-slate-100"
                      >
                        <FolderOpen className="w-3.5 h-3.5" />
                        <span>کتابخانه رسانه</span>
                      </button>
                      {coverMediaId && (
                        <button
                          type="button"
                          onClick={() => {
                            setCoverMediaId(null);
                            setCoverPreviewUrl(null);
                            setMediaFeedback('');
                          }}
                          className="px-2.5 py-1.5 bg-white border border-rose-200 rounded-md text-xs font-semibold text-rose-700 hover:bg-rose-50"
                        >
                          حذف کاور
                        </button>
                      )}
                    </div>
                    <MediaLibraryPickerModal
                      open={mediaPickerOpen}
                      onClose={() => setMediaPickerOpen(false)}
                      onSelect={(item) => {
                        setCoverMediaId(item.id);
                        setCoverPreviewUrl(item.file_path_480 || item.file_path_800 || item.file_path_1200);
                        setMediaFeedback(`تصویر #${item.id} (WebP) از کتابخانه انتخاب شد.`);
                      }}
                    />
                  </div>
                </div>
              </div>
            </div>
          </div>

          {/* نوار دکمه ذخیره Sticky پایین صفحه (بخش ۷ قوانین UX) */}
          <div className="sticky bottom-4 z-20 bg-slate-900 text-white px-6 py-3.5 rounded-xl shadow-lg flex items-center justify-between gap-4">
            <div className="text-xs text-slate-300">
              {liveWarnings.length > 0 ? (
                <span>
                  یادآوری غیرمسدودکننده: {liveWarnings.length} مورد قابل تکمیل ({liveWarnings[0]})
                </span>
              ) : (
                <span className="text-emerald-400 font-medium">تمامی فیلدهای کلیدی تکمیل شده‌اند.</span>
              )}
            </div>
            <div className="flex items-center gap-3">
              {isAdmin && bizId > 0 && (
                <button
                  type="button"
                  onClick={() => {
                    const current: DeleteTargetSummary =
                      pageBusinesses.find((b) => Number(b.id) === Number(bizId)) || {
                        id: bizId,
                        name,
                        slug,
                        status,
                      };
                    openDeleteDialog([current]);
                  }}
                  className="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-rose-300 hover:text-rose-200"
                >
                  <Trash2 className="w-3.5 h-3.5" />
                  حذف این کسب‌وکار
                </button>
              )}
              <button
                type="button"
                onClick={() => setMode('list')}
                className="px-4 py-2 text-xs font-semibold text-slate-300 hover:text-white"
              >
                انصراف
              </button>
              <button
                type="submit"
                className="px-6 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-lg transition-colors whitespace-nowrap shrink-0"
              >
                ذخیره فوری کسب‌وکار
              </button>
            </div>
          </div>
        </form>
      )}

      {/* پنجره حذف امن تکی / گروهی کسب‌وکارها با محافظت کامل از سئوی سایت */}
      {deleteTargets && (
        <div
          role="dialog"
          aria-modal="true"
          aria-label="حذف کسب‌وکارها و تنظیم استراتژی سئو"
          className="fixed inset-0 z-50 bg-slate-950/60 flex items-center justify-center p-4"
        >
          <div className="bg-white rounded-xl border border-slate-200 max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-xl p-5 space-y-4">
            <div className="flex items-start justify-between gap-3 pb-3 border-b border-slate-200">
              <div>
                <h3 className="font-bold text-base text-slate-900 flex items-center gap-2">
                  <ShieldCheck className="w-5 h-5 text-emerald-700 shrink-0" />
                  حذف {deleteTargets.length.toLocaleString('fa-IR')} کسب‌وکار انتخاب‌شده با استراتژی سئو
                </h3>
                <p className="text-xs text-slate-600 mt-1">
                  گزینهٔ مناسب را انتخاب کنید تا حذف موارد تستی یا واقعی هیچ خطای خزش (Crawl Error) یا افت سئو در گوگل ایجاد نکند.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setDeleteTargets(null)}
                disabled={deleteBusy}
                className="p-1 rounded hover:bg-slate-100 text-slate-500"
                aria-label="بستن پنجره حذف"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {(() => {
              const pubCount = deleteTargets.filter((b) => b.status === 'published').length;
              const draftCount = deleteTargets.length - pubCount;
              return (
                <div className="rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs space-y-1.5">
                  <div className="flex flex-wrap gap-3 font-semibold">
                    <span className="text-slate-800">
                      مجموع انتخاب‌شده: {deleteTargets.length.toLocaleString('fa-IR')} مورد
                    </span>
                    <span className="text-emerald-800">
                      پیش‌نویس / منتشرنشده: {draftCount.toLocaleString('fa-IR')} مورد
                    </span>
                    <span className={pubCount > 0 ? 'text-amber-800' : 'text-slate-500'}>
                      منتشرشده در سایت عمومی: {pubCount.toLocaleString('fa-IR')} مورد
                    </span>
                  </div>
                  <div className="text-slate-600 truncate">
                    نمونه موارد:{' '}
                    {deleteTargets
                      .slice(0, 5)
                      .map((b) => b.name)
                      .join('، ')}
                    {deleteTargets.length > 5 ? ' و …' : ''}
                  </div>
                </div>
              );
            })()}

            {deleteError && (
              <p role="alert" className="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs">
                {deleteError}
              </p>
            )}

            <fieldset className="space-y-2.5 text-xs">
              <legend className="font-bold text-slate-900 mb-1">
                استراتژی سئو برای نشانی‌های عمومی (مخصوص موارد منتشرشده):
              </legend>

              <label className="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 hover:border-emerald-600 cursor-pointer bg-emerald-50/30">
                <input
                  type="radio"
                  name="seo_delete_mode"
                  checked={seoMode === 'auto_seo'}
                  onChange={() => setSeoMode('auto_seo')}
                  className="mt-0.5"
                />
                <div>
                  <div className="font-bold text-slate-900">
                    هوشمند و استاندارد گوگل (پیشنهادی — ۴۱۰ Gone برای منتشرشده‌ها + پاک‌سازی کامل پیش‌نویس‌ها)
                  </div>
                  <p className="text-slate-600 mt-0.5 leading-5">
                    پیش‌نویس‌ها و ایمپورت‌های تستی منتشرنشده کاملاً پاک و اسلاگشان برای ورود مجدد JSON آزاد می‌شود. برای کسب‌وکارهای منتشرشده کد استاندارد <strong>HTTP 410 (Gone) + noindex</strong> همراه با دکمه هدایت کاربر به صفحه شهر و دسته ثبت می‌شود تا گوگل بدون خطای ۴۰۴ آن را از ایندکس خارج کند.
                  </p>
                </div>
              </label>

              <label className="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 hover:border-blue-600 cursor-pointer">
                <input
                  type="radio"
                  name="seo_delete_mode"
                  checked={seoMode === 'redirect_category'}
                  onChange={() => setSeoMode('redirect_category')}
                  className="mt-0.5"
                />
                <div>
                  <div className="font-bold text-slate-900">
                    ریدایرکت دائمی 301 به صفحه دسته و شهر مرتبط (<span dir="ltr">/city/شهر/دسته/</span>)
                  </div>
                  <p className="text-slate-600 mt-0.5 leading-5">
                    مناسب زمانی که کسب‌وکار منتشرشده لینک خارجی یا ورودی جستجو دارد و می‌خواهید اعتبار سئو به فهرست همان دسته در همان شهر منتقل شود.
                  </p>
                </div>
              </label>

              <label className="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 hover:border-blue-600 cursor-pointer">
                <input
                  type="radio"
                  name="seo_delete_mode"
                  checked={seoMode === 'redirect_business'}
                  onChange={() => setSeoMode('redirect_business')}
                  className="mt-0.5"
                />
                <div className="w-full space-y-2">
                  <div>
                    <div className="font-bold text-slate-900">
                      ریدایرکت دائمی 301 به یک کسب‌وکار منتشرشدهٔ جایگزین (ادغام مورد تکراری)
                    </div>
                    <p className="text-slate-600 mt-0.5 leading-5">
                      مناسب زمانی که یک کسب‌وکار دو بار ثبت شده و می‌خواهید نسخهٔ اضافی به نسخهٔ اصلی ریدایرکت شود.
                    </p>
                  </div>
                  {seoMode === 'redirect_business' && (
                    <div className="p-2.5 rounded bg-slate-50 border border-slate-200 space-y-2">
                      <input
                        type="text"
                        value={replaceQuery}
                        onChange={(e) => setReplaceQuery(e.target.value)}
                        placeholder="جستجوی نام کسب‌وکار منتشرشدهٔ مقصد..."
                        className="w-full px-2.5 py-1.5 border border-slate-300 rounded bg-white text-xs"
                      />
                      <select
                        aria-label="کسب‌وکار جایگزین مقصد ریدایرکت ۳۰۱"
                        value={replaceBusinessId}
                        onChange={(e) => setReplaceBusinessId(Number(e.target.value))}
                        className="w-full px-2.5 py-1.5 border border-slate-300 rounded bg-white text-xs"
                      >
                        <option value={0}>— انتخاب کسب‌وکار منتشرشدهٔ مقصد —</option>
                        {replaceCandidates.map((cand) => (
                          <option key={cand.id} value={cand.id}>
                            {cand.name} ({cand.city_name || ''} · /{cand.city_slug}/{cand.slug}/)
                          </option>
                        ))}
                      </select>
                    </div>
                  )}
                </div>
              </label>

              <label className="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 hover:border-amber-600 cursor-pointer">
                <input
                  type="radio"
                  name="seo_delete_mode"
                  checked={seoMode === 'purge_clean'}
                  onChange={() => setSeoMode('purge_clean')}
                  className="mt-0.5"
                />
                <div>
                  <div className="font-bold text-slate-900">
                    حذف کامل و آزادسازی اسلاگ بدون ثبت ۴۱۰/۳۰۱ (مخصوص موارد تستی که در گوگل ایندکس نشده‌اند)
                  </div>
                  <p className="text-slate-600 mt-0.5 leading-5">
                    اگر موردی را به‌صورت آزمایشی منتشر کرده‌اید و می‌خواهید بدون باقی‌ماندن قانون URL همان اسلاگ را دوباره وارد کنید، این گزینه را انتخاب کنید.
                  </p>
                </div>
              </label>
            </fieldset>

            <label className="flex items-start gap-2 text-xs text-slate-800 bg-slate-50 border border-slate-200 rounded-lg p-3 cursor-pointer">
              <input
                type="checkbox"
                checked={deleteUnusedMedia}
                onChange={(e) => setDeleteUnusedMedia(e.target.checked)}
                className="mt-0.5"
              />
              <span>
                <strong>پاک‌سازی خودکار تصاویر بلااستفاده روی هاست:</strong> اگر تصویر کاور یا گالری این موارد در هیچ کسب‌وکار، مقاله یا بنر دیگری استفاده نشده باشد، هم از جدول <code className="font-mono">media</code> و هم از پوشه <code className="font-mono">public_html/uploads</code> حذف شود.
              </span>
            </label>

            <div className="flex items-center justify-end gap-2 pt-3 border-t border-slate-200">
              <button
                type="button"
                disabled={deleteBusy}
                onClick={() => setDeleteTargets(null)}
                className="px-4 py-2 rounded-lg border border-slate-300 text-xs font-semibold text-slate-700 hover:bg-slate-50"
              >
                انصراف
              </button>
              <button
                type="button"
                disabled={deleteBusy}
                onClick={() => void confirmDeleteBusinesses()}
                className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-rose-700 hover:bg-rose-800 text-white text-xs font-bold disabled:opacity-50"
              >
                <Trash2 className="w-3.5 h-3.5" />
                {deleteBusy
                  ? 'در حال حذف ایمن…'
                  : `تأیید و حذف نهایی (${deleteTargets.length.toLocaleString('fa-IR')} مورد)`}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
