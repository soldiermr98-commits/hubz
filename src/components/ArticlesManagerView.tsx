import { PersianDateInput } from './PersianDateInput';
import React, { useEffect, useRef, useState } from 'react';
import { ArrowDown, ArrowUp, ExternalLink, Eye, FileText, FolderOpen, Plus, Save, Trash2, Upload } from 'lucide-react';
import { AiArticleAssistant } from './AiArticleAssistant';
import { ArticleEditor } from './ArticleEditor';
import { MediaDimensionGuideBox } from './MediaDimensionGuideBox';
import { MediaLibraryPickerModal } from './MediaLibraryPickerModal';
import { insertArticleSuggestion, isRichArticleText } from '../services/articleText';
import {
  ArticleBizRow, ArticleData, ArticleFaqRow, ArticleItem, ArticleItemDisplay, ArticleTopicTag, BusinessItem, CategoryItem, CityItem,
  autoBuildCityCategoryArticle, deleteArticleItem, fetchArticleBusinesses, fetchArticlesList, saveArticleItem,
  updateArticleSchema, updateArticleTagsSchema, uploadMediaWebp,
} from '../services/adminApi';

interface Props {
  cities: CityItem[];
  categories: CategoryItem[];
  csrfToken: string;
  isAdmin: boolean;
  onGoReview: () => void;
  onDirtyChange: (dirty:boolean) => void;
}
const emptyArticle = ():ArticleData => ({
  title_fa:'', intro_html:'', slug:'', city_id:null, category_id:null, meta_title:'', meta_description:'',
  methodology_html:'', cover_media_id:null, cover_url:null, items:[], faqs:[], tags:[],
});
const editorData = (a:ArticleItem):ArticleData => {
  const d = a.revision?.data || a;
  return {
    title_fa:d.title_fa || '', intro_html:d.intro_html || '', slug:d.slug || '',
    city_id:d.city_id ? Number(d.city_id) : null, category_id:d.category_id ? Number(d.category_id) : null,
    meta_title:d.meta_title || '', meta_description:d.meta_description || '', methodology_html:d.methodology_html || '',
    cover_media_id:d.cover_media_id ? Number(d.cover_media_id) : null, cover_url:d.cover_url || null,
    items:Array.isArray(d.items) ? d.items.map(i=>({...i, business_id:Number(i.business_id)})) : [],
    faqs:Array.isArray(d.faqs) ? d.faqs.map(f=>({...f})) : [],
    tags:Array.isArray(d.tags) ? d.tags.map((tag:any)=>typeof tag==='string'?tag:String(tag.name_fa||'' )).filter(Boolean) : [],
  };
};
const input = 'w-full border border-slate-300 rounded-lg bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-300';
const secondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50';
const primary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-bold text-white hover:bg-blue-800 disabled:opacity-50';
const statusLabel = (a:ArticleItem) => a.revision
  ? `منتشرشده · ${a.revision.status === 'review' ? 'تغییرات در انتظار بررسی' : 'تغییرات پیش‌نویس'}`
  : ({draft:'پیش‌نویس خصوصی',review:'در انتظار بررسی',published:'منتشرشده'}[a.status]);

