/**
 * S17: استخراج Lat/Lng از هر ورودی متنی گوگل‌مپ (Paste سریع در فرم ادمین)
 *
 * الگوهای پشتیبانی‌شده (به ترتیب اولویت):
 *  1) لینک گوگل‌مپ با !3dLAT!4dLNG  →  https://www.google.com/maps/.../!3d37.2761!4d49.5886...
 *  2) لینک گوگل‌مپ با @LAT,LNG      →  https://www.google.com/maps/@37.2761,49.5886,17z
 *  3) پارامتر q= / ll= / query=     →  https://maps.google.com/?q=37.2761,49.5886 (با %2C هم کار می‌کند)
 *  4) مختصات خام یک‌خطی             →  37.2761, 49.5886  |  37.2761 49.5886  |  37.2761؛49.5886
 *
 * ارقام فارسی/عربی به‌طور خودکار به انگلیسی تبدیل می‌شوند و نقطه/ویرگول فارسی هم پذیرفته است.
 * خروجی فقط وقتی معتبر است که |lat| ≤ 90 و |lng| ≤ 180 باشد؛ در غیر این صورت null برمی‌گردد
 * تا فرم ادمین به کاربر خطای صریح بدهد، نه اینکه بی‌سر‌و‌صدا مقدار اشتباه بنویسد.
 */
export interface ParsedCoords {
  lat: number;
  lng: number;
}

const FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
const AR_DIGITS = '٠١٢٣٤٥٦٧٨٩';

export function normalizeDigits(raw: string): string {
  let out = '';
  for (const ch of raw) {
    const fa = FA_DIGITS.indexOf(ch);
    if (fa >= 0) {
      out += String(fa);
      continue;
    }
    const ar = AR_DIGITS.indexOf(ch);
    if (ar >= 0) {
      out += String(ar);
      continue;
    }
    out += ch;
  }
  return out;
}

export function parseCoordsFromPaste(raw: string): ParsedCoords | null {
  if (!raw) return null;
  let text = normalizeDigits(String(raw))
    .replace(/[٫،؛]/g, ',') // ویرگول/ممیز فارسی
    .trim();
  if (!text) return null;
  try {
    // لینک‌های گوگل مقادیر را URL-encode می‌کنند (%2C)؛ decode فقط وقتی معتبر است
    if (/%/.test(text)) text = decodeURIComponent(text);
  } catch {
    /* رشته با % خراب → همان نسخهٔ خام استفاده می‌شود */
  }

  const num = '(-?\\d+(?:\\.\\d+)?)';
  const patterns: RegExp[] = [
    new RegExp(`!3d${num}\\s*,?\\s*!4d${num}`, 'i'),
    new RegExp(`@${num}\\s*,\\s*${num}`),
    new RegExp(`[?&](?:q|ll|query|destination)=${num}\\s*(?:%2C|,)\\s*${num}`, 'i'),
    new RegExp(`^${num}\\s*(?:,|;|\\s)\\s*${num}$`),
  ];
  for (const re of patterns) {
    const m = text.match(re);
    if (!m) continue;
    const lat = Number(m[1]);
    const lng = Number(m[2]);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
    if (Math.abs(lat) > 90 || Math.abs(lng) > 180 || (lat === 0 && lng === 0)) continue;
    return { lat, lng };
  }
  return null;
}
