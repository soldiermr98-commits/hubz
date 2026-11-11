// Tests run only in jsdom with fake API responses. No production, real AI, or external network.
import assert from 'node:assert/strict';
import React, { act } from 'react';
import { JSDOM } from 'jsdom';

const dom=new JSDOM('<!doctype html><html><body><div id="root"></div></body></html>',{
  url:'http://example.test/admin/articles/',pretendToBeVisual:true,
});
Object.assign(globalThis,{
  window:dom.window,document:dom.window.document,Node:dom.window.Node,Element:dom.window.Element,
  HTMLElement:dom.window.HTMLElement,DOMParser:dom.window.DOMParser,MutationObserver:dom.window.MutationObserver,
  getComputedStyle:dom.window.getComputedStyle.bind(dom.window),
  requestAnimationFrame:dom.window.requestAnimationFrame.bind(dom.window),
  cancelAnimationFrame:dom.window.cancelAnimationFrame.bind(dom.window),IS_REACT_ACT_ENVIRONMENT:true,
});
Object.defineProperty(globalThis,'navigator',{value:dom.window.navigator,configurable:true});
dom.window.confirm=()=>true;
// ProseMirror may ask the browser for selection geometry while focusing.
if(!dom.window.Range.prototype.getClientRects) dom.window.Range.prototype.getClientRects=()=>({length:0,item:()=>null,[Symbol.iterator]:function*(){}} as unknown as DOMRectList);
if(!dom.window.Range.prototype.getBoundingClientRect) dom.window.Range.prototype.getBoundingClientRect=()=>({top:0,bottom:0,left:0,right:0,width:0,height:0,x:0,y:0,toJSON:()=>{}} as DOMRect);
const {createRoot}=await import('react-dom/client');
const {ArticlesManagerView}=await import('../src/components/ArticlesManagerView');
const {articleTextForEditor,insertArticleSuggestion,isRichArticleText,RICH_TEXT_MARKER,safeArticleHref}=await import('../src/services/articleText');

