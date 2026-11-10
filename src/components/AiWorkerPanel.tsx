import React, { useCallback, useEffect, useState } from 'react';
import { AiManagedProvider, AiProviderStatus, AiServiceLogRow, fetchAiProviderLogs, fetchAiProviderManagement, fetchAiProviders, removeAiProvider, saveAiProvider, testAiProvider, updateFeatureSchema } from '../services/adminApi';

const input = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const secondary = 'rounded-lg border border-blue-300 bg-white px-3 py-2 text-sm font-semibold text-blue-800 disabled:opacity-50';
const primary = 'rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50';

type Result = {error:boolean;text:string} | null;

function ProviderCard({provider,csrfToken,schemaReady,onReload}:{provider:AiManagedProvider;csrfToken:string;schemaReady:boolean;onReload:()=>Promise<void>}) {
  const [model,setModel]=useState(provider.model);
  const [key,setKey]=useState('');
  const [baseUrl,setBaseUrl]=useState(provider.base_url ?? '');
  const [enabled,setEnabled]=useState(provider.enabled);
  const [confirmed,setConfirmed]=useState(provider.free_tier_confirmed);
  const [busy,setBusy]=useState(false);
  const [result,setResult]=useState<Result>(null);
  const [logs,setLogs]=useState<AiServiceLogRow[]|null>(null);
  const loadLogs=useCallback(async()=>{
    try { const d=await fetchAiProviderLogs(provider.id); setLogs(d.logs||[]); } catch { setLogs(null); }
  },[provider.id]);
  useEffect(()=>{setModel(provider.model);setEnabled(provider.enabled);setConfirmed(provider.free_tier_confirmed);setKey('');setBaseUrl(provider.base_url ?? '');},
    [provider.id,provider.version,provider.model,provider.enabled,provider.free_tier_confirmed,provider.base_url]);
  useEffect(()=>{ void loadLogs(); },[loadLogs]);

  const save=async(test:boolean)=>{
    if(busy)return;
    if(test && !enabled){setResult({error:true,text:'برای تست، ابتدا سرویس را فعال کنید.'});return;}
    if(test && !schemaReady){setResult({error:true,text:'ابتدا جدول‌های بخش هوش مصنوعی را آماده کنید.'});return;}
    if(test && !window.confirm('تست، یک درخواست کوتاه واقعی به همین سرویس می‌فرستد و ممکن است سهمیه یا هزینه داشته باشد. ادامه می‌دهید؟'))return;
    setBusy(true);setResult(null);
    try {
      const saved=await saveAiProvider({provider:provider.id,model,api_key:key,enabled,free_tier_confirmed:confirmed,version:provider.version,base_url:baseUrl.trim()},csrfToken);
      if(!saved.ok){setResult({error:true,text:saved.error||'ذخیره انجام نشد.'});return;}
      setKey('');
      await onReload(); void loadLogs();
      if(!test){setResult({error:false,text:saved.message||'تنظیمات ذخیره شد.'});return;}
      const checked=await testAiProvider(provider.id,csrfToken);
      setResult({error:!checked.ok,text:checked.ok?(checked.message||'اتصال موفق بود.'):`تنظیمات ذخیره شد؛ تست ناموفق: ${checked.error||'ارتباط برقرار نشد.'}`});
    } finally {setBusy(false);}
  };
  const remove=async()=>{
    if(busy || !window.confirm('کلید ذخیره‌شده در پنل حذف و این سرویس غیرفعال شود؟ کلیدهای قدیمی داخل فایل یا بکاپ جداگانه باقی می‌مانند.'))return;
    setBusy(true);setResult(null);
    try {
      const removed=await removeAiProvider(provider.id,provider.version,csrfToken);
      if(!removed.ok){setResult({error:true,text:removed.error||'حذف انجام نشد.'});return;}
      setKey('');await onReload();setResult({error:false,text:removed.message||'کلید حذف شد.'});
    } finally {setBusy(false);}
  };
  return <section className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3" aria-label={`تنظیمات ${provider.label}`}>
    <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold" dir="ltr">{provider.label}</h3>
      <span className={`text-xs font-semibold ${provider.available?'text-emerald-700':'text-slate-500'}`}>{provider.available?'تنظیم‌شده (اتصال را تست کنید)':'غیرفعال یا تنظیم‌نشده'}</span>
    </div>
    <p className="text-xs text-slate-600">{provider.key_configured?'کلید ثبت شده است؛ برای تغییر، کلید جدید را وارد کنید.':'کلید API را وارد کنید.'} {provider.source==='file'?'تنظیم قبلی از فایل خصوصی خوانده می‌شود.':''}</p>
    {provider.error&&<p role="alert" className="text-xs text-rose-700">{provider.error}</p>}
    <div className="grid gap-3 sm:grid-cols-2">
      <label className="text-sm">کلید API <input aria-label={`کلید API ${provider.label}`} type="password" dir="ltr" maxLength={512} autoComplete="new-password" spellCheck={false} className={`${input} mt-1`} value={key}
        placeholder={provider.key_configured?'خالی = حفظ کلید فعلی':'کلید API'} onChange={e=>{setKey(e.target.value);if(e.target.value && !provider.key_configured)setEnabled(true);}} /></label>
      <label className="text-sm">شناسه مدل (قابل ویرایش) <input aria-label={`مدل ${provider.label}`} dir="ltr" maxLength={120} autoComplete="off" spellCheck={false} className={`${input} mt-1`} value={model} onChange={e=>setModel(e.target.value)}/></label>
    </div>
    <label className="block text-sm">نشانی جایگزین سرویس (اختیاری — پراکسی/Cloudflare Worker برای عبور از تحریم IP)
      <input aria-label={`نشانی جایگزین ${provider.label}`} dir="ltr" maxLength={300} autoComplete="off" spellCheck={false} className={`${input} mt-1`}
        placeholder="https://your-worker.example/openai/v1/chat/completions" value={baseUrl} onChange={e=>setBaseUrl(e.target.value)}/>
    </label>
    <p className="text-xs text-slate-500">برای سرویس‌های Chat نشانی کامل endpoint جایگزین می‌شود؛ برای Gemini نشانی پایه است و <code dir="ltr">/models/…/generateContent</code> به آن اضافه می‌شود. خالی بگذارید = نشانی رسمی سرویس. فقط https و بدون پارامتر پذیرفته می‌شود.</p>
    {(provider.suggested_models?.length ?? 0)>0&&<label className="block text-sm">مدل‌های پیشنهادی
      <select aria-label={`مدل پیشنهادی ${provider.label}`} className={`${input} mt-1`} dir="ltr"
        value={provider.suggested_models?.some(item=>item.id===model)?model:''} onChange={e=>{if(e.target.value)setModel(e.target.value);}}>
        <option value="" disabled>انتخاب از فهرست یا وارد کردن شناسه دلخواه</option>
        {provider.suggested_models?.map(item=><option key={item.id} value={item.id}>{item.id}{item.note?` — ${item.note}`:''}</option>)}
      </select>
    </label>}
    <p className="text-xs text-slate-600">انتخاب از فهرست فقط شناسه بالا را تغییر می‌دهد؛ برای اعمال، تنظیمات را ذخیره کنید. شناسه مدل دلخواه نیز قابل وارد کردن است.</p>
    {provider.id==='openrouter'&&<p className="text-xs text-slate-600">در OpenRouter فقط <code dir="ltr">openrouter/free</code> یا مدل‌های دارای پسوند <code dir="ltr">:free</code> پذیرفته می‌شوند.</p>}
    <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={enabled} onChange={e=>setEnabled(e.target.checked)}/><span>این سرویس برای نویسنده فعال باشد</span></label>
    <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)}/><span>طرح، سهمیه و احتمال هزینه این حساب را بررسی کرده‌ام.</span></label>
    {logs&&logs.length>0&&<div className="rounded-lg border border-slate-200 bg-white p-3">
      <p className="mb-2 text-xs font-bold text-slate-700">آخرین تلاش‌های اتصال — کد وضعیت دقیق هر تلاش (بدون افشای کلید)</p>
      <ul className="space-y-1 text-xs">
        {logs.slice(0,6).map((l,i)=>(
          <li key={i} className="flex flex-wrap items-center gap-2">
            <span className={`rounded px-1.5 py-0.5 font-bold ${l.ok?'bg-emerald-50 text-emerald-700':'bg-rose-50 text-rose-700'}`} dir="ltr">{l.ok?'OK':(l.status_code??'NETWORK')}</span>
            <span className="tabular-nums text-slate-400" dir="ltr">{l.created_at}</span>
            <span className="text-slate-600">{l.message}</span>
          </li>
        ))}
      </ul>
    </div>}
    <div className="flex flex-wrap gap-2">
      <button type="button" className={primary} disabled={busy} onClick={()=>void save(false)}>ذخیره تنظیمات</button>
      <button type="button" className={secondary} disabled={busy} onClick={()=>void save(true)}>ذخیره و تست اتصال</button>
      {provider.key_configured&&<button type="button" className="rounded-lg border border-rose-200 px-3 py-2 text-sm text-rose-700 disabled:opacity-50" disabled={busy} onClick={()=>void remove()}>حذف کلید</button>}
    </div>
    {busy&&<p className="text-xs text-slate-600">در حال انجام…</p>}
    {result&&<p role={result.error?'alert':'status'} className={`text-sm ${result.error?'text-rose-700':'text-emerald-700'}`}>{result.text}</p>}
  </section>;
}

