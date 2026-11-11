/**
 * سرویس ارتباط پنل مدیریت React با API سمت سرور (/api/v1/*)
 * پشتیبانی کامل از عملیات مرحله S0 (احراز هویت، CSRF، مایگریشن بدون ترمینال)
 * و مرحله S1 (CRUD شهر، دسته، بسته دسته، کسب‌وکار، پیشنهاد اسلاگ و رسانه WebP)
 */

export interface AdminUser {
  id: number;
  username: string;
  display_name: string;
  role: 'admin' | 'editor';
  permissions?: StaffPermission[];
  last_login_at?: string;
}

export type StaffPermission = 'articles' | 'businesses' | 'media' | 'review';
export interface StaffAccount { id:number; username:string; display_name:string; is_active:number; permissions:StaffPermission[]; created_at:string; last_login_at:string|null; }
export async function fetchStaff():Promise<{ok:boolean;users:StaffAccount[]}> { return request('/api/v1/staff',{cache:'no-store'}); }
export async function saveStaff(payload:unknown,csrf:string):Promise<any> { return action('/api/v1/staff',payload,csrf); }

export interface CityItem {
  id: number;
  name_fa: string;
  slug: string;
  lat: number | null;
  lng: number | null;
  bbox_json: string | null;
  publish_mode: 'manual' | 'rules' | 'full_auto';
  is_active: number;
  businesses_count?: number;
  neighborhoods_count?: number;
}

export interface NeighborhoodItem {
  id: number;
  city_id: number;
  name_fa: string;
  slug: string;
  lat: number | null;
  lng: number | null;
}

export interface CategoryItem {
  id: number;
  parent_id: number | null;
  name_fa: string;
  slug: string;
  sort: number;
  is_active: number;
  businesses_count?: number;
}

export interface CategoryPackItem {
  id: number;
  name: string;
  queries_json: string;
  is_active: number;
  category_id?: number | null;
  category_name_fa?: string | null;
  category_slug?: string | null;
}

export interface BusinessContactItem {
  id?: number;
  business_id?: number;
  type: 'phone' | 'mobile' | 'instagram' | 'whatsapp' | 'website';
  value: string;
  is_primary: number;
  source: string;
}

export interface BusinessItem {
  id: number;
  name: string;
  slug: string;
  category_id: number;
  city_id: number;
  neighborhood_id: number | null;
  address: string | null;
  lat: number | null;
  lng: number | null;
  coords_source?: string | null;
  coords_verified_at?: string | null;
  osm_source_id?: string | null;
  hours: string | null;
  short_desc: string | null;
  cover_media_id: number | null;
  cover_thumb_url?: string | null;
  cover_medium_url?: string | null;
  status: 'draft' | 'pending_review' | 'published' | 'hidden' | 'rejected';
  tier: 'A' | 'B' | 'C';
  internal_score: number;
  promoted?: number;
  confidence: 'low' | 'medium' | 'high';
  city_name?: string;
  city_slug?: string;
  category_name?: string;
  neighborhood_name?: string;
  contacts: BusinessContactItem[];
  warnings: string[];
  review_current?: boolean;
  review_hash?: string | null;
  review_source_note?: string | null;
  reviewed_at?: string | null;
  updated_at: string;
}

export interface MediaAsset {
  id: number;
  file_path_480: string;
  file_path_800: string;
  file_path_1200: string;
  mime_type: string;
  width: number;
  height: number;
  size_bytes: number;
  alt_text: string;
}

export interface CollectorJobResult {
  found: number;
  inserted: number;
  duplicates: number;
  updated_sources?: number;
  pending_review?: number;
  source_conflict?: number;
  errors: number;
  last_error?: string | null;
  warning?: string;
  finished_at?: string;
}

export interface CollectorJobItem {
  id: number;
  city_id: number;
  pack_id: number | null;
  provider: string;
  params_json: string;
  status: 'queued' | 'claimed' | 'running' | 'done' | 'failed' | 'paused';
  claimed_at: string | null;
  attempts: number;
  result_json: string | null;
  error: string | null;
  city_name?: string;
  pack_name?: string;
  created_at: string;
  updated_at: string;
}

export interface ProviderStateItem {
  provider: string;
  enabled: number;
  priority: number;
  paused_until: string | null;
  last_error: string | null;
}

export interface ReviewQueueItem {
  id: number;
  kind: 'business' | 'change' | 'duplicate' | 'article' | 'claim' | 'report';
  target_id: number;
  reason_code: string;
  payload_json: string;
  status: 'pending' | 'approved' | 'rejected';
  decided_by: number | null;
  decided_at: string | null;
  created_at: string;
  business_name?: string | null;
  business_slug?: string | null;
  business_address?: string | null;
  business_score?: number | null;
  business_tier?: 'A' | 'B' | 'C' | null;
  business_confidence?: 'low' | 'medium' | 'high' | null;
  business_category_id?: number | null;
  category_name?: string | null;
  city_name?: string | null;
}

export interface ChangeLogItem {
  id: number;
  business_id: number;
  business_name?: string | null;
  business_slug?: string | null;
  field: string;
  old_value: string | null;
  new_value: string | null;
  source: string;
  status: 'pending' | 'approved' | 'rejected' | 'auto_applied';
  created_at: string;
}

export interface Benchmark100Summary {
  total_processed: number;
  published: number;
  pending_review: number;
  duplicate: number;
  source_conflict: number;
  breakdown_reasons: Record<string, number>;
}

export interface ArticleBizRow {
  id?: number;
  article_id?: number;
  business_id: number;
  business_name_fa?: string;
  business_slug?: string;
  weighted_score?: number;
  tier?: 'A' | 'B' | 'C';
  rank_num: number;
  badge_fa: string;
  verdict_fa: string;
  custom_desc_html: string;
  highlights?: string | null;
  pros?: string[];
  cons?: string[];
  is_sponsored?: number;
  sponsor_label?: string | null;
  sponsor_start?: string | null;
  sponsor_end?: string | null;
}

