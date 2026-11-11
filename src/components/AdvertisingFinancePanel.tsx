import { PersianDateInput } from './PersianDateInput';
import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  AdBanner, AdContract, AdFinanceData, AdGatewayStatus, AdOnlinePaymentSummary,
  FINANCE_PENDING_STORAGE_KEY, fetchAdvertisingFinance, financeMutation, updateAdvertisingSchema,
} from '../services/adminApi';
import {AdvertisingSandboxGateways} from './AdvertisingSandboxGateways';

const input='w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
const button='inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold hover:bg-slate-50 disabled:opacity-50';
const primary='inline-flex items-center justify-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-bold text-white hover:bg-blue-800 disabled:opacity-50';
const statusLabel:Record<string,string>={active:'فعال',completed:'تکمیل‌شده',cancelled:'لغوشده'};
const methodLabel:Record<string,string>={bank_transfer:'انتقال بانکی',cash:'نقدی',other:'سایر'};
const onlineState:Record<string,string>={link_ready:'لینک شروع‌نشده',creating:'در حال ایجاد درگاه',pending:'در انتظار نتیجه',verifying:'در حال بررسی',uncertain:'نتیجه نامشخص',verified:'تأییدشده',reconciled:'ثبت با بررسی مدیر',sandbox_verified:'تأیید آزمایشی',sandbox_reconciled:'ثبت آزمایشی با بررسی مدیر',cancelled:'لغوشده'};
const paths={contract:'/api/v1/advertising/contracts',manual:'/api/v1/advertising/payments/manual',status:'/api/v1/advertising/contracts/status',void:'/api/v1/advertising/payments/manual/void',link:'/api/v1/advertising/payments/online',resolve:'/api/v1/advertising/payments/online/resolve'} as const;
type PendingKind=keyof typeof paths;
type Pending={kind:PendingKind;payload:Record<string,unknown>};
type ContractForm={title:string;client_name:string;client_phone:string;amount_toman:string;banner_id:number;starts_at:string;ends_at:string;notes:string};
type PaymentForm={amount_toman:string;method:'bank_transfer'|'cash'|'other';reference:string;notes:string;confirmed:boolean};
type LinkForm={amount_toman:string;provider:string;hours:number;confirmed:boolean};
type ResolutionForm={contract:AdContract;payment:AdOnlinePaymentSummary;resolution:''|'paid'|'not_paid';reason:string;reference:string;checked_provider:boolean;no_future_charge_confirmed:boolean};
const emptyContract=():ContractForm=>({title:'',client_name:'',client_phone:'',amount_toman:'',banner_id:0,starts_at:'',ends_at:'',notes:''});
const emptyPayment=():PaymentForm=>({amount_toman:'',method:'bank_transfer',reference:'',notes:'',confirmed:false});
const money=(n:string|number)=>`${Number(n).toLocaleString('fa-IR')} تومان`;

function randomRequestKey():string {
  if (!window.crypto?.getRandomValues) throw new Error('تولید شناسه امن در این مرورگر ممکن نیست. با HTTPS و مرورگر به‌روز وارد شوید.');
  return Array.from(window.crypto.getRandomValues(new Uint8Array(32)),v=>v.toString(16).padStart(2,'0')).join('');
}
function readPending():Pending|null {
  try {
    const raw=window.sessionStorage.getItem(FINANCE_PENDING_STORAGE_KEY);
    if (!raw) return null;
    const value=JSON.parse(raw);
    if (value && typeof value.kind==='string' && Object.hasOwn(paths,value.kind) && value.payload && typeof value.payload==='object' && !Array.isArray(value.payload)) return value as Pending;
  } catch { /* Storage is optional; keep the current tab open if it is unavailable. */ }
  return null;
}
function writePending(value:Pending|null):boolean {
  try {
    if (value) window.sessionStorage.setItem(FINANCE_PENDING_STORAGE_KEY,JSON.stringify(value));
    else window.sessionStorage.removeItem(FINANCE_PENDING_STORAGE_KEY);
    return true;
  } catch { return false; }
}
function pendingSummary(item:Pending):string {
  const value=(key:string)=>typeof item.payload[key]==='string'||typeof item.payload[key]==='number' ? String(item.payload[key]) : '—';
  if(item.kind==='contract')return `قرارداد «${value('title')}» برای ${value('client_name')}، ${value('amount_toman')} تومان`;
  if(item.kind==='manual')return `پرداخت قرارداد #${value('contract_id')}، ${value('amount_toman')} تومان، مرجع ${value('reference')}`;
  if(item.kind==='status')return `تغییر وضعیت قرارداد #${value('contract_id')} به ${statusLabel[value('status')]||value('status')}`;
  if(item.kind==='link')return `ساخت لینک آزمایشی قرارداد #${value('contract_id')}، ${value('amount_toman')} تومان، درگاه ${value('provider')}`;
  if(item.kind==='resolve')return `تعیین تکلیف آزمایشی پرداخت #${value('payment_id')} در قرارداد #${value('contract_id')}`;
  return `ابطال پرداخت #${value('payment_id')} در قرارداد #${value('contract_id')}`;
}
function safeSandboxUrl(value:unknown,token:unknown):string|null {
  if(typeof value!=='string'||typeof token!=='string'||!/^[a-f0-9]{64}$/.test(token))return null;
  try {
    const url=new URL(value);
    if(url.protocol!=='https:'||url.origin!==window.location.origin||url.username||url.password||url.search||url.hash||url.pathname!==`/api/v1/public/advertising-payment/${token}`)return null;
    return url.href;
  } catch {return null;}
}

