import React, { useCallback, useEffect, useRef, useState } from 'react';
import { CloudDownload, RefreshCw } from 'lucide-react';
import { deleteApifyRun, fetchApifyRun, fetchApifyStatus, importLatestApify, refreshApifyRun, resolveApifyRun, saveApifySchedule, startApifyCollection, type ApifyRunData, type ApifyRunSummary, type ApifySchedule, type CategoryItem, type CityItem } from '../services/adminApi';

interface Props { cities:CityItem[]; categories:CategoryItem[]; csrfToken:string; onOpen:(run:ApifyRunData)=>boolean; onBusyChange:(busy:boolean)=>void; paused:boolean }
const inputClass='w-full mt-1 border border-slate-300 rounded-lg bg-white px-3 py-2 text-sm';
const statusLabels:Record<string,string>={starting:'در حال آغاز',running:'در حال جمع‌آوری',ready:'آماده پیش‌نمایش',failed:'ناموفق / بسته‌شده',too_large:'خروجی بیش از حد مجاز',start_unknown:'آغاز نامشخص؛ متوقف'};

export const ApifyAutomationPanel:React.FC<Props>=({cities,categories,csrfToken,onOpen,onBusyChange,paused})=>{
  const [schedule,setSchedule]=useState<ApifySchedule|null>(null);
  const [runs,setRuns]=useState<ApifyRunSummary[]>([]); const [configured,setConfigured]=useState(false);
  const [terms,setTerms]=useState<Record<string,string>>({});
  const [city,setCity]=useState(0); const [category,setCategory]=useState(0); const [limit,setLimit]=useState('20'); const [cap,setCap]=useState('1.00');
  const [busy,setBusy]=useState(false); const [notice,setNotice]=useState(''); const [error,setError]=useState('');
  const initialized=useRef(false); const requestedRun=useRef<number|null>(null); const polling=useRef(false);
  const openRef=useRef(onOpen); openRef.current=onOpen;
  const setWorking=(value:boolean)=>{setBusy(value);onBusyChange(value);};
  const refresh=useCallback(async()=>{
    try {
      const r=await fetchApifyStatus();setConfigured(r.token_configured);setSchedule(r.schedule);setRuns(r.runs);setTerms(r.search_terms||{});
      if(!initialized.current && r.schedule){setCity(Number(r.schedule.city_id||0));setCategory(Number(r.schedule.category_id||0));if(r.schedule.max_charge_usd!==null)setCap(String(r.schedule.max_charge_usd));initialized.current=true;}
      return r;
    } catch(e){setError(e instanceof Error?e.message:'دریافت وضعیت Apify ناموفق بود.');return null;}
  },[]);
  useEffect(()=>{void refresh();},[refresh]);

  // Polling checks only a locally recorded execution. It never starts or retries a paid run.
  useEffect(()=>{
    const pending=runs.find(r=>r.status==='starting'||r.status==='running');
    if(!configured||!pending||busy||paused)return;
    let cancelled=false;
    const timer=window.setInterval(async()=>{
      if(document.hidden||polling.current)return;
      polling.current=true;
      try {
        const checked=await refreshApifyRun(Number(pending.id),csrfToken);
        const current=await fetchApifyStatus();
        if(cancelled)return;
        const row=current.runs.find(r=>Number(r.id)===Number(pending.id));
        let opened:boolean|undefined;
        if(row?.status==='ready' && Number(row.item_count)>0 && requestedRun.current===Number(row.id)){
          const ready=await fetchApifyRun(Number(row.id));
          if(cancelled)return;
          opened=openRef.current(ready.run); requestedRun.current=null;
        }
        if(cancelled)return;
        setConfigured(current.token_configured);setSchedule(current.schedule);setRuns(current.runs);setTerms(current.search_terms||{});
        if(opened===true)setNotice('جمع‌آوری تمام شد و نتیجه در بخش پیش‌نمایش پایین آماده است؛ هنوز چیزی ثبت یا منتشر نشده است.');
        if(opened===false)setNotice('نتیجه آماده است؛ هر وقت خواستید با دکمه «باز کردن نتیجه» آن را ببینید.');
        if(!checked.ok && row?.status==='running')setError(checked.error||'دریافت نتیجه موقتاً ناموفق بود؛ وضعیت دوباره بررسی می‌شود.');
        else setError('');
      } catch(e){if(!cancelled)setError(e instanceof Error?e.message:'بررسی وضعیت موقتاً ناموفق بود.');}
      finally{polling.current=false;}
    },8000);
    return ()=>{cancelled=true;window.clearInterval(timer);};
  },[runs,configured,busy,paused,csrfToken]);

  const open=async(id:number)=>{
    setWorking(true);setError('');
    try{const r=await fetchApifyRun(id);if(onOpen(r.run))setNotice('نتیجه در بخش پیش‌نمایش پایین آماده شد؛ هنوز چیزی ثبت نشده است.');}
    catch(e){setError(e instanceof Error?e.message:'دریافت نتیجه ناموفق بود.');}
    finally{setWorking(false);}
  };
  const start=async()=>{
    const n=Number(limit), amount=Number(cap);
    if(!city||!category||!Number.isInteger(n)||n<10||n>500||!Number.isFinite(amount)||amount<0.01||amount>100||!/^\d+(?:\.\d{1,2})?$/.test(cap)){setError('شهر، دسته، تعداد ۱۰ تا ۵۰۰ و سقف هزینه معتبر را انتخاب کنید.');return;}
    const term=terms[String(category)]||categories.find(c=>Number(c.id)===category)?.name_fa||'';
    const cityName=cities.find(c=>Number(c.id)===city)?.name_fa||'';
    const old=Number(schedule?.enabled)===1&&schedule?.mode==='legacy'?' برنامه قدیمی هنوز فعال است و ممکن است جداگانه اجرا شود.':'';
    if(!window.confirm(`جمع‌آوری «${term}» در ${cityName} شروع شود؟ حداکثر ${n} مکان درخواست می‌شود و سقف هزینه Actor برای این اجرا ${amount.toFixed(2)} دلار است. هزینه‌های دیگر حساب ممکن است جداگانه محاسبه شوند. نتیجه خودکار منتشر نمی‌شود.${old}`))return;
    setWorking(true);setError('');setNotice('');
    const r=await startApifyCollection({city_id:city,category_id:category,max_places:n,max_charge_usd:amount,confirmed:true,expected_search_term:term},csrfToken);
    if(!r.ok){setError(`${r.error||'ارتباط با سرور قطع شد.'} پیش از تکرار، وضعیت جمع‌آوری را بررسی کنید.`);await refresh();}
    else {
      if(r.status==='started'&&r.run_id)requestedRun.current=r.run_id;
      await refresh();
      setNotice(r.status==='start_unknown'?'نتیجه شروع نامشخص است. برای جلوگیری از هزینه تکراری، ابتدا حساب Apify را بررسی کنید.':'جمع‌آوری شروع شد؛ تا وقتی این صفحه باز است نتیجه خودکار بررسی و دریافت می‌شود.');
    }
    setWorking(false);
  };
  const latest=async()=>{
    setWorking(true);setError('');setNotice('');
    const r=await importLatestApify(csrfToken);
    if(!r.ok||!r.id)setError(r.error||'نتیجه قبلی یافت نشد.');
    else {
      try{const ready=await fetchApifyRun(r.id);if(onOpen(ready.run))setNotice('آخرین خروجی سازگار دریافت شد؛ شهر و دسته آن را در پیش‌نمایش بررسی کنید. اجرای جدیدی شروع نشده است.');}
      catch(e){setError(e instanceof Error?e.message:'باز کردن نتیجه ناموفق بود.');}
      await refresh();
    }
    setWorking(false);
  };
  const enable=async()=>{
    const amount=Number(cap);
    if(!city||!category||!Number.isFinite(amount)||amount<0.01||amount>100||!/^\d+(?:\.\d{1,2})?$/.test(cap)){setError('شهر، دسته و سقف هزینه معتبر را انتخاب کنید.');return;}
    const old=Number(schedule?.enabled)===1&&schedule?.mode==='legacy'?' برنامه قدیمی با این برنامه جایگزین می‌شود.':'';
    const term=terms[String(category)]||categories.find(c=>Number(c.id)===category)?.name_fa||'';
    const cityName=cities.find(c=>Number(c.id)===city)?.name_fa||'';
    const same=Number(schedule?.enabled)===1&&schedule?.mode==='direct'&&Number(schedule.city_id)===city&&Number(schedule.category_id)===category;
    const next=same?`نوبت بعدی طبق برنامه فعلی (${schedule?.next_run_at||'زمان مقرر'}) باقی می‌ماند.`:'اولین نوبت ۱۴ روز بعد است.';
    if(!window.confirm(`تکرار «${term}» در ${cityName} فعال شود؟ هر ۱۴ روز حداکثر ۵۰۰ مکان و سقف ${amount.toFixed(2)} دلار برای هر اجرا درخواست می‌شود. ${next} هزینه‌های دیگر حساب ممکن است جدا باشند.${old}`))return;
    setWorking(true);setError('');setNotice('');
    const r=await saveApifySchedule({enabled:true,direct:true,confirmed:true,city_id:city,category_id:category,max_charge_usd:amount,expected_search_term:term},csrfToken);
    if(!r.ok)setError(r.error||'ذخیره ناموفق بود.');else{await refresh();setNotice('تکرار فعال شد. برای اجرا در زمان مقرر، کرون هاست باید فعال باشد.');}
    setWorking(false);
  };
  const disable=async()=>{
    if(!window.confirm('تکرار متوقف شود؟ جمع‌آوری آغازشده لغو نمی‌شود و نتیجه آن همچنان دریافت می‌شود.'))return;
    setWorking(true);setError('');const r=await saveApifySchedule({enabled:false},csrfToken);if(!r.ok)setError(r.error||'توقف ناموفق بود.');else{await refresh();setNotice('تکرار متوقف شد.');}setWorking(false);
  };
  const remove=async(id:number)=>{
    if(!window.confirm('فقط این خروجی ذخیره‌شده پاک می‌شود؛ کسب‌وکارها حذف نمی‌شوند. ادامه؟'))return;
    setWorking(true);setError('');const r=await deleteApifyRun(id,csrfToken);if(!r.ok)setError(r.error||'حذف ناموفق بود.');else await refresh();setWorking(false);
  };
  const resolve=async(id:number)=>{
    if(!window.confirm('نتیجه شروع مشخص نیست و ممکن است هزینه ایجاد شده باشد. ابتدا حساب Apify را بررسی کنید. این تأیید توقف را می‌بندد، اما تکرار را دوباره فعال نمی‌کند. بررسی کردید؟'))return;
    setWorking(true);setError('');const r=await resolveApifyRun(id,csrfToken);if(!r.ok)setError(r.error||'رفع توقف ناموفق بود.');else await refresh();setWorking(false);
  };
  const enabled=Number(schedule?.enabled)===1;
  const term=terms[String(category)]||categories.find(c=>Number(c.id)===category)?.name_fa||'';
  return <section className="bg-white border border-slate-200 rounded-2xl p-5 md:p-6 space-y-4">
    <div className="flex justify-between gap-3 items-start"><div><p className="text-xs font-bold text-blue-700">مرحله ۱ از ۳</p><h2 className="font-bold text-lg flex items-center gap-2 mt-1"><CloudDownload className="w-5 h-5 text-blue-700"/> جمع‌آوری از Google Maps</h2>
      <p className="text-sm text-slate-600 mt-1">شهر و دسته را انتخاب کنید. نتیجه برای بررسی باز می‌شود؛ انتشار خودکار نیست.</p></div>
      <button type="button" disabled={busy} onClick={()=>void refresh()} className="text-blue-700 text-xs flex items-center gap-1"><RefreshCw className="w-4 h-4"/> تازه‌سازی</button></div>
    {!configured&&<div className="text-sm leading-6 bg-amber-50 text-amber-900 rounded-lg p-3">توکن Apify تنظیم نشده است. توکن جدید را فقط در تنظیمات خصوصی هاست وارد کنید، نه در پنل یا گفتگو.</div>}
    {enabled&&schedule?.mode==='legacy'&&<div className="text-xs text-amber-900 bg-amber-50 rounded-lg p-3">یک برنامه نسخه قبلی هنوز فعال است و ممکن است جداگانه هزینه ایجاد کند. برای جلوگیری از اجرای آن، از بخش تکرار پایین «توقف تکرار» را بزنید؛ این بروزرسانی خودش آن را تغییر نمی‌دهد.</div>}
    <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
      <label className="text-xs font-semibold">شهر<select className={inputClass} value={city} onChange={e=>setCity(Number(e.target.value))} disabled={busy}><option value={0}>انتخاب شهر</option>{cities.filter(c=>Number(c.is_active)===1).map(c=><option value={c.id} key={c.id}>{c.name_fa}</option>)}</select></label>
      <label className="text-xs font-semibold">دسته<select className={inputClass} value={category} onChange={e=>setCategory(Number(e.target.value))} disabled={busy}><option value={0}>انتخاب دسته</option>{categories.filter(c=>Number(c.is_active)===1).map(c=><option value={c.id} key={c.id}>{c.name_fa}</option>)}</select></label>
      <label className="text-xs font-semibold">حداکثر مکان در جمع‌آوری دستی<input dir="ltr" type="number" min="10" max="500" step="1" className={inputClass} value={limit} onChange={e=>setLimit(e.target.value)} disabled={busy}/><span className="font-normal text-slate-500">برای آزمون اولیه ۲۰ مورد مناسب است.</span></label>
      <label className="text-xs font-semibold">سقف هزینه هر اجرا (دلار)<input dir="ltr" type="number" min="0.01" max="100" step="0.01" className={inputClass} value={cap} onChange={e=>setCap(e.target.value)} disabled={busy}/></label>
    </div>
    {term&&<p className="text-xs text-slate-600">عبارت جست‌وجو: <b>{term}</b> · فقط یک عبارت جست‌وجو می‌شود؛ ممکن است همه زیرشاخه‌ها پوشش داده نشوند.</p>}
    <div className="flex flex-wrap items-center gap-2"><button type="button" disabled={busy||!configured} onClick={()=>void start()} className="bg-blue-700 text-white rounded-lg px-5 py-3 text-sm font-bold disabled:opacity-50">جمع‌آوری</button>
      <button type="button" disabled={busy||!configured} onClick={()=>void latest()} className="border border-slate-300 rounded-lg px-4 py-3 text-sm font-semibold disabled:opacity-50">دریافت آخرین نتیجه موجود</button></div>
    <p className="text-xs text-slate-500 leading-6">«جمع‌آوری» با تأیید شما اجرای جدید و احتمال هزینه دارد؛ «آخرین نتیجه» اجرای جدید شروع نمی‌کند و پنج اجرای موفق اخیر را بررسی می‌کند. سقف برای Actor است؛ هزینه‌های دیگر حساب ممکن است جدا باشند. عکس و متن نظر در این جمع‌آوری درخواست نمی‌شوند.</p>
    {error&&<p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 rounded-lg">{error}</p>}{notice&&<p role="status" className="text-sm text-blue-800 bg-blue-50 p-3 rounded-lg">{notice}</p>}
    <details className="border border-slate-200 rounded-lg p-3"><summary className="font-semibold text-sm cursor-pointer">تکرار اختیاری هر ۱۴ روز — {enabled?'فعال':'خاموش'}</summary>
      {enabled&&schedule?.mode==='legacy'&&<p className="text-xs text-amber-900 bg-amber-50 p-2 mt-2">یک برنامه نسخه قبلی هنوز فعال است و طبق تنظیمات قبلی اجرا می‌شود. آن را متوقف کنید یا آگاهانه با برنامه ساده جایگزین کنید؛ این بروزرسانی خودش برنامه را تغییر نمی‌دهد.</p>}
      <p className="text-xs text-slate-600 leading-6 mt-2">با فعال‌سازی اولیه، شهر، دسته و سقف هزینه بالا ذخیره می‌شود و اولین نوبت ۱۴ روز بعد است. در تغییر سقف برنامه فعال همان نوبت قبلی حفظ می‌شود؛ جمع‌آوری دستی همان شهر و دسته می‌تواند نوبت بعدی را تا ۱۴ روز عقب ببرد. هر نوبت حداکثر ۵۰۰ مکان درخواست می‌کند و عبارت آن از تنظیمات فعلی دسته خوانده می‌شود. اجرا نیازمند کرون فعال هاست است؛ نتیجه خودکار ثبت یا منتشر نمی‌شود.</p>
      {enabled&&<p className="text-xs text-slate-700 mt-2">برنامه ذخیره‌شده: {cities.find(c=>Number(c.id)===Number(schedule?.city_id))?.name_fa||'شهر نامشخص'} · {categories.find(c=>Number(c.id)===Number(schedule?.category_id))?.name_fa||'دسته نامشخص'} · سقف {schedule?.max_charge_usd} دلار. تغییر فیلدهای بالا تا زدن دکمه فعال‌سازی این برنامه را تغییر نمی‌دهد.</p>}
      <p className="text-xs text-slate-500 mt-2">زمان نوبت بعدی سرور: {schedule?.next_run_at||'تعیین نشده'}</p>
      {schedule?.last_error&&<p className="mt-2 text-xs text-rose-800">آخرین خطا: {schedule.last_error}</p>}
      <div className="flex flex-wrap gap-2 mt-3"><button type="button" disabled={busy||!configured} onClick={()=>void enable()} className="bg-blue-700 text-white rounded px-4 py-2 text-sm disabled:opacity-50">فعال‌سازی / تغییر تکرار</button><button type="button" disabled={busy||!enabled} onClick={()=>void disable()} className="border border-slate-300 rounded px-4 py-2 text-sm disabled:opacity-50">توقف تکرار</button></div>
    </details>
    {runs.length>0&&<details className="border border-slate-200 rounded-lg p-3" open={runs.some(r=>['starting','running','start_unknown'].includes(r.status))||undefined}><summary className="font-semibold text-sm cursor-pointer">وضعیت و نتیجه‌های قبلی ({runs.length})</summary><div className="overflow-x-auto mt-3"><table className="w-full text-xs text-right"><thead className="bg-slate-50"><tr><th className="p-2">زمان</th><th className="p-2">وضعیت / تعداد</th><th className="p-2">عملیات</th></tr></thead><tbody>{runs.map(r=><tr key={r.id} className="border-t align-top"><td className="p-2 text-slate-500">{r.created_at}</td><td className="p-2">{r.status==='ready'&&Number(r.item_count)===0?'موردی پیدا نشد':statusLabels[r.status]||r.status}{r.item_count!==null?` · ${r.item_count} رکورد`:''}{r.error&&<div className="text-rose-700 mt-1">{r.error}</div>}</td><td className="p-2"><div className="flex flex-wrap gap-2">{r.status==='ready'&&<button type="button" disabled={busy||Number(r.item_count)===0} className="text-blue-700 font-bold disabled:opacity-50" onClick={()=>void open(Number(r.id))}>باز کردن نتیجه</button>}{r.status==='start_unknown'&&<button type="button" disabled={busy} className="text-amber-800" onClick={()=>void resolve(Number(r.id))}>رفع توقف پس از بررسی</button>}{['ready','failed','too_large'].includes(r.status)&&<button type="button" disabled={busy} className="text-rose-700" onClick={()=>void remove(Number(r.id))}>حذف نتیجه</button>}</div></td></tr>)}</tbody></table></div></details>}
  </section>;
};