export interface ArticleFaqRow {
  id?: number;
  article_id?: number;
  question_fa: string;
  answer_fa: string;
  sort_order: number;
}

export interface ArticleData {
  city_id: number | null;
  category_id: number | null;
  slug: string;
  title_fa: string;
  intro_html: string;
  methodology_html: string;
  meta_title: string | null;
  meta_description: string | null;
  cover_media_id: number | null;
  cover_url?: string | null;
  items: ArticleBizRow[];
  faqs: ArticleFaqRow[];
  tags: string[];
}
export interface ArticleTopicTag { id:number; name_fa:string; slug:string }
export interface ArticleItemDisplay {
  eligibility: 'eligible' | 'not_published' | 'missing_context' | 'missing';
  business_status: BusinessItem['status'] | null;
  city_id: number | null;
  category_id: number | null;
}
export interface ArticleItem extends Omit<ArticleData,'tags'> {
  tags: ArticleTopicTag[];
  item_display?: Record<string, ArticleItemDisplay>;
  id: number;
  status: 'draft' | 'review' | 'published';
  city_name_fa?: string | null;
  city_slug?: string | null;
  category_name_fa?: string | null;
  category_slug?: string | null;
  published_at?: string | null;
  edit_token: string;
  revision: null | {status:'draft'|'review';base_conflict:boolean;updated_by:number;updated_at:string;data:ArticleData};
}

export interface CheckReport {
  ok: boolean;
  timestamp_tehran: string;
  php: {
    version: string;
    version_ok: boolean;
    sapi: string;
    memory_limit: string;
    max_execution_time: string;
    timezone: string;
    argon2id_supported: boolean;
    password_algo_active: string;
  };
  extensions: {
    gd: boolean;
    gd_webp: boolean;
    mbstring: boolean;
    curl: boolean;
    intl: boolean;
    pdo_mysql: boolean;
  };
  directories: Record<string, { path: string; exists: boolean; writable: boolean }>;
  database: {
    connected: boolean;
    version: string;
    time_zone: string;
    error: string | null;
  };
  directadmin_cron: {
    detected_php_cli: string;
    worker_script: string;
    recommended_cron_line: string;
  };
}

export const RESERVED_SLUGS = [
  'admin',
  'api',
  'assets',
  'uploads',
  'cache',
  'city',
  'b',
  'sitemap',
  'robots',
  'install',
  'check',
  'storage',
];

const SLUG_DICTIONARY: Record<string, string> = {
  'رشت': 'rasht',
  'بندر انزلی': 'bandar-anzali',
  'انزلی': 'anzali',
  'لاهیجان': 'lahijan',
  'لنگرود': 'langarud',
  'تالش': 'talesh',
  'آستارا': 'astara',
  'فومن': 'fuman',
  'ماسوله': 'masouleh',
  'ماسال': 'masal',
  'رودبار': 'rudbar',
  'صومعه‌سرا': 'someh-sara',
  'صومعه سرا': 'someh-sara',
  'آستانه اشرفیه': 'astaneh-ashrafiyeh',
  'رودسر': 'rudsar',
  'سیاهکل': 'siahkal',
  'دیلمان': 'deylaman',
  'شفت': 'shaft',
  'خمام': 'khomam',
  'رضوانشهر': 'rezvanshahr',
  'گلسار': 'golsar',
  'منظریه': 'manzarieh',
  'معلم': 'moallem',
  'سبزه میدان': 'sabzeh-meydan',
  'کافه': 'cafe',
  'رستوران': 'restaurant',
  'کبابی': 'kababi',
  'سالن زیبایی': 'beauty-salon',
  'کلینیک': 'clinic',
  'دندانپزشکی': 'dental',
  'ویلا': 'villa',
  'اقامتگاه': 'ecolodge',
  'بومگردی': 'ecolodge',
  'هتل': 'hotel',
  'جاذبه': 'attraction',
  'گردشگری': 'tourism',
};

const CHAR_MAP: Record<string, string> = {
  'آ': 'a', 'ا': 'a', 'أ': 'a', 'إ': 'e',
  'ب': 'b', 'پ': 'p', 'ت': 't', 'ث': 's',
  'ج': 'j', 'چ': 'ch', 'ح': 'h', 'خ': 'kh',
  'د': 'd', 'ذ': 'z', 'ر': 'r', 'ز': 'z',
  'ژ': 'zh', 'س': 's', 'ش': 'sh', 'ص': 's',
  'ض': 'z', 'ط': 't', 'ظ': 'z', 'ع': 'a',
  'غ': 'gh', 'ف': 'f', 'ق': 'gh', 'ک': 'k',
  'ك': 'k', 'گ': 'g', 'ل': 'l', 'م': 'm',
  'ن': 'n', 'و': 'v', 'ه': 'h', 'ة': 'h',
  'ی': 'y', 'ي': 'y', 'ئ': 'y',
  '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
  '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  '‌': '-',
};

export function suggestLatinSlug(persianText: string, prefix = 'item'): string {
  let clean = persianText.trim().replace(/[ي]/g, 'ی').replace(/[ك]/g, 'ک');
  const sortedKeys = Object.keys(SLUG_DICTIONARY).sort((a, b) => b.length - a.length);
  for (const fa of sortedKeys) {
    clean = clean.split(fa).join(` ${SLUG_DICTIONARY[fa]} `);
  }
  let out = '';
  for (const ch of clean.toLowerCase()) {
    out += CHAR_MAP[ch] !== undefined ? CHAR_MAP[ch] : ch;
  }
  let slug = out
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .replace(/-+/g, '-');
  if (!slug) {
    slug = `${prefix}-${Math.floor(100 + Math.random() * 899)}`;
  }
  if (RESERVED_SLUGS.includes(slug)) {
    slug = `${slug}-gilan`;
  }
  return slug;
}

