import React, { useEffect, useMemo, useState } from 'react';
import { Database, Sparkles } from 'lucide-react';
import { AiProviderStatus, AiFactCombo, fetchAiProviders, fetchAiFactCombos, fetchAiFacts, generateAiArticle } from '../services/adminApi';

const input = 'w-full border border-slate-300 rounded-lg bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300';
const button = 'inline-flex items-center gap-2 rounded-lg border border-blue-300 bg-white px-3 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-50 disabled:opacity-50';

// S29: ارقام فارسی برای پیام‌های رابط کاربری
const faDigits = (n:number|string) => String(n).replace(/\d/g, d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

interface Props { title:string; currentText:string; csrfToken:string; onInsert:(text:string, mode:'append'|'replace')=>void }

/** Explicit request → separate suggestion → explicit insert. Never saves or publishes. */
export const AiArticleAssistant:React.FC<Props> = ({title,currentText,csrfToken,onInsert}) => {
  const [open,setOpen]=useState(false);
  const [providers,setProviders]=useState<AiProviderStatus[]>([]);
  const [schemaReady,setSchemaReady]=useState(false);
  const [provider,setProvider]=useState('');
  const [brief,setBrief]=useState(''); const [facts,setFacts]=useState('');
  const [confirmSend,setConfirmSend]=useState(false); const [confirmQuota,setConfirmQuota]=useState(false);
  const [result,setResult]=useState(''); const [resultProvider,setResultProvider]=useState('');
  const [loading,setLoading]=useState(false); const [busy,setBusy]=useState(false); const [error,setError]=useState('');
  // S29: فکت از کسب‌وکارهای منتشرشده — کاملاً دستی؛ هیچ فراخوانی خودکاری در بازشدن دستیار انجام نمی‌شود
  const [factsOpen,setFactsOpen]=useState(false);
  const [combos,setCombos]=useState<AiFactCombo[]>([]);
  const [combosLoaded,setCombosLoaded]=useState(false);
  const [comboId,setComboId]=useState('');
  const [factBusy,setFactBusy]=useState(false);
  const [factMsg,setFactMsg]=useState('');
  const [factError,setFactError]=useState('');
  useEffect(()=>{
    if(!open) return;
    let cancelled=false; setLoading(true); setError('');
    fetchAiProviders().then(data=>{
      if(cancelled)return; setProviders(data.providers);setSchemaReady(data.schema_ready);
      setProvider(previous=>data.providers.some(p=>p.id===previous && p.available) ? previous : (data.providers.find(p=>p.available)?.id || ''));
    }).catch(e=>{if(!cancelled)setError(e instanceof Error?e.message:'دریافت وضعیت سرویس‌ها ناموفق بود.');})
      .finally(()=>{if(!cancelled)setLoading(false);});
    return()=>{cancelled=true;};
  },[open]);
  const generate=async()=>{
    if(busy || !provider || !confirmSend || !confirmQuota)return;
    if(result && !window.confirm('متن پیشنهادی قبلی با نتیجه جدید جایگزین شود؟'))return;
    setBusy(true);setError('');
    const response=await generateAiArticle({provider,title,brief,facts,confirm_send:confirmSend,confirm_quota:confirmQuota},csrfToken);
    setBusy(false);
    if(!response.ok || !response.text){setError(response.error || 'تولید متن ناموفق بود.');return;}
    setResult(response.text);setResultProvider(`${response.provider || ''} · ${response.model || ''}`);
  };
  const insert=(replace:boolean)=>{
    if(!result)return;
    if(replace && currentText.trim() && !window.confirm('متن فعلی مقاله با پیشنهاد جایگزین شود؟'))return;
    onInsert(result, replace ? 'replace' : 'append');
    setResult('');
  };
  // S29: بارگذاری ترکیب‌های شهر×دسته فقط با باز شدن دستی بلوک فکت‌ها؛ یک‌بار و کش در حافظهٔ کامپوننت
  useEffect(()=>{
    if(!factsOpen || combosLoaded) return;
    let cancelled=false; setFactError('');
    fetchAiFactCombos().then(data=>{
      if(cancelled)return;
      setCombos(Array.isArray(data.combos)?data.combos:[]);
      setCombosLoaded(true);
    }).catch(e=>{if(!cancelled)setFactError(e instanceof Error?e.message:'دریافت ترکیب‌های شهر و دسته ناموفق بود.');});
    return()=>{cancelled=true;};
  },[factsOpen,combosLoaded]);
  const cityGroups=useMemo(()=>{
    const groups=new Map<number,{id:number;name:string;total:number;cats:{id:number;name:string;total:number}[]}>();
    for(const c of combos){
      const cityId=Number(c.city_id);
      const g=groups.get(cityId)??{id:cityId,name:c.city_name,total:0,cats:[]};
      g.total+=Number(c.total);
      g.cats.push({id:Number(c.category_id),name:c.category_name,total:Number(c.total)});
      groups.set(cityId,g);
    }
    return [...groups.values()];
  },[combos]);
  const loadFacts=async()=>{
    if(factBusy||!comboId)return;
    const sep=comboId.indexOf(':');
    const cityId=Number(comboId.slice(0,sep)); const categoryId=Number(comboId.slice(sep+1));
    setFactBusy(true);setFactError('');setFactMsg('');
    let response;
    try{ response=await fetchAiFacts(cityId,categoryId); }
    catch(e){ setFactBusy(false); setFactError(e instanceof Error?e.message:'دریافت فکت‌ها ناموفق بود.'); return; }
    setFactBusy(false);
    if(!response.ok){ setFactError(response.error || 'دریافت فکت‌ها ناموفق بود.'); return; }
    const count=Number(response.count||0);
    if(!count){ setFactError('مرکز منتشرشده‌ای برای این ترکیب نیست.'); return; }
    const addition=(response.facts||'').trim();
    const base=facts.trim();
    const combined=base?`${base}\n\n${addition}`:addition;
    if(combined.length>8000){
      setFacts(combined.slice(0,8000));
      setFactMsg(`${faDigits(count)} مرکز اضافه شد، اما سقف ۸۰۰۰ حرف فیلد پر شد و انتهای متن کُنده شد؛ فکت‌ها را دستی کوتاه‌تر کنید.`);
    }else{
      setFacts(combined);
      setFactMsg(`${faDigits(count)} مرکز به انتهای فکت‌ها اضافه شد.` + (response.truncated?' (به دلیل سقف طول فقط بخشی از مراکز جا شد.)':''));
    }
  };
  return <section className="rounded-xl border border-blue-200 bg-blue-50/40 p-4" aria-label="دستیار نوشتن مقاله">
    <button type="button" className="flex items-center gap-2 font-bold text-sm text-blue-900" aria-expanded={open} onClick={()=>setOpen(v=>!v)}><Sparkles size={17}/> نوشتن با هوش مصنوعی <span className="font-normal text-xs">(اختیاری)</span></button>
    {open&&<div className="space-y-3 mt-4">
      <p className="text-xs leading-6 text-slate-700">سرویس‌های آماده در فهرست نمایش داده می‌شوند؛ هیچ جابه‌جایی یا فراخوانی خودکار انجام نمی‌شود. فقط عنوان و دو کادر زیر به سرویس انتخاب‌شده ارسال می‌شوند، نه کل مقاله یا بانک کسب‌وکارها.</p>
      <p className="text-xs leading-6 text-amber-900">رایگان بودن به حساب، مدل، سهمیه و منطقه بستگی دارد و تضمین‌شده نیست؛ قبل از ارسال، صورتحساب حساب خود را بررسی کنید. متن ممکن است اشتباه باشد و همه اطلاعات و حقوق تصاویر باید انسانی بررسی شوند.</p>
      {loading&&<p className="text-sm">در حال بررسی تنظیمات…</p>}
      {error&&<p role="alert" className="text-sm text-rose-800">{error}</p>}
      {!loading&&!schemaReady&&<p className="text-sm text-amber-900">مدیر باید پس از پشتیبان، به‌روزرسانی اختصاصی بخش‌های جدید را در تنظیمات اجرا کند.</p>}
      {!loading&&schemaReady&&providers.every(p=>!p.available)&&<p className="text-sm text-amber-900">هیچ سرویس آماده‌ای نیست. مدیر می‌تواند کلید و مدل را در «تنظیمات ← سرویس‌های نویسنده» ثبت و تست کند؛ کلید را در گفت‌وگو نفرستید.</p>}
      <label className="block text-sm">سرویس<select aria-label="سرویس هوش مصنوعی" className={`${input} mt-1`} value={provider} onChange={e=>setProvider(e.target.value)} disabled={busy||!schemaReady}>
        <option value="">انتخاب سرویس</option>{providers.map(p=><option key={p.id} value={p.id} disabled={!p.available}>{p.label} {p.model ? `· ${p.model}` : ''} {!p.available?'(آماده نیست)':''}</option>)}
      </select></label>
      <label className="block text-sm">راهنمای نویسنده (اختیاری)<textarea aria-label="راهنمای نویسنده" className={`${input} mt-1`} rows={2} maxLength={2500} value={brief} onChange={e=>setBrief(e.target.value)} placeholder="موضوع، مخاطب و سبک دلخواه"/></label>
      <label className="block text-sm">اطلاعات و منابع بررسی‌شده برای این متن (اختیاری)<textarea aria-label="اطلاعات بررسی‌شده" className={`${input} mt-1`} rows={3} maxLength={8000} value={facts} onChange={e=>setFacts(e.target.value)} placeholder="فقط اطلاعاتی را وارد کنید که اجازه ارسال و استفاده از آن‌ها را دارید."/></label>
      <div className="rounded-lg border border-slate-200 bg-white p-3">
        <button type="button" className="inline-flex items-center gap-2 text-xs font-semibold text-blue-800 hover:underline" aria-expanded={factsOpen} onClick={()=>setFactsOpen(v=>!v)}><Database size={15}/> فکت از کسب‌وکارهای منتشرشده (دیتابیس) <span className="font-normal">{factsOpen?'(بستن)':'(نمایش)'}</span></button>
        {factsOpen&&<div className="space-y-2 mt-2">
          <p className="text-xs leading-6 text-slate-600">ترکیب شهر و دسته را انتخاب کنید؛ نام، دسته، محله/نشانی و تلفن عمومیِ مراکز منتشرشده به‌صورت ردیف‌های فکت به انتهای کادر بالا اضافه می‌شود. متن فعلی حذف نمی‌شود و پیش از ارسال قابل ویرایش است؛ این داده فقط با تیک ارسال شما به سرویس می‌رود. هیچ فراخوانی خودکاری انجام نمی‌شود.</p>
          {factError&&<p role="alert" className="text-xs text-rose-800">{factError}</p>}
          {factMsg&&<p className="text-xs text-emerald-800">{factMsg}</p>}
          <select aria-label="ترکیب شهر و دسته برای فکت" className={input} value={comboId} onChange={e=>{setComboId(e.target.value);setFactMsg('');setFactError('');}} disabled={factBusy}>
            <option value="">انتخاب شهر و دسته</option>
            {cityGroups.map(g=><optgroup key={g.id} label={`${g.name} — مجموع ${faDigits(g.total)} مرکز`}>
              <option value={`${g.id}:0`}>همهٔ دسته‌های {g.name} ({faDigits(g.total)} مرکز)</option>
              {g.cats.map(ct=><option key={ct.id} value={`${g.id}:${ct.id}`}>{ct.name} ({faDigits(ct.total)} مرکز)</option>)}
            </optgroup>)}
          </select>
          <button type="button" className={button} disabled={factBusy||!comboId} onClick={()=>void loadFacts()}>{factBusy?'در حال دریافت…':'دریافت فکت‌ها و افزودن به اطلاعات بالا'}</button>
        </div>}
      </div>
      <label className="flex items-start gap-2 text-xs leading-6"><input type="checkbox" className="mt-1" checked={confirmSend} onChange={e=>setConfirmSend(e.target.checked)}/> اجازه می‌دهم عنوان، راهنما و اطلاعات بالا به سرویس انتخاب‌شده ارسال شود.</label>
      <label className="flex items-start gap-2 text-xs leading-6"><input type="checkbox" className="mt-1" checked={confirmQuota} onChange={e=>setConfirmQuota(e.target.checked)}/> سهمیه و احتمال هزینه حسابم را بررسی کرده‌ام.</label>
      <button type="button" className={button} disabled={busy||loading||!schemaReady||!provider||!title.trim()||!confirmSend||!confirmQuota} onClick={()=>void generate()}>{busy?'در حال تولید…':'ساخت متن پیشنهادی'}</button>
      {!title.trim()&&<p className="text-xs text-slate-600">ابتدا عنوان مقاله را وارد کنید.</p>}
      {result&&<div className="border border-blue-200 bg-white rounded-lg p-3 space-y-2"><p className="text-sm font-bold">پیشنهاد برای بازبینی <span dir="ltr" className="text-xs font-normal">{resultProvider}</span></p><textarea aria-label="متن پیشنهادی هوش مصنوعی" readOnly className={input} rows={9} value={result}/><p className="text-xs text-slate-600">این متن هنوز ذخیره یا منتشر نشده است؛ ادعاها را با منبع واقعی بررسی کنید.</p><div className="flex flex-wrap gap-2"><button type="button" className={button} onClick={()=>insert(false)}>افزودن به انتهای متن</button><button type="button" className={button} onClick={()=>insert(true)}>جایگزینی متن مقاله</button></div></div>}
    </div>}
  </section>;
};
