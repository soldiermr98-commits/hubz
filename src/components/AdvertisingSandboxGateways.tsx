import React, {useCallback, useEffect, useRef, useState} from 'react';
import {AdGatewayLog, AdGatewayStatus, GATEWAY_PENDING_STORAGE_KEY, fetchAdvertisingGateways, financeMutation} from '../services/adminApi';

const input='w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const button='inline-flex items-center justify-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50';
type Draft={mode:'disabled'|'sandbox';key:string};
function gatewayPending():boolean {try{return window.sessionStorage.getItem(GATEWAY_PENDING_STORAGE_KEY)!==null;}catch{return false;}}
function markGatewayPending(pending:boolean):boolean {
  try {if(pending)window.sessionStorage.setItem(GATEWAY_PENDING_STORAGE_KEY,'1');else window.sessionStorage.removeItem(GATEWAY_PENDING_STORAGE_KEY);return true;}
  catch{return false;}
}

/** Experimental sandbox settings only. Credentials are never persisted in browser storage. */
export const AdvertisingSandboxGateways:React.FC<{
  csrfToken:string; disabled:boolean; onProviders:(v:AdGatewayStatus[])=>void; onBlocked:(v:boolean)=>void;
}> = ({csrfToken,disabled,onProviders,onBlocked})=>{
  const [rows,setRows]=useState<AdGatewayStatus[]>([]);const [log,setLog]=useState<AdGatewayLog[]>([]);
  const [drafts,setDrafts]=useState<Record<string,Draft>>({});const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);const [needsReview,setNeedsReview]=useState(gatewayPending);
  const [persisted,setPersisted]=useState(true);const [reviewed,setReviewed]=useState(false);const [notice,setNotice]=useState('');const busyRef=useRef(false);
  const load=useCallback(async()=>{
    setLoading(true);
    try {
      const result=await fetchAdvertisingGateways();
      if(!result.schema_ready||!Array.isArray(result.providers))throw new Error('تنظیمات آزمایشی در دسترس نیست.');
      setRows(result.providers);setLog(Array.isArray(result.log)?result.log:[]);onProviders(result.providers);
      setDrafts(Object.fromEntries(result.providers.map(p=>[p.id,{mode:p.mode==='sandbox'?'sandbox':'disabled',key:''}])));
      return true;
    } catch(e) {setRows([]);onProviders([]);setNotice(e instanceof Error?e.message:'دریافت درگاه‌ها ناموفق بود.');return false;}
    finally {setLoading(false);}
  },[onProviders]);
  useEffect(()=>{void load();},[load]);
  useEffect(()=>{onBlocked(loading||busy||needsReview||rows.length===0);},[loading,busy,needsReview,rows.length,onBlocked]);
  const change=(id:string,patch:Partial<Draft>)=>setDrafts(current=>({...current,[id]:{...(current[id]||{mode:'disabled',key:''}),...patch}}));
  const save=async(row:AdGatewayStatus,remove=false)=>{
    if(disabled||busyRef.current||needsReview)return;
    const draft=drafts[row.id];if(!draft)return;
    const question=remove?`کلید ${row.label} حذف و درگاه آزمایشی غیرفعال شود؟ تراکنش‌های قبلی تغییر نمی‌کنند.`:
      `تنظیمات ${row.label} فقط برای حالت آزمایشی ذخیره شود؟ دریافت واقعی در این نسخه غیرفعال است.`;
    if(!window.confirm(question))return;
    busyRef.current=true;setBusy(true);setReviewed(false);setNotice('');setPersisted(markGatewayPending(true));
    const payload={provider:row.id,mode:remove?'disabled':draft.mode,key:remove?'':draft.key,version:Number(row.version),confirm_live:false,remove,remove_confirmed:remove};
    change(row.id,{key:''}); // do not retain credentials after sending, even on timeout
    try {
      const result=await financeMutation('/api/v1/advertising/gateways/save',payload,csrfToken);
      if(result.ok){markGatewayPending(false);setNotice(result.message||'تنظیمات آزمایشی ذخیره شد.');await load();}
      else if(result.ambiguous){setNeedsReview(true);setNotice('نتیجه ذخیره نامشخص است؛ کلید از فرم پاک شد. وضعیت را تازه کنید و پیش از تغییر دوباره مستقل بررسی کنید.');}
      else {markGatewayPending(false);setNotice(result.error||'تنظیمات ثبت نشد.');await load();}
    } finally {busyRef.current=false;setBusy(false);}
  };
  return <section aria-label="تنظیم درگاه آزمایشی" className="rounded-lg border border-amber-300 bg-amber-50/50 p-4 space-y-3">
    <div><h3 className="font-bold">درگاه آزمایشی — بدون دریافت وجه واقعی</h3><p className="text-xs leading-6">فقط برای بررسی در محیط آزمایشی و قرارداد ساختگی استفاده شود. فعال‌سازی واقعی در این نسخه ممکن نیست؛ کلیدها به مرورگر بازگردانده نمی‌شوند.</p></div>
    {notice&&<p role="status" className="rounded bg-white p-2 text-xs">{notice}</p>}
    {needsReview&&<div role="alert" className="rounded border border-amber-300 bg-white p-3 text-xs space-y-2"><p>نتیجه آخرین ذخیره نامشخص است. پس از بررسی وضعیت و تاریخچه، پیگیری را صریحاً پایان دهید.</p>{!persisted&&<p>حافظه موقت مرورگر در دسترس نیست؛ قبل از خروج وضعیت را بررسی کنید.</p>}<div className="flex flex-wrap gap-2"><button type="button" className={button} disabled={loading||busy} onClick={()=>void load().then(setReviewed)}>بررسی مجدد تنظیمات</button><button type="button" className={button} disabled={!reviewed||busy} onClick={()=>{if(window.confirm('وضعیت و تاریخچه تنظیمات را بررسی کرده‌اید؟')){markGatewayPending(false);setNeedsReview(false);setReviewed(false);}}}>پایان بررسی تنظیمات</button></div></div>}
    {loading?<p className="text-xs">در حال دریافت تنظیمات…</p>:rows.map(row=>{
      const draft=drafts[row.id]||{mode:'disabled',key:''};const locked=disabled||busy||needsReview;
      return <div key={row.id} className="rounded border bg-white p-3 space-y-2">
        <p className="text-sm font-semibold">{row.label} · {row.mode==='sandbox'?'آزمایشی':row.mode==='live'?'تنظیم واقعی قدیمی، غیرقابل استفاده':'غیرفعال'} · {row.key_configured?'کلید ذخیره‌شده':'بدون کلید'}</p>
        {row.error&&<p role="alert" className="text-xs text-rose-700">{row.error}</p>}
        <div className="grid gap-2 sm:grid-cols-2">
          <label className="text-xs">حالت {row.label}<select className={`${input} mt-1`} disabled={locked} value={draft.mode} onChange={e=>change(row.id,{mode:e.target.value as Draft['mode']})}><option value="disabled">غیرفعال</option><option value="sandbox">فقط آزمایشی</option></select></label>
          <label className="text-xs">کلید {row.label}<input className={`${input} mt-1`} type="password" autoComplete="new-password" disabled={locked} value={draft.key} onChange={e=>change(row.id,{key:e.target.value})} placeholder={row.key_configured?'خالی: حفظ کلید فعلی':'کلید آزمایشی'}/></label>
        </div>
        {row.id==='zibal'&&<p className="text-xs text-slate-600">کلید عمومی سندباکس زیبال: <code>zibal</code></p>}
        {row.id==='aqayepardakht'&&<p className="text-xs text-slate-600">پین عمومی سندباکس آقای پرداخت: <code>sandbox</code></p>}
        <div className="flex flex-wrap gap-2"><button type="button" className={button} disabled={locked} onClick={()=>void save(row)}>ذخیره تنظیمات {row.label}</button><button type="button" className={button} disabled={locked||!row.key_configured} onClick={()=>void save(row,true)}>حذف کلید {row.label}</button></div>
      </div>;
    })}
    {log.length>0&&<details><summary className="cursor-pointer text-xs font-semibold">تاریخچه تغییر تنظیمات</summary><ul className="mt-2 space-y-1 text-xs">{log.map((item,i)=><li key={`${item.provider}-${item.version}-${i}`}>{item.provider} · {item.old_mode} ← {item.new_mode} · {item.key_action} · مدیر #{item.changed_by} · {item.changed_at}</li>)}</ul></details>}
    <p className="text-xs text-slate-600 leading-6">کلیدها فقط رمزگذاری‌شده در سرور نگهداری می‌شوند؛ پشتیبان کلید خصوصی سرور باید جدا و امن باشد. هر لینک عکس ثابت تنظیمات خود را حفظ می‌کند.</p>
  </section>;
};
