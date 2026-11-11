import React, { useCallback, useEffect, useState } from 'react';
import {
  BarChart3,
  PhoneCall,
  MapPin,
  Eye,
  Globe,
  RefreshCw,
  Clock,
  Award,
  CheckCircle2,
  AlertTriangle,
  ShieldCheck,
} from 'lucide-react';
import {
  AnalyticsSummaryState,
  fetchAnalyticsSummary,
  runCronMaintenanceS6,
} from '../services/adminApi';

interface Props {
  csrfToken: string;
  onNavigateReview?: () => void;
}

const sponsorLabels: Record<string, string> = {
  active: 'فعال',
  upcoming: 'آینده',
  ended: 'پایان‌یافته',
  article_unpublished: 'مقاله منتشر نشده',
  business_unpublished: 'کسب‌وکار منتشر نشده',
};

export const S6AnalyticsPanel: React.FC<Props> = ({ csrfToken, onNavigateReview }) => {
  const [data, setData] = useState<AnalyticsSummaryState | null>(null);
  const [loading, setLoading] = useState<boolean>(false);
  const [cronRunning, setCronRunning] = useState<boolean>(false);
  const [feedback, setFeedback] = useState<{ type: 'ok' | 'err' | null; text: string }>({
    type: null,
    text: '',
  });

  const loadAnalytics = useCallback(async () => {
    setLoading(true);
    try { setData(await fetchAnalyticsSummary()); }
    catch (e) { setData(null); setFeedback({type:'err',text:e instanceof Error ? e.message : 'خطا در دریافت آمار'}); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => {
    loadAnalytics();
  }, [loadAnalytics]);

  const handleRunCron = async () => {
    setCronRunning(true);
    setFeedback({ type: null, text: '' });
    const res = await runCronMaintenanceS6(csrfToken);
    setCronRunning(false);
    setFeedback({
      type: res.ok ? 'ok' : 'err',
      text: res.message || res.error || 'اجرای کرون تکمیل شد.',
    });
    await loadAnalytics();
  };

  if (!data) {
    return <div role="alert" className="p-4 bg-amber-50 text-amber-900 rounded-lg text-sm">{loading ? 'در حال دریافت آمار…' : (feedback.text || 'آمار در دسترس نیست.')} <button onClick={loadAnalytics} className="underline mr-2">تلاش مجدد</button></div>;
  }

  return (
    <div className="bg-white/85 backdrop-blur-xl border border-blue-200/80 rounded-2xl p-6 space-y-6 shadow-sm">
      <div className="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200/80 pb-4">
        <div>
          <div className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-blue-50 border border-blue-200 text-blue-800 text-[11px] font-bold mb-1.5">
            <BarChart3 className="w-3.5 h-3.5" />
            <span>مرحله S6 — آمار تعاملات روزانه، اسپانسرشیپ زمان‌دار و کرون ۵ دقیقه‌ای</span>
          </div>
          <h2 className="text-base font-bold text-slate-900">
            مرکز پایش کلیک‌های تماس و نقشه (events_daily) + مدیریت جایگاه‌های ویژه و ادعاهای مالکیت
          </h2>
          <p className="text-xs text-slate-600 mt-1">
            ثبت سبک بدون کوکی با <code className="font-mono">navigator.sendBeacon</code> · ابطال خودکار کش صفحات در شروع/پایان اسپانسرشیپ · فرم‌های عمومی ادعای مالکیت و گزارش خطا در صفحات کسب‌وکار
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2.5">
          <button
            type="button"
            onClick={handleRunCron}
            disabled={cronRunning}
            className="inline-flex items-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 disabled:opacity-60 text-white text-xs font-semibold rounded-lg shadow-sm"
          >
            <Clock className="w-4 h-4" />
            {cronRunning
              ? 'در حال اجرای نگهداری...'
              : 'اجرای فوری نگهداری'}
          </button>
          <button
            type="button"
            onClick={loadAnalytics}
            className="inline-flex items-center gap-1.5 px-3.5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg"
          >
            <RefreshCw className={`w-3.5 h-3.5 ${loading ? 'animate-spin' : ''}`} />
            بروزرسانی آمار
          </button>
        </div>
      </div>

      {feedback.type && (
        <div
          className={`p-3.5 rounded-xl border text-xs font-semibold flex items-center gap-2.5 ${
            feedback.type === 'ok'
              ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
              : 'bg-rose-50 border-rose-200 text-rose-900'
          }`}
        >
          {feedback.type === 'ok' ? (
            <CheckCircle2 className="w-4 h-4 text-emerald-700 shrink-0" />
          ) : (
            <AlertTriangle className="w-4 h-4 text-rose-700 shrink-0" />
          )}
          <span>{feedback.text}</span>
        </div>
      )}

      {/* کارت‌های خلاصه ۳۰ روز اخیر جدول events_daily */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3.5">
        <div className="bg-slate-50/90 border border-slate-200/90 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>بازدید صفحات (View)</span>
            <Eye className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-xl font-bold font-mono tabular-nums text-slate-900 mt-1">
            {data.totals_30d.view}
          </div>
        </div>

        <div className="bg-blue-50/60 border border-blue-200/80 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-blue-900 font-medium">
            <span>کلیک تماس مستقیم</span>
            <PhoneCall className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-xl font-bold font-mono tabular-nums text-blue-950 mt-1">
            {data.totals_30d.call}
          </div>
        </div>

        <div className="bg-slate-50/90 border border-slate-200/90 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>کلیک مسیریابی نقشه</span>
            <MapPin className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-xl font-bold font-mono tabular-nums text-slate-900 mt-1">
            {data.totals_30d.map}
          </div>
        </div>

        <div className="bg-slate-50/90 border border-slate-200/90 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>وب‌سایت و اینستاگرام</span>
            <Globe className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-xl font-bold font-mono tabular-nums text-slate-900 mt-1">
            {data.totals_30d.website + data.totals_30d.instagram}
          </div>
        </div>

        <div className="bg-slate-50/90 border border-slate-200/90 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>ادعای مالکیت / گزارش</span>
            <ShieldCheck className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-lg font-bold font-mono tabular-nums text-slate-900 mt-1">
            {data.pending_claims} مالکیت · {data.pending_reports} گزارش
          </div>
          {onNavigateReview && (
            <button
              type="button"
              onClick={onNavigateReview}
              className="text-[11px] text-blue-700 hover:underline mt-0.5 font-semibold"
            >
              مشاهده در صندوق بازبینی ←
            </button>
          )}
        </div>

        <div className="bg-slate-50/90 border border-slate-200/90 rounded-xl p-3.5">
          <div className="flex items-center justify-between text-xs text-slate-500">
            <span>آخرین اجرای نگهداری (دستی یا کرون)</span>
            <Clock className="w-4 h-4 text-blue-600" />
          </div>
          <div className="text-xs font-mono text-slate-800 mt-2 truncate" dir="ltr">
            {data.sponsor_last_check}
          </div>
        </div>
      </div>

      {/* دو جدول کنار هم: پرمخاطب‌ترین کسب‌وکارها و جایگاه‌های اسپانسری */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="border border-slate-200 rounded-xl overflow-hidden bg-white">
          <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
            <span className="text-xs font-bold text-slate-900">
              پرمخاطب‌ترین کسب‌وکارها بر اساس تعامل واقعی (تماس + نقشه + بازدید)
            </span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 bg-slate-50/50">
                  <th className="py-2.5 px-3 text-right font-semibold">کسب‌وکار</th>
                  <th className="py-2.5 px-3 text-right font-semibold">شهر</th>
                  <th className="py-2.5 px-3 text-right font-semibold">تماس</th>
                  <th className="py-2.5 px-3 text-right font-semibold">نقشه</th>
                  <th className="py-2.5 px-3 text-right font-semibold">بازدید</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {data.top_businesses.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="py-6 text-center text-slate-500">
                      هنوز رویدادی ثبت نشده است.
                    </td>
                  </tr>
                ) : (
                  data.top_businesses.map((b) => (
                    <tr key={b.id} className="hover:bg-slate-50">
                      <td className="py-2.5 px-3 font-bold text-slate-900">
                        <a
                          href={`/${b.city_slug}/${b.slug}/`}
                          target="_blank"
                          rel="noreferrer"
                          className="hover:text-blue-700"
                        >
                          {b.name}
                        </a>
                      </td>
                      <td className="py-2.5 px-3 text-slate-600">{b.city_name}</td>
                      <td className="py-2.5 px-3 font-mono font-bold text-blue-700 tabular-nums">
                        {b.calls_cnt}
                      </td>
                      <td className="py-2.5 px-3 font-mono text-slate-800 tabular-nums">
                        {b.maps_cnt}
                      </td>
                      <td className="py-2.5 px-3 font-mono text-slate-600 tabular-nums">
                        {b.views_cnt}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>

        <div className="border border-slate-200 rounded-xl overflow-hidden bg-white">
          <div className="px-4 py-3 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
            <span className="text-xs font-bold text-slate-900 flex items-center gap-1.5">
              <Award className="w-4 h-4 text-blue-600" />
              <span>جایگاه‌های ویژه و اسپانسری زمان‌دار (article_items)</span>
            </span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs border-collapse">
              <thead>
                <tr className="border-b border-slate-200 text-slate-500 bg-slate-50/50">
                  <th className="py-2.5 px-3 text-right font-semibold">واحد اسپانسر</th>
                  <th className="py-2.5 px-3 text-right font-semibold">مقاله هدف</th>
                  <th className="py-2.5 px-3 text-right font-semibold">بازه اعتبار</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {data.sponsored_items.length === 0 ? (
                  <tr>
                    <td colSpan={3} className="py-6 text-center text-slate-500">
                      مورد اسپانسری ثبت نشده است.
                    </td>
                  </tr>
                ) : (
                  data.sponsored_items.map((sp) => (
                    <tr key={sp.id} className="hover:bg-slate-50">
                      <td className="py-2.5 px-3">
                        <div className="font-bold text-slate-900">{sp.business_name}</div>
                        <div className="text-[11px] text-blue-700 font-medium">
                          برچسب: {sp.sponsor_label || 'پیشنهاد ویژه'}
                        </div>
                        <div className={`mt-1 font-semibold ${sp.display_status === 'active' ? 'text-emerald-700' : 'text-amber-800'}`}>
                          وضعیت: {sponsorLabels[sp.display_status] || 'نامشخص؛ پنل را به‌روز کنید'}
                        </div>
                      </td>
                      <td className="py-2.5 px-3">
                        {sp.article_status === 'published' && sp.article_slug ? (
                          <a
                            href={`/${encodeURIComponent(sp.article_slug)}/`}
                            target="_blank"
                            rel="noreferrer"
                            className="text-slate-800 hover:text-blue-700 font-medium"
                          >{sp.article_title}</a>
                        ) : <span className="text-slate-700 font-medium">{sp.article_title}</span>}
                      </td>
                      <td className="py-2.5 px-3 font-mono text-[11px] text-slate-600" dir="ltr">
                        <div>از: {sp.sponsor_start || 'همیشه'}</div>
                        <div>تا: {sp.sponsor_end || 'نامحدود'}</div>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  );
};
