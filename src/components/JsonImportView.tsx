import React, { useEffect, useMemo, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, FileJson, Search } from 'lucide-react';
import { ApifyAutomationPanel } from './ApifyAutomationPanel';
import { csvNeedsRunTime, parseBusinessCsv, supplyCsvRunTime } from '../services/csvImport';
import { applyJsonImport, prepareJsonImport, previewJsonImport, type ApifyRunData, type CategoryItem, type CityItem, type ImportEntry, type ImportOptions, type ImportResult, type ImportRow } from '../services/adminApi';

interface Props { cities:CityItem[]; categories:CategoryItem[]; csrfToken:string; onRefreshData:()=>Promise<void>; onGoReview?:()=>void }
interface PreviewRequest { items:unknown[]; startedAt:string; options:ImportOptions }
const labels:Record<ImportRow['status'],string>={new:'جدید',update:'به‌روزرسانی',review:'نیازمند تصمیم',filtered:'فیلتر شده',invalid:'نامعتبر',conflict:'تعارض',stale:'قدیمی یا تکراری'};
const control='w-full px-3 py-2.5 text-sm bg-white border border-slate-300 rounded-lg focus:border-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100';
const rowObject=(item:unknown):Record<string,unknown>=>item && typeof item==='object' && !Array.isArray(item)?item as Record<string,unknown>:{};
const getCategory=(item:unknown):string|null=>{
  const row=rowObject(item);
  if(typeof row.source==='string' && typeof row.category==='string')return row.category;
  if(typeof row.categoryName==='string' && row.categoryName!=='')return row.categoryName;
  if(Array.isArray(row.categories)&&typeof row.categories[0]==='string')return row.categories[0];
  // Apify's converter uses an empty category when the provider supplied none.
  if(typeof row.title==='string')return '';
  return typeof row.category==='string'?row.category:null;
};
const needsRunTime=(item:unknown):boolean=>{
  const row=rowObject(item);
  return csvNeedsRunTime(row) || (typeof row.title==='string' && (typeof row.scrapedAt!=='string'||!row.scrapedAt.trim()) && !row.source);
};
const categoryNames=(value:unknown[])=>Array.from(new Set(value.map(getCategory).filter((x):x is string=>x!==null)));
const timePattern=/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/;

