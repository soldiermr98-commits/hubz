import Papa from 'papaparse';

// CSV is only converted locally. The existing authenticated preview/apply API
// remains authoritative and validates each row before anything is saved.
const MAX_ROWS = 500;
const normalizeHeader = (value: string) => value.replace(/^\uFEFF/, '').normalize('NFKC')
  .replace(/[\u200c\u200d\u0640]/g, '').replace(/ك/g, 'ک').replace(/ي/g, 'ی').trim().toLowerCase();
const digits = (value: string) => value.replace(/[۰-۹]/g, c => String(c.charCodeAt(0) - 0x6f0))
  .replace(/[٠-٩]/g, c => String(c.charCodeAt(0) - 0x660));

const aliases: Record<string, string[]> = {
  source: ['source', 'منبع'], source_id: ['source_id', 'شناسه مکان (source_id)'],
  source_url: ['source_url', 'لینک نقشه'], name: ['name', 'نام کسب‌وکار', 'نام کسب و کار'],
  province: ['province', 'استان'], city: ['city', 'شهر'], category: ['category', 'دسته‌بندی', 'دسته بندی'],
  address: ['address', 'آدرس', 'نشانی'], lat: ['lat', 'عرض جغرافیایی (lat)'],
  lng: ['lng', 'طول جغرافیایی (lng)'], phones: ['phones', 'phone', 'تلفن'],
  rating: ['rating', 'امتیاز در منبع'], reviews_count: ['reviews_count', 'تعداد رأی در منبع'],
  fetched_at: ['fetched_at'], reviews_complete: ['reviews_complete'], reviews: ['reviews'],
  jalali_time: ['زمان استخراج (شمسی)'],
};
const aliasLookup = new Map(Object.entries(aliases).flatMap(([target, names]) => names.map(name => [normalizeHeader(name), target] as const)));
const required = ['source', 'source_id', 'name', 'city', 'category'];

function parseArray(value: string, field: string, line: number): unknown[] {
  if (!value) return [];
  if (field === 'phones' && !value.startsWith('[')) return value.split(/[;|]/).map(x => x.trim()).filter(Boolean);
  try {
    const parsed: unknown = JSON.parse(value);
    if (Array.isArray(parsed)) return parsed;
  } catch { /* report a clear error below */ }
  throw new Error(`سطر ${line}: ستون ${field} باید آرایه JSON معتبر باشد.`);
}

function parseBoolean(value: string, line: number): boolean {
  if (!value || ['false', '0', 'no', 'خیر'].includes(value.toLowerCase())) return false;
  if (['true', '1', 'yes', 'بله'].includes(value.toLowerCase())) return true;
  throw new Error(`سطر ${line}: مقدار reviews_complete باید true یا false باشد.`);
}

function numberOrNull(value: string): string | null {
  // Keep invalid numbers as strings so server validation rejects them; never turn
  // missing/invalid values into zero or make a guessed numeric observation.
  return value ? digits(value).replace(/٫/g, '.').replace(/٬/g, '') : null;
}

export function parseBusinessCsv(text: string): Record<string, unknown>[] {
  const parsed = Papa.parse<string[]>(text, { skipEmptyLines: 'greedy', preview: MAX_ROWS + 2 });
  if (parsed.errors.length) throw new Error(`CSV معتبر نیست: ${parsed.errors[0].message}`);
  const [header, ...rows] = parsed.data;
  if (!header || !rows.length) throw new Error('فایل CSV باید سرستون و حداقل یک رکورد داشته باشد.');
  if (rows.length > MAX_ROWS) throw new Error('حداکثر ۵۰۰ ردیف در هر فایل پذیرفته می‌شود.');
  const keys = header.map(name => aliasLookup.get(normalizeHeader(name)) || '');
  const present = keys.filter(Boolean);
  if (new Set(present).size !== present.length) throw new Error('یک ستون شناخته‌شده بیش از یک بار در فایل وجود دارد.');
  const missing = required.filter(key => !present.includes(key));
  if (missing.length) throw new Error(`سرستون‌های لازم یافت نشد: ${missing.join('، ')}.`);
  return rows.map((cells, index) => {
    const line = index + 2;
    if (cells.length !== header.length) throw new Error(`تعداد ستون‌های سطر ${line} با سرستون برابر نیست.`);
    const values: Record<string, string> = {};
    keys.forEach((key, i) => { if (key) values[key] = cells[i].trim(); });
    const rawSource = values.source || '';
    const normalizedSource = normalizeHeader(rawSource).replace(/\s+/g, ' ');
    const source = ['google_maps', 'google maps', 'گوگل مپ', 'گوگل مپس'].includes(normalizedSource)
      ? 'google_maps' : rawSource;
    const fetchedAt = values.fetched_at || '';
    const row: Record<string, unknown> = {
      source, source_id: values.source_id || '', source_url: values.source_url || null,
      name: values.name || '', province: values.province || null, city: values.city || '',
      category: values.category || '', address: values.address || null,
      lat: numberOrNull(values.lat || ''), lng: numberOrNull(values.lng || ''),
      phones: parseArray(values.phones || '', 'phones', line), rating: numberOrNull(values.rating || ''),
      reviews_count: numberOrNull(values.reviews_count || ''), fetched_at: fetchedAt,
      reviews_complete: parseBoolean(values.reviews_complete || '', line),
      reviews: parseArray(values.reviews || '', 'reviews', line),
    };
    // A Jalali wall-clock value has no timezone in the uploaded schema. Preserve
    // it as provenance, never silently interpret it as an ISO instant.
    if (values.jalali_time) row.csv_recorded_time_jalali = values.jalali_time;
    if (!fetchedAt) row.__csv_needs_time = true;
    return row;
  });
}

export function csvNeedsRunTime(item: Record<string, unknown>): boolean {
  return item.__csv_needs_time === true;
}

export function supplyCsvRunTime(item: Record<string, unknown>, startedAt: string): Record<string, unknown> {
  if (!csvNeedsRunTime(item)) return item;
  const { __csv_needs_time: _marker, ...out } = item;
  return { ...out, fetched_at: startedAt, observation_time_origin: 'user_supplied_csv_run_started_at' };
}