export function validateAsciiSlug(slug: string): { ok: boolean; error?: string } {
  const clean = slug.trim().toLowerCase();
  if (!clean) {
    return {
      ok: false,
      error: 'اسلاگ نمی‌تواند خالی باشد. روی دکمه «پیشنهاد خودکار» کلیک کنید یا یک عبارت انگلیسی بنویسید.',
    };
  }
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(clean)) {
    return {
      ok: false,
      error: 'اسلاگ فقط باید شامل حروف کوچک انگلیسی (a-z)، اعداد (0-9) و خط تیره (-) باشد.',
    };
  }
  if (RESERVED_SLUGS.includes(clean)) {
    return {
      ok: false,
      error: `اسلاگ «${clean}» جزو اسلاگ‌های رزروشده سیستم است. لطفاً آن را به «${clean}-gilan» تغییر دهید.`,
    };
  }
  return { ok: true };
}

export function normalizeIranPhone(raw: string): string {
  const mapDigits: Record<string, string> = {
    '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
    '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
  };
  let digits = raw
    .split('')
    .map((c) => mapDigits[c] ?? c)
    .join('')
    .replace(/[^0-9+]/g, '');

  if (digits.startsWith('0098')) digits = '+98' + digits.slice(4);
  else if (digits.startsWith('09') && digits.length === 11) digits = '+98' + digits.slice(1);
  else if (digits.startsWith('013') && digits.length === 11) digits = '+98' + digits.slice(1);
  else if (digits.length === 8 && !digits.startsWith('0')) digits = '+9813' + digits;
  return digits;
}

export function computeBusinessWarnings(
  biz: Partial<BusinessItem>,
  contacts: BusinessContactItem[]
): string[] {
  const warnings: string[] = [];
  if (!biz.cover_media_id) {
    warnings.push('بدون عکس (تصویر پیش‌فرض دسته نمایش داده می‌شود)');
  }
  const hasPhone = contacts.some(
    (c) => (c.type === 'phone' || c.type === 'mobile') && c.is_primary === 1 && c.value.trim() !== ''
  );
  if (!hasPhone) {
    warnings.push('شماره تماس ثبت یا تأیید نشده');
  }
  if (!biz.lat || !biz.lng) {
    warnings.push('مختصات نقشه (lat/lng) وارد نشده');
  }
  if (!biz.address || biz.address.trim() === '') {
    warnings.push('آدرس دقیق متنی خالی است');
  }
  if (!biz.short_desc || biz.short_desc.trim() === '') {
    warnings.push('توضیح کوتاه خالی است (آماده تکمیل با AI)');
  }
  return warnings;
}

export interface AiTaskRow {
  id: number;
  type: 'business_text' | 'article_draft';
  target_id: number;
  target_title?: string | null;
  input_json: string;
  status: 'queued' | 'claimed' | 'done' | 'failed';
  output_json?: string | null;
  used_fields_json?: string | null;
  model?: string | null;
  attempts: number;
  error?: string | null;
  created_at: string;
  updated_at?: string;
}

export interface AiQueueStats {
  queued: number;
  claimed: number;
  done: number;
  failed: number;
}

export interface TopEngagedBusiness {
  id: number;
  name: string;
  slug: string;
  tier: 'A' | 'B' | 'C';
  internal_score: number;
  city_name: string;
  city_slug: string;
  views_cnt: number;
  calls_cnt: number;
  maps_cnt: number;
  total_interactions: number;
}

export interface SponsoredPlacementItem {
  id: number;
  article_id: number;
  business_id: number;
  position: number;
  sponsor_label: string;
  sponsor_start: string | null;
  sponsor_end: string | null;
  business_name: string;
  business_slug: string;
  article_title: string;
  article_slug: string;
  article_status: string;
  business_status: string;
  display_status: 'active' | 'upcoming' | 'ended' | 'article_unpublished' | 'business_unpublished';
  city_name: string;
}

export interface AnalyticsSummaryState {
  ok: boolean;
  totals_30d: {
    view: number;
    call: number;
    map: number;
    website: number;
    instagram: number;
    whatsapp: number;
  };
  top_businesses: TopEngagedBusiness[];
  sponsored_items: SponsoredPlacementItem[];
  pending_claims: number;
  pending_reports: number;
  sponsor_last_check: string;
}


