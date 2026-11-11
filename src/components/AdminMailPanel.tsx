import React, {useCallback, useEffect, useState} from 'react';
import {AdminMailSettings, fetchAdminMail, saveAdminMail, testAdminMail} from '../services/adminApi';

const field='mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm';
export function AdminMailPanel({csrfToken}:{csrfToken:string}) {
  const [settings,setSettings]=useState<AdminMailSettings|null>(null);
  const [password,setPassword]=useState('');
  const [clear,setClear]=useState(false);
  const [busy,setBusy]=useState(false);
  const [notice,setNotice]=useState<{error:boolean;text:string}|null>(null);
  const load=useCallback(async()=>{try {const r=await fetchAdminMail();setSettings(r.settings);} catch(e) {setNotice({error:true,text:e instanceof Error?e.message:'دریافت تنظیمات ناموفق بود.'});}},[]);
  useEffect(()=>{void load();},[load]);
  const update=(patch:Partial<AdminMailSettings>)=>setSettings(s=>s?{...s,...patch}:s);
  const save=async(e:React.FormEvent)=>{e.preventDefault();if(!settings)return;setBusy(true);const r=await saveAdminMail({...settings,password,clear_password:clear},csrfToken);setBusy(false);setNotice({error:!r.ok,text:r.message||r.error});if(r.ok){setPassword('');setClear(false);await load();}};
  const test=async()=>{setBusy(true);const r=await testAdminMail(csrfToken);setBusy(false);setNotice({error:!r.ok,text:r.message||r.error});};
  return <section className="rounded-xl border border-slate-200 bg-white p-6 space-y-4" aria-label="تنظیمات ایمیل مدیر">
    <div><h2 className="font-bold">اعلان ایمیلی مدیر</h2><p className="mt-2 text-sm leading-6 text-slate-600">در حال حاضر برای درخواست جدید تبلیغات و گزارش خطای کاربران اعلان ارسال می‌شود. ثبت درخواست مستقل از موفقیت ایمیل است و به کرون نیاز ندارد. اطلاعات تماس در ایمیل قرار نمی‌گیرد.</p></div>
    {notice&&<p role={notice.error?'alert':'status'} className={`rounded-lg p-3 text-sm ${notice.error?'bg-rose-50 text-rose-800':'bg-emerald-50 text-emerald-800'}`}>{notice.text}</p>}
    {!settings?<button type="button" onClick={()=>void load()} className="rounded border px-3 py-2 text-sm">دریافت دوباره تنظیمات</button>:<form onSubmit={e=>void save(e)} className="space-y-4">
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={settings.enabled} onChange={e=>update({enabled:e.target.checked})}/> ارسال اعلان‌ها فعال باشد</label>
      <div className="grid gap-3 md:grid-cols-2">
        <label className="text-sm">ایمیل مدیر (گیرنده)<input type="email" dir="ltr" maxLength={254} className={field} value={settings.recipient} onChange={e=>update({recipient:e.target.value})}/></label>
        <label className="text-sm">ایمیل فرستنده<input type="email" dir="ltr" maxLength={254} className={field} value={settings.from} onChange={e=>update({from:e.target.value})}/></label>
        <label className="text-sm">روش ارسال<select className={field} value={settings.method} onChange={e=>update({method:e.target.value as 'php'|'smtp'})}><option value="php">ایمیل PHP هاست</option><option value="smtp">SMTP</option></select></label>
        {settings.method==='smtp'&&<><label className="text-sm">سرور SMTP<input dir="ltr" autoComplete="off" className={field} value={settings.host} onChange={e=>update({host:e.target.value})}/></label><label className="text-sm">پورت<input dir="ltr" type="number" min="1" max="65535" className={field} value={settings.port} onChange={e=>update({port:Number(e.target.value)})}/></label><label className="text-sm">امنیت اتصال<select className={field} value={settings.security} onChange={e=>update({security:e.target.value as 'tls'|'ssl'})}><option value="tls">STARTTLS</option><option value="ssl">TLS مستقیم (SSL)</option></select></label><label className="text-sm">نام کاربری SMTP<input dir="ltr" autoComplete="off" className={field} value={settings.username} onChange={e=>update({username:e.target.value})}/></label><label className="text-sm">رمز SMTP <span className="text-slate-500">{settings.password_configured?'(خالی بگذارید تا رمز قبلی حفظ شود)':''}</span><input type="password" dir="ltr" autoComplete="new-password" disabled={clear} className={field} value={password} onChange={e=>setPassword(e.target.value)}/></label>{settings.password_configured&&<label className="flex gap-2 items-center text-sm"><input type="checkbox" checked={clear} onChange={e=>{setClear(e.target.checked);if(e.target.checked)setPassword('');}}/> حذف رمز ذخیره‌شده</label>}</>}
      </div>
      <p className="text-xs leading-6 text-slate-600">تحویل واقعی به تنظیمات هاست، SPF/DKIM و سرویس گیرنده بستگی دارد. پشتیبان پوشه خصوصی storage و کلید رمزگذاری را نگه دارید.</p>
      <div className="flex flex-wrap gap-2"><button disabled={busy} className="rounded-lg bg-blue-700 px-4 py-2 text-sm text-white disabled:opacity-50">ذخیره تنظیمات</button><button type="button" disabled={busy} onClick={()=>void test()} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-50">ارسال ایمیل آزمایشی</button></div>
      <p className="text-xs text-slate-500">تست از آخرین تنظیمات ذخیره‌شده استفاده می‌کند. تحویل به سرویس ارسال به معنی رسیدن قطعی به صندوق نیست.</p>
    </form>}
  </section>;
}