export const ArticlesManagerView:React.FC<Props> = ({cities,categories,csrfToken,isAdmin,onGoReview,onDirtyChange}) => {
  const [articles,setArticles]=useState<ArticleItem[]>([]);
  const [schemaReady,setSchemaReady]=useState(true);
  const [tagsSchemaReady,setTagsSchemaReady]=useState(false);
  const [tagCatalog,setTagCatalog]=useState<ArticleTopicTag[]>([]);
  const [tagDraft,setTagDraft]=useState('');
  const [richAvailable,setRichAvailable]=useState(false);
  const [extraDisplay,setExtraDisplay]=useState<Record<string, ArticleItemDisplay>>({});
  const [loading,setLoading]=useState(true);
  const [mode,setMode]=useState<'list'|'edit'>('list');
  const [selected,setSelected]=useState<ArticleItem|null>(null);
  const [form,setForm]=useState<ArticleData>(emptyArticle);
  const [dirty,setDirty]=useState(false);
  const [busy,setBusy]=useState(false);
  const inFlight=useRef(false);
  const [message,setMessage]=useState<{error:boolean;text:string}|null>(null);
  const [autoCity,setAutoCity]=useState(0);
  const [autoCategory,setAutoCategory]=useState(0);
  const [pickerOpen,setPickerOpen]=useState(false);
  const [query,setQuery]=useState('');
  const [page,setPage]=useState(1);
  const [picker,setPicker]=useState<{businesses:BusinessItem[];total:number;per_page:number}>({businesses:[],total:0,per_page:50});
  const [pickerError,setPickerError]=useState('');
  const textRef=useRef<HTMLTextAreaElement>(null);

  const load = async ():Promise<ArticleItem[]> => {
    setLoading(true);
    try {
      const result=await fetchArticlesList(); setArticles(result.articles); setSchemaReady(result.schema_ready);
      setTagsSchemaReady(result.article_tags_ready === true);setTagCatalog(result.tag_catalog||[]);
      setRichAvailable(result.rich_editor_available === true); return result.articles;
    } catch(e) {setMessage({error:true,text:e instanceof Error?e.message:'دریافت مقالات ناموفق بود.'}); return [];}
    finally {setLoading(false);}
  };
  useEffect(()=>{void load();},[]);
  useEffect(()=>{onDirtyChange(dirty);return()=>onDirtyChange(false);},[dirty,onDirtyChange]);
  useEffect(()=>{if (!autoCity && cities.length) setAutoCity(Number(cities[0].id)); if (!autoCategory && categories.length) setAutoCategory(Number(categories[0].id));},[cities,categories,autoCity,autoCategory]);
  useEffect(()=>{
    if (!dirty) return;
    const leave=(e:BeforeUnloadEvent)=>{e.preventDefault();e.returnValue='';};
    window.addEventListener('beforeunload',leave);return()=>window.removeEventListener('beforeunload',leave);
  },[dirty]);
  useEffect(()=>{
    if (mode!=='edit' || !pickerOpen) return;
    let cancelled=false;
    const timer=window.setTimeout(async()=>{
      try {const result=await fetchArticleBusinesses(page,query,Number(form.city_id||0),Number(form.category_id||0)); if(!cancelled){setPicker(result);setPickerError('');}}
      catch(e){if(!cancelled)setPickerError(e instanceof Error?e.message:'جست‌وجوی کسب‌وکار ناموفق بود.');}
    },200);
    return()=>{cancelled=true;window.clearTimeout(timer);};
  },[mode,pickerOpen,page,query,form.city_id,form.category_id]);

  const change = (patch:Partial<ArticleData>) => {setForm(f=>({...f,...patch}));setDirty(true);setMessage(null);};
  const [mediaPickerOpen, setMediaPickerOpen] = useState(false);
  const canLeave = () => !dirty || window.confirm('تغییرات ذخیره نشده‌اند. از این صفحه خارج شوید؟');
  const openArticle = (a:ArticleItem|null) => {
    if(!canLeave()) return;
    setSelected(a);setForm(a?editorData(a):emptyArticle());setDirty(false);setPickerOpen(false);setExtraDisplay({});setTagDraft('');setMessage(null);setMode('edit');
  };
  const returnToList = () => {if(canLeave()){setMode('list');setDirty(false);setMessage(null);}};
  const updateList = (article:ArticleItem) => setArticles(prev=>[article,...prev.filter(a=>Number(a.id)!==Number(article.id))]);

  // S20: حذف کامل مقاله از دیتابیس — بازگشت‌ناپذیر؛ رکورد و همهٔ ردیف‌های وابسته واقعاً پاک می‌شوند.
  const removeArticle = async (a:ArticleItem) => {
    const title = a.revision?.data.title_fa || a.title_fa || `#${a.id}`;
    if (!window.confirm(`مقاله «${title}» برای همیشه از دیتابیس حذف شود؟\nاین عملیات بازگشت‌ناپذیر است: نسخهٔ منتشرشده، پیش‌نویس، پیش‌نمایش خصوصی و نشانی مقاله کامل پاک می‌شود و هیچ اثری از آن در سایت یا دیتابیس نمی‌ماند.`)) return;
    setBusy(true);setMessage(null);
    try {
      const res = await deleteArticleItem(Number(a.id), csrfToken);
      setMessage({error:false,text:res.message || 'مقاله کامل حذف شد.'});
      setArticles(prev=>prev.filter(x=>Number(x.id)!==Number(a.id)));
      if (selected && Number(selected.id)===Number(a.id)) { setSelected(null); setMode('list'); }
    } catch(e) {
      setMessage({error:true,text:e instanceof Error?e.message:'حذف مقاله ناموفق بود.'});
    } finally { setBusy(false); }
  };

  const save = async (status:'draft'|'review'|'published'):Promise<ArticleItem|null> => {
    if (inFlight.current) return null;
    if (!form.title_fa.trim()) {setMessage({error:true,text:'لطفاً عنوان مقاله را بنویسید.'});return null;}
    inFlight.current=true;setBusy(true);setMessage(null);
    try {
      const res=await saveArticleItem({
        ...form, id:selected?.id, edit_token:selected?.edit_token, status,
        items:form.items.map((item,index)=>({...item,rank_num:index+1})),
        faqs:form.faqs.map((faq,index)=>({...faq,sort_order:index+1})),
      },csrfToken);
      if (!res.ok || !res.article) {setMessage({error:true,text:res.error || 'ذخیره ناموفق بود.'});return null;}
      const article=res.article as ArticleItem;
      setSelected(article);setForm(editorData(article));setExtraDisplay({});setDirty(false);updateList(article);
      setMessage({error:false,text:res.message});
      return article;
    } finally {inFlight.current=false;setBusy(false);}
  };
  const preview = async () => {
    // Open synchronously, before awaiting the save, to avoid popup blockers.
    const popup=window.open('about:blank','_blank');
    const pendingStatus = selected?.revision?.status === 'review' || (!selected?.revision && selected?.status === 'review') ? 'review' : 'draft';
    const article = dirty || !selected ? await save(pendingStatus) : selected;
    if (!article) {popup?.close();return;}
    const url=`/api/v1/articles/preview/${article.id}`;
    if (popup) popup.location.href=url;
    else setMessage({error:true,text:'پنجره پیش‌نمایش مسدود شد. اجازه بازشدن پنجره را بدهید و دوباره بزنید.'});
  };
  const publish = async () => {
    const prompt=isAdmin ? 'این نسخه در سایت عمومی منتشر شود؟' : 'این نسخه برای تأیید مدیر ارسال شود؟';
    if(window.confirm(prompt)) await save(isAdmin?'published':'review');
  };
  const runSchema = async () => {
    if(!window.confirm('از فایل‌ها و دیتابیس نسخه پشتیبان تهیه کرده‌اید؟ فقط به‌روزرسانی اختصاصی مقاله و نشانی‌ها اجرا می‌شود.')) return;
    setBusy(true);const result=await updateArticleSchema(csrfToken);setBusy(false);
    setMessage({error:!result.ok,text:result.message || result.error});if(result.ok) await load();
  };
  const runTagSchema = async () => {
    if(!window.confirm('از دیتابیس نسخه پشتیبان تهیه کرده‌اید؟ این به‌روزرسانی فقط جدول‌های برچسب موضوعی مقاله را اضافه می‌کند و مقاله‌های قبلی را تغییر نمی‌دهد.')) return;
    setBusy(true);const result=await updateArticleTagsSchema(csrfToken);setBusy(false);
    setMessage({error:!result.ok,text:result.message || result.error});if(result.ok) await load();
  };
  const addTag = () => {
    const tag=tagDraft.trim();if(!tag)return;
    if(tag.length>50||/[<>]/.test(tag)){setMessage({error:true,text:'برچسب باید حداکثر ۵۰ نویسه و بدون نشانهٔ HTML باشد.'});return;}
    if(form.tags.length>=20&&!form.tags.some(t=>t.toLocaleLowerCase()===tag.toLocaleLowerCase())){setMessage({error:true,text:'برای هر مقاله حداکثر ۲۰ برچسب ثبت کنید.'});return;}
    if(form.tags.some(t=>t.toLocaleLowerCase()===tag.toLocaleLowerCase())){setTagDraft('');return;}
    change({tags:[...form.tags,tag]});setTagDraft('');
  };
  const autoBuild = async () => {
    if(!canLeave())return;
    setBusy(true);const result=await autoBuildCityCategoryArticle(autoCity,autoCategory,csrfToken);setBusy(false);
    if(!result.ok){setMessage({error:true,text:result.error || 'ساخت پیش‌نویس ناموفق بود.'});return;}
    const list=await load();const created=list.find(a=>Number(a.id)===Number(result.article_id));
    if(created){setSelected(created);setForm(editorData(created));setMode('edit');setDirty(false);}
    setMessage({error:false,text:result.message});
  };
  const upload = async (file?:File) => {
    if(!file)return;
    setBusy(true);const result=await uploadMediaWebp(file,form.title_fa,csrfToken);setBusy(false);
    if(!result.ok){setMessage({error:true,text:result.error || 'آپلود تصویر ناموفق بود.'});return;}
    change({cover_media_id:Number(result.media.id),cover_url:result.media.file_path_800 || result.previewUrl});
    setMessage({error:false,text:'تصویر اضافه شد. برای نگه‌داشتن آن، پیش‌نویس را ذخیره کنید.'});
  };
  const insertHeading = () => {
    const el=textRef.current;if(!el)return;
    const start=el.selectionStart,end=el.selectionEnd,chosen=form.intro_html.slice(start,end);
    const prefix=start>0?'\n\n':''; const insert=`${prefix}## ${chosen}\n`;
    change({intro_html:form.intro_html.slice(0,start)+insert+form.intro_html.slice(end)});
    requestAnimationFrame(()=>{el.focus();el.setSelectionRange(start+prefix.length+3,start+prefix.length+3+chosen.length);});
  };
  const addBusiness = (b:BusinessItem) => {
    if(form.items.some(i=>Number(i.business_id)===Number(b.id)))return;
    const item:ArticleBizRow={business_id:Number(b.id),business_name_fa:b.name,business_slug:b.slug,rank_num:form.items.length+1,badge_fa:'',verdict_fa:'',custom_desc_html:'',is_sponsored:0,sponsor_label:'تبلیغ'};
    if (b.status === 'published' && b.city_id && b.category_id) setExtraDisplay(prev=>({...prev,[String(b.id)]:{
      eligibility:'eligible',business_status:'published',city_id:Number(b.city_id),category_id:Number(b.category_id),
    }}));
    change({items:[...form.items,item]});
  };
  const moveItem = (index:number, direction:number) => {
    const next=[...form.items], target=index+direction;if(target<0||target>=next.length)return;
    [next[index],next[target]]=[next[target],next[index]];change({items:next});
  };
  const updateItem = (index:number, patch:Partial<ArticleBizRow>) => change({items:form.items.map((item,i)=>i===index?{...item,...patch}:item)});
  const updateFaq = (index:number, patch:Partial<ArticleFaqRow>) => change({faqs:form.faqs.map((faq,i)=>i===index?{...faq,...patch}:faq)});
  const itemDisplay = (item:ArticleBizRow):ArticleItemDisplay|undefined => extraDisplay[String(item.business_id)] ?? selected?.item_display?.[String(item.business_id)];
  const eligibleCount = form.items.filter(item=>itemDisplay(item)?.eligibility==='eligible').length;
  const unknownCount = form.items.filter(item=>!itemDisplay(item)).length;
  const richReadOnly = !richAvailable && isRichArticleText(form.intro_html);

  return <div className="max-w-6xl mx-auto space-y-5">
    <div className="flex flex-wrap items-center justify-between gap-3">
      <div><h1 className="text-xl font-bold text-slate-900">مقاله‌ها</h1><p className="text-sm text-slate-600 mt-1">بنویسید، خصوصی ببینید و پس از بررسی منتشر کنید.</p></div>
      {mode==='list' ? <button className={primary} disabled={!schemaReady || busy} onClick={()=>openArticle(null)}><Plus size={16}/> مقاله جدید</button> : <button className={secondary} onClick={returnToList}>بازگشت به فهرست</button>}
    </div>
    {message && <div role={message.error?'alert':'status'} className={`rounded-lg p-3 text-sm ${message.error?'bg-rose-50 text-rose-800 border border-rose-200':'bg-emerald-50 text-emerald-800 border border-emerald-200'}`}>{message.text}</div>}
    {!schemaReady && <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 space-y-3">
      <p className="font-bold">به‌روزرسانی دیتابیس ویرایشگر مقاله هنوز انجام نشده است.</p>
      <p>بعد از پشتیبان‌گیری، فقط به‌روزرسانی اختصاصی مقاله و نشانی‌ها را اجرا کنید. این دکمه مایگریشن قدیمی ۰۰۳ را اجرا نمی‌کند و محتوای قبلی را منتشر یا از انتشار خارج نمی‌کند.</p>
      {isAdmin ? <button className={primary} disabled={busy} onClick={runSchema}>تکمیل به‌روزرسانی مقاله و نشانی‌ها</button> : <p>از مدیر سایت بخواهید این به‌روزرسانی را انجام دهد.</p>}
    </div>}
    {schemaReady && !tagsSchemaReady && <div role="alert" className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 space-y-3">
      <p className="font-bold">ساختار برچسب‌های موضوعی مقاله هنوز نصب نشده است.</p>
      <p>به‌روزرسانی زیر فقط دو جدول افزایشی می‌سازد؛ هیچ مقاله‌ای را منتشر نمی‌کند و وضعیت قبلی را تغییر نمی‌دهد.</p>
      {isAdmin ? <button className={primary} disabled={busy} onClick={runTagSchema}>آماده‌سازی برچسب‌های موضوعی</button> : <p>از مدیر سایت بخواهید ساختار برچسب‌ها را آماده کند.</p>}
    </div>}
    {mode==='list' ? <>
      <div className="bg-white border border-slate-200 rounded-xl overflow-hidden">
        {loading ? <p className="p-6 text-sm text-slate-500">در حال دریافت مقاله‌ها…</p> : articles.length===0 ? <div className="p-8 text-center text-slate-600"><FileText className="mx-auto mb-2"/><p>هنوز مقاله‌ای ثبت نشده است.</p><p className="text-sm mt-1">با «مقاله جدید» شروع کنید.</p></div> :
          <ul className="divide-y divide-slate-100">{articles.map(a=><li key={a.id} className="p-4 flex flex-wrap items-center justify-between gap-3">
            <div className="min-w-0"><div className="font-bold break-words">{a.revision?.data.title_fa || a.title_fa}</div><div className="text-xs text-slate-600 mt-1">{statusLabel(a)}</div></div>
            <div className="flex flex-wrap gap-2"><button className={secondary} disabled={!schemaReady} onClick={()=>openArticle(a)}>ویرایش</button><a className={secondary} href={`/api/v1/articles/preview/${a.id}`} target="_blank" rel="noreferrer"><Eye size={15}/> پیش‌نمایش خصوصی</a>{a.status==='published'&&<a className={secondary} href={`/${a.slug}/`} target="_blank" rel="noreferrer"><ExternalLink size={15}/> نسخه منتشرشده</a>}{isAdmin&&<button className="inline-flex items-center justify-center gap-2 rounded-lg border border-rose-300 bg-white px-3 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50 disabled:opacity-50" disabled={busy} onClick={()=>void removeArticle(a)}><Trash2 size={15}/> حذف کامل</button>}</div>
          </li>)}</ul>}
      </div>
      <details className="bg-white border border-slate-200 rounded-xl p-4"><summary className="cursor-pointer text-sm font-bold">ساخت پیش‌نویس فهرست کسب‌وکارها (اختیاری)</summary>
        <p className="text-xs text-slate-500 mt-2">حداکثر ۵۰ کسب‌وکار منتشرشده، به ترتیب نام، اضافه می‌شود. پیش‌نویس را قبل از انتشار بررسی و مرتب کنید.</p>
        <div className="grid sm:grid-cols-3 gap-3 mt-3"><select aria-label="شهر فهرست" className={input} value={autoCity} onChange={e=>setAutoCity(Number(e.target.value))}>{cities.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select><select aria-label="دسته فهرست" className={input} value={autoCategory} onChange={e=>setAutoCategory(Number(e.target.value))}>{categories.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select><button className={secondary} disabled={busy||!schemaReady||!autoCity||!autoCategory} onClick={autoBuild}>ساخت و باز کردن پیش‌نویس</button></div>
      </details>
    </> : <>
      <fieldset disabled={busy} className="bg-white border border-slate-200 rounded-xl p-4 sm:p-6 space-y-5 disabled:opacity-75">
        <div className="flex flex-wrap items-center justify-between gap-2"><div className="text-sm font-bold">{selected ? statusLabel(selected) : 'مقاله جدید · هنوز ذخیره نشده'}</div>{dirty&&<span className="text-xs text-amber-700">تغییرات ذخیره نشده</span>}</div>
        {selected?.revision?.base_conflict && <div role="alert" className="bg-amber-50 border border-amber-300 text-amber-900 text-sm rounded p-3">نسخه منتشرشده بعد از شروع این ویرایش تغییر کرده است. انتشار این نسخه برای جلوگیری از حذف تغییرات متوقف می‌شود؛ ابتدا دو نسخه را بررسی کنید.</div>}
        <label className="block text-sm font-bold">عنوان مقاله<input className={`${input} mt-2 text-base`} value={form.title_fa} onChange={e=>change({title_fa:e.target.value})} placeholder="مثلاً راهنمای سفر به رشت" maxLength={255}/></label>
        {!richReadOnly && <AiArticleAssistant title={form.title_fa} currentText={form.intro_html} csrfToken={csrfToken} onInsert={(text,mode)=>change({intro_html:insertArticleSuggestion(form.intro_html,text,mode)})}/>}
        {richAvailable ? <ArticleEditor key={selected?.id ?? 'new'} value={form.intro_html} onChange={text=>change({intro_html:text})} disabled={busy}/>
          : <div><div className="flex items-center justify-between gap-2"><label htmlFor="article-body" className="text-sm font-bold">متن مقاله</label>{!richReadOnly && <button type="button" className={secondary} onClick={insertHeading}>افزودن تیتر</button>}</div>
            <textarea ref={textRef} id="article-body" readOnly={richReadOnly} className={`${input} mt-2 min-h-64 leading-8`} value={form.intro_html} onChange={e=>change({intro_html:e.target.value})} placeholder="متن خود را اینجا بنویسید…"/>
            <p role="status" className="text-xs text-amber-800 mt-1">{richReadOnly
              ? 'این متن قالب‌بندی‌شده فعلاً فقط‌خواندنی است. برای ویرایش آن، API سازگار و افزونه DOM در PHP وب لازم است؛ سایر تنظیمات را می‌توانید بدون تغییر متن ذخیره کنید.'
              : 'ویرایشگر دیداری زمانی فعال می‌شود که API سازگار و افزونه DOM در PHP وب در دسترس باشند؛ متن ساده همچنان قابل ویرایش است.'}</p>
          </div>}
        <section aria-labelledby="article-tags-heading" className="rounded-lg border border-sky-200 bg-sky-50/40 p-4 space-y-3">
          <div><h2 id="article-tags-heading" className="font-bold text-sm">برچسب‌های موضوعی مقاله <span className="font-normal text-slate-500">(اختیاری)</span></h2><p className="text-xs text-slate-600 mt-1">موضوع‌های قابل استفاده را انتخاب کنید یا برچسب تازه‌ای بسازید؛ هر برچسب را Enter کنید.</p></div>
          {tagsSchemaReady ? <>
            <div className="flex flex-wrap gap-2">
              <input aria-label="برچسب موضوعی" list="article-topic-tag-options" className={`${input} min-w-48 flex-1`} maxLength={50} value={tagDraft} onChange={e=>setTagDraft(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();addTag();}}} placeholder="مثلاً گردشگری، کافه، طبیعت"/>
              <datalist id="article-topic-tag-options">{tagCatalog.map(tag=><option key={tag.id} value={tag.name_fa}/>)}</datalist>
              <button type="button" className={secondary} onClick={addTag} disabled={!tagDraft.trim()}>افزودن برچسب</button>
            </div>
            <div className="flex flex-wrap gap-2" aria-label="برچسب‌های انتخاب‌شده">
              {form.tags.map(tag=><span key={tag.toLocaleLowerCase()} className="inline-flex items-center gap-2 rounded-full border border-sky-300 bg-white px-3 py-1 text-xs text-sky-900">{tag}<button type="button" aria-label={`حذف برچسب ${tag}`} onClick={()=>change({tags:form.tags.filter(t=>t!==tag)})} className="font-bold text-slate-500 hover:text-rose-700">×</button></span>)}
              {!form.tags.length&&<span className="text-xs text-slate-500">برچسبی انتخاب نشده است.</span>}
            </div>
            {!!tagCatalog.length&&<p className="text-xs text-slate-500">برچسب‌های موجود: {tagCatalog.map(t=>t.name_fa).join('، ')}</p>}
          </> : <p className="text-xs text-amber-800">ابتدا مدیر باید ساختار برچسب‌ها را آماده کند؛ پس از آن این بخش فعال می‌شود.</p>}
        </section>
        <div className="rounded-lg border border-slate-200 p-4 space-y-3"><div className="font-bold text-sm">تصویر اصلی مقاله <span className="font-normal text-slate-500">(اختیاری · تبدیل خودکار به WebP)</span></div>
          <MediaDimensionGuideBox slot="article_cover" compact />
          {form.cover_url && <img src={form.cover_url} alt="تصویر اصلی مقاله" className="w-full max-w-sm h-40 object-cover rounded-lg"/>}
          <div className="flex flex-wrap gap-2"><label className={`${secondary} cursor-pointer`}><Upload size={15}/> انتخاب تصویر (بهینه‌سازی خودکار · GIF متحرک حفظ می‌شود)<input type="file" accept="image/jpeg,image/png,image/webp,image/gif,image/bmp,image/avif,.gif" className="sr-only" disabled={busy} onChange={e=>{void upload(e.target.files?.[0]);e.target.value='';}}/></label><button type="button" className={secondary} disabled={busy} onClick={()=>setMediaPickerOpen(true)}><FolderOpen size={15}/> انتخاب از کتابخانه رسانه</button>{form.cover_media_id&&<button type="button" className={secondary} onClick={()=>change({cover_media_id:null,cover_url:null})}>حذف تصویر</button>}</div>
          <MediaLibraryPickerModal open={mediaPickerOpen} onClose={()=>setMediaPickerOpen(false)} onSelect={item=>{change({cover_media_id:item.id,cover_url:item.file_path_800||item.file_path_1200||item.file_path_480});setMessage({error:false,text:`تصویر #${item.id} از کتابخانه انتخاب شد. برای نگه‌داشتن آن، پیش‌نویس را ذخیره کنید.`});}} />
        </div>
        <section aria-labelledby="article-sponsor-heading" className="rounded-lg border border-amber-200 bg-amber-50/40 p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 id="article-sponsor-heading" className="font-bold text-sm text-slate-900">کسب‌وکارها و جایگاه اسپانسری <span className="font-normal text-slate-500">(اختیاری)</span></h2>
              <p className="text-xs text-slate-600 mt-1 leading-6">فقط کسب‌وکارهای منتشرشده با شهر و دستهٔ معتبر در جست‌وجو می‌آیند. پیش‌نویس را اول در «کسب‌وکارها» منتشر کنید؛ اسپانسر در جدول همین مقاله و کنار نام کسب‌وکار نمایش داده می‌شود، نه به شکل بنر جداگانه.</p>
            </div>
            <button type="button" className={secondary} onClick={()=>setPickerOpen(v=>!v)}><Plus size={14}/> {pickerOpen?'بستن جست‌وجو':'افزودن کسب‌وکار'}</button>
          </div>
          {pickerOpen&&<div className="mt-3 p-3 bg-white border border-slate-200 rounded-lg"><input aria-label="جست‌وجوی کسب‌وکار منتشرشده" className={input} placeholder="جست‌وجوی کسب‌وکار منتشرشده" value={query} onChange={e=>{setQuery(e.target.value);setPage(1);}}/>{pickerError&&<p role="alert" className="text-rose-700 text-xs mt-2">{pickerError}</p>}<ul className="divide-y divide-slate-200 mt-2">{picker.businesses.map(b=><li key={b.id} className="flex justify-between items-center gap-2 py-2 text-sm"><span>{b.name} <small className="text-slate-500">{b.city_name}</small></span><button type="button" className={secondary} disabled={form.items.some(i=>Number(i.business_id)===Number(b.id))} onClick={()=>addBusiness(b)}>افزودن</button></li>)}</ul><div className="flex items-center gap-3 text-xs mt-2"><button type="button" className={secondary} disabled={page<=1} onClick={()=>setPage(p=>p-1)}>قبلی</button><span>صفحه {page} · {picker.total} نتیجه</span><button type="button" className={secondary} disabled={page*picker.per_page>=picker.total} onClick={()=>setPage(p=>p+1)}>بعدی</button></div></div>}
          {form.items.length===0 ? <p className="text-xs text-slate-500 mt-4">هنوز کسب‌وکاری به این مقاله اضافه نشده است.</p> : <div className="text-xs text-slate-600 mt-4 space-y-1" role="status">
            <p>{form.items.length} کسب‌وکار · ترتیب را با پیکان‌ها عوض کنید.</p>
            <p>در آخرین بررسی، {eligibleCount} مورد از {form.items.length} از نظر وضعیت کسب‌وکار قابل نمایش‌اند{unknownCount ? `؛ وضعیت ${unknownCount} مورد نامشخص است` : ''}. انتشار آخرین نسخه و بازه اسپانسر نیز باید جداگانه بررسی شوند.</p>
          </div>}
          {dirty && <p role="status" className="text-xs text-amber-900 mt-2">تغییرات این فرم هنوز ذخیره یا منتشر نشده‌اند.</p>}
          {selected?.revision && <p className="text-xs text-amber-900 mt-2">این فرم نسخهٔ در حال ویرایش را نشان می‌دهد؛ نسخهٔ عمومی تا انتشار یا تأیید مدیر جداگانه باقی می‌ماند.</p>}
          {form.items.map((item,index)=>{
            const info=itemDisplay(item);
            const mismatch=!!info && (!!form.city_id && !!info.city_id && Number(form.city_id)!==info.city_id
              || !!form.category_id && !!info.category_id && Number(form.category_id)!==info.category_id);
            const visibility=info?.eligibility === 'eligible' ? 'کسب‌وکار از نظر وضعیت و شهر و دسته قابل نمایش است؛ نسخهٔ مقاله باید منتشر شود و برچسب اسپانسر به زمان‌بندی وابسته است.'
              : info?.eligibility === 'not_published' ? `در سایت نمایش داده نمی‌شود: کسب‌وکار منتشرشده نیست (${info.business_status || 'نامشخص'}).`
              : info?.eligibility === 'missing_context' ? 'در سایت نمایش داده نمی‌شود: شهر یا دستهٔ کسب‌وکار یافت نشد.'
              : info?.eligibility === 'missing' ? 'در سایت نمایش داده نمی‌شود: کسب‌وکار یافت نشد.'
              : 'وضعیت نمایش این کسب‌وکار هنوز مشخص نیست؛ پس از ذخیره و بارگذاری دوباره بررسی کنید.';
            return <div key={item.business_id} className={`border rounded-lg p-3 mt-3 space-y-3 ${Number(item.is_sponsored)?'border-amber-300 bg-amber-50':'border-slate-200 bg-white'}`}>
            <div className="flex flex-wrap items-center justify-between gap-2"><strong className="text-sm">{index+1}. {item.business_name_fa || `کسب‌وکار #${item.business_id}`}</strong><div className="flex gap-1"><button type="button" aria-label="انتقال به بالا" className={secondary} disabled={index===0} onClick={()=>moveItem(index,-1)}><ArrowUp size={14}/></button><button type="button" aria-label="انتقال به پایین" className={secondary} disabled={index===form.items.length-1} onClick={()=>moveItem(index,1)}><ArrowDown size={14}/></button><button type="button" aria-label="حذف کسب‌وکار" className={secondary} onClick={()=>change({items:form.items.filter((_,i)=>i!==index)})}><Trash2 size={14}/></button></div></div>
            <p className={`text-xs leading-6 ${info?.eligibility === 'eligible'?'text-emerald-800':'text-amber-900'}`}>{visibility}</p>
            {mismatch && <p className="text-xs leading-6 text-amber-900">شهر یا دستهٔ این کسب‌وکار با تنظیمات مقاله متفاوت است؛ ارتباط آن با موضوع مقاله را بررسی کنید.</p>}
            <label className="flex items-start gap-2 text-sm font-semibold text-amber-950"><input type="checkbox" className="mt-1" aria-label={`جایگاه اسپانسری ${item.business_name_fa || `کسب‌وکار ${item.business_id}`}`} checked={!!Number(item.is_sponsored)} onChange={e=>updateItem(index,{is_sponsored:e.target.checked?1:0})}/><span>جایگاه ویژه اسپانسری <span className="block text-xs font-normal text-slate-600 mt-1">در بازه فعال، با برچسب تبلیغ در ابتدای فهرست نشان داده می‌شود.</span></span></label>
            {!!Number(item.is_sponsored)&&<div className="rounded-lg bg-white border border-amber-200 p-3"><div className="grid sm:grid-cols-3 gap-3"><label className="text-xs">برچسب تبلیغ<input aria-label={`برچسب تبلیغ ${item.business_name_fa || `کسب‌وکار ${item.business_id}`}`} maxLength={80} className={`${input} mt-1`} value={item.sponsor_label||''} onChange={e=>updateItem(index,{sponsor_label:e.target.value})}/></label><div className="text-xs">شروع (اختیاری)<PersianDateInput label={`شروع اسپانسر ${item.business_name_fa || `کسب‌وکار ${item.business_id}`}`} withTime value={(item.sponsor_start||'').replace(' ','T').slice(0,16)} onChange={value=>updateItem(index,{sponsor_start:value||null})}/></div><div className="text-xs">پایان (اختیاری)<PersianDateInput label={`پایان اسپانسر ${item.business_name_fa || `کسب‌وکار ${item.business_id}`}`} withTime value={(item.sponsor_end||'').replace(' ','T').slice(0,16)} onChange={value=>updateItem(index,{sponsor_end:value||null})}/></div></div><p className="text-xs text-slate-500 mt-2">زمان خالی یعنی بدون محدودیت؛ ساعت طبق تنظیمات سایت است. تغییرات پس از انتشار یا تأیید مدیر نمایش داده می‌شوند.</p></div>}
            <label className="block text-xs">توضیح اختصاصی<textarea className={`${input} mt-1`} rows={2} value={item.custom_desc_html||''} onChange={e=>updateItem(index,{custom_desc_html:e.target.value})}/></label>
            <details><summary className="text-xs cursor-pointer text-slate-600">جزئیات بیشتر این گزینه</summary><div className="grid sm:grid-cols-2 gap-2 mt-2"><label className="text-xs">برچسب<input className={`${input} mt-1`} value={item.badge_fa||''} onChange={e=>updateItem(index,{badge_fa:e.target.value})}/></label><label className="text-xs">جمع‌بندی<input className={`${input} mt-1`} value={item.verdict_fa||''} onChange={e=>updateItem(index,{verdict_fa:e.target.value})}/></label></div></details>
          </div>;})}
        </section>
        <details className="border border-slate-200 rounded-lg p-4"><summary className="cursor-pointer font-bold text-sm">تنظیمات بیشتر مقاله <span className="font-normal text-slate-500">(اختیاری)</span></summary>
          <div className="space-y-5 mt-4"><div className="grid sm:grid-cols-2 gap-3">
            <label className="text-sm">شهر<select className={`${input} mt-1`} value={form.city_id||0} onChange={e=>{change({city_id:Number(e.target.value)||null});setPage(1);}}><option value="0">بدون شهر</option>{cities.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
            <label className="text-sm">دسته<select className={`${input} mt-1`} value={form.category_id||0} onChange={e=>{change({category_id:Number(e.target.value)||null});setPage(1);}}><option value="0">بدون دسته</option>{categories.map(c=><option key={c.id} value={c.id}>{c.name_fa}</option>)}</select></label>
            <label className="text-sm">نشانی لاتین <span className="text-slate-500">(خالی = خودکار)</span><input dir="ltr" className={`${input} mt-1`} value={form.slug} onChange={e=>change({slug:e.target.value})} placeholder="article-address"/></label>
            <label className="text-sm">عنوان در موتور جست‌وجو<input className={`${input} mt-1`} value={form.meta_title||''} onChange={e=>change({meta_title:e.target.value})} maxLength={255}/></label>
          </div>
          <label className="block text-sm">توضیح کوتاه در موتور جست‌وجو<textarea className={`${input} mt-1`} rows={2} value={form.meta_description||''} onChange={e=>change({meta_description:e.target.value})} maxLength={320}/></label>
          <label className="block text-sm">توضیحات تکمیلی<textarea className={`${input} mt-1`} rows={3} value={form.methodology_html} onChange={e=>change({methodology_html:e.target.value})}/></label>
          <div className="border-t border-slate-200 pt-4"><div className="font-bold text-sm">پرسش‌های متداول</div>{form.faqs.map((faq,index)=><div key={index} className="border rounded-lg p-3 mt-2 space-y-2"><input aria-label="پرسش" placeholder="پرسش" className={input} value={faq.question_fa} onChange={e=>updateFaq(index,{question_fa:e.target.value})}/><textarea aria-label="پاسخ" placeholder="پاسخ" className={input} value={faq.answer_fa} onChange={e=>updateFaq(index,{answer_fa:e.target.value})}/><button className={secondary} onClick={()=>change({faqs:form.faqs.filter((_,i)=>i!==index)})}>حذف پرسش</button></div>)}<button className={`${secondary} mt-2`} onClick={()=>change({faqs:[...form.faqs,{question_fa:'',answer_fa:'',sort_order:form.faqs.length+1}]})}><Plus size={14}/> افزودن پرسش</button></div>
          </div>
        </details>
      </fieldset>
      <div className="flex flex-wrap gap-2 sticky bottom-0 bg-slate-50/95 border-t border-slate-200 p-3 rounded-lg">
        <button className={secondary} disabled={busy||!schemaReady} onClick={()=>void save('draft')}><Save size={16}/> ذخیره پیش‌نویس</button>
        <button className={secondary} disabled={busy||!schemaReady} onClick={()=>void preview()}><Eye size={16}/> پیش‌نمایش خصوصی</button>
        <button className={primary} disabled={busy||!schemaReady} onClick={()=>void publish()}>{isAdmin?'انتشار مقاله':'ارسال برای بررسی مدیر'}</button>
        {selected?.status==='published'&&<a className={secondary} href={`/${selected.slug}/`} target="_blank" rel="noreferrer"><ExternalLink size={15}/> نسخه منتشرشده</a>}
        {selected?.revision?.status==='review' && isAdmin && <button className={secondary} onClick={onGoReview}>رفتن به صف بررسی</button>}
      </div>
      <p className="text-xs text-slate-500">پیش‌نمایش با حساب پنل باز می‌شود و عمومی نیست. پیش‌نمایشِ تغییراتِ ذخیره‌نشده ابتدا آن‌ها را به صورت پیش‌نویس ذخیره می‌کند.</p>
    </>}
  </div>;
};