// Never replace server failures with fabricated local data or local authentication.
async function request(path: string, options: RequestInit = {}): Promise<any> {
  let response: Response;
  try { response = await fetch(path, { credentials: 'same-origin', ...options }); }
  catch { throw new Error('ارتباط با سرور برقرار نشد.'); }
  if (!(response.headers.get('content-type') || '').includes('application/json')) {
    throw new Error(`پاسخ نامعتبر از سرور (HTTP ${response.status}).`);
  }
  const data = await response.json();
  if (!response.ok || !data.ok) throw new Error(data.error || `خطای سرور (HTTP ${response.status}).`);
  return data;
}
async function action(path: string, payload: unknown, csrf: string): Promise<any> {
  try {
    return await request(path, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(payload ?? {}) });
  } catch (e) {
    const message = e instanceof Error ? e.message : 'خطای نامشخص';
    return { ok: false, error: message, message };
  }
}
export interface AdminMailSettings { enabled:boolean; method:'php'|'smtp'; recipient:string; from:string; host:string; port:number; security:'tls'|'ssl'; username:string; password_configured:boolean; version:number; }
export async function fetchAdminMail():Promise<{ok:boolean;settings:AdminMailSettings}> { return request('/api/v1/admin-mail',{cache:'no-store'}); }
export async function saveAdminMail(payload:unknown,csrf:string):Promise<any> { return action('/api/v1/admin-mail',payload,csrf); }
export async function testAdminMail(csrf:string):Promise<any> { return action('/api/v1/admin-mail/test',{},csrf); }
export async function fetchAuthMe(): Promise<{ok:boolean; authenticated:boolean; user:AdminUser|null; csrf_token:string}> { return request('/api/v1/auth/me'); }
export async function loginAdmin(params: {username:string;password:string;csrfToken:string}): Promise<any> {
  return action('/api/v1/auth/login', { username:params.username, password:params.password }, params.csrfToken);
}
export async function logoutAdmin(csrfToken:string): Promise<any> { return action('/api/v1/auth/logout', {}, csrfToken); }
export async function runWebMigrations(csrfToken:string): Promise<any> { return action('/api/v1/system/migrate', {}, csrfToken); }
export async function updateFeatureSchema(csrfToken:string): Promise<any> { return action('/api/v1/system/update-feature-schema', {}, csrfToken); }
export interface AiProviderStatus { id:string; label:string; model:string; available:boolean }
export interface AiModelSuggestion { id:string; note:string }
export interface AiManagedProvider extends AiProviderStatus {
  enabled:boolean; free_tier_confirmed:boolean; key_configured:boolean;
  source:'none'|'file'|'panel'; version:number; error:string;
  // S9: نشانی جایگزین endpoint (پراکسی/Cloudflare Worker) — اختیاری و سازگار با بک‌اند قدیمی.
  base_url?:string;
  // Optional for compatibility with a previously deployed backend during an update.
  default_model?:string; suggested_models?:AiModelSuggestion[];
}
export async function fetchAiProviders(): Promise<{ok:boolean;schema_ready:boolean;providers:AiProviderStatus[]}> {
  return request('/api/v1/ai/article/providers', {cache:'no-store'});
}
export async function fetchAiProviderManagement(): Promise<{ok:boolean;schema_ready:boolean;providers:AiManagedProvider[]}> {
  return request('/api/v1/ai/providers/manage', {cache:'no-store'});
}
export async function saveAiProvider(payload:{provider:string;model:string;api_key:string;enabled:boolean;free_tier_confirmed:boolean;version:number;base_url?:string},csrf:string):Promise<{ok:boolean;message?:string;error?:string}> {
  return action('/api/v1/ai/providers/save',payload,csrf);
}
export async function removeAiProvider(provider:string,version:number,csrf:string):Promise<{ok:boolean;message?:string;error?:string}> {
  return action('/api/v1/ai/providers/remove',{provider,version,confirm:true},csrf);
}
export async function testAiProvider(provider:string,csrf:string):Promise<{ok:boolean;message?:string;error?:string}> {
  return action('/api/v1/ai/providers/test',{provider,confirm_cost:true},csrf);
}
export async function generateAiArticle(payload:{provider:string;title:string;brief:string;facts:string;confirm_send:boolean;confirm_quota:boolean}, csrf:string): Promise<{ok:boolean;text?:string;provider?:string;model?:string;error?:string}> {
  return action('/api/v1/ai/article/generate',payload,csrf);
}
export interface AdBanner { id:number; version:number; title:string; alt_text:string; media_id:number; image_url:string|null; target_url:string; placement:string; city_id:number|null; category_id:number|null; starts_at:string|null; ends_at:string|null; active:number; priority:number; views_total:number|null; clicks_total:number|null; views_30:number|null; clicks_30:number|null }
export interface AdInquiry { id:number; name:string; phone:string; message:string|null; status:'new'|'handled'; created_at:string }
export async function fetchAdvertising(): Promise<{ok:boolean;schema_ready:boolean;reporting_schema_ready:boolean;placements:Record<string,string>;banners:AdBanner[];inquiries:AdInquiry[]}> { return request('/api/v1/advertising',{cache:'no-store'}); }
export async function updateAdvertisingSchema(csrf:string):Promise<any> { return action('/api/v1/system/update-advertising-schema',{},csrf); }
export async function saveAdBanner(payload:unknown,csrf:string):Promise<any> { return action('/api/v1/advertising/banner',payload,csrf); }
export async function deleteAdBanner(id:number,version:number,csrf:string):Promise<any> { return action('/api/v1/advertising/banner/delete',{id,version},csrf); }
export async function updateAdInquiry(id:number,operation:'new'|'handled'|'delete',csrf:string):Promise<any> { return action('/api/v1/advertising/inquiry',{id,operation},csrf); }

