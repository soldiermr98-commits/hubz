import { PersianDateInput } from './PersianDateInput';
import React, { useCallback, useEffect, useState } from 'react';
import { FolderOpen, ImagePlus, Plus, Trash2 } from 'lucide-react';
import { AdvertisingFinancePanel } from './AdvertisingFinancePanel';
import { MediaDimensionGuideBox } from './MediaDimensionGuideBox';
import { MediaLibraryPickerModal } from './MediaLibraryPickerModal';
import { AdBanner, AdInquiry, CategoryItem, CityItem, deleteAdBanner, fetchAdvertising, saveAdBanner, updateAdInquiry, updateAdvertisingSchema, updateFeatureSchema, uploadMediaWebp } from '../services/adminApi';

const input='w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const btn='inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50';
const primary='inline-flex items-center justify-center gap-2 rounded-lg bg-blue-700 text-white px-4 py-2 text-sm font-bold hover:bg-blue-800 disabled:opacity-50';
type BannerForm={id?:number;version?:number;title:string;alt_text:string;media_id:number|null;image_url:string|null;target_url:string;placement:string;city_id:number;category_id:number;starts_at:string;ends_at:string;active:boolean;priority:number};
const blank=():BannerForm=>({title:'',alt_text:'',media_id:null,image_url:null,target_url:'',placement:'home',city_id:0,category_id:0,starts_at:'',ends_at:'',active:false,priority:0});
const HOME_ONLY_PLACEMENTS=new Set(['home','home_below_hero','home_mid']);
const dateInput=(value:string|null)=>value ? value.replace(' ','T').slice(0,16) : '';

