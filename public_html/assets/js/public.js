/**
 * HamiHubX.ir - اسکریپت پایه فوق‌سبک سایت عمومی (< 5KB)
 * ۱. بارگذاری تنبل (Lazy-Load) نقشه Leaflet سلف‌هاست فقط در صورت کلیک کاربر روی دکمه نمایش نقشه
 * ۲. ثبت کلیک‌های تماس، نقشه، اینستاگرام، واتساپ و وب‌سایت با navigator.sendBeacon در events_daily
 * ۳. ارسال بدون رفرش فرم‌های ادعای مالکیت (Claim) و گزارش خطا (Report) در مرحله S6
 * ۴. S9: ارسال زمینهٔ آمار جامع (مسیر، رفرر، UTM، جستجوی داخلی) به همان endpoint سبک
 */
(function () {
  'use strict';

  var leafletLoaded = false;
  var leafletLoading = false;
  var pendingCallbacks = [];

  function loadLeafletAssets(done) {
    if (leafletLoaded && window.HHXMap) {
      done();
      return;
    }
    pendingCallbacks.push(done);
    if (leafletLoading) return;
    leafletLoading = true;

    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = '/assets/vendor/leaflet/leaflet.css?v=s18';
    document.head.appendChild(link);

    var script = document.createElement('script');
    script.src = '/assets/vendor/leaflet/leaflet.js?v=s18';
    script.async = true;
    script.onload = function () {
      leafletLoaded = true;
      leafletLoading = false;
      while (pendingCallbacks.length > 0) {
        var cb = pendingCallbacks.shift();
        if (typeof cb === 'function') cb();
      }
    };
    document.body.appendChild(script);
  }

  function initMapPlaceholder(holder) {
    if (!holder || holder.getAttribute('data-initialized') === '1') return;
    holder.setAttribute('data-initialized', '1');

    var rawMarkers = holder.getAttribute('data-markers') || '[]';
    var tileUrl =
      holder.getAttribute('data-tile-url') ||
      'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    var markers = [];
    try {
      markers = JSON.parse(rawMarkers);
    } catch (e) {
      markers = [];
    }

    var canvas = document.createElement('div');
    canvas.className = 'hx-map-canvas';
    holder.appendChild(canvas);

    loadLeafletAssets(function () {
      if (window.HHXMap && typeof window.HHXMap.render === 'function') {
        window.HHXMap.render(canvas, markers, tileUrl);
      }
    });
  }

  // S9: زمینهٔ غنی رهگیری — مسیر، رفرر، UTM و جستجوی داخلی؛ بدون ذخیرهٔ کوکی یا رهگیری بین‌روزی.
  function trackingContext() {
    var ctx = { path: location.pathname };
    try {
      var params = new URLSearchParams(location.search);
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term'].forEach(function (k) {
        var v = params.get(k);
        if (v) ctx.utm = ctx.utm || {}, ctx.utm[k] = String(v).slice(0, 100);
      });
      var q = params.get('q');
      if (q) ctx.search_query = String(q).slice(0, 100);
      if (document.referrer) ctx.referrer = String(document.referrer).slice(0, 300);
    } catch (e) {}
    return ctx;
  }

  function trackPageView() {
    var page = document.querySelector('[data-hx-page-view]');
    if (!page || !navigator.sendBeacon) return;
    var payload = {
      event_type: 'view',
      business_id: Number(page.getAttribute('data-business-id') || 0),
      article_id: Number(page.getAttribute('data-article-id') || 0),
      city_id: Number(page.getAttribute('data-city-id') || 0),
      category_id: Number(page.getAttribute('data-category-id') || 0)
    };
    var ctx = trackingContext();
    for (var k in ctx) payload[k] = ctx[k];
    navigator.sendBeacon('/api/v1/events/track', JSON.stringify(payload));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', trackPageView);
  else trackPageView();

  // بازگشت نرم به بالای صفحه؛ دکمه فقط پس از عبور از یک صفحه‌نمایش نمایان می‌شود.
  function initBackTop() {
    var btn = document.querySelector('[data-hx-back-top]');
    if (!btn) return;
    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    function update() {
      if (window.scrollY > window.innerHeight * 0.9) btn.setAttribute('data-visible', '1');
      else btn.removeAttribute('data-visible');
    }
    window.addEventListener('scroll', update, { passive: true });
    update();
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initBackTop);
  else initBackTop();

  // Anonymous, approximate banner counters. A view is counted once for an
  // element when its image has loaded and intersects the visible viewport;
  // reloads and bots can count again. They are not unique or billing evidence.
  function trackBanner(id, type) {
    if (!navigator.sendBeacon || !/^[1-9][0-9]*$/.test(id)) return;
    try { navigator.sendBeacon('/api/v1/public/banner-event', JSON.stringify({ banner_id: Number(id), event: type })); } catch (e) {}
  }

  function initBannerViews() {
    var banners = Array.prototype.slice.call(document.querySelectorAll('[data-hx-banner-id]'));
    if (!banners.length) return;
    var observer = null;
    function visible(element) {
      var rect = element.getBoundingClientRect();
      return rect.width > 0 && rect.height > 0 && rect.bottom > 0 && rect.right > 0 && rect.top < window.innerHeight && rect.left < window.innerWidth;
    }
    function check(element) {
      if (element.getAttribute('data-hx-banner-viewed') === '1' || document.visibilityState === 'hidden' || !visible(element)) return;
      var image = element.querySelector('img');
      if (!image || !image.complete || !image.naturalWidth) return;
      element.setAttribute('data-hx-banner-viewed', '1');
      if (observer) observer.unobserve(element);
      trackBanner(element.getAttribute('data-hx-banner-id') || '', 'view');
    }
    function checkAll() { banners.forEach(check); }
    if ('IntersectionObserver' in window) {
      observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) { if (entry.isIntersecting) check(entry.target); });
      });
      banners.forEach(function (element) { observer.observe(element); });
    } else {
      window.addEventListener('scroll', checkAll, { passive: true });
      window.addEventListener('resize', checkAll);
    }
    banners.forEach(function (element) {
      var image = element.querySelector('img');
      if (image) image.addEventListener('load', function () { check(element); });
    });
    document.addEventListener('visibilitychange', checkAll);
    checkAll();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initBannerViews);
  else initBannerViews();

  document.addEventListener('click', function (ev) {
    var target = ev.target;
    if (!target || !target.closest) return;

    var ad = target.closest('[data-hx-banner-id]');
    if (ad) trackBanner(ad.getAttribute('data-hx-banner-id') || '', 'click');

    var toggleBtn = target.closest('[data-hx-toggle-form]');
    if (toggleBtn) {
      ev.preventDefault();
      var formId = toggleBtn.getAttribute('data-hx-toggle-form');
      var formEl = formId ? document.getElementById(formId) : null;
      if (formEl) {
        formEl.style.display = formEl.style.display === 'none' ? 'block' : 'none';
      }
      return;
    }

    var mapBtn = target.closest('[data-hx-load-map], .hhx-map-trigger');
    if (mapBtn) {
      ev.preventDefault();
      var holder = mapBtn.closest('[data-hx-map], .hhx-map-box');
      initMapPlaceholder(holder);
      mapBtn.style.display = 'none';
      return;
    }

    var trackEl = target.closest('[data-hx-track], [data-track-type]');
    if (trackEl && navigator.sendBeacon) {
      var evType =
        trackEl.getAttribute('data-hx-track') ||
        trackEl.getAttribute('data-track-type') ||
        'view';
      var payload = {
        event_type: evType,
        business_id: Number(trackEl.getAttribute('data-business-id') || 0),
        article_id: Number(trackEl.getAttribute('data-article-id') || 0)
      };
      var ctx = trackingContext();
      for (var k in ctx) payload[k] = ctx[k];
      try {
        navigator.sendBeacon('/api/v1/events/track', JSON.stringify(payload));
      } catch (e) {}
    }
  });

  document.addEventListener('auxclick', function (ev) {
    var target = ev.target;
    if (ev.button !== 1 || !target || !target.closest) return;
    var ad = target.closest('[data-hx-banner-id]');
    if (ad) trackBanner(ad.getAttribute('data-hx-banner-id') || '', 'click');
  });

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form || !form.getAttribute) return;
    var formType = form.getAttribute('data-hx-public-form');
    if (!formType) return;

    ev.preventDefault();
    var msgEl = form.querySelector('[data-hx-form-msg]');
    var endpoint = formType === 'claim' ? '/api/v1/public/claim' :
      formType === 'advertising' ? '/api/v1/public/advertising-inquiry' : '/api/v1/public/comments';
    var fd = new FormData(form);
    var body = {};
    fd.forEach(function (val, key) {
      body[key] = val;
    });

    if (msgEl) {
      msgEl.style.color = '#0369a1';
      msgEl.textContent = 'در حال ارسال به صندوق بازبینی...';
    }

    var submit = form.querySelector('button[type="submit"]');
    if (submit && submit.disabled) return;
    if (submit) submit.disabled = true;
    // Cached pages must never contain a session-specific CSRF token. Obtain one
    // for this browser immediately before sending an advertising inquiry.
    var token = formType === 'advertising' ? fetch('/api/v1/auth/me', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(function (res) {
        if (!res.csrf_token) throw new Error('Missing CSRF token');
        return res.csrf_token;
      }) : Promise.resolve('');
    token.then(function (csrf) {
      var headers = { 'Content-Type': 'application/json' };
      if (csrf) { headers['X-CSRF-Token'] = csrf; body._csrf = csrf; }
      return fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body) });
    }).then(function (r) {
        return r.json();
      })
      .then(function (res) {
        if (msgEl) {
          msgEl.style.color = res.ok ? '#15803d' : '#b91c1c';
          msgEl.textContent = res.message || res.error || (res.ok ? 'درخواست ثبت شد.' : 'پاسخ نامعتبر از سرور.');
        }
        if (res.ok) {
          form.reset();
          if (formType === 'comment') {
            try { document.dispatchEvent(new CustomEvent('hx:comment-success')); } catch (e) {}
          }
        }
      })
      .catch(function () {
        if (msgEl) {
          msgEl.style.color = '#b91c1c';
          msgEl.textContent = 'خطا در ارتباط با سرور.';
        }
      }).finally(function () { if (submit) submit.disabled = false; });
  });

  // S10 — فیلتر دسته‌بندی کارت‌های «برترین کسب‌وکارها» در صفحه اصلی
  document.querySelectorAll('[data-hx-filter-tabs]').forEach(function (wrap) {
    var tabs = wrap.querySelectorAll('button[data-hx-cat]');
    if (!tabs.length) return;
    var cards = document.querySelectorAll('[data-hx-cat-card]');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var cat = tab.getAttribute('data-hx-cat') || 'all';
        tabs.forEach(function (t) { t.classList.toggle('hx-tab-active', t === tab); });
        cards.forEach(function (card) {
          card.hidden = cat !== 'all' && card.getAttribute('data-hx-cat') !== cat;
        });
      });
    });
  });
  // S16 — پس از موفقیت ثبت دیدگاه، فرم پنهان و کارت تشکر نمایش داده می‌شود
  document.addEventListener('hx:comment-success', function () {
    var form = document.getElementById('hx-comment-form');
    var done = document.getElementById('hx-comment-done');
    if (form) form.style.display = 'none';
    if (done) {
      done.style.display = 'block';
      var smooth = true;
      try { smooth = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches); } catch (e) {}
      try { done.scrollIntoView({ behavior: smooth ? 'smooth' : 'auto', block: 'center' }); } catch (e) {}
    }
  });

  // S16 — هایلایت خودکار بخش فعال در ناوبری چسبان صفحه جزئیات (با احترام به prefers-reduced-motion)
  (function () {
    var links = document.querySelectorAll('.hx-subnav a[href^="#"]');
    if (!links.length || !('IntersectionObserver' in window)) return;
    var sections = [];
    links.forEach(function (link) {
      var id = (link.getAttribute('href') || '').slice(1);
      var sec = id ? document.getElementById(id) : null;
      if (sec) sections.push({ link: link, sec: sec });
    });
    if (!sections.length) return;
    var activeTimer = null;
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        sections.forEach(function (pair) {
          var isActive = pair.sec === entry.target;
          pair.link.classList.toggle('hx-subnav-active', isActive);
        });
      });
    }, { rootMargin: '-25% 0px -60% 0px', threshold: 0 });
    sections.forEach(function (pair) { observer.observe(pair.sec); });
  })();
})();