/** Admin-only ledger UI. A pending snapshot is retained in this tab to allow safe idempotent retry. */
export const AdvertisingFinancePanel:React.FC<{csrfToken:string;banners:AdBanner[];onSchemaUpdated?:()=>void}> = ({csrfToken,banners,onSchemaUpdated})=>{
  const [data,setData]=useState<AdFinanceData|null>(null);
  const [loading,setLoading]=useState(true);
  const [busy,setBusy]=useState(false);
  const [notice,setNotice]=useState<{error:boolean;text:string}|null>(null);
  const [contract,setContract]=useState<ContractForm|null>(null);
  const [paymentContract,setPaymentContract]=useState<AdContract|null>(null);
  const [payment,setPayment]=useState<PaymentForm>(emptyPayment);
  const [gateways,setGateways]=useState<AdGatewayStatus[]>([]);const [gatewaysBlocked,setGatewaysBlocked]=useState(true);
  const [linkContract,setLinkContract]=useState<AdContract|null>(null);
  const [linkForm,setLinkForm]=useState<LinkForm>({amount_toman:'',provider:'',hours:24,confirmed:false});
  const [createdLink,setCreatedLink]=useState<string|null>(null);
  const [resolution,setResolution]=useState<ResolutionForm|null>(null);
  const [pending,setPending]=useState<Pending|null>(readPending);
  const [persisted,setPersisted]=useState(true);
  const [reviewed,setReviewed]=useState(false);
  const busyRef=useRef(false);

  const load=useCallback(async():Promise<boolean>=>{
    setLoading(true);
    try { setData(await fetchAdvertisingFinance()); return true; }
    catch(e) { setData(null);setNotice({error:true,text:e instanceof Error?e.message:'دریافت قراردادها ناموفق بود.'});return false; }
    finally { setLoading(false); }
  },[]);
  useEffect(()=>{void load();},[load]);
  useEffect(()=>{
    if (!pending) return;
    const warn=(e:BeforeUnloadEvent)=>{e.preventDefault();e.returnValue='';};
    window.addEventListener('beforeunload',warn);return ()=>window.removeEventListener('beforeunload',warn);
  },[pending]);
  const clearPending=()=>{writePending(null);setPending(null);setReviewed(false);setPersisted(true);};
  const setup=async()=>{
    if(busyRef.current||pending||!window.confirm('آیا پشتیبان قابل بازیابی دارید و ثبت مالی هنگام به‌روزرسانی متوقف است؟ جدول‌های اختصاصی و قفل یکتایی رسید با بررسی سوابق آماده می‌شوند؛ مایگریشن قدیمی ۰۰۳ اجرا نمی‌شود.'))return;
    busyRef.current=true;setBusy(true);
    try { const r=await updateAdvertisingSchema(csrfToken);setNotice({error:!r.ok,text:r.message||r.error||'عملیات پایان یافت.'});if(r.ok){await load();onSchemaUpdated?.();} }
    finally {busyRef.current=false;setBusy(false);}
  };
  const submit=async(item:Pending, retry=false)=>{
    if(busyRef.current||(!retry&&pending))return;
    busyRef.current=true;setBusy(true);setReviewed(false);
    // Persist before the request, including a copy of the form, so a lost response or refresh can be reconciled.
    setPersisted(writePending(item));setPending(item);
    try {
      const result=await financeMutation(paths[item.kind],item.payload,csrfToken);
      if(result.ok){
        if(item.kind==='link') {
          const link=result.data?.link;
          const url=link&&typeof link==='object'&&'url' in link ? safeSandboxUrl(link.url,item.payload.link_token) : null;
          if(!url){setNotice({error:true,text:'نشانی لینک با دامنه امن همین پنل یکسان نیست یا پاسخ ناقص است. کلید قبلی نگه داشته شد؛ پیکربندی سایت و نتیجه را بررسی کنید.'});await load();return;}
          // Keep the request snapshot until the administrator explicitly saves
          // the capability. If the component unmounts during a request, a later
          // replay can recover the same token rather than orphaning the link.
          setCreatedLink(url);setLinkContract(null);
          setNotice({error:false,text:'لینک آزمایشی آماده است؛ پیش از پایان پیگیری آن را به‌صورت امن نگه دارید.'});await load();return;
        }
        clearPending();setContract(null);setPaymentContract(null);setPayment(emptyPayment());setLinkContract(null);setResolution(null);
        setNotice({error:false,text:result.message||'عملیات ثبت شد.'});await load();
      } else if(result.ambiguous || retry){
        // A later 4xx, such as an expired login, cannot disprove an earlier
        // ambiguous attempt. Retain the original request until it is reviewed.
        setNotice({error:true,text:`${result.error||'نتیجه نامشخص است.'} نتیجه درخواست نخست همچنان نامشخص است؛ تا بررسی آن درخواست جدید ثبت نکنید.`});
      } else {
        clearPending();setNotice({error:true,text:result.error||'عملیات ثبت نشد.'});await load();
      }
    } finally {busyRef.current=false;setBusy(false);}
  };
  const createContract=(e:React.FormEvent)=>{
    e.preventDefault();if(!contract||busyRef.current||pending)return;
    if(!window.confirm('مبلغ و شرایط اولیه بعداً قابل ویرایش نیستند. ایجاد قرارداد، بنر را فعال یا پرداختی ثبت نمی‌کند. ادامه می‌دهید؟'))return;
    try {void submit({kind:'contract',payload:{...contract,request_key:randomRequestKey()}});}
    catch(e){setNotice({error:true,text:e instanceof Error?e.message:'شناسه امن ساخته نشد.'});}
  };
  const recordManual=(e:React.FormEvent)=>{
    e.preventDefault();if(!paymentContract||busyRef.current||pending||!payment.confirmed)return;
    if(!window.confirm('آیا رسید واقعی و مانده قرارداد را مستقل بررسی کرده‌اید؟ این ثبت تأیید بانکی نیست و وجهی جابه‌جا نمی‌کند.'))return;
    try {void submit({kind:'manual',payload:{contract_id:Number(paymentContract.id),...payment,request_key:randomRequestKey()}});}
    catch(e){setNotice({error:true,text:e instanceof Error?e.message:'شناسه امن ساخته نشد.'});}
  };
  const changeStatus=(c:AdContract,status:'active'|'completed'|'cancelled')=>{
    if(busyRef.current||pending)return;
    const reason=window.prompt('دلیل تغییر وضعیت را بنویسید (حداقل ۸ حرف):')?.trim();
    if(!reason)return;if(reason.length<8){setNotice({error:true,text:'دلیل تغییر وضعیت باید حداقل ۸ حرف باشد.'});return;}
    if(!window.confirm(`وضعیت قرارداد به «${statusLabel[status]}» تغییر کند؟ بازگشت وجه خودکار انجام نمی‌شود.`))return;
    void submit({kind:'status',payload:{contract_id:Number(c.id),version:Number(c.version),status,reason,confirmed:true}});
  };
  const voidPayment=(contractId:number,paymentId:number)=>{
    if(busyRef.current||pending)return;
    const reason=window.prompt('دلیل ابطال ثبت دستی را بنویسید (حداقل ۸ حرف):')?.trim();
    if(!reason)return;if(reason.length<8){setNotice({error:true,text:'دلیل ابطال باید حداقل ۸ حرف باشد.'});return;}
    if(!window.confirm('فقط ثبت دفتر ابطال می‌شود؛ هیچ وجهی به‌صورت خودکار برگردانده نخواهد شد. ادامه می‌دهید؟'))return;
    void submit({kind:'void',payload:{contract_id:contractId,payment_id:paymentId,reason,confirmed:true}});
  };
  const createSandboxLink=(e:React.FormEvent)=>{
    e.preventDefault();if(!linkContract||busyRef.current||pending||gatewaysBlocked||!linkForm.confirmed)return;
    if(!gateways.some(p=>p.id===linkForm.provider&&p.mode==='sandbox'&&p.key_configured&&!p.error))return;
    if(!window.confirm('این لینک فقط برای قرارداد ساختگی و آزمون درگاه است، پول واقعی دریافت نمی‌کند و ممکن است تا تعیین تکلیف تغییرات مالی قرارداد را مسدود کند. ادامه می‌دهید؟'))return;
    try {void submit({kind:'link',payload:{contract_id:Number(linkContract.id),amount_toman:linkForm.amount_toman,provider:linkForm.provider,hours:linkForm.hours,version:Number(linkContract.version),request_key:randomRequestKey(),link_token:randomRequestKey(),confirmed:true}});}
    catch(e){setNotice({error:true,text:e instanceof Error?e.message:'شناسه امن ساخته نشد.'});}
  };
  const cancelSandboxLink=(c:AdContract,p:AdOnlinePaymentSummary)=>{
    if(busyRef.current||pending||p.mode!=='sandbox'||p.state!=='link_ready')return;
    const reason=window.prompt('دلیل لغو لینک استفاده‌نشده را بنویسید (حداقل ۱۲ حرف):')?.trim();
    if(!reason)return;if(reason.length<12){setNotice({error:true,text:'دلیل باید حداقل ۱۲ حرف باشد.'});return;}
    if(!window.confirm('فقط لینک آزمایشی شروع‌نشده لغو می‌شود. ادامه می‌دهید؟'))return;
    void submit({kind:'resolve',payload:{payment_id:Number(p.id),contract_id:Number(c.id),resolution:'cancel_link',reason,confirmed:true}});
  };
  const resolveSandbox=(e:React.FormEvent)=>{
    e.preventDefault();const r=resolution;
    if(!r||busyRef.current||pending||r.payment.mode!=='sandbox'||!r.resolution||!r.checked_provider||!r.no_future_charge_confirmed)return;
    if(r.reason.trim().length<12){setNotice({error:true,text:'دلیل بررسی باید حداقل ۱۲ حرف باشد.'});return;}
    if(!window.confirm('آیا نتیجه آزمایشی را مستقل بررسی کرده‌اید؟ این تصمیم فقط اظهار مدیر است و تأیید خودکار درگاه یا بازگشت وجه نیست.'))return;
    void submit({kind:'resolve',payload:{payment_id:Number(r.payment.id),contract_id:Number(r.contract.id),resolution:r.resolution,reason:r.reason.trim(),reference:r.reference.trim(),checked_provider:true,no_future_charge_confirmed:true,confirmed:true}});
  };
  const clearReviewed=()=>{
    if(!reviewed||!pending)return;
    if(window.confirm('فهرست و تاریخچه را بررسی کرده‌اید و می‌دانید نتیجه درخواست چه بوده است؟ پس از پایان پیگیری، ثبت دوباره ممکن است تکراری باشد.')){clearPending();setNotice({error:false,text:'پیگیری این درخواست با تأیید شما بسته شد.'});}
  };

  return <section aria-label="مدیریت مالی تبلیغات" className="rounded-xl border bg-white p-5 space-y-4">
    <div className="flex flex-wrap justify-between gap-3"><div><h2 className="font-bold">قراردادها و ثبت دستی</h2><p className="text-xs text-slate-600 mt-1 leading-6">همه مبلغ‌ها تومان‌اند. ثبت دستی فقط اظهار مدیر است؛ تأیید بانکی و بازگشت وجه خودکار نیست. مبلغ و شرایط اولیه قرارداد ثابت‌اند.</p></div><button type="button" className={button} disabled={loading||busy} onClick={()=>void load()}>تازه‌سازی قراردادها</button></div>
    {notice&&<p role={notice.error?'alert':'status'} className={`rounded-lg p-3 text-sm ${notice.error?'bg-rose-50 text-rose-800':'bg-emerald-50 text-emerald-800'}`}>{notice.text}</p>}
    {createdLink&&<div role="status" className="rounded border border-amber-300 bg-amber-50 p-3 space-y-2 text-sm"><p className="font-bold">لینک صرفاً آزمایشی ساخته شد؛ وجهی دریافت نشده است.</p><label className="block">لینک آزمایشی<input aria-label="لینک آزمایشی" className={`${input} mt-1`} dir="ltr" value={createdLink} readOnly onFocus={e=>e.currentTarget.select()}/></label><p className="text-xs">لینک را محرمانه نگه دارید. تا تأیید شما، درخواست و رمز لینک برای بازیابی در حافظه همین زبانه می‌ماند؛ پس از پایان پیگیری، رمز از سرور قابل بازیابی نیست. وب‌سرور ممکن است مسیر آن را در لاگ ثبت کند.</p>{!persisted&&<p className="font-bold text-xs">حافظه موقت مرورگر در دسترس نیست؛ پیش از خروج، لینک را امن نگه دارید.</p>}<button type="button" className={button} disabled={busy} onClick={()=>{if(window.confirm('لینک را در جای امن نگه داشته‌اید؟ پس از پایان پیگیری امکان بازیابی رمز آن از سرور وجود ندارد.')){clearPending();setCreatedLink(null);}}}>لینک را نگه داشتم؛ پایان پیگیری</button></div>}
    {pending&&!(pending.kind==='link'&&createdLink)&&<div role="alert" className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm space-y-3">
      <p className="font-bold">نتیجه یک درخواست مالی هنوز نیاز به پیگیری دارد.</p>
      <p>درخواست: {pendingSummary(pending)}</p>
      <p>فهرست و تاریخچه را بررسی کنید. فقط موارد اخیر نشان داده می‌شوند؛ نبودن یک مورد در این فهرست به‌تنهایی اثبات ثبت‌نشدن آن نیست. جزئیات درخواست و رمز لینک احتمالی در حافظه همین برگه نگه‌داری می‌شود؛ اطلاعات را روی دستگاه مشترک رها نکنید.</p>
      {!persisted&&<p className="font-bold">ذخیره موقت در مرورگر ممکن نیست؛ این برگه را تا تعیین تکلیف باز نگه دارید.</p>}
      <div className="flex flex-wrap gap-2">
        {(pending.kind==='contract'||pending.kind==='manual'||pending.kind==='link')&&<button type="button" className={button} disabled={busy} onClick={()=>void submit(pending,true)}>تکرار امن همان درخواست</button>}
        <button type="button" className={button} disabled={busy||loading} onClick={()=>void load().then(setReviewed)}>بررسی مجدد فهرست و تاریخچه</button>
        <button type="button" className={button} disabled={busy||!reviewed} onClick={clearReviewed}>پایان پیگیری پس از بررسی</button>
      </div>
      {pending.kind!=='contract'&&pending.kind!=='manual'&&pending.kind!=='link'&&<p>تغییر وضعیت، ابطال و تعیین تکلیف خودکار تکرار نمی‌شوند؛ نتیجه را در فهرست و تاریخچه بررسی کنید.</p>}
    </div>}
    {loading&&!data?<p className="text-sm">در حال دریافت قراردادها…</p>:!data?<p className="text-sm text-rose-700">اطلاعات مالی در دسترس نیست؛ ثبت جدید انجام ندهید.</p>:!data.schema_ready?<div className="rounded-lg bg-amber-50 p-4 text-sm space-y-2"><p>جدول قراردادها هنوز آماده نیست.</p><button type="button" className={primary} disabled={busy||!!pending} onClick={()=>void setup()}>آماده‌سازی قراردادها</button></div>:<>
      {data.online_sandbox_enabled&&<AdvertisingSandboxGateways csrfToken={csrfToken} disabled={busy||!!pending} onProviders={setGateways} onBlocked={setGatewaysBlocked}/>}
      <div className="flex flex-wrap gap-2"><button type="button" className={primary} disabled={busy||!!pending} onClick={()=>{setContract(emptyContract());setPaymentContract(null);setLinkContract(null);setResolution(null);}}>قرارداد جدید</button><span className="text-xs text-slate-500 self-center">حداکثر ۱۰۰ قرارداد و ۵۰۰ پرداخت اخیر نشان داده می‌شود.</span></div>
      {contract&&<form onSubmit={createContract} className="rounded-xl border border-blue-200 bg-blue-50/40 p-4 space-y-3">
        <h3 className="font-bold">قرارداد جدید</h3><div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm">عنوان قرارداد<input required maxLength={150} className={`${input} mt-1`} value={contract.title} disabled={busy||!!pending} onChange={e=>setContract({...contract,title:e.target.value})}/></label>
          <label className="text-sm">طرف قرارداد<input required maxLength={150} className={`${input} mt-1`} value={contract.client_name} disabled={busy||!!pending} onChange={e=>setContract({...contract,client_name:e.target.value})}/></label>
          <label className="text-sm">شماره تماس (اختیاری)<input dir="ltr" maxLength={32} className={`${input} mt-1`} value={contract.client_phone} disabled={busy||!!pending} onChange={e=>setContract({...contract,client_phone:e.target.value})}/></label>
          <label className="text-sm">مبلغ قرارداد (تومان)<input required inputMode="numeric" dir="ltr" className={`${input} mt-1`} value={contract.amount_toman} disabled={busy||!!pending} onChange={e=>setContract({...contract,amount_toman:e.target.value})} placeholder="100000"/></label>
          <label className="text-sm">بنر مرتبط (اختیاری)<select className={`${input} mt-1`} value={contract.banner_id} disabled={busy||!!pending} onChange={e=>setContract({...contract,banner_id:Number(e.target.value)})}><option value={0}>بدون بنر</option>{banners.map(b=><option key={b.id} value={b.id}>{b.title}</option>)}</select></label>
          <div className="text-sm">شروع قرارداد (اختیاری)<PersianDateInput label="شروع قرارداد" value={contract.starts_at} disabled={busy||!!pending} onChange={value=>setContract({...contract,starts_at:value})}/></div>
          <div className="text-sm">پایان قرارداد (اختیاری)<PersianDateInput label="پایان قرارداد" value={contract.ends_at} disabled={busy||!!pending} onChange={value=>setContract({...contract,ends_at:value})}/></div>
          <label className="text-sm sm:col-span-2">توضیحات قرارداد<textarea maxLength={2000} className={`${input} mt-1`} value={contract.notes} disabled={busy||!!pending} onChange={e=>setContract({...contract,notes:e.target.value})}/></label>
        </div><p className="text-xs text-slate-600">مبلغ صحیح و بدون جداکننده یا اعشار وارد شود. تاریخ و بنر اینجا زمان‌بندی یا انتشار بنر را تغییر نمی‌دهند.</p>
        <div className="flex gap-2"><button className={primary} disabled={busy||!!pending}>ثبت قرارداد</button><button type="button" className={button} disabled={busy||!!pending} onClick={()=>setContract(null)}>انصراف</button></div>
      </form>}
      {paymentContract&&<form onSubmit={recordManual} className="rounded-xl border border-amber-300 bg-amber-50/40 p-4 space-y-3">
        <h3 className="font-bold">ثبت دستی برای «{paymentContract.title}»</h3><p className="text-xs">مانده هنگام نمایش: {money(paymentContract.remaining_toman)}. پیش از ثبت، رسید را مستقل بررسی کنید.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm">مبلغ پرداخت (تومان)<input required inputMode="numeric" dir="ltr" className={`${input} mt-1`} value={payment.amount_toman} disabled={busy||!!pending} onChange={e=>setPayment({...payment,amount_toman:e.target.value})}/></label>
          <label className="text-sm">روش پرداخت<select className={`${input} mt-1`} value={payment.method} disabled={busy||!!pending} onChange={e=>setPayment({...payment,method:e.target.value as PaymentForm['method']})}>{Object.entries(methodLabel).map(([key,label])=><option key={key} value={key}>{label}</option>)}</select></label>
          <label className="text-sm">مرجع یا شماره رسید<input required minLength={3} maxLength={120} className={`${input} mt-1`} value={payment.reference} disabled={busy||!!pending} onChange={e=>setPayment({...payment,reference:e.target.value})}/></label>
          <label className="text-sm">توضیح (اختیاری)<input maxLength={1000} className={`${input} mt-1`} value={payment.notes} disabled={busy||!!pending} onChange={e=>setPayment({...payment,notes:e.target.value})}/></label>
        </div><label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={payment.confirmed} disabled={busy||!!pending} onChange={e=>setPayment({...payment,confirmed:e.target.checked})}/><span>رسید را بررسی کرده‌ام و می‌دانم ثبت دستی تأیید بانک نیست.</span></label>
        <div className="flex gap-2"><button className={primary} disabled={busy||!!pending||!payment.confirmed}>ثبت پرداخت دستی</button><button type="button" className={button} disabled={busy||!!pending} onClick={()=>setPaymentContract(null)}>انصراف</button></div>
      </form>}
      {linkContract&&data.online_sandbox_enabled&&<form onSubmit={createSandboxLink} className="rounded-xl border border-amber-300 bg-amber-50/40 p-4 space-y-3">
        <h3 className="font-bold">لینک آزمایشی برای «{linkContract.title}»</h3><p className="text-xs leading-6">فقط برای قرارداد ساختگی؛ مانده واقعی پس از آزمون تغییر نمی‌کند. تا مشخص‌شدن نتیجه، تغییرات مالی قرارداد مسدود می‌شوند.</p>
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm">مبلغ آزمایشی (تومان)<input required inputMode="numeric" dir="ltr" className={`${input} mt-1`} value={linkForm.amount_toman} disabled={busy||!!pending} onChange={e=>setLinkForm({...linkForm,amount_toman:e.target.value})}/></label>
          <label className="text-sm">درگاه آزمایشی<select required className={`${input} mt-1`} value={linkForm.provider} disabled={busy||!!pending||gatewaysBlocked} onChange={e=>setLinkForm({...linkForm,provider:e.target.value})}><option value="">انتخاب درگاه</option>{gateways.filter(g=>g.mode==='sandbox'&&g.key_configured&&!g.error).map(g=><option key={g.id} value={g.id}>{g.label}</option>)}</select></label>
          <label className="text-sm">مدت اعتبار لینک (ساعت، ۱ تا ۷۲)<input required type="number" min={1} max={72} step={1} className={`${input} mt-1`} value={linkForm.hours} disabled={busy||!!pending} onChange={e=>setLinkForm({...linkForm,hours:Number(e.target.value)})}/></label>
        </div><label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={linkForm.confirmed} disabled={busy||!!pending} onChange={e=>setLinkForm({...linkForm,confirmed:e.target.checked})}/><span>این قرارداد ساختگی است و می‌دانم لینک برای دریافت وجه واقعی نیست.</span></label>
        <div className="flex gap-2"><button className={primary} disabled={busy||!!pending||gatewaysBlocked||!linkForm.confirmed}>ساخت لینک آزمایشی</button><button type="button" className={button} disabled={busy||!!pending} onClick={()=>setLinkContract(null)}>انصراف</button></div>
      </form>}
      {resolution&&data.online_sandbox_enabled&&<form onSubmit={resolveSandbox} className="rounded-xl border border-amber-300 bg-amber-50/40 p-4 space-y-3">
        <h3 className="font-bold">بررسی تراکنش آزمایشی #{resolution.payment.id}</h3><p className="text-xs leading-6">ابتدا مستقل در پنل سندباکس بررسی کنید؛ وضعیت در حال اجرا تا پنج دقیقه قابل تصمیم نیست. این تصمیم اظهار مدیر است و مانده واقعی را تغییر نمی‌دهد.</p>
        <div className="grid gap-3 sm:grid-cols-2"><label className="text-sm">نتیجه بررسی<select required className={`${input} mt-1`} value={resolution.resolution} disabled={busy||!!pending} onChange={e=>setResolution({...resolution,resolution:e.target.value as ResolutionForm['resolution']})}><option value="">انتخاب کنید</option><option value="not_paid">پرداختی رخ نداده است</option><option value="paid">پرداخت آزمایشی رخ داده است</option></select></label>
          {resolution.resolution==='paid'&&<label className="text-sm">مرجع مستقل آزمایشی<input required minLength={3} maxLength={120} dir="ltr" className={`${input} mt-1`} value={resolution.reference} disabled={busy||!!pending} onChange={e=>setResolution({...resolution,reference:e.target.value})}/></label>}
          <label className="text-sm sm:col-span-2">دلیل بررسی (حداقل ۱۲ حرف)<textarea required minLength={12} maxLength={1000} className={`${input} mt-1`} value={resolution.reason} disabled={busy||!!pending} onChange={e=>setResolution({...resolution,reason:e.target.value})}/></label></div>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={resolution.checked_provider} disabled={busy||!!pending} onChange={e=>setResolution({...resolution,checked_provider:e.target.checked})}/><span>وضعیت را مستقل در درگاه آزمایشی بررسی کرده‌ام.</span></label>
        <label className="flex items-start gap-2 text-sm"><input type="checkbox" checked={resolution.no_future_charge_confirmed} disabled={busy||!!pending} onChange={e=>setResolution({...resolution,no_future_charge_confirmed:e.target.checked})}/><span>نبود امکان پرداخت بعدی با این تراکنش را تأیید می‌کنم.</span></label>
        <div className="flex gap-2"><button className={primary} disabled={busy||!!pending||!resolution.resolution||!resolution.checked_provider||!resolution.no_future_charge_confirmed}>ثبت نتیجه بررسی آزمایشی</button><button type="button" className={button} disabled={busy||!!pending} onClick={()=>setResolution(null)}>انصراف</button></div>
      </form>}
      {data.contracts.length===0?<p className="text-sm text-slate-500">هنوز قراردادی ثبت نشده است.</p>:<div className="space-y-3">{data.contracts.map(c=>{
        const payments=data.manual_payments.filter(p=>String(p.contract_id)===String(c.id));
        const history=data.status_log.filter(x=>String(x.contract_id)===String(c.id));
        const online=data.online_payments.filter(x=>String(x.contract_id)===String(c.id));
        const unresolved=online.some(p=>['link_ready','creating','pending','verifying','uncertain'].includes(p.state));
        return <article key={String(c.id)} className="rounded-lg border border-slate-200 p-4 space-y-3" aria-label={`قرارداد ${c.title}`}>
          <div className="flex flex-wrap justify-between gap-2"><div><h3 className="font-bold">{c.title}</h3><p className="text-xs text-slate-600 mt-1">قرارداد #{c.id} · {c.client_name}{c.client_phone?` · ${c.client_phone}`:''} · {statusLabel[c.status]||c.status}{c.banner_title?` · بنر: ${c.banner_title}`:''}</p></div><p className="text-sm">مبلغ: <strong>{money(c.amount_toman)}</strong> · ثبت‌شده: {money(c.paid_toman)} · مانده: <strong>{money(c.remaining_toman)}</strong></p></div>
          {(c.starts_at||c.ends_at)&&<p className="text-xs text-slate-600">بازه قرارداد: {c.starts_at||'بدون شروع'} تا {c.ends_at||'بدون پایان'} (میلادی؛ مستقل از زمان‌بندی بنر)</p>}
          {c.notes&&<p className="text-xs whitespace-pre-wrap">شرایط و توضیحات: {c.notes}</p>}
          <p className="text-xs text-slate-500">ثبت قرارداد: {c.created_at}{c.created_by&&` · مدیر #${c.created_by}`}</p>
          {unresolved&&<p className="rounded bg-amber-50 p-2 text-xs">تراکنش آنلاین تعیین‌تکلیف‌نشده وجود دارد؛ تغییرات مالی تا بررسی مسدود است.</p>}
          <div className="flex flex-wrap gap-2">
            <button type="button" className={button} disabled={busy||!!pending||unresolved||c.status!=='active'||Number(c.remaining_toman)<=0} onClick={()=>{setPaymentContract(c);setPayment(emptyPayment());setContract(null);setLinkContract(null);setResolution(null);}}>پرداخت دستی</button>
            {data.online_sandbox_enabled&&<button type="button" className={button} disabled={busy||!!pending||unresolved||gatewaysBlocked||c.status!=='active'||Number(c.remaining_toman)<=0||!gateways.some(g=>g.mode==='sandbox'&&g.key_configured&&!g.error)} onClick={()=>{setLinkContract(c);setLinkForm({amount_toman:'',provider:'',hours:24,confirmed:false});setContract(null);setPaymentContract(null);setResolution(null);}}>لینک آزمایشی</button>}
            {c.status==='active'?<><button type="button" className={button} disabled={busy||!!pending||unresolved||Number(c.remaining_toman)>0} onClick={()=>changeStatus(c,'completed')}>تکمیل قرارداد</button><button type="button" className={button} disabled={busy||!!pending||unresolved} onClick={()=>changeStatus(c,'cancelled')}>لغو قرارداد</button></>:<button type="button" className={button} disabled={busy||!!pending||unresolved} onClick={()=>changeStatus(c,'active')}>بازگشایی قرارداد</button>}
          </div>
          <details><summary className="cursor-pointer text-sm font-semibold">پرداخت‌ها و تاریخچه</summary><div className="space-y-2 mt-2 text-xs">
            {payments.length===0?<p>پرداخت دستی ثبت نشده است.</p>:payments.map(p=><div key={String(p.id)} className="rounded bg-slate-50 p-2"><p>{money(p.amount_toman)} · {methodLabel[p.method]||p.method} · مرجع: <span dir="ltr">{p.reference}</span> · {p.status==='recorded'?'ثبت‌شده':'ابطال‌شده'} · {p.recorded_at}{p.recorded_by&&` · مدیر ثبت #${p.recorded_by}`}</p>{p.notes&&<p className="whitespace-pre-wrap">توضیح پرداخت: {p.notes}</p>}{p.void_reason&&<p>دلیل ابطال: {p.void_reason} · {p.voided_at}{p.voided_by&&` · مدیر ابطال #${p.voided_by}`}</p>}{p.status==='recorded'&&<button type="button" className={`${button} mt-2`} disabled={busy||!!pending||unresolved||c.status==='completed'} onClick={()=>voidPayment(Number(c.id),Number(p.id))}>ابطال ثبت دستی</button>}</div>)}
            {online.map(p=><div key={String(p.id)} className="rounded bg-slate-50 p-2 space-y-1"><p>تراکنش #{p.id} {p.mode==='sandbox'?'آزمایشی':'آنلاین'} · {p.provider} · {money(p.amount_toman)} · وضعیت: {onlineState[p.state]||p.state} · شناسه: <span dir="ltr">{p.order_id}</span>{p.verified_reference&&` · مرجع: ${p.verified_reference}`}{p.last_error_code&&` · کد بررسی: ${p.last_error_code}`}</p>
              {data.online_sandbox_enabled&&p.mode==='sandbox'&&p.state==='link_ready'&&<button type="button" className={button} disabled={busy||!!pending} onClick={()=>cancelSandboxLink(c,p)}>لغو لینک آزمایشی شروع‌نشده</button>}
              {data.online_sandbox_enabled&&p.mode==='sandbox'&&['creating','pending','verifying','uncertain'].includes(p.state)&&<button type="button" className={button} disabled={busy||!!pending} onClick={()=>{setResolution({contract:c,payment:p,resolution:'',reason:'',reference:'',checked_provider:false,no_future_charge_confirmed:false});setContract(null);setPaymentContract(null);setLinkContract(null);}}>بررسی تراکنش آزمایشی</button>}
              {(data.online_audit||[]).filter(a=>String(a.payment_id)===String(p.id)).map(a=><p key={String(a.id)} className="text-slate-600">{onlineState[a.old_state]||a.old_state} ← {onlineState[a.new_state]||a.new_state} · {a.reason} · {a.changed_at} · {a.changed_by?`مدیر #${a.changed_by}`:'سیستم'}{a.reference&&` · مرجع ${a.reference}`}</p>)}
            </div>)}
            {history.map(h=><p key={String(h.id)} className="rounded bg-slate-50 p-2">{statusLabel[h.old_status]||h.old_status} ← {statusLabel[h.new_status]||h.new_status} · {h.reason} · {h.changed_at}{h.changed_by&&` · مدیر #${h.changed_by}`}</p>)}
          </div></details>
        </article>;
      })}</div>}
      <p className="text-xs text-slate-500 leading-6">مرجع رسید پس از ابطال نیز برای ثبت جدید قابل استفاده نیست. برای اصلاح رقم یا شرایط، طبق روال حسابداری قرارداد جداگانه بسازید؛ حذف بنر قرارداد را پاک نمی‌کند. پرداخت آنلاین واقعی هنوز در این نسخه فعال نشده است.</p>
    </>}
  </section>;
};
