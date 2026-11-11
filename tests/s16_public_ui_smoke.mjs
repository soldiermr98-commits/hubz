// تست مرورگری jsdom برای S16 — بدون شبکه و بدون داده واقعی.
// ۱) تب‌های فیلتر صفحه اصلی کارت‌ها را واقعاً پنهان/نمایان می‌کنند (علت گزارش کاربر)
// ۲) endpoint فرم دیدگاه روی /api/v1/public/comments است و بعد از موفقیت فرم پنهان می‌شود
// ۳) ناوبری چسبان (hx-subnav) با IntersectionObserver بخش فعال را علامت می‌زند
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { JSDOM } from 'jsdom';

const js = await readFile('public_html/assets/js/public.js', 'utf8');

// ---------- ۱) تب‌های دسته صفحه اصلی ----------
{
  const html = `<!doctype html><html><body>
  <div class="hx-filter-tabs" data-hx-filter-tabs role="group">
    <button type="button" class="hx-tab-active" data-hx-cat="all">همه دسته‌ها</button>
    <button type="button" data-hx-cat="beauty-salon">سالن زیبایی <span class="hx-tab-count">(۱۰)</span></button>
    <button type="button" data-hx-cat="cafe-restaurant">کافه و رستوران <span class="hx-tab-count">(۱)</span></button>
    <button type="button" data-hx-cat="villa-ecolodge">ویلا و اقامتگاه <span class="hx-tab-count">(۱)</span></button>
  </div>
  <div class="hx-grid hx-grid-3">
    <article class="hx-card hx-business-card" data-hx-cat-card data-hx-cat="beauty-salon"><a href="/">B1</a></article>
    <article class="hx-card hx-business-card" data-hx-cat-card data-hx-cat="beauty-salon"><a href="/">B2</a></article>
    <article class="hx-card hx-business-card" data-hx-cat-card data-hx-cat="cafe-restaurant"><a href="/">C1</a></article>
    <article class="hx-card hx-business-card" data-hx-cat-card data-hx-cat="villa-ecolodge"><a href="/">V1</a></article>
  </div>
  </body></html>`;
  const dom = new JSDOM(html, { url: 'https://example.test/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  w.eval(js);
  const tabs = [...w.document.querySelectorAll('button[data-hx-cat]')];
  const cards = [...w.document.querySelectorAll('[data-hx-cat-card]')];
  const cafeTab = tabs.find(t => t.getAttribute('data-hx-cat') === 'cafe-restaurant');
  cafeTab.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  const hidden = cards.filter(c => c.hidden).length;
  assert.equal(hidden, 3, 'انتخاب تب «کافه و رستوران» باید سه کارت غیرکافه را پنهان کند');
  assert.equal(cafeTab.classList.contains('hx-tab-active'), true, 'تب انتخاب‌شده فعال می‌شود');
  const allTab = tabs.find(t => t.getAttribute('data-hx-cat') === 'all');
  allTab.dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  assert.equal(cards.filter(c => c.hidden).length, 0, 'تب «همه دسته‌ها» همه کارت‌ها را برمی‌گرداند');
  console.log('OK تب‌های فیلتر صفحه اصلی');
}

// ---------- ۲) فرم دیدگاه: endpoint و پیام موفقیت ----------
{
  const html = `<!doctype html><html><body>
  <form id="hx-comment-form" data-hx-public-form="comment" action="/api/v1/public/comments" method="post">
    <input name="business_id" value="377">
    <input name="name" value="کاربر آزمون">
    <input name="rating" value="5">
    <textarea name="body">یک دیدگاه آزمایشی با متن کافی برای ارسال.</textarea>
    <input name="hx_a" value="3"><input name="hx_b" value="4"><input name="hx_ts" value="1"><input name="hx_sig" value="x">
    <input name="answer" value="7">
    <span data-hx-form-msg></span>
    <button type="submit">ارسال</button>
  </form>
  <div id="hx-comment-done" style="display:none">دریافت شد</div>
  </body></html>`;
  const dom = new JSDOM(html, { url: 'https://example.test/comment/rasht/demo/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let postedTo = '';
  w.fetch = async (url, opts) => {
    postedTo = url;
    return { json: async () => ({ ok: true, message: 'دیدگاه شما ثبت شد' }) };
  };
  w.eval(js);
  const form = w.document.getElementById('hx-comment-form');
  form.dispatchEvent(new w.Event('submit', { bubbles: true, cancelable: true }));
  await new Promise(r => setTimeout(r, 30));
  assert.equal(postedTo, '/api/v1/public/comments', 'فرم دیدگاه باید به endpoint جدید ارسال شود');
  await new Promise(r => setTimeout(r, 10));
  assert.equal(w.document.getElementById('hx-comment-form').style.display, 'none', 'پس از موفقیت فرم پنهان می‌شود');
  assert.equal(w.document.getElementById('hx-comment-done').style.display, 'block', 'کارت تشکر نمایان می‌شود');
  const msg = form.querySelector('[data-hx-form-msg]').textContent;
  assert.equal(msg, 'دیدگاه شما ثبت شد', 'پیام سرور نمایش داده می‌شود');
  console.log('OK فرم ثبت دیدگاه (تب جدید)');
}

// ---------- ۳) هایلایت بخش فعال در hx-subnav ----------
{
  const html = `<!doctype html><html><body>
  <nav class="hx-subnav"><a href="#about">معرفی</a><a href="#reviews">دیدگاه‌ها</a></nav>
  <section id="about"></section><section id="reviews"></section>
  </body></html>`;
  const dom = new JSDOM(html, { url: 'https://example.test/rasht/demo/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window;
  let cb = null;
  w.IntersectionObserver = class {
    constructor(callback) { cb = callback; }
    observe() {}
    unobserve() {}
    disconnect() {}
  };
  w.eval(js);
  const about = w.document.getElementById('about');
  const linkAbout = w.document.querySelector('a[href="#about"]');
  const linkReviews = w.document.querySelector('a[href="#reviews"]');
  cb([{ target: about, isIntersecting: true }]);
  assert.equal(linkAbout.classList.contains('hx-subnav-active'), true, 'بخش «معرفی» فعال می‌شود');
  assert.equal(linkReviews.classList.contains('hx-subnav-active'), false, 'بخش دیگر غیرفعال می‌ماند');
  console.log('OK ناوبری چسبان صفحه جزئیات');
}

console.log('==== تست‌های UI با موفقیت انجام شد ====');