// PDO may serialize integer columns as strings. All ledger amounts remain integer toman.
export const FINANCE_PENDING_STORAGE_KEY='hhx.advertising.finance.pending.v1';
export const GATEWAY_PENDING_STORAGE_KEY='hhx.advertising.gateway.pending.v1';
type DbInteger = number | string;
export interface AdContract {
  id:DbInteger; version:DbInteger; title:string; client_name:string; client_phone:string|null;
  banner_id:DbInteger|null; banner_title:string|null; starts_at:string|null; ends_at:string|null;
  amount_toman:DbInteger; manual_paid:DbInteger; online_paid:DbInteger; paid_toman:DbInteger; remaining_toman:DbInteger;
  status:'active'|'completed'|'cancelled'; notes:string|null; created_by:DbInteger; created_at:string;
}
export interface AdManualPayment {
  id:DbInteger; contract_id:DbInteger; amount_toman:DbInteger; method:'bank_transfer'|'cash'|'other';
  reference:string; notes:string|null; status:'recorded'|'voided'; recorded_by:DbInteger; recorded_at:string;
  voided_by:DbInteger|null; voided_at:string|null; void_reason:string|null;
}
export interface AdContractStatusLog {
  id:DbInteger; contract_id:DbInteger; old_status:string; new_status:string; reason:string; changed_by:DbInteger; changed_at:string;
}
export interface AdOnlinePaymentSummary {
  id:DbInteger; contract_id:DbInteger; provider:string; mode:'live'|'sandbox'; state:string; amount_toman:DbInteger;
  order_id:string; provider_id:string|null; verified_reference:string|null; last_error_code:string|null; created_at:string;
}
export interface AdOnlineAudit {
  id:DbInteger; payment_id:DbInteger; old_state:string; new_state:string; reason:string;
  reference:string|null; changed_by:DbInteger|null; changed_at:string;
}
export interface AdGatewayStatus {
  id:'zibal'|'aqayepardakht'|'idpay'; label:string; mode:'disabled'|'sandbox'|'live';
  key_configured:boolean; version:number; error:string;
}
export interface AdGatewayLog {
  provider:string; old_mode:string; new_mode:string; key_action:string; version:DbInteger; changed_by:DbInteger; changed_at:string;
}
export interface AdFinanceData {
  ok:boolean; schema_ready:boolean; online_sandbox_enabled?:boolean; contracts:AdContract[]; manual_payments:AdManualPayment[];
  online_payments:AdOnlinePaymentSummary[]; status_log:AdContractStatusLog[]; online_audit?:AdOnlineAudit[];
}
export interface FinanceMutationResult { ok:boolean; message?:string; error?:string; ambiguous?:boolean; data?:Record<string,unknown> }
export async function fetchAdvertisingGateways():Promise<{ok:boolean;schema_ready:boolean;providers:AdGatewayStatus[];log:AdGatewayLog[]}> {
  return request('/api/v1/advertising/gateways',{cache:'no-store'});
}
export async function fetchAdvertisingFinance():Promise<AdFinanceData> {
  return request('/api/v1/advertising/finance',{cache:'no-store'});
}
/** Transport failures, timeouts, rate limits and 5xx may have occurred after commit. */
export async function financeMutation(path:string, payload:unknown, csrf:string):Promise<FinanceMutationResult> {
  try {
    const response=await fetch(path,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});
    const isJson=(response.headers.get('content-type')||'').includes('application/json');
    if (!isJson) return {ok:false,ambiguous:true,error:'پاسخ قابل اطمینان نبود؛ ممکن است درخواست ثبت شده باشد.'};
    const data=await response.json();
    if(response.ok&&data.ok===true) return {ok:true,message:typeof data.message==='string'?data.message:'عملیات ثبت شد.',data};
    // Do not treat gateway/proxy timeouts or rate limits as proof the server rolled back.
    const definite=[400,401,403,404,409,413,422].includes(response.status)&&data?.ok===false;
    return {ok:false,ambiguous:!definite,error:typeof data.error==='string'?data.error:'نتیجه درخواست نامشخص است.'};
  } catch {
    return {ok:false,ambiguous:true,error:'ارتباط قطع شد؛ ممکن است درخواست ثبت شده باشد.'};
  }
}
export async function fetchCitiesAndNeighborhoods(): Promise<{cities:CityItem[];neighborhoods:NeighborhoodItem[]}> { return request('/api/v1/cities'); }
export async function fetchCategoriesAndPacks(): Promise<{categories:CategoryItem[];packs:CategoryPackItem[]}> { return request('/api/v1/categories'); }
export async function fetchBusinessesList(): Promise<BusinessItem[]> { return (await request('/api/v1/businesses')).businesses; }
export async function fetchBusinessesPage(page:number, cityId:number, query:string, quality='',status='', coordsRisk:string=''): Promise<{businesses:BusinessItem[];total:number;page:number;per_page:number;data_review_schema_ready:boolean}> {
  return request('/api/v1/businesses?' + new URLSearchParams({page:String(page),city_id:String(cityId),q:query,quality,status,coords_risk:coordsRisk}));
}
// S18: شمارندهٔ گزارش «مختصات پرریسک» (مرکز پلی‌گون OSM، بدون تأیید دستی) برای کارت پنل ادمین
export async function fetchCoordsRiskTotal(): Promise<number> {
  const data = await request('/api/v1/businesses?' + new URLSearchParams({page:'1',city_id:'0',q:'',quality:'',status:'',coords_risk:'1'}));
  return Number(data.total || 0);
}
export async function recordBusinessDataReview(payload:{business_id:number;review_hash:string;source_note:string;confirmed:boolean},csrf:string):Promise<any> {
  return action('/api/v1/businesses/data-review',payload,csrf);
}
export async function setBusinessPromotion(businessId:number, active:boolean, csrf:string): Promise<any> {
  return action('/api/v1/businesses/promotion', {business_id:businessId,active}, csrf);
}
export interface ImportCandidate { id:number; name:string; address:string|null; status:string; distance:number|null; category_id:number; category_name:string|null; same_phone:boolean }
export interface ImportRow { index:number; name:string; source_category:string; source:string; source_id:string; rating:number|null; reviews_count:number|null; status:'new'|'update'|'review'|'filtered'|'invalid'|'conflict'|'stale'; message:string; candidates:ImportCandidate[]; business_id?:number }
export interface ImportOptions { city_id:number; category_id:number; source_categories:string[]; min_rating:number; min_reviews:number }
export interface ImportEntry { index:number; item:unknown; decision:string; candidate_ids:number[] }
export interface ImportResult { index:number; status:'created'|'updated'|'skipped'|'error'; business_id?:number; message:string }
export interface PreparedJson { ok:boolean; items?:unknown[]; stats?:{apify:number;already_standard:number;missing_time:number;missing_identity:number;missing_coordinates:number}; error?:string }
export async function prepareJsonImport(items:unknown[], runStartedAt:string, csrf:string):Promise<PreparedJson> {
  return action('/api/v1/import/json/prepare',{items,run_started_at:runStartedAt},csrf);
}
export interface ApifySchedule { enabled:number|string;mode:'direct'|'legacy';task_id:string;city_id:number|string|null;category_id:number|string|null;max_charge_usd:number|string|null;source_categories:string[];min_rating:number|string;min_reviews:number|string;next_run_at:string|null;last_error:string|null }
export interface ApifyRunSummary { id:number|string;task_id:string;remote_run_id:string|null;dataset_id:string|null;status:string;city_id:number|string|null;category_id:number|string|null;started_at_iso:string|null;finished_at_iso:string|null;item_count:number|string|null;error:string|null;created_at:string;updated_at:string }
export interface ApifyRunData { id:number|string;task_id:string;remote_run_id:string|null;status:string;city_id:number|string|null;category_id:number|string|null;source_categories:string[];min_rating:number|string;min_reviews:number|string;started_at_iso:string|null;finished_at_iso:string|null;item_count:number|string|null;items:unknown[] }
export async function fetchApifyStatus():Promise<{ok:boolean;token_configured:boolean;schedule:ApifySchedule;runs:ApifyRunSummary[];search_terms:Record<string,string>}> { return request('/api/v1/import/apify/status',{cache:'no-store'}); }
export async function fetchApifyRun(id:number):Promise<{ok:boolean;run:ApifyRunData}> { return request(`/api/v1/import/apify/runs/${id}`,{cache:'no-store'}); }
export async function saveApifySchedule(payload:{enabled:boolean;direct?:boolean;confirmed?:boolean;expected_search_term?:string;task_id?:string;city_id?:number;category_id?:number;max_charge_usd?:number;source_categories?:string[];min_rating?:number;min_reviews?:number},csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/schedule',payload,csrf); }
export async function startApifyCollection(payload:{city_id:number;category_id:number;max_places:number;max_charge_usd:number;confirmed:boolean;expected_search_term:string},csrf:string):Promise<{ok:boolean;status?:string;run_id?:number;error?:string}> { return action('/api/v1/import/apify/start',payload,csrf); }
export async function importLatestApify(csrf:string):Promise<{ok:boolean;id?:number;error?:string}> { return action('/api/v1/import/apify/latest',{},csrf); }
export async function importApifyRun(run_id:string,csrf:string):Promise<{ok:boolean;id?:number;error?:string}> { return action('/api/v1/import/apify/run',{run_id},csrf); }
export async function refreshApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/refresh',{id},csrf); }
export async function deleteApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/delete',{id},csrf); }
export async function resolveApifyRun(id:number,csrf:string):Promise<{ok:boolean;error?:string}> { return action('/api/v1/import/apify/resolve',{id,confirmed:true},csrf); }
export async function previewJsonImport(items:unknown[], options:ImportOptions, csrf:string): Promise<{ok:boolean;rows?:ImportRow[];error?:string}> {
  return action('/api/v1/import/json/preview',{items,options},csrf);
}
export async function applyJsonImport(entries:ImportEntry[], options:ImportOptions, csrf:string): Promise<{ok:boolean;results?:ImportResult[];error?:string}> {
  return action('/api/v1/import/json/apply',{entries,options},csrf);
}
export async function saveCityItem(payload:Partial<CityItem>, csrf:string): Promise<any> { return action('/api/v1/cities',payload,csrf); }
export async function saveCategoryItem(payload:Partial<CategoryItem>, csrf:string): Promise<any> { return action('/api/v1/categories',payload,csrf); }
export async function saveCategoryPackItem(payload:Partial<CategoryPackItem>, csrf:string): Promise<any> { return action('/api/v1/category-packs',payload,csrf); }
export async function saveBusinessItem(payload:any, csrf:string): Promise<any> { return action('/api/v1/businesses',payload,csrf); }
export async function publishSavedBusiness(businessId:number, csrf:string):Promise<any> { return action('/api/v1/businesses/publish',{business_id:businessId},csrf); }
export type BusinessSeoDeleteMode = 'auto_seo' | 'redirect_category' | 'redirect_business' | 'purge_clean';
export interface DeleteBusinessesPayload {
  ids: number[];
  seo_mode: BusinessSeoDeleteMode;
  redirect_business_id?: number;
  delete_unused_media?: boolean;
}
export async function deleteBusinesses(payload: DeleteBusinessesPayload, csrf: string): Promise<any> {
  return action('/api/v1/businesses/delete', payload, csrf);
}
export async function updateSeoSchema(csrf: string): Promise<any> {
  return action('/api/v1/system/update-seo-schema', {}, csrf);
}

export type MediaSlotKey = 'business_cover' | 'article_cover' | 'advertising_banner';
export interface MediaDimensionGuide {
  slot: MediaSlotKey;
  title: string;
  recommended_px: string;
  aspect_ratio: string;
  min_px: string;
  fit_mode: string;
  note: string;
}
export const MEDIA_DIMENSION_GUIDES: Record<MediaSlotKey, MediaDimensionGuide> = {
  business_cover: {
    slot: 'business_cover',
    title: 'تصویر شاخص (کاور) کسب‌وکار',
    recommended_px: '1200 × 800',
    aspect_ratio: '3:2 افقی (یا 16:9 معادل 1200×675)',
    min_px: '800 × 530',
    fit_mode: 'برش هوشمند از مرکز در کارت‌ها (object-fit: cover)',
    note: 'سوژه اصلی یا تابلو را در مرکز کادر قرار دهید. هر فرمتی (JPG/PNG/WebP/GIF/BMP/AVIF) آپلود شود، به‌صورت خودکار و ۱۰۰٪ به ۳ نسخه WebP کم‌حجم (480، 800 و 1200 پیکسل) تبدیل می‌شود و هیچ فایل JPEG یا PNG روی هاست ذخیره نخواهد شد.',
  },
  article_cover: {
    slot: 'article_cover',
    title: 'تصویر شاخص مقاله و کارت شبکه‌های اجتماعی',
    recommended_px: '1200 × 630',
    aspect_ratio: '1.91:1 یا 16:9 افقی (1200×675)',
    min_px: '960 × 500',
    fit_mode: 'پوشش کامل هدر مقاله و کارت OpenGraph (object-fit: cover)',
    note: 'ابعاد 1200×630 بهترین نمایش را هم در بالای مقاله و هم هنگام اشتراک‌گذاری لینک در تلگرام، واتساپ و گوگل ایجاد می‌کند. خروجی روی هاست منحصراً به WebP بهینه تبدیل می‌شود.',
  },
  advertising_banner: {
    slot: 'advertising_banner',
    title: 'بنر تبلیغاتی جایگاه‌های عمومی سایت',
    recommended_px: '1200 × 250 (تکی) یا 800 × 250 (دوتایی کنار هم)',
    aspect_ratio: 'افقی 5:1 تا 3:1 (تکی تمام‌عرض یا جفت بنر دسکتاپ)',
    min_px: '600 × 200',
    fit_mode: 'نمایش کامل بدون برش (object-fit: contain)',
    note: 'در هر جایگاه حداکثر ۲ بنر فعال کنار هم در دسکتاپ نمایش داده می‌شود. بنرها برش نمی‌خورند و تصویر آپلودی خودکار به WebP بهینه تبدیل می‌شود.',
  },
};

export interface MediaVariantInfo {
  web_path: string;
  host_path: string;
  exists: boolean;
  bytes: number;
  is_webp: boolean;
}
export interface MediaUsageInfo {
  type: 'business_cover' | 'business_photo' | 'article_cover' | 'article_revision' | 'advertising_banner';
  label: string;
  entity_id: number;
  title: string;
  status: string;
}
export interface MediaLibraryItem {
  id: number;
  file_path_1200: string;
  file_path_800: string;
  file_path_480: string;
  url_1200: string;
  url_800: string;
  url_480: string;
  host_path_1200: string;
  mime_type: string;
  width: number;
  height: number;
  alt_text: string;
  uploaded_by_name: string;
  created_at: string;
  variants: Record<'1200' | '800' | '480', MediaVariantInfo>;
  total_bytes: number;
  all_files_present: boolean;
  all_webp: boolean;
  usages: MediaUsageInfo[];
  usage_count: number;
  can_force_detach: boolean;
}
export interface MediaLibrarySummary {
  total_db_records: number;
  used_db_records: number;
  unused_db_records: number;
  missing_files_records: number;
  non_webp_db_records: number;
  uploads_host_root: string;
  disk_webp_files: number;
  disk_non_webp_files: number;
  disk_orphan_files: number;
  disk_total_bytes: number;
}
export interface MediaLibraryResponse {
  ok: boolean;
  items: MediaLibraryItem[];
  pagination: { page: number; per_page: number; total: number; total_pages: number };
  summary: MediaLibrarySummary;
  dimension_guides?: Record<string, MediaDimensionGuide>;
}
export async function fetchMediaLibrary(params: { page?: number; per_page?: number; usage?: string; q?: string } = {}): Promise<MediaLibraryResponse> {
  const qs = new URLSearchParams({
    page: String(params.page ?? 1),
    per_page: String(params.per_page ?? 24),
    usage: params.usage ?? 'all',
    q: params.q ?? '',
  });
  return request('/api/media-library.php?' + qs.toString(), { cache: 'no-store' });
}
export async function updateMediaAltText(id: number, altText: string, csrf: string): Promise<any> {
  return action('/api/media-library.php?action=update', { id, alt_text: altText }, csrf);
}
export async function deleteMediaItems(
  payload: { ids?: number[]; detach_usages?: boolean; purge_orphans?: boolean },
  csrf: string
): Promise<any> {
  return action('/api/media-library.php?action=delete', payload, csrf);
}
export function formatBytesFa(bytes: number): string {
  const b = Math.max(0, Number(bytes) || 0);
  if (b < 1024) return `${b.toLocaleString('fa-IR')} بایت`;
  if (b < 1024 * 1024) return `${(b / 1024).toLocaleString('fa-IR', { maximumFractionDigits: 1 })} KB`;
  return `${(b / (1024 * 1024)).toLocaleString('fa-IR', { maximumFractionDigits: 2 })} MB`;
}
export async function uploadMediaWebp(file:File, altText:string, csrf:string): Promise<any> {
  try {
    const body = new FormData(); body.append('image',file); body.append('alt_text',altText);
    const data = await request('/api/v1/media/upload',{method:'POST',headers:{'X-CSRF-Token':csrf},body});
    return {...data,previewUrl:data.media?.file_path_480};
  } catch(e) { return {ok:false,error:e instanceof Error ? e.message : 'خطای آپلود'}; }
}
export async function fetchCollectorQueueState(): Promise<{jobs:CollectorJobItem[];providers:ProviderStateItem[];collector_bearer_token:string;photo_policy:string}> { return request('/api/v1/collector/jobs'); }
export async function createCollectorJob(payload:{city_id:number;category_id?:number;pack_id?:number;provider:string;custom_query?:string},csrf:string): Promise<any> { return action('/api/v1/collector/jobs',payload,csrf); }
export async function updateCollectorProvider(payload:{provider:string;enabled:number;priority:number;clear_pause?:boolean},csrf:string): Promise<any> { return action('/api/v1/collector/providers',payload,csrf); }
export async function triggerCollectorJobRun(csrf:string): Promise<{ok:boolean;logLines:string[]}> {
  const result=await action('/api/v1/collector/run-next',{},csrf);
  return {ok:result.ok,logLines:[result.message || result.error || 'پاسخ نامعتبر', ...(result.summary ? [JSON.stringify(result.summary)] : [])]};
}
export async function fetchReviewQueueState(status='pending',kind='all',query=''): Promise<{items:ReviewQueueItem[];change_logs:ChangeLogItem[];pending_counts:Record<string,number>}> {
  return request('/api/v1/review-queue?' + new URLSearchParams({status,kind,q:query}));
}
export async function decideReviewQueueItems(ids:number[],decision:'approved'|'rejected'|'merge',csrf:string,mergeIntoId?:number): Promise<any> {
  return action('/api/v1/review-queue/decide',{ids,decision,...(mergeIntoId?{merge_into_id:mergeIntoId}:{})},csrf);
}
export async function fetchArticlesList(): Promise<{articles:ArticleItem[];schema_ready:boolean;article_tags_ready?:boolean;tag_catalog?:ArticleTopicTag[];rich_editor_available?:boolean}> { return request('/api/v1/articles', {cache:'no-store'}); }
export async function saveArticleItem(payload:Partial<Omit<ArticleItem,'tags'>> & {tags?:string[]},csrf:string): Promise<any> { return action('/api/v1/articles',payload,csrf); }
/** S20: حذف کامل مقاله از دیتابیس (فقط ادمین) — رکورد و همهٔ ردیف‌های وابسته واقعاً پاک می‌شوند. */
export async function deleteArticleItem(id:number,csrf:string): Promise<any> { return action('/api/v1/articles/delete',{id},csrf); }
export async function updateArticleSchema(csrf:string):Promise<any> { return action('/api/v1/system/update-article-schema',{},csrf); }
export async function updateArticleTagsSchema(csrf:string):Promise<any> { return action('/api/v1/system/update-article-tags-schema',{},csrf); }
export async function fetchArticleBusinesses(page:number, query:string, cityId:number, categoryId:number): Promise<{businesses:BusinessItem[];total:number;per_page:number}> {
  return request('/api/v1/businesses?' + new URLSearchParams({page:String(page),q:query,city_id:String(cityId),category_id:String(categoryId),status:'published',article_picker:'1'}));
}
export async function autoBuildCityCategoryArticle(cityId:number,categoryId:number,csrf:string): Promise<any> { return action('/api/v1/articles/auto-build',{city_id:cityId,category_id:categoryId},csrf); }
export async function flushPublicHtmlCache(csrf:string): Promise<any> { return action('/api/v1/cache/flush',{},csrf); }
export async function fetchAnalyticsSummary(): Promise<AnalyticsSummaryState> { return request('/api/v1/analytics/summary'); }
export async function runCronMaintenanceS6(csrf:string): Promise<any> { return action('/api/v1/system/cron-run',{},csrf); }

// ============================================================ S9 - ماژول جامع آمار بازدید و کلیک

export interface AnalyticsTotals { views:number; uniques:number; call:number; map:number; website:number; instagram:number; whatsapp:number; banner:number; searches:number }
export interface AnalyticsSeriesRow extends AnalyticsTotals { day:string }
export interface AnalyticsTopRow { id:number; title:string; views:number; uniques:number; call:number; map:number; website:number; instagram:number; whatsapp:number; banner:number; searches:number }
export interface AnalyticsDashboard { ok:boolean; from:string; to:string; series:AnalyticsSeriesRow[]; totals:AnalyticsTotals; tops:Record<string, AnalyticsTopRow[]>; available_types:string[] }
export interface AnalyticsLogRow { id:number; occurred_at:string; event_type:string; target_type:string; target_id:number; target_title:string; path:string; ip_address:string; ref_source:string; ref_host:string; referrer:string; utm_source:string; utm_medium:string; utm_campaign:string; utm_term:string; device:string; browser:string; search_query:string }
export interface AnalyticsLogFilters { from?:string; to?:string; event_type?:string; target_type?:string; target_id?:number; ref_source?:string; q?:string }

function analyticsQuery(params:Record<string, string|number|undefined>): URLSearchParams {
  const q = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '' && v !== 0) q.set(k, String(v));
  }
  return q;
}