export const AiWorkerPanel: React.FC<{csrfToken:string;isAdmin:boolean}> = ({csrfToken,isAdmin}) => {
  const [providers,setProviders]=useState<AiProviderStatus[]>([]);
  const [managed,setManaged]=useState<AiManagedProvider[]>([]);
  const [ready,setReady]=useState<boolean|null>(null);
  const [error,setError]=useState('');
  const [busy,setBusy]=useState(false);
  const load=useCallback(async()=>{
    try {
      if(isAdmin){const d=await fetchAiProviderManagement();setManaged(d.providers);setProviders(d.providers);setReady(d.schema_ready);}
      else {const d=await fetchAiProviders();setProviders(d.providers);setReady(d.schema_ready);}
      setError('');
    } catch(e){setError(e instanceof Error?e.message:'خطا در دریافت وضعیت.');}
  },[isAdmin]);
  useEffect(()=>{void load();},[load]);
  const install=async()=>{
    if(!window.confirm('از فایل‌ها و دیتابیس پشتیبان گرفته‌اید؟ فقط جدول‌های جدید اضافه می‌شوند و مهاجرت قدیمی ۰۰۳ اجرا نمی‌شود.'))return;
    setBusy(true);const result=await updateFeatureSchema(csrfToken);setBusy(false);if(!result.ok)setError(result.error);else await load();
  };
  return <section className="rounded-xl bg-white border border-slate-200 p-6 space-y-4">
    <h2 className="font-bold text-slate-900">سرویس‌های نویسنده هوش مصنوعی</h2>
    <p className="text-sm text-slate-600 leading-7">{isAdmin?'کلید و مدل را همین‌جا ثبت یا تغییر دهید. کلید پس از ذخیره دیگر نمایش داده نمی‌شود.':'مدیر سایت می‌تواند کلید و مدل سرویس‌ها را در این بخش تنظیم کند.'} سرویس‌ها خودکار جابه‌جا نمی‌شوند.</p>
    <p className="text-xs text-amber-900 leading-6">رایگان‌بودن و هزینه به حساب، مدل، منطقه و سهمیه وابسته است. تست هم یک درخواست واقعی است؛ قبل از تأیید، صورتحساب را بررسی کنید.</p>
    {error&&<p role="alert" className="text-sm text-rose-800">{error}</p>}
    {ready===false&&<div className="text-sm rounded bg-amber-50 p-3 text-amber-900">جدول‌های بخش جدید آماده نیستند. {isAdmin&&<button type="button" disabled={busy} className="underline font-bold" onClick={()=>void install()}>آماده‌سازی پس از پشتیبان‌گیری</button>}</div>}
    {isAdmin?<div className="space-y-3">{managed.map(p=><ProviderCard key={p.id} provider={p} csrfToken={csrfToken} schemaReady={ready===true} onReload={load}/>)}</div>:
      <ul className="text-sm divide-y">{providers.map(p=><li key={p.id} className="py-2 flex justify-between gap-3"><span dir="ltr">{p.label} {p.model&&`· ${p.model}`}</span><span className={p.available?'text-emerald-700':'text-slate-500'}>{p.available?'تنظیم‌شده':'تنظیم‌نشده'}</span></li>)}</ul>}
    <p className="text-xs text-slate-600 leading-6">فقط مدیر می‌تواند کلیدها را ثبت یا حذف کند. کلید را در گفت‌وگو نفرستید؛ پشتیبان فایل‌های خصوصی و دیتابیس را امن نگه دارید.</p>
  </section>;
};
