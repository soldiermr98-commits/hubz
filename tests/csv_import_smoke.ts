// Synthetic fixtures only; no production database, uploads, or network.
import assert from 'node:assert/strict';
import { parseBusinessCsv, csvNeedsRunTime, supplyCsvRunTime } from '../src/services/csvImport';

const standard='\uFEFFsource,source_id,source_url,name,province,city,category,address,lat,lng,phones,rating,reviews_count,fetched_at,reviews_complete,reviews\n'+
  'google_maps,fixture-id,https://example.test/map,"آزمایشی، یک",,رشت,کافه,"نشانی, طبقه دوم",۳۷٫۲,۴۹٫۶,"+989120000001;+989120000002",۴٫۵,۱۲,2026-10-01T08:00:00Z,false,[]\n';
const [a]=parseBusinessCsv(standard);
assert.equal(a.name,'آزمایشی، یک');assert.equal(a.address,'نشانی, طبقه دوم');
assert.equal(a.lat,'37.2');assert.equal(a.lng,'49.6');assert.equal(a.rating,'4.5');assert.equal(a.reviews_count,'12');
assert.deepEqual(a.phones,['+989120000001','+989120000002']);assert.equal(a.reviews_complete,false);assert.deepEqual(a.reviews,[]);
assert.equal(a.fetched_at,'2026-10-01T08:00:00Z');assert.equal(csvNeedsRunTime(a),false);
const persian='نام کسب‌وکار,شهر,دسته‌بندی,منبع,امتیاز در منبع,تعداد رأی در منبع,تلفن,آدرس,عرض جغرافیایی (lat),طول جغرافیایی (lng),زمان استخراج (شمسی),لینک نقشه,شناسه مکان (source_id)\n'+
 'آزمایشی,رشت,کافه,گوگل مپ,۴٫۲,۳۰,+989120000003,"نشانی\nساختگی",۳۷٫۲,۴۹٫۶,۱۴۰۵/۰۸/۱۵ - ۱۳:۴۰,https://example.test/map,0x123:0x456\n';
const [b]=parseBusinessCsv(persian);
assert.equal(b.source,'google_maps');assert.equal(b.source_id,'0x123:0x456');assert.equal(b.address,'نشانی\nساختگی');
assert.equal(b.csv_recorded_time_jalali,'۱۴۰۵/۰۸/۱۵ - ۱۳:۴۰');assert.equal(b.fetched_at,'');assert(csvNeedsRunTime(b));
const filled=supplyCsvRunTime(b,'2026-11-06T10:00:00Z');
assert.equal(filled.fetched_at,'2026-11-06T10:00:00Z');assert.equal(filled.observation_time_origin,'user_supplied_csv_run_started_at');
assert.equal(filled.csv_recorded_time_jalali,b.csv_recorded_time_jalali);assert(!('__csv_needs_time' in filled));
assert.equal(b.fetched_at,'','source object remains unchanged');
assert.throws(()=>parseBusinessCsv('name,city\nA,B'),/سرستون/);
assert.throws(()=>parseBusinessCsv('source,source_id,name,city,category,source\na,b,c,d,e,f'),/بیش از یک بار/);
assert.throws(()=>parseBusinessCsv('source,source_id,name,city,category\na,b,c,d'),/تعداد ستون/);
assert.throws(()=>parseBusinessCsv('source,source_id,name,city,category,reviews_complete\na,b,c,d,e,perhaps'),/reviews_complete/);
assert.throws(()=>parseBusinessCsv('source,source_id,name,city,category,reviews\na,b,c,d,e,not-json'),/reviews/);
assert.throws(()=>parseBusinessCsv('source,source_id,name,city,category\n'+Array.from({length:501},(_,i)=>`x,${i},n,c,k`).join('\n')),/۵۰۰/);
const [unrecognized]=parseBusinessCsv('source,source_id,name,city,category\nunknown source,abc,n,c,k');
assert.equal(unrecognized.source,'unknown source','unknown source is not guessed');
console.log('PASS: CSV conversion, quoting, Persian columns/digits, source time, and fail-closed validation.');
