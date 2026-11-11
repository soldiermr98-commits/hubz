// تست اجرایی پارسر Paste سریع S17 — با tsx اجرا می‌شود (npx tsx tests/s17_coords_parse_smoke.tsx)
import assert from 'node:assert/strict';
import { parseCoordsFromPaste } from '../src/services/coordsParse';

// مختصات خام
assert.deepEqual(parseCoordsFromPaste('37.2761, 49.5886'), { lat: 37.2761, lng: 49.5886 }, 'مختصات خام با ویرگول و فاصله');
assert.deepEqual(parseCoordsFromPaste('37.2761 49.5886'), { lat: 37.2761, lng: 49.5886 }, 'مختصات با فاصله');
assert.deepEqual(parseCoordsFromPaste('37.2761;49.5886'), { lat: 37.2761, lng: 49.5886 }, 'مختصات با سمی‌کالن');

// ارقام فارسی/عربی و ویرگول فارسی
assert.deepEqual(parseCoordsFromPaste('۳۷.۲۷۶۱ ، ۴۹.۵۸۸۶'), { lat: 37.2761, lng: 49.5886 }, 'ارقام فارسی با ممیز فارسی');
assert.deepEqual(parseCoordsFromPaste('٣٦.٥٤٨٧,٥٢.٤٥٦٧'), { lat: 36.5487, lng: 52.4567 }, 'ارقام عربی');

// لینک‌های گوگل‌مپ
assert.deepEqual(
  parseCoordsFromPaste('https://www.google.com/maps/place/x/@37.2761,49.5886,17z/data=...'),
  { lat: 37.2761, lng: 49.5886 },
  'لینک گوگل‌مپ با @lat,lng'
);
assert.deepEqual(
  parseCoordsFromPaste('https://www.google.com/maps/place/x/!3d37.2761!4d49.5886?hl=fa'),
  { lat: 37.2761, lng: 49.5886 },
  'لینک گوگل‌مپ با !3d/!4d (اولویت بالاتر از @)'
);
assert.deepEqual(
  parseCoordsFromPaste('https://maps.google.com/?q=37.2761%2C49.5886'),
  { lat: 37.2761, lng: 49.5886 },
  'لینک قدیمی با q= و %2C'
);
assert.deepEqual(
  parseCoordsFromPaste('https://www.google.com/maps/search/?api=1&query=37.2761,49.5886'),
  { lat: 37.2761, lng: 49.5886 },
  'لینک search با query='
);

// اولویت: !3d/!4d باید مقدم بر @ باشد (در لینک‌های ترکیبی)
assert.deepEqual(
  parseCoordsFromPaste('https://www.google.com/maps/@36.1,49.9,15z/!3d37.2761!4d49.5886'),
  { lat: 37.2761, lng: 49.5886 },
  'در لینک ترکیبی، !3d/!4d مقدم است'
);

// ورودی نامعتبر → null (فرم ادمین باید خطای صریح بدهد، نه مقدار اشتباه)
assert.equal(parseCoordsFromPaste('https://www.google.com/search?q=کافه+لنگرود'), null, 'لینک بدون مختصات');
assert.equal(parseCoordsFromPaste('37.2761'), null, 'عدد تکی');
assert.equal(parseCoordsFromPaste(''), null, 'رشتهٔ خالی');
assert.equal(parseCoordsFromPaste('120, 49.5886'), null, 'lat خارج محدوده');
assert.equal(parseCoordsFromPaste('37.2761, 999'), null, 'lng خارج محدوده');
assert.equal(parseCoordsFromPaste('0, 0'), null, 'مختصات صفر مطلق بی‌معنا');
assert.equal(parseCoordsFromPaste('-91, 49.5886'), null, 'lat منفی خارج محدوده');

// منفی‌های معتبر
assert.deepEqual(parseCoordsFromPaste('-33.865143, 151.209900'), { lat: -33.865143, lng: 151.2099 }, 'مختصات نیمکرهٔ جنوبی');

console.log('همهٔ تست‌های پارسر Paste سریع با موفقیت گذشت ✅');