export const AdvertisingView:React.FC<{csrfToken:string;cities:CityItem[];categories:CategoryItem[]}> = ({csrfToken,cities,categories})=>{
  const [ready,setReady]=useState(false);const [reportingReady,setReportingReady]=useState(false);const [loading,setLoading]=useState(true);const [busy,setBusy]=useState(false);
  const [placements,setPlacements]=useState<Record<string,string>>({});const [banners,setBanners]=useState<AdBanner[]>([]);const [inquiries,setInquiries]=useState<AdInquiry[]>([]);
  const [form,setForm]=useState<BannerForm|null>(null);const [message,setMessage]=useState<{error:boolean;text:string}|null>(null);
  const [mediaPickerOpen,setMediaPickerOpen]=useState(false);
  const load=useCallback(async()=>{setLoading(true);try{const d=await fetchAdvertising();setReady(d.schema_ready);setReportingReady(d.reporting_schema_ready);setPlacements(d.placements);setBanners(d.banners);setInquiries(d.inquiries);}
    catch(e){setMessage({error:true,text:e instanceof Error?e.message:'دریافت تبلیغات ناموفق بود.'});}finally{setLoading(false);}},[]);
  useEffect(()=>{void load();},[load]);
  const patch=(value:Partial<BannerForm>)=>setForm(f=>f?{...f,...value}:f);
  const edit=(b:AdBanner)=>setForm({id:Number(b.id),version:Number(b.version),title:b.title,alt_text:b.alt_text,media_id:Number(b.media_id),image_url:b.image_url,target_url:b.target_url,placement:b.placement,city_id:Number(b.city_id)||0,category_id:Number(b.category_id)||0,starts_at:dateInput(b.starts_at),ends_at:dateInput(b.ends_at),active:!!Number(b.active),priority:Number(b.priority)||0});
  const install=async()=>{
    if(!window.confirm('آیا از دیتابیس و فایل‌ها پشتیبان گرفته‌اید؟ فقط جدول‌های جدید ساخته می‌شوند و مهاجرت قدیمی ۰۰۳ اجرا نمی‌شود.'))return;
    setBusy(true);const r=await updateFeatureSchema(csrfToken);setBusy(false);setMessage({error:!r.ok,text:r.message||r.error});if(r.ok)await load();
  };
  const installReporting=async()=>{
    if(!window.confirm('آیا پشتیبان قابل بازیابی دارید و ثبت مالی هنگام به‌روزرسانی متوقف است؟ جدول‌های گزارش و قرارداد و قفل یکتایی رسید آماده می‌شوند؛ مهاجرت قدیمی ۰۰۳ اجرا نمی‌شود.'))return;
    setBusy(true);const r=await updateAdvertisingSchema(csrfToken);setBusy(false);setMessage({error:!r.ok,text:r.message||r.error});if(r.ok)await load();
  };
  const upload=async(file?:File)=>{if(!file)return;setBusy(true);const r=await uploadMediaWebp(file,form?.alt_text || form?.title || 'بنر تبلیغاتی',csrfToken);setBusy(false);
    if(!r.ok||!r.media){setMessage({error:true,text:r.error||'آپلود تصویر ناموفق بود.'});return;}patch({media_id:Number(r.media.id),image_url:r.media.file_path_480||r.previewUrl});
  };
  const save=async(e:React.FormEvent)=>{e.preventDefault();if(!form||busy)return;setBusy(true);const r=await saveAdBanner(form,csrfToken);setBusy(false);
    if(!r.ok){setMessage({error:true,text:r.error||'ذخیره بنر ناموفق بود.'});return;}setForm(null);setMessage({error:false,text:r.message});await load();
  };
  const remove=async(b:AdBanner)=>{if(!window.confirm(`بنر «${b.title}» و آمار آن حذف شود؟ تصویر بنر هم به‌طور کامل از هاست پاک می‌شود (قراردادهای مرتبط حذف نمی‌شوند).`))return;setBusy(true);const r=await deleteAdBanner(Number(b.id),Number(b.version),csrfToken);setBusy(false);setMessage({error:!r.ok,text:r.message||r.error});if(r.ok)await load();};
  const inquiry=async(i:AdInquiry,operation:'new'|'handled'|'delete')=>{if(operation==='delete'&&!window.confirm('این درخواست و اطلاعات تماس آن حذف شود؟'))return;setBusy(true);const r=await updateAdInquiry(Number(i.id),operation,csrfToken);setBusy(false);setMessage({error:!r.ok,text:r.message||r.error});if(r.ok)await load();};
  return <div className="max-w-6xl mx-auto space-y-5">
    <div><h1 className="text-xl font-bold">تبلیغات و درخواست‌ها</h1><p className="text-sm text-slate-600 mt-1">بنر مستقل از جایگاه اسپانسریِ کسب‌وکارهای داخل مقاله است.</p></div>
    {message&&<div role={message.error?'alert':'status'} className={`rounded p-3 text-sm ${message.error?'bg-rose-50 text-rose-800':'bg-emerald-50 text-emerald-800'}`}>{message.text}</div>}
    {loading?<p>در حال دریافت…</p>:!ready?<section className="rounded-xl border border-amber-300 bg-amber-50 p-5 space-y-3 text-sm"><p className="font-bold">جدول‌های بخش جدید هنوز آماده نیستند.</p><p>بعد از پشتیبان‌گیری، تنها به‌روزرسانی اختصاصی هوش مصنوعی، تبلیغات و بازبینی را اجرا کنید.</p><button type="button" className={primary} disabled={busy} onClick={()=>void install()}>آماده‌سازی بخش‌های جدید</button></section>:<>
      {!reportingReady&&<section className="rounded-xl border border-amber-300 bg-amber-50 p-5 space-y-3 text-sm"><p className="font-bold">گزارش بنر و قراردادها هنوز آماده نیستند.</p><p>بعد از پشتیبان‌گیری، به‌روزرسانی اختصاصی را اجرا کنید. داده‌های قبلی تغییر نمی‌کنند.</p><button type="button" className={primary} disabled={busy} onClick={()=>void installReporting()}>آماده‌سازی گزارش و قرارداد</button></section>}
      <section id="advertising-inquiries" className="bg-white border border-blue-200 rounded-xl p-5 space-y-4 scroll-mt-4"><div className="flex flex-wrap items-center justify-between gap-2"><h2 className="font-bold">درخواست‌های تبلیغات</h2><span className="rounded-full bg-blue-50 px-3 py-1 text-sm font-semibold text-blue-800">{inquiries.filter(i=>i.status==='new').length.toLocaleString('fa-IR')} درخواست جدید</span></div><p className="text-xs text-slate-600">اطلاعات ارسالی فقط در دسترس مدیر است. پس از پایان پیگیری، اطلاعات غیرلازم را حذف کنید؛ حداکثر ۱۰۰ درخواست اخیر نمایش داده می‌شود.</p>
        {inquiries.length===0?<p className="text-sm text-slate-500">درخواستی ثبت نشده است.</p>:<ul className="divide-y">{inquiries.map(i=><li key={i.id} className="py-3 space-y-2"><div className="flex flex-wrap justify-between gap-2"><div><strong>{i.name}</strong> · <span dir="ltr">{i.phone}</span> · <span className="text-xs">{i.status==='new'?'جدید':'پیگیری‌شده'} · {i.created_at}</span></div><div className="flex gap-2"><button type="button" className={btn} disabled={busy} onClick={()=>void inquiry(i,i.status==='new'?'handled':'new')}>{i.status==='new'?'ثبت به‌عنوان پیگیری‌شده':'بازگرداندن به جدید'}</button><button type="button" className={btn} disabled={busy} onClick={()=>void inquiry(i,'delete')}>حذف اطلاعات</button></div></div>{i.message&&<p className="text-sm whitespace-pre-wrap">{i.message}</p>}</li>)}</ul>}
      </section>
      <section className="bg-white border rounded-xl p-5 space-y-4">
        <div className="flex flex-wrap justify-between items-start gap-3"><div><h2 className="font-bold">بنرها و جایگاه‌ها</h2><p className="text-xs text-slate-600 mt-1 leading-6">در هر جایگاه حداکثر ۲ بنر فعال هم‌زمان نمایش داده می‌شود (در دسکتاپ ۲ بنر کنار هم و در موبایل زیر هم؛ در صورت وجود ۱ بنر، تمام‌عرض). ترتیب نمایش بر اساس اولویت بالاتر و سپس بنر جدیدتر است. شروع شامل و پایان غیرشامل است.</p></div><button type="button" className={primary} onClick={()=>{setForm(blank());setMessage(null);}}><Plus size={16}/> بنر جدید</button></div>
        {form&&<form onSubmit={save} className="rounded-xl border border-blue-200 bg-blue-50/40 p-4 space-y-4">
          <h3 className="font-bold text-sm">{form.id?'ویرایش بنر':'بنر جدید · ابتدا غیرفعال'}</h3>
          <div className="grid sm:grid-cols-2 gap-3">
            <label className="text-sm">عنوان داخلی<input required maxLength={150} className={`${input} mt-1`} value={form.title} onChange={e=>patch({title:e.target.value})}/></label>
            <label className="text-sm">متن جایگزین تصویر<input required maxLength={255} className={`${input} mt-1`} value={form.alt_text} onChange={e=>patch({alt_text:e.target.value})}/></label>
            <label className="text-sm sm:col-span-2">نشانی مقصد (HTTPS یا مسیر داخلی)<input required dir="ltr" className={`${input} mt-1`} value={form.target_url} onChange={e=>patch({target_url:e.target.value})} placeholder="https://example.com/"/></label>
            <label className="text-sm">جایگاه<select className={`${input} mt-1`} value={form.placement} onChange={e=>patch({placement:e.target.value, ...(HOME_ONLY_PLACEMENTS.has(e.target.value)?{city_id:0,category_id:0}:e.target.value==='city'?{category_id:0}:{})})}>{Object.entries(placements).map(([id,label])=><option key={id} value={id}>{label}</option>)}</select></label>
            <label className="text-sm">اولویت (۱۰۰- تا ۱۰۰)<input type="number" min={-100} max={100} className={`${input} mt-1`} value={form.priority} onChange={e=>patch({priority:Number(e.target.value)})}/></label>
            <label className="text-sm">فقط شهر (اختیاری)<select disabled={HOME_ONLY_PLACEMENTS.has(form.placement)} className={`${input} mt-1 disabled:opacity-50`} value={form.city_id} onChange={e=>patch({city_id:Number(e.target.value)})}><option value={0}>همه شهرها</option>{cities.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
            <label className="text-sm">فقط دسته (اختیاری)<select disabled={HOME_ONLY_PLACEMENTS.has(form.placement)||form.placement==='city'} className={`${input} mt-1 disabled:opacity-50`} value={form.category_id} onChange={e=>patch({category_id:Number(e.target.value)})}><option value={0}>همه دسته‌ها</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
            <div className="text-sm">شروع (اختیاری)<PersianDateInput label="شروع بنر" withTime value={form.starts_at} onChange={value=>patch({starts_at:value})}/></div>
            <div className="text-sm">پایان (اختیاری)<PersianDateInput label="پایان بنر" withTime value={form.ends_at} onChange={value=>patch({ends_at:value})}/></div>
          </div>
          <div className="space-y-2"><MediaDimensionGuideBox slot="advertising_banner" compact />{form.image_url&&<img src={form.image_url} alt="پیش‌نمایش بنر" className="max-h-40 max-w-full rounded border mb-2"/>}<div className="flex flex-wrap items-center gap-2"><label className={`${btn} cursor-pointer`}><ImagePlus size={16}/> انتخاب تصویر بنر (GIF متحرک حفظ می‌شود · بقیه به WebP بهینه)<input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/avif,.gif" className="sr-only" disabled={busy} onChange={e=>{void upload(e.target.files?.[0]);e.target.value='';}}/></label><button type="button" className={btn} disabled={busy} onClick={()=>setMediaPickerOpen(true)}><FolderOpen size={16}/> انتخاب از کتابخانه رسانه</button>{!form.media_id&&<span className="text-xs text-amber-800">تصویر لازم است.</span>}</div><MediaLibraryPickerModal open={mediaPickerOpen} onClose={()=>setMediaPickerOpen(false)} onSelect={item=>patch({media_id:item.id,image_url:item.file_path_1200||item.file_path_800||item.file_path_480})} /></div>
          <label className="flex items-start gap-2 text-sm"><input type="checkbox" className="mt-1" checked={form.active} onChange={e=>patch({active:e.target.checked})}/><span>نمایش بنر فعال باشد <small className="block text-slate-600 mt-1">پس از ذخیره و فقط در زمان و جایگاه انتخاب‌شده نمایش داده می‌شود.</small></span></label>
          <div className="flex gap-2"><button className={primary} disabled={busy||!form.media_id}>ذخیره بنر</button><button type="button" className={btn} disabled={busy} onClick={()=>setForm(null)}>انصراف</button></div>
        </form>}
        {banners.length===0?<p className="text-sm text-slate-500">هنوز بنری ثبت نشده است.</p>:<ul className="divide-y">{banners.map(b=><li key={b.id} className="py-3 flex flex-wrap items-center justify-between gap-3"><div className="flex items-center gap-3">{b.image_url&&<img src={b.image_url} alt="" className="w-24 h-14 object-contain rounded border"/>}<div><strong className="text-sm">{b.title}</strong><p className="text-xs text-slate-600 mt-1">{placements[b.placement]||b.placement} · {Number(b.active)?'فعال':'غیرفعال'} · اولویت {b.priority}</p>{reportingReady&&<p className="text-xs text-slate-700 mt-2">۳۰ روز اخیر: {Number(b.views_30||0).toLocaleString('fa-IR')} نمایش · {Number(b.clicks_30||0).toLocaleString('fa-IR')} کلیک · نرخ کلیک {Number(b.views_30)>0 ? `${(100*Number(b.clicks_30)/Number(b.views_30)).toLocaleString('fa-IR',{maximumFractionDigits:1})}٪` : '—'}<br/>کل از زمان فعال‌سازی آمار: {Number(b.views_total||0).toLocaleString('fa-IR')} نمایش · {Number(b.clicks_total||0).toLocaleString('fa-IR')} کلیک</p>}</div></div><div className="flex gap-2"><button type="button" className={btn} onClick={()=>edit(b)}>ویرایش بنر</button><button type="button" className={btn} disabled={busy} onClick={()=>void remove(b)}><Trash2 size={14}/> حذف</button></div></li>)}</ul>}
        <p className="text-xs text-slate-500 leading-6">حداکثر ۲۰۰ بنر اخیر نمایش داده می‌شود. آمار پس از آماده‌سازی شروع می‌شود؛ نمایش یعنی تصویر بارگذاری‌شده وارد محدوده صفحه شده است. تعداد یکتا نیست، با مسدودکننده‌ها کم می‌شود و ممکن است با ربات/بازخوانی زیاد شود؛ مبنای صورتحساب یا اثبات بازدید واقعی نیست. حذف بنر آمار آن را نیز حذف می‌کند.</p>
      </section>
      <AdvertisingFinancePanel csrfToken={csrfToken} banners={banners} onSchemaUpdated={()=>void load()}/>

    </>}
  </div>;
};
