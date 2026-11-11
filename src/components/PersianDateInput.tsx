import React, { useId, useState } from 'react';
import { isValidJalaaliDate, jalaaliMonthLength, toGregorian, toJalaali } from 'jalaali-js';

const months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
const fa = (n: number) => n.toLocaleString('fa-IR', {useGrouping: false});
const pad = (n: number) => String(n).padStart(2, '0');

/** Displays Jalali dates but preserves the Gregorian wall-clock format expected by the API. */
export function PersianDateInput({value, onChange, disabled, withTime = false, label}: {
  value: string; onChange: (value: string) => void; disabled?: boolean; withTime?: boolean; label: string;
}) {
  const id = useId();
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}))?/.exec(value);
  const date = match ? toJalaali(Number(match[1]), Number(match[2]), Number(match[3])) : null;
  const today = toJalaali(new Date());
  const [open, setOpen] = useState(false);
  const [view, setView] = useState(() => ({year: date?.jy ?? today.jy, month: date?.jm ?? today.jm}));
  const choose = (day: number) => {
    if (!isValidJalaaliDate(view.year, view.month, day)) return;
    const g = toGregorian(view.year, view.month, day);
    onChange(`${g.gy}-${pad(g.gm)}-${pad(g.gd)}${withTime ? `T${match?.[4] ?? '00'}:${match?.[5] ?? '00'}` : ''}`);
    setOpen(false);
  };
  const changeMonth = (delta: number) => setView(v => {
    const m = v.month + delta;
    return m < 1 ? {year: v.year - 1, month: 12} : m > 12 ? {year: v.year + 1, month: 1} : {...v, month: m};
  });
  const first = toGregorian(view.year, view.month, 1);
  const offset = (new Date(first.gy, first.gm - 1, first.gd).getDay() + 1) % 7;
  return <div className="relative mt-1 space-y-2">
    <div className="flex flex-wrap items-center gap-2">
      <button id={id} type="button" disabled={disabled} aria-label={label} aria-expanded={open} onClick={() => {if (!open && date) setView({year:date.jy,month:date.jm}); setOpen(!open);}} className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm hover:bg-slate-50 disabled:opacity-50">
        {date ? `${fa(date.jy)}/${fa(date.jm)}/${fa(date.jd)}` : 'انتخاب تاریخ شمسی'}
      </button>
      {value && <button type="button" disabled={disabled} onClick={() => onChange('')} className="text-sm text-rose-700 underline">پاک کردن تاریخ</button>}
      {withTime && date && <label className="text-sm">ساعت <input type="time" aria-label={`${label}، ساعت`} disabled={disabled} className="rounded-lg border border-slate-300 px-2 py-1" value={`${match?.[4] ?? '00'}:${match?.[5] ?? '00'}`} onChange={e => {if (e.target.value) onChange(`${value.slice(0,10)}T${e.target.value}`);}} /></label>}
    </div>
    {open && !disabled && <div role="group" aria-label={`تقویم ${label}`} className="absolute z-30 top-full right-0 mt-1 w-72 rounded-xl border border-slate-200 bg-white p-3 shadow-xl">
      <div className="flex items-center justify-between mb-3"><button type="button" aria-label="ماه قبل" onClick={() => changeMonth(-1)} className="rounded px-3 py-1 hover:bg-slate-100">‹</button><strong>{months[view.month-1]} {fa(view.year)}</strong><button type="button" aria-label="ماه بعد" onClick={() => changeMonth(1)} className="rounded px-3 py-1 hover:bg-slate-100">›</button></div>
      <div className="grid grid-cols-7 text-center text-xs text-slate-500 mb-1">{['ش','ی','د','س','چ','پ','ج'].map((d,i)=><span key={i}>{d}</span>)}</div>
      <div className="grid grid-cols-7 text-center">{Array.from({length:offset},(_,i)=><span key={`empty${i}`}/>)}{Array.from({length:jalaaliMonthLength(view.year,view.month)},(_,i)=><button key={i} type="button" aria-label={`${fa(i+1)} ${months[view.month-1]} ${fa(view.year)}`} onClick={()=>choose(i+1)} className={`rounded-full p-1.5 text-sm hover:bg-blue-100 ${date?.jy===view.year && date.jm===view.month && date.jd===i+1 ? 'bg-blue-700 text-white' : ''}`}>{fa(i+1)}</button>)}</div>
      <button type="button" onClick={()=>setOpen(false)} className="mt-2 text-sm text-slate-600 underline">بستن تقویم</button>
    </div>}
  </div>;
}
