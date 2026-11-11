import React, { useEffect, useRef, useState } from 'react';
import { EditorContent, useEditor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import TextAlign from '@tiptap/extension-text-align';
import {
  AlignCenter, AlignJustify, AlignLeft, AlignRight, Bold, Italic, Link2, List, ListOrdered,
  Quote, Redo2, Strikethrough, Underline, Undo2, Unlink2,
} from 'lucide-react';
import { articleTextForEditor, isRichArticleText, RICH_TEXT_MARKER, safeArticleHref, sanitizeEditorHtml } from '../services/articleText';

interface Props { value:string; onChange:(value:string)=>void; disabled?:boolean }
interface ToolProps { label:string; active?:boolean; disabled?:boolean; onClick:()=>void; children:React.ReactNode }
const Tool:React.FC<ToolProps> = ({label,active,disabled,onClick,children}) => <button
  type="button" title={label} aria-label={label} aria-pressed={active} disabled={disabled}
  onMouseDown={event=>event.preventDefault()} onClick={onClick}
  className={`inline-flex h-9 min-w-9 items-center justify-center rounded border px-2 text-slate-700 disabled:opacity-40 ${active?'border-blue-500 bg-blue-100':'border-slate-300 bg-white hover:bg-slate-100'}`}
>{children}</button>;

/** Self-hosted editor: opening a legacy article never rewrites it. */
export const ArticleEditor:React.FC<Props> = ({value,onChange,disabled=false}) => {
  const [mode,setMode]=useState<'visual'|'html'>('visual');
  const [linkOpen,setLinkOpen]=useState(false); const [linkValue,setLinkValue]=useState('');
  const [linkError,setLinkError]=useState('');
  const onChangeRef=useRef(onChange);onChangeRef.current=onChange;
  const latest=useRef(value); const initial=useRef(articleTextForEditor(value));
  const editor=useEditor({
    extensions:[
      StarterKit.configure({
        heading:{levels:[2,3,4]}, code:false, codeBlock:false, horizontalRule:false,
        link:{openOnClick:false, autolink:false, linkOnPaste:false,
          HTMLAttributes:{target:null,rel:null,class:null}, isAllowedUri:url=>safeArticleHref(url)!==null},
      }),
      TextAlign.configure({types:['heading','paragraph'],alignments:['left','center','right','justify']}),
    ],
    content:initial.current,
    shouldRerenderOnTransaction:true,
    editorProps:{
      attributes:{class:'article-editor-surface',role:'textbox','aria-label':'متن مقاله','aria-multiline':'true',dir:'rtl'},
      transformPastedHTML:html=>sanitizeEditorHtml(html),
    },
    onUpdate:({editor:current})=>{
      const next=RICH_TEXT_MARKER+current.getHTML();
      latest.current=next;onChangeRef.current(next);
    },
  },[]);
  useEffect(()=>{
    if(!editor || latest.current===value)return;
    latest.current=value;
    editor.commands.setContent(articleTextForEditor(value),{emitUpdate:false});
  },[editor,value]);
  useEffect(()=>{if(editor)editor.setEditable(!disabled,false);},[editor,disabled]);

  const openLink=()=>{
    if(!editor)return;
    if(editor.state.selection.empty && !editor.isActive('link')) {
      setLinkError('ابتدا متن مورد نظر را در ویرایشگر انتخاب کنید.');return;
    }
    setLinkError('');setLinkValue(String(editor.getAttributes('link').href||''));setLinkOpen(true);
  };
  const applyLink=()=>{
    if(!editor)return;
    const safe=safeArticleHref(linkValue);
    if(!safe){setLinkError('نشانی باید با https:// یا http:// شروع شود، یا یک مسیر داخلی با / باشد.');return;}
    editor.chain().focus().extendMarkRange('link').setLink({href:safe}).run();
    setLinkOpen(false);setLinkError('');
  };
  const heading=editor?.isActive('heading',{level:2})?'h2':editor?.isActive('heading',{level:3})?'h3':editor?.isActive('heading',{level:4})?'h4':'paragraph';
  return <div className="space-y-2">
    <div className="flex items-center justify-between gap-3 flex-wrap">
      <span className="text-sm font-bold">متن مقاله</span>
      <div role="group" aria-label="حالت ویرایش مقاله" className="flex gap-1 text-xs">
        <button type="button" aria-pressed={mode==='visual'} className={`rounded border px-3 py-1.5 ${mode==='visual'?'bg-blue-700 text-white border-blue-700':'bg-white border-slate-300'}`} onClick={()=>setMode('visual')}>دیداری</button>
        <button type="button" aria-pressed={mode==='html'} className={`rounded border px-3 py-1.5 ${mode==='html'?'bg-blue-700 text-white border-blue-700':'bg-white border-slate-300'}`} onClick={()=>setMode('html')}>متن / HTML</button>
      </div>
    </div>
    {mode==='visual' ? <div className="rounded-lg border border-slate-300 overflow-hidden bg-white">
      <div role="toolbar" aria-label="ابزارهای متن مقاله" className="flex flex-wrap gap-1.5 border-b border-slate-200 bg-slate-50 p-2">
        <select aria-label="نوع متن و تیتر" value={heading} disabled={disabled||!editor} onChange={event=>{
          if(!editor)return;
          const type=event.target.value;
          if(type==='paragraph') editor.chain().focus().setParagraph().run();
          else editor.chain().focus().setHeading({level:Number(type.slice(1)) as 2|3|4}).run();
        }} className="rounded border border-slate-300 bg-white px-2 text-sm">
          <option value="paragraph">بند معمولی</option><option value="h2">تیتر ۲</option><option value="h3">تیتر ۳</option><option value="h4">تیتر ۴</option>
        </select>
        <Tool label="پررنگ" active={editor?.isActive('bold')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleBold().run()}><Bold size={16}/></Tool>
        <Tool label="کج" active={editor?.isActive('italic')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleItalic().run()}><Italic size={16}/></Tool>
        <Tool label="زیرخط" active={editor?.isActive('underline')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleUnderline().run()}><Underline size={16}/></Tool>
        <Tool label="خط‌خورده" active={editor?.isActive('strike')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleStrike().run()}><Strikethrough size={16}/></Tool>
        <Tool label="فهرست نقطه‌ای" active={editor?.isActive('bulletList')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleBulletList().run()}><List size={16}/></Tool>
        <Tool label="فهرست شماره‌دار" active={editor?.isActive('orderedList')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleOrderedList().run()}><ListOrdered size={16}/></Tool>
        <Tool label="نقل قول" active={editor?.isActive('blockquote')} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().toggleBlockquote().run()}><Quote size={16}/></Tool>
        <Tool label="راست‌چین" active={editor?.isActive({textAlign:'right'})} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().setTextAlign('right').run()}><AlignRight size={16}/></Tool>
        <Tool label="وسط‌چین" active={editor?.isActive({textAlign:'center'})} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().setTextAlign('center').run()}><AlignCenter size={16}/></Tool>
        <Tool label="چپ‌چین" active={editor?.isActive({textAlign:'left'})} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().setTextAlign('left').run()}><AlignLeft size={16}/></Tool>
        <Tool label="تراز دوطرفه" active={editor?.isActive({textAlign:'justify'})} disabled={disabled||!editor} onClick={()=>editor?.chain().focus().setTextAlign('justify').run()}><AlignJustify size={16}/></Tool>
        <Tool label="درج پیوند" active={editor?.isActive('link')} disabled={disabled||!editor} onClick={openLink}><Link2 size={16}/></Tool>
        <Tool label="حذف پیوند" disabled={disabled||!editor||!editor.isActive('link')} onClick={()=>editor?.chain().focus().extendMarkRange('link').unsetLink().run()}><Unlink2 size={16}/></Tool>
        <Tool label="بازگردانی" disabled={disabled||!editor||!editor.can().chain().focus().undo().run()} onClick={()=>editor?.chain().focus().undo().run()}><Undo2 size={16}/></Tool>
        <Tool label="تکرار" disabled={disabled||!editor||!editor.can().chain().focus().redo().run()} onClick={()=>editor?.chain().focus().redo().run()}><Redo2 size={16}/></Tool>
      </div>
      {linkOpen&&<div className="flex flex-wrap gap-2 items-end border-b border-slate-200 p-3">
        <label className="text-xs flex-1 min-w-48">نشانی پیوند<input autoFocus dir="ltr" aria-label="نشانی پیوند" className="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-sm" value={linkValue} onChange={event=>setLinkValue(event.target.value)} placeholder="https://example.com/"/></label>
        <button type="button" className="rounded bg-blue-700 px-3 py-2 text-sm text-white" onClick={applyLink}>ثبت پیوند</button>
        <button type="button" className="rounded border border-slate-300 px-3 py-2 text-sm" onClick={()=>{setLinkOpen(false);setLinkError('');}}>انصراف</button>
      </div>}
      {linkError&&<p role="alert" className="px-3 pt-2 text-xs text-rose-700">{linkError}</p>}
      <EditorContent editor={editor}/>
    </div> : <>
      <textarea aria-label="متن HTML مقاله" dir="rtl" disabled={disabled} className="w-full min-h-64 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm leading-8" value={isRichArticleText(value)?value.slice(RICH_TEXT_MARKER.length):articleTextForEditor(value)} onChange={event=>onChange(RICH_TEXT_MARKER+event.target.value)}/>
      <p className="text-xs leading-6 text-slate-600">با ویرایش HTML، فقط بند، تیترهای ۲ تا ۴، فهرست، قالب‌بندی متن و پیوندهای امن نگه داشته می‌شوند؛ اسکریپت و سایر ویژگی‌ها حذف می‌شوند.</p>
    </>}
    {!isRichArticleText(value) && value!=='' && <p className="text-xs leading-6 text-amber-800">متن قبلی تا وقتی محتوای این کادر را تغییر ندهید دست‌نخورده می‌ماند. پس از ویرایش، نمایش را در پیش‌نمایش خصوصی بررسی کنید.</p>}
    <p className="text-xs leading-6 text-slate-500">انتشار همچنان فقط با انتخاب صریح مدیر انجام می‌شود. تصویر اصلی مقاله جداگانه در بخش پایین انتخاب می‌شود.</p>
  </div>;
};
