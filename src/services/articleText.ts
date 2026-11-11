import DOMPurify from 'dompurify';

// The marker keeps existing article text on its original rendering path until
// the author explicitly edits it. Old PHP versions strip it and show plain text.
export const RICH_TEXT_MARKER = '<!--hhx-rich-v1-->';
export const isRichArticleText = (text:string):boolean => text.startsWith(RICH_TEXT_MARKER);

const escapeHtml = (value:string):string => value.replace(/[&<>"']/g, char => ({
  '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;',
}[char] as string));

/** Keep this protocol policy in sync with ArticleText::validHref. */
export function safeArticleHref(value:string):string | null {
  const href=value.trim();
  if (!href || href.length>2048 || /[\x00-\x20\x7f\\]/.test(href)) return null;
  if (href.startsWith('/')) return href.startsWith('//') ? null : href;
  try {
    const url=new URL(href);
    if (!['http:','https:'].includes(url.protocol) || !url.hostname || url.username || url.password) return null;
    return url.href;
  } catch { return null; }
}

/**
 * Clean before the contenteditable ever sees server data or pasted HTML. The
 * server independently sanitizes on save and on render; this is not a trust boundary.
 */
export function sanitizeEditorHtml(value:string):string {
  const html=DOMPurify.sanitize(value, {
    ALLOWED_TAGS:['p','h2','h3','h4','strong','b','em','i','u','s','del','blockquote','ul','ol','li','br','a'],
    ALLOWED_ATTR:['href','style','start'], ALLOW_DATA_ATTR:false,
  });
  const container=document.createElement('div');
  container.innerHTML=String(html);
  container.querySelectorAll('*').forEach(element=>{
    const style=element.getAttribute('style');
    if (style !== null && (!['P','H2','H3','H4'].includes(element.tagName) || !/^text-align\s*:\s*(left|right|center|justify)\s*;?$/i.test(style.trim()))) {
      element.removeAttribute('style');
    }
    const href=element.getAttribute('href');
    if (href !== null) {
      const safe=element.tagName === 'A' ? safeArticleHref(href) : null;
      if (safe) element.setAttribute('href',safe); else element.removeAttribute('href');
    }
    const start=element.getAttribute('start');
    if (start !== null && (element.tagName !== 'OL' || !/^[1-9][0-9]{0,4}$/.test(start))) element.removeAttribute('start');
  });
  return container.innerHTML;
}

/** The legacy editor supports paragraphs and lines beginning with ## or ###. */
export function plainTextToEditorHtml(value:string):string {
  const lines=value.replace(/\r\n?/g,'\n').split('\n');
  const blocks:string[]=[]; let paragraph:string[]=[];
  const flush=()=>{if(paragraph.length){blocks.push(`<p>${paragraph.map(escapeHtml).join('<br>')}</p>`);paragraph=[];}};
  for(const line of lines) {
    const trimmed=line.trim();
    if(!trimmed){flush();continue;}
    if(trimmed.startsWith('### ')){flush();blocks.push(`<h3>${escapeHtml(trimmed.slice(4))}</h3>`);}
    else if(trimmed.startsWith('## ')){flush();blocks.push(`<h2>${escapeHtml(trimmed.slice(3))}</h2>`);}
    else paragraph.push(line);
  }
  flush();return blocks.join('') || '<p></p>';
}

/** Existing data stays byte-for-byte unchanged until the author edits the body. */
export function articleTextForEditor(value:string):string {
  if(isRichArticleText(value)) return sanitizeEditorHtml(value.slice(RICH_TEXT_MARKER.length));
  // The old public renderer stripped tags, rather than interpreting them as
  // formatting. Approximate that display in the editor, without decoding
  // entities as HTML or rewriting the stored legacy value on open.
  const legacy=value.replace(/<br\s*\/?>/gi,'\n')
    .replace(/<\/(?:p|div|h[1-6]|li)>/gi,'\n\n')
    .replace(/<!--[\s\S]*?(?:-->|$)|<\?[\s\S]*?(?:\?>|$)|<\/?[a-z][^>]*>/gi,'');
  return plainTextToEditorHtml(legacy);
}

/** AI text is deliberately inserted only after an explicit action. */
export function insertArticleSuggestion(current:string, suggestion:string, mode:'append'|'replace'):string {
  if(mode === 'replace' || !current) return suggestion;
  if(!isRichArticleText(current)) return current + '\n\n' + suggestion;
  // Keep the existing marked bytes untouched. The escaped suggestion is a
  // separate block, so neither AI HTML nor legacy text can become active markup.
  return current + plainTextToEditorHtml(suggestion);
}