const oldText='متن قدیمی <b>بدون قالب</b> &amp; محفوظ\n\n## تیتر قدیمی\nجمله دوم';
let available=true;
let existing:any={
  id:1,status:'published',title_fa:'مقاله ساختگی',slug:'fake-article',city_id:1,category_id:1,
  intro_html:oldText,methodology_html:'توضیحات محفوظ',meta_title:'عنوان',meta_description:'شرح',cover_media_id:null,cover_url:null,faqs:[],
  tags:['سفر'],
  items:[
    {business_id:11,business_name_fa:'گزینه موجود',business_slug:'one',rank_num:1,is_sponsored:1,sponsor_label:'تبلیغ',custom_desc_html:'توضیح محفوظ'},
    {business_id:12,business_name_fa:'گزینه خصوصی',business_slug:'two',rank_num:2,is_sponsored:0,custom_desc_html:''},
  ],
  item_display:{'11':{eligibility:'eligible',business_status:'published',city_id:1,category_id:2},
    '12':{eligibility:'not_published',business_status:'draft',city_id:1,category_id:1}},
  revision:null,edit_token:'original-token',
};
const requests:Array<{url:string;method:string;body:any}>=[];
globalThis.fetch=async(input,options={})=>{
  const url=String(input),method=options.method||'GET',body=options.body?JSON.parse(String(options.body)):null;
  requests.push({url,method,body});
  const json=(data:any)=>new Response(JSON.stringify(data),{status:200,headers:{'content-type':'application/json'}});
  if(url==='/api/v1/articles'&&method==='GET')return json({ok:true,schema_ready:true,article_tags_ready:true,tag_catalog:[{id:1,name_fa:'سفر',slug:'safar'}],rich_editor_available:available,articles:[existing]});
  if(url==='/api/v1/articles'&&method==='POST'){
    existing={...existing,edit_token:'next-token',revision:{status:body.status,base_conflict:false,data:body}};
    return json({ok:true,message:'پیش‌نویس ذخیره شد.',article:existing});
  }
  throw new Error('Unexpected request: '+url);
};
const pause=(ms=0)=>new Promise(r=>setTimeout(r,ms));
const rootNode=document.getElementById('root')!;let root=createRoot(rootNode);
const props={cities:[],categories:[],csrfToken:'fake',isAdmin:true,onGoReview:()=>{},onDirtyChange:()=>{}};
function button(text:string){const b=[...document.querySelectorAll('button')].find(e=>e.textContent?.trim()===text);assert(b,'Missing button '+text);return b;}
function tool(label:string){const b=document.querySelector(`button[aria-label="${label}"]`) as HTMLButtonElement|null;assert(b,'Missing tool '+label);return b;}
async function click(el:HTMLElement){await act(async()=>{el.click();await pause();});}
async function setText(el:HTMLInputElement|HTMLTextAreaElement,value:string){
  await act(async()=>{
    const proto=el instanceof dom.window.HTMLTextAreaElement?dom.window.HTMLTextAreaElement.prototype:dom.window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto,'value')!.set!.call(el,value);
    el.dispatchEvent(new dom.window.Event('input',{bubbles:true}));await pause();
  });
}
const saves=()=>requests.filter(r=>r.url==='/api/v1/articles'&&r.method==='POST');
try {
  assert.equal(insertArticleSuggestion(oldText,'پیشنهاد','append'),oldText+'\n\nپیشنهاد','AI append must preserve legacy bytes');
  const marked=RICH_TEXT_MARKER+'<p>متن <strong>پررنگ</strong></p>';
  const appended=insertArticleSuggestion(marked,'پیشنهاد <script>بی‌خطر</script>','append');
  assert(appended.startsWith(marked),'AI append must preserve existing rich markup');
  assert(appended.includes('&lt;script&gt;')&&!appended.includes('<script>'));
  assert.equal(insertArticleSuggestion(marked,'جایگزین','replace'),'جایگزین');
  assert.equal(safeArticleHref('javascript:alert(1)'),null);assert.equal(safeArticleHref('//evil.example'),null);
  assert.equal(safeArticleHref('/rasht/fixture/'),'/rasht/fixture/');
  const legacyView=articleTextForEditor(oldText);assert(legacyView.includes('<h2>تیتر قدیمی</h2>'));
  assert(!legacyView.includes('<strong>'),'legacy tags were not formatted by the old renderer');

  await act(async()=>{root.render(<ArticlesManagerView {...props}/>);await pause(20);});
  await click(button('ویرایش'));
  assert.equal(saves().length,0,'opening must not save');
  assert(document.body.textContent?.includes('برچسب‌های موضوعی مقاله'));
  assert(document.body.textContent?.includes('سفر'),'existing article topic tags should load in the editor');
  assert(document.querySelector('[role="toolbar"][aria-label="ابزارهای متن مقاله"]'));
  assert(document.body.textContent?.includes('در سایت نمایش داده نمی‌شود: کسب‌وکار منتشرشده نیست'));
  assert(document.body.textContent?.includes('شهر یا دستهٔ این کسب‌وکار با تنظیمات مقاله متفاوت است'));
  assert(document.body.textContent?.includes('1 مورد از 2'));
  let surface=document.querySelector('.article-editor-surface') as HTMLElement & {editor:any};
  assert(surface && surface.textContent?.includes('متن قدیمی'));
  await click(button('متن / HTML'));
  const htmlBox=document.querySelector('[aria-label="متن HTML مقاله"]') as HTMLTextAreaElement;
  assert(htmlBox.value.includes('<p>')&&htmlBox.value.includes('<h2>تیتر قدیمی</h2>'),'HTML mode must represent legacy paragraphs');
  await click(button('دیداری'));
  assert.equal(saves().length,0,'changing views must not save or convert content');

  const topicInput=document.querySelector('[aria-label="برچسب موضوعی"]') as HTMLInputElement;
  await setText(topicInput,'کافه');
  await act(async()=>{topicInput.dispatchEvent(new dom.window.KeyboardEvent('keydown',{key:'Enter',bubbles:true}));await pause();});
  assert(document.body.textContent?.includes('کافه'),'new topic tag should be selectable before save');
  const check=document.querySelector('[aria-label="جایگاه اسپانسری گزینه موجود"]') as HTMLInputElement;
  await click(check);
  await click(button('ذخیره پیش‌نویس'));
  assert.equal(saves().at(-1)!.body.intro_html,oldText,'a sponsor-only edit must preserve legacy text exactly');
  assert.deepEqual(saves().at(-1)!.body.tags,['سفر','کافه'],'article tag selection must be included in the saved revision');
  assert.equal(saves().at(-1)!.body.status,'draft');
  assert.equal(saves().at(-1)!.body.edit_token,'original-token');
  assert(document.body.textContent?.includes('پیش‌نویس ذخیره شد.'));
  await click(document.querySelector('[aria-label="جایگاه اسپانسری گزینه موجود"]') as HTMLElement);
  assert(!document.body.textContent?.includes('پیش‌نویس ذخیره شد.'),'stale success must clear on the next edit');
  assert(document.body.textContent?.includes('تغییرات این فرم هنوز ذخیره یا منتشر نشده‌اند'));

  surface=document.querySelector('.article-editor-surface') as HTMLElement & {editor:any};
  const editor=surface.editor;assert(editor,'Tiptap editor did not initialize');
  await act(async()=>{editor.commands.setTextSelection({from:1,to:9});await pause();});
  await click(tool('پررنگ'));
  assert(editor.getHTML().includes('<strong>'),'bold toolbar must format selected text');
  assert.equal(saves().length,1,'formatting must never auto-save');
  const select=document.querySelector('[aria-label="نوع متن و تیتر"]') as HTMLSelectElement;
  await act(async()=>{select.value='h3';select.dispatchEvent(new dom.window.Event('change',{bubbles:true}));await pause();});
  assert(editor.getHTML().includes('<h3>'),'heading tag selection must update the editor');
  await act(async()=>{editor.commands.setTextSelection({from:1,to:5});await pause();});
  await click(tool('درج پیوند'));
  await setText(document.querySelector('[aria-label="نشانی پیوند"]') as HTMLInputElement,'javascript:alert(1)');
  await click(button('ثبت پیوند'));
  assert(document.body.textContent?.includes('نشانی باید با https://'));
  await setText(document.querySelector('[aria-label="نشانی پیوند"]') as HTMLInputElement,'https://example.test/path');
  await click(button('ثبت پیوند'));
  assert(editor.getHTML().includes('href="https://example.test/path"'),'safe selected link must be inserted');
  await click(button('ذخیره پیش‌نویس'));
  const richSent=saves().at(-1)!.body.intro_html as string;
  assert(isRichArticleText(richSent)&&richSent.includes('<strong>')&&richSent.includes('<h3>'));
  assert.equal(saves().at(-1)!.body.status,'draft');

  await click(button('متن / HTML'));
  const unsafeBox=document.querySelector('[aria-label="متن HTML مقاله"]') as HTMLTextAreaElement;
  await setText(unsafeBox,'<p onclick="alert(1)">سلام <strong>فارسی</strong><script>alert(2)</script><a href="javascript:alert(3)">پیوند</a></p>');
  await click(button('دیداری'));
  surface=document.querySelector('.article-editor-surface') as HTMLElement & {editor:any};
  assert(surface.textContent?.includes('فارسی'));
  assert(!surface.querySelector('script')&&!surface.querySelector('[onclick]')&&!surface.querySelector('[href^="javascript:"]'),'untrusted HTML must not reach the contenteditable');
  console.log('PASS: visual and HTML modes, toolbar, links, AI insertion, sponsor warnings, and unchanged legacy data');

  await act(async()=>{root.unmount();});
  available=false;
  existing={...existing,intro_html:marked,revision:null,edit_token:'read-only-token'};
  root=createRoot(rootNode);
  await act(async()=>{root.render(<ArticlesManagerView {...props}/>);await pause(20);});
  await click(button('ویرایش'));
  const readonly=document.getElementById('article-body') as HTMLTextAreaElement;
  assert(readonly.readOnly&&readonly.value===marked,'rich text must be protected when DOM capability is unavailable');
  assert(document.body.textContent?.includes('فقط‌خواندنی'));
  assert(![...document.querySelectorAll('button')].some(b=>b.textContent?.includes('نوشتن با هوش مصنوعی')),'AI insertion must not alter protected rich content');
  await click(document.querySelector('[aria-label="جایگاه اسپانسری گزینه موجود"]') as HTMLElement);
  await click(button('ذخیره پیش‌نویس'));
  assert.equal(saves().at(-1)!.body.intro_html,marked,'other fields can be saved without rewriting protected rich text');
  console.log('PASS: no-DOM fallback is read-only for existing rich content and allows sponsor-only drafts');
} finally {
  await act(async()=>{root.unmount();});dom.window.close();
}