export async function fetchAnalyticsDashboard(params:{days?:number}&AnalyticsLogFilters = {}):Promise<AnalyticsDashboard> {
  return request('/api/v1/analytics/dashboard?' + analyticsQuery({ days:params.days ?? 7, ...params }), { cache:'no-store' });
}

export async function fetchAnalyticsLogs(params:{page?:number;per_page?:number}&AnalyticsLogFilters = {}):Promise<{ok:boolean;items:AnalyticsLogRow[];pagination:{page:number;per_page:number;total:number;total_pages:number}}> {
  return request('/api/v1/analytics/logs?' + analyticsQuery({ page:params.page ?? 1, per_page:params.per_page ?? 25, ...params }), { cache:'no-store' });
}

/** لینک مستقیم دانلود خروجی اکسل (CSV با UTF-8 BOM) - احراز هویت با نشست فعال انجام می‌شود. */
export function analyticsExportUrl(type:'aggregate'|'logs', params:AnalyticsLogFilters = {}): string {
  return '/api/v1/analytics/export?' + analyticsQuery({ type, ...params }).toString();
}

export interface AnalyticsSettingsState { ok:boolean; raw_retention_days:number; visitor_retention_days:number; ai_logs_retention_days:number }
export async function fetchAnalyticsSettings():Promise<AnalyticsSettingsState> { return request('/api/v1/analytics/settings', { cache:'no-store' }); }
export async function saveAnalyticsSettings(rawRetentionDays:number, csrf:string):Promise<{ok:boolean;message?:string;error?:string;raw_retention_days?:number}> {
  return action('/api/v1/analytics/settings', { raw_retention_days:rawRetentionDays }, csrf);
}