export const JsonImportView:React.FC<Props>=({cities,categories,csrfToken,onRefreshData,onGoReview})=>{
  const [items,setItems]=useState<unknown[]>([]); const [prepared,setPrepared]=useState<unknown[]>([]);
  const [filename,setFilename]=useState(''); const [runStartedAt,setRunStartedAt]=useState('');
  const [city,setCity]=useState(0); const [category,setCategory]=useState(0); const [sourceCategories,setSourceCategories]=useState<string[]>([]);
  const [minRating,setMinRating]=useState(0); const [minReviews,setMinReviews]=useState(0);
  const [rows,setRows]=useState<ImportRow[]|null>(null); const [decisions,setDecisions]=useState<Record<number,string>>({}); const [results,setResults]=useState<ImportResult[]>([]);
  const [rowFilter,setRowFilter]=useState('all'); const [sort,setSort]=useState('rating'); const [search,setSearch]=useState('');
  const [busy,setBusy]=useState(false); const [progress,setProgress]=useState(''); const [error,setError]=useState('');
  const [pendingPreview,setPendingPreview]=useState<PreviewRequest|null>(null);
  const resultRef=useRef<HTMLElement>(null);
  const previewRef=useRef<HTMLDivElement>(null);
  const categoriesInFile=useMemo(()=>{
    const counts=new Map<string,number>();for(const item of items){const value=getCategory(item);if(value!==null)counts.set(value,(counts.get(value)||0)+1);}return Array.from(counts.entries());
  },[items]);
  const detectedCities=useMemo(()=>Array.from(new Set(items.map(item=>rowObject(item).city).filter((v):v is string=>typeof v==='string'&&!!v.trim()))).slice(0,6),[items]);
  const options:ImportOptions={city_id:city,category_id:category,source_categories:sourceCategories,min_rating:minRating,min_reviews:minReviews};
  const invalidate=()=>{setPendingPreview(null);setRows(null);setPrepared([]);setDecisions({});setResults([]);setError('');setProgress('');};
  const showResult=()=>window.setTimeout(()=>resultRef.current?.scrollIntoView?.({behavior:'smooth',block:'start'}),0);
  const readFile=async(event:React.ChangeEvent<HTMLInputElement>)=>{
    const file=event.target.files?.[0];event.target.value='';
    if(!file)return;
    if(items.length&&!window.confirm('فایل جدید جایگزین نتیجه‌ای می‌شود که برای بررسی باز کرده‌اید. ادامه می‌دهید؟'))return;
    invalidate();setItems([]);setFilename('');setSourceCategories([]);setRunStartedAt('');setCity(0);setCategory(0);
    if(file.size>4*1024*1024){setError('حجم فایل باید حداکثر ۴ مگابایت باشد.');return;}
    try{
      const text=await file.text();
      const parsed:unknown=file.name.toLowerCase().endsWith('.csv') ? parseBusinessCsv(text) : JSON.parse(text.replace(/^\uFEFF/,''));
      if(!Array.isArray(parsed)||parsed.length===0||parsed.length>500)throw new Error('فایل باید آرایه‌ای شامل ۱ تا ۵۰۰ رکورد باشد.');
      const cats=categoryNames(parsed);
      setItems(parsed);setFilename(file.name);setSourceCategories(cats.length<=100?cats:[]);showResult();
    }catch(e){setError(e instanceof SyntaxError?'فایل JSON معتبر نیست.':e instanceof Error?e.message:'خواندن فایل ناموفق بود.');}
  };
  const openRun=(run:ApifyRunData):boolean=>{
    if(items.length && !window.confirm('باز کردن نتیجه جدید، داده‌ای را که اکنون برای پیش‌نمایش انتخاب کرده‌اید جایگزین می‌کند. ادامه می‌دهید؟'))return false;
    const cats=categoryNames(run.items), available=new Set(cats);
    // A saved schedule's explicit source choices remain authoritative. Otherwise show every
    // category in preview; the administrator will explicitly choose which records to register.
    const chosen=run.source_categories?.length?run.source_categories.filter(c=>available.has(c)):(cats.length<=100?cats:[]);
    const nextCity=run.city_id?Number(run.city_id):0, nextCategory=run.category_id?Number(run.category_id):0;
    const startedAt=run.started_at_iso||'';
    const nextOptions:ImportOptions={city_id:nextCity,category_id:nextCategory,source_categories:chosen,min_rating:Number(run.min_rating||0),min_reviews:Number(run.min_reviews||0)};
    invalidate();setItems(run.items);setFilename('نتیجه دریافت‌شده از Apify');setRunStartedAt(startedAt);
    setCity(nextCity);setCategory(nextCategory);setSourceCategories(chosen);setMinRating(nextOptions.min_rating);setMinReviews(nextOptions.min_reviews);
    if(nextCity&&nextCategory&&chosen.length&&(!run.items.some(needsRunTime)||timePattern.test(startedAt)))setPendingPreview({items:run.items,startedAt,options:nextOptions});
    showResult();
    return true;
  };
  const executePreview=async(request:PreviewRequest)=>{
    const {items:input,startedAt,options:opts}=request;
    if(!input.length||!opts.city_id||!opts.category_id||!opts.source_categories.length){setError('برای ادامه، شهر و دسته مقصد و حداقل یک دسته از نتیجه را انتخاب کنید.');return;}
    if(opts.source_categories.length>100){setError('حداکثر ۱۰۰ دسته از نتیجه را انتخاب کنید.');return;}
    if(input.some(needsRunTime)&&!startedAt.trim()){setError('زمان شروع جمع‌آوری در نتیجه وجود ندارد؛ زمان واقعی آن را در تنظیمات بررسی وارد کنید.');return;}
    if(startedAt.trim()&&!timePattern.test(startedAt.trim())){setError('زمان شروع باید ISO 8601 با منطقه زمانی باشد؛ مانند 2026-11-06T10:00:00Z.');return;}
    setBusy(true);setError('');setResults([]);setProgress('در حال آماده‌سازی و بررسی نتیجه…');setRows(null);
    try {
      const withCsvTimes=input.map(item=>csvNeedsRunTime(rowObject(item)) ? supplyCsvRunTime(rowObject(item),startedAt.trim()) : item);
      const converted=await prepareJsonImport(withCsvTimes,startedAt.trim(),csrfToken);
      if(!converted.ok||!converted.items){setError(converted.error||'آماده‌سازی نتیجه ناموفق بود.');return;}
      if(new Blob([JSON.stringify({items:converted.items,options:opts})]).size>5*1024*1024){setError('خروجی پس از تبدیل بیش از سقف درخواست است. فایل را به بخش‌های کوچک‌تر تقسیم کنید.');return;}
      const res=await previewJsonImport(converted.items,opts,csrfToken);
      if(!res.ok||!res.rows){setError(res.error||'پیش‌نمایش ناموفق بود.');return;}
      setPrepared(converted.items);setRows(res.rows);setRowFilter('all');setSearch('');
      // Even a single source category can contain unrelated places. Previewing
      // automatically must never silently select records for registration.
      setDecisions(Object.fromEntries(res.rows.map(r=>[r.index,'skip'])));
    } finally { setBusy(false);setProgress(''); }
  };
  useEffect(()=>{
    if(!pendingPreview||busy)return;
    setPendingPreview(null);
    void executePreview(pendingPreview);
  },[pendingPreview,busy]); // The request is an immutable snapshot of the just-opened run.
  useEffect(()=>{
    if(!rows)return;
    const timer=window.setTimeout(()=>previewRef.current?.scrollIntoView?.({behavior:'smooth',block:'start'}),0);
    return ()=>window.clearTimeout(timer);
  },[rows]);
  const doPreview=()=>void executePreview({items,startedAt:runStartedAt,options});
  const visibleRows=useMemo(()=>{
    const filtered=(rows||[]).filter(r=>(rowFilter==='all'||r.status===rowFilter)&&(!search.trim()||`${r.name} ${r.source_id} ${r.source_category}`.toLowerCase().includes(search.trim().toLowerCase())));
    return filtered.sort((a,b)=>{
      if(sort==='name')return a.name.localeCompare(b.name,'fa')||a.index-b.index;
      if(sort==='reviews')return (b.reviews_count??-1)-(a.reviews_count??-1)||(b.rating??-1)-(a.rating??-1)||a.index-b.index;
      if(sort==='rating')return (b.rating??-1)-(a.rating??-1)||(b.reviews_count??-1)-(a.reviews_count??-1)||a.index-b.index;
      return a.index-b.index;
    });
  },[rows,rowFilter,search,sort]);
  const selected=(rows||[]).filter(r=>decisions[r.index]&&decisions[r.index]!=='skip');
  const eligibleVisible=visibleRows.filter(r=>r.status==='new'||r.status==='update');
  const doApply=async()=>{
    if(!rows||!selected.length||!prepared.length)return;
    if(!window.confirm(`${selected.length} رکورد انتخاب شده است. موارد جدید منتشر نمی‌شوند و تغییر مشخصات موجود به صف بازبینی می‌رود؛ اما امتیاز و تعداد نظر منبع موجود ممکن است بلافاصله به‌روز و در سایت نمایش داده شود. ادامه می‌دهید؟`))return;
    setBusy(true);setError('');setResults([]);
    const entries:ImportEntry[]=selected.map(r=>({index:r.index,item:prepared[r.index],decision:decisions[r.index],candidate_ids:r.candidates.map(c=>c.id)}));
    let all:ImportResult[]=[];
    try {
      for(let i=0;i<entries.length;i+=25){
        setProgress(`ثبت ${Math.min(i+25,entries.length)} از ${entries.length}…`);
        const res=await applyJsonImport(entries.slice(i,i+25),options,csrfToken);
        if(!res.ok||!res.results){setError(`${res.error||'ارتباط قطع شد.'} نتیجه نوبت آخر ممکن است نامشخص باشد؛ پیش از تلاش دوباره پیش‌نمایش بگیرید.`);setRows(null);break;}
        all=[...all,...res.results];setResults(all);setDecisions(prev=>{const next={...prev};for(const r of res.results||[])if(r.status==='created'||r.status==='updated')next[r.index]='skip';return next;});
      }
      await onRefreshData();
    } finally { setProgress('');setBusy(false); }
  };
  const needsDestination=!city||!category;
  const destinationFields=<div className="grid sm:grid-cols-2 gap-3">
    <label className="text-sm font-semibold">شهر مقصد<select disabled={busy} className={`${control} mt-1`} value={city} onChange={e=>{invalidate();setCity(Number(e.target.value));}}><option value="0">انتخاب شهر</option>{cities.filter(c=>Number(c.is_active)===1).map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
    <label className="text-sm font-semibold">دسته مقصد در حامی‌هاب<select disabled={busy} className={`${control} mt-1`} value={category} onChange={e=>{invalidate();setCategory(Number(e.target.value));}}><option value="0">انتخاب دسته</option>{categories.filter(c=>Number(c.is_active)===1).map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
  </div>;
  return <div className="space-y-5">
    <fieldset disabled={busy} className="w-full"><ApifyAutomationPanel cities={cities} categories={categories} csrfToken={csrfToken} onOpen={openRun} onBusyChange={setBusy} paused={busy}/></fieldset>
    <section ref={resultRef} tabIndex={-1} aria-labelledby="import-result-title" className="scroll-mt-4 bg-white border border-slate-200 rounded-2xl p-5 md:p-6 space-y-4 outline-none">
      <div className="flex flex-wrap justify-between items-start gap-3"><div><p className="text-xs font-bold text-blue-700">مرحله ۲ از ۳</p><h1 id="import-result-title" className="font-bold text-lg mt-1">بررسی نتیجه</h1><p className="text-sm text-slate-600 mt-1">اینجا فقط بررسی می‌کنید؛ هیچ موردی خودکار ثبت یا منتشر نمی‌شود.</p></div>
        {items.length>0&&<span className="rounded-full bg-blue-50 text-blue-800 text-sm font-bold px-3 py-1.5">{items.length} مورد دریافت‌شده</span>}</div>
      {!items.length&&<div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-600">هنوز نتیجه‌ای باز نشده است. در بخش بالا شهر و دسته را انتخاب کنید و «جمع‌آوری» را بزنید؛ نتیجه همین‌جا نمایش داده می‌شود.</div>}
      {items.length>0&&<>
        <p className="text-xs text-slate-500">{filename}</p>
        {needsDestination&&<div className="rounded-xl bg-amber-50 border border-amber-200 p-4 space-y-3"><p className="text-sm font-bold text-amber-950">شهر و دسته این نتیجه مشخص نیست؛ ابتدا مقصد درست را انتخاب کنید.</p><p className="text-xs leading-6 text-amber-900">انتخاب‌های بخش جمع‌آوری به نتیجه قبلی نسبت داده نمی‌شوند. {detectedCities.length>0?`شهرهای نوشته‌شده در داده: ${detectedCities.join('، ')}.`:'شهر و دسته را با محتوای نتیجه بررسی کنید.'}</p>{destinationFields}</div>}
        {!needsDestination&&<div className="flex flex-wrap items-center gap-2 text-sm"><CheckCircle2 className="w-4 h-4 text-emerald-600"/><span>مقصد بررسی:</span><b>{cities.find(c=>Number(c.id)===city)?.name_fa||'شهر نامشخص'} · {categories.find(c=>Number(c.id)===category)?.name_fa||'دسته نامشخص'}</b></div>}
        {!rows&&<div className="rounded-xl bg-slate-50 border border-slate-200 p-4"><p className="text-sm font-semibold">نمونه‌ای از نتیجه دریافت‌شده</p><ul className="mt-2 space-y-1 text-sm text-slate-700">{items.slice(0,5).map((item,index)=>{const r=rowObject(item);return <li key={index}>{String(r.name||r.title||'بدون نام')} <span className="text-slate-500">· {String(r.city||'شهر نامشخص')} · {getCategory(item)||'دسته نامشخص'}</span></li>;})}</ul>{items.length>5&&<p className="text-xs text-slate-500 mt-2">و {items.length-5} مورد دیگر در پیش‌نمایش کامل</p>}</div>}
        <details className="border border-slate-200 rounded-xl p-4" open={undefined}><summary className="cursor-pointer text-sm font-semibold">تنظیمات مقصد و فیلترهای بیشتر</summary>
          <div className="space-y-4 mt-4">{!needsDestination&&destinationFields}
            {items.some(needsRunTime)&&<label className="block text-xs font-semibold">زمان واقعی شروع جمع‌آوری (ISO با منطقه زمانی)<input dir="ltr" className={`${control} mt-1 max-w-lg`} value={runStartedAt} disabled={busy} onChange={e=>{invalidate();setRunStartedAt(e.target.value);}} placeholder="2026-11-06T10:00:00Z"/><span className="block text-slate-500 font-normal leading-5 mt-1">برای Apify از جزئیات همان اجرا استفاده کنید. در CSV بدون fetched_at، زمان واقعی شروع همان نوبت برای ردیف‌های فاقد زمان استفاده می‌شود؛ تاریخ شمسیِ بدون منطقه زمانی خودکار تفسیر نمی‌شود و در داده خام حفظ خواهد شد. اگر زمان را نمی‌دانید، ثبت نکنید.</span></label>}
            <div className="grid sm:grid-cols-2 gap-3"><label className="text-xs font-semibold">حداقل امتیاز (اختیاری)<input disabled={busy} type="number" min="0" max="5" step="0.1" className={`${control} mt-1`} value={minRating} onChange={e=>{invalidate();setMinRating(Number(e.target.value));}}/></label><label className="text-xs font-semibold">حداقل تعداد نظر (اختیاری)<input disabled={busy} type="number" min="0" step="1" className={`${control} mt-1`} value={minReviews} onChange={e=>{invalidate();setMinReviews(Number(e.target.value));}}/></label></div>
            {categoriesInFile.length>0&&<fieldset disabled={busy} className="border border-slate-200 rounded-lg p-3"><legend className="text-xs font-bold px-1">دسته‌های موجود در نتیجه</legend><div className="flex flex-wrap gap-3 mt-1">{categoriesInFile.map(([name,count])=><label key={name} className="text-xs flex items-center gap-1.5"><input type="checkbox" checked={sourceCategories.includes(name)} onChange={e=>{invalidate();setSourceCategories(prev=>e.target.checked?[...prev,name]:prev.filter(x=>x!==name));}}/>{name||'دسته نامشخص'} ({count})</label>)}</div><div className="flex gap-3 mt-3 text-xs"><button type="button" className="text-blue-700" onClick={()=>{invalidate();setSourceCategories(categoriesInFile.map(([n])=>n));}}>انتخاب همه</button><button type="button" className="text-slate-600" onClick={()=>{invalidate();setSourceCategories([]);}}>لغو انتخاب‌ها</button></div><p className="text-xs text-slate-500 mt-2">فقط دسته‌های مناسب مقصد را برای ثبت انتخاب کنید.</p></fieldset>}
          </div></details>
        {categoriesInFile.length>100&&<p className="text-sm text-amber-800">این نتیجه بیش از ۱۰۰ دسته دارد؛ در تنظیمات حداکثر ۱۰۰ دسته مرتبط را انتخاب کنید.</p>}
        {items.some(needsRunTime)&&!runStartedAt&&<p className="text-sm text-amber-800">زمان شروع مشخص نیست؛ در «تنظیمات مقصد و فیلترهای بیشتر» زمان واقعی را وارد کنید.</p>}
        {!rows&&!pendingPreview&&!busy&&<button type="button" onClick={doPreview} className="px-5 py-2.5 rounded-lg bg-blue-700 text-white text-sm font-bold">نمایش پیش‌نمایش</button>}
      </>}
      {error&&<p role="alert" className="text-sm text-rose-800 bg-rose-50 p-3 rounded-lg">{error}</p>}{progress&&<p className="text-sm text-blue-700" role="status">{progress}</p>}
      {results.length>0&&<div className="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-sm" role="status"><p className="font-bold">ثبت انجام شد: {results.filter(r=>r.status==='created').length} جدید، {results.filter(r=>r.status==='updated').length} به‌روزرسانی، {results.filter(r=>r.status==='skipped').length} ردشده، {results.filter(r=>r.status==='error').length} خطا.</p><p className="mt-1 text-emerald-900">موارد جدید برای تأیید و انتشار در «بازبینی» قرار می‌گیرند.</p>{onGoReview&&<button type="button" onClick={onGoReview} className="mt-3 bg-emerald-700 text-white rounded-lg px-4 py-2 font-bold">رفتن به بازبینی</button>}{results.some(r=>r.status==='skipped'||r.status==='error')&&<ul className="mt-2 text-xs space-y-1">{results.filter(r=>r.status==='skipped'||r.status==='error').map((r,i)=><li key={`${r.index}-${i}`}>ردیف {r.index+1}: {r.message}</li>)}</ul>}</div>}
      {rows&&<div ref={previewRef} className="scroll-mt-4 border-t border-slate-200 pt-4 space-y-4">
        <div className="flex flex-wrap justify-between items-start gap-3"><div><h2 className="font-bold text-base">پیش‌نمایش و انتخاب</h2><p className="text-xs text-slate-600 mt-1 leading-6">{(['new','update','review','filtered','invalid','conflict','stale'] as const).map(k=>`${labels[k]}: ${rows.filter(r=>r.status===k).length}`).join(' · ')}</p></div><button type="button" disabled={busy||!selected.length} onClick={()=>void doApply()} className="bg-emerald-700 text-white rounded-lg px-4 py-2.5 text-sm font-bold disabled:opacity-50">ثبت {selected.length} مورد انتخاب‌شده</button></div>
        <p className="text-sm text-slate-600">موارد مناسب را تیک بزنید؛ سپس دکمهٔ «ثبت موارد انتخاب‌شده» فعال می‌شود.</p>
        {(sourceCategories.length>1||sourceCategories.includes(''))&&<p className="rounded-lg bg-amber-50 text-amber-900 p-3 text-sm leading-6">دسته‌های منبع متفاوت یا نامشخص‌اند؛ مواردی را که واقعاً به مقصد می‌خورند خودتان انتخاب کنید. پیش‌نمایش به معنی تأیید دسته‌بندی نیست.</p>}
        <div className="grid sm:grid-cols-3 gap-3"><label className="flex items-center gap-2 text-xs"><Search className="w-4 h-4"/><input aria-label="جستجو در پیش‌نمایش" className={control} placeholder="جستجو در پیش‌نمایش" value={search} onChange={e=>setSearch(e.target.value)}/></label><select aria-label="فیلتر وضعیت" className={control} value={rowFilter} onChange={e=>setRowFilter(e.target.value)}><option value="all">همه وضعیت‌ها</option>{(Object.keys(labels) as ImportRow['status'][]).map(k=><option key={k} value={k}>{labels[k]}</option>)}</select><select aria-label="ترتیب پیش‌نمایش" className={control} value={sort} onChange={e=>setSort(e.target.value)}><option value="rating">امتیاز بالاتر، سپس تعداد نظر</option><option value="reviews">تعداد نظر بیشتر</option><option value="name">نام</option><option value="source">ترتیب نتیجه</option></select></div>
        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-slate-600"><span>{visibleRows.length} مورد نمایش داده می‌شود؛ انتخاب موارد پنهان در فیلتر حفظ می‌شود.</span><div className="flex gap-3"><button type="button" disabled={busy||!eligibleVisible.length} className="text-blue-700 font-semibold disabled:opacity-50" onClick={()=>setDecisions(prev=>({...prev,...Object.fromEntries(eligibleVisible.map(r=>[r.index,r.status==='new'?'new':'update']))}))}>انتخاب موارد قابل ثبتِ نمایش‌داده‌شده</button><button type="button" disabled={busy} className="text-slate-600" onClick={()=>setDecisions(prev=>Object.fromEntries(Object.keys(prev).map(k=>[k,'skip'])))}>لغو همه انتخاب‌ها</button></div></div>
        <div className="overflow-x-auto max-h-[36rem] overflow-y-auto"><table className="w-full text-sm text-right"><thead className="bg-slate-50 sticky top-0"><tr><th className="p-3">نام و شهر</th><th className="p-3">دسته منبع / امتیاز</th><th className="p-3">نتیجه بررسی</th><th className="p-3">انتخاب</th></tr></thead><tbody>{visibleRows.map(r=>{const data=rowObject(prepared[r.index]);return <tr key={r.index} className="border-t border-slate-200 align-top"><td className="p-3 min-w-44"><b>{r.name||'بدون نام'}</b><div className="text-xs text-slate-500 mt-1">{typeof data.city==='string'&&data.city?data.city:'شهر نامشخص'}{typeof data.address==='string'&&data.address?` · ${data.address}`:''}</div></td><td className="p-3 min-w-36">{r.source_category||'دسته نامشخص'}<div className="mt-1 text-xs text-slate-500">{r.rating===null?'بدون امتیاز':`${r.rating} از ۵`} · {r.reviews_count===null?'تعداد نظر نامشخص':`${r.reviews_count} نظر`}</div></td><td className="p-3 min-w-44"><strong>{labels[r.status]}</strong><div className="mt-1 text-xs leading-5 text-slate-600">{r.message}</div></td><td className="p-3 min-w-44">{(r.status==='new'||r.status==='update')&&<label className="flex gap-2"><input disabled={busy} type="checkbox" checked={!!decisions[r.index]&&decisions[r.index]!=='skip'} onChange={e=>setDecisions(prev=>({...prev,[r.index]:e.target.checked?(r.status==='new'?'new':'update'):'skip'}))}/>انتخاب این مورد</label>}{r.status==='review'&&<select disabled={busy} aria-label={`تصمیم برای ${r.name||'این مکان'}`} className={control} value={decisions[r.index]||'skip'} onChange={e=>setDecisions(prev=>({...prev,[r.index]:e.target.value}))}><option value="skip">فعلاً رد شود</option><option value="new">این مکان جدید و جداست</option>{r.candidates.map(c=><option key={c.id} value={`link:${c.id}`}>اتصال به #{c.id} {c.name} · {c.category_name||'دسته نامشخص'}{c.same_phone?' · تلفن مشترک':''}{c.distance!==null?` · ${Math.round(c.distance)} متر`:''} {c.address?` · ${c.address}`:''}</option>)}</select>}</td></tr>;})}</tbody></table></div>
      </div>}
      <details className="border-t border-slate-200 pt-4"><summary className="cursor-pointer text-sm font-semibold flex items-center gap-2"><FileJson className="w-4 h-4"/>ورود فایل JSON یا CSV (اختیاری)</summary><p className="text-xs text-slate-600 leading-6 mt-2">فایل UTF-8 با سرستون‌های استاندارد انگلیسی یا قالب فارسی کسب‌وکار؛ حداکثر ۵۰۰ رکورد و ۴ مگابایت. ابتدا پیش‌نمایش و سپس انتخاب و ثبت دستی انجام می‌شود.</p><label className="block text-sm mt-3">انتخاب فایل<input disabled={busy} type="file" accept=".json,.csv,application/json,text/csv" onChange={readFile} className="block mt-2 text-sm"/></label></details>
      <p className="text-xs text-slate-500 leading-6 flex gap-2"><AlertTriangle className="w-4 h-4 shrink-0 mt-1"/><span>دسته و شهر را پیش از ثبت بررسی کنید. موارد مشکوک به تصمیم شما نیاز دارند. متن نظر و وب‌سایت در داده خام حفظ می‌شود، اما نمایش مستقل نظر و انتقال خودکار وب‌سایت هنوز وجود ندارد. مجوز استفاده از داده‌ها را بررسی کنید.</span></p>
    </section>
  </div>;
};