// ============================================================ S9 - لاگ تلاش‌های اتصال هوش مصنوعی

export interface AiServiceLogRow { ok:number; status_code:number|null; message:string; created_at:string }
export async function fetchAiProviderLogs(provider:string, limit=15):Promise<{ok:boolean;provider:string;logs:AiServiceLogRow[]}> {
  return request('/api/v1/ai/providers/logs?' + new URLSearchParams({ provider, limit:String(limit) }), { cache:'no-store' });
}

// ============ S16 — دیدگاه‌های کاربران روی کسب‌وکارها ============
export interface CommentItem {
  id: number;
  business_id: number;
  name: string;
  rating: number;
  body: string;
  status: 'pending' | 'approved' | 'rejected';
  created_at: string;
  reviewed_at: string | null;
  business_name: string;
  business_slug: string;
  city_name_fa: string;
  city_slug: string;
}
export interface CommentListResponse {
  ok: boolean;
  items: CommentItem[];
  total: number;
  page: number;
  per_page: number;
  status: string;
  counts: { pending: number; approved: number; rejected: number };
}
export async function fetchComments(status: string, q: string, page: number): Promise<CommentListResponse> {
  const params = new URLSearchParams({ status, page: String(page) });
  if (q.trim()) params.set('q', q.trim());
  return request(`/api/v1/comments?${params.toString()}`, { cache: 'no-store' });
}
export async function moderateComment(id: number, act: 'approve' | 'reject' | 'delete', csrf: string): Promise<any> {
  return action('/api/v1/comments/moderate', { id, action: act }, csrf);
}
