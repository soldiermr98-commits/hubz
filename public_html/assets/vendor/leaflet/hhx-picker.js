/**
 * HamiHubX.ir - موتور انتخاب مکان ادمین (Self-Hosted OSM Picker)
 * S17: نقشهٔ پنل مدیریت با پین قابل کشیدن (Drag) + کلیک برای انتخاب نقطه + جابه‌جایی نقشه
 * بدون هیچ CDN خارجی؛ تایل‌های OpenStreetMap؛ کاملاً سازگار با CSP سایت.
 *
 * API:
 *   window.HHXPicker.render(container, options)
 *   options: { lat, lng, zoom, onChange(lat, lng) }
 *   خروجی: { setPos(lat, lng), getPos(), destroy() }
 */
(function () {
  'use strict';

  var styleInjected = false;
  var STYLE = [
    '.hhx-picker{position:relative;width:100%;height:340px;border:1px solid #cbd5e1;border-radius:10px;overflow:hidden;background:#e2e8f0;direction:ltr;cursor:grab;touch-action:none;font-family:inherit}',
    '.hhx-picker:active{cursor:grabbing}',
    '.hhx-picker-tiles{position:absolute;inset:0}',
    '.hhx-picker-tiles img{position:absolute;width:256px;height:256px;user-select:none;-webkit-user-drag:none;pointer-events:none}',
    '.hhx-picker-pin{position:absolute;width:26px;height:36px;margin-left:-13px;margin-top:-36px;cursor:grab;touch-action:none;z-index:4}',
    '.hhx-picker-pin:active{cursor:grabbing}',
    '.hhx-picker-pin::before{content:"";position:absolute;inset:0;background:#e11d48;clip-path:polygon(50% 100%,0 28%,12% 10%,50% 0,88% 10%,100% 28%);border-radius:2px}',
    '.hhx-picker-pin::after{content:"";position:absolute;left:9px;top:8px;width:8px;height:8px;background:#fff;border-radius:50%}',
    '.hhx-picker-ctrl{position:absolute;top:8px;left:8px;z-index:5;display:flex;flex-direction:column;gap:2px}',
    '.hhx-picker-btn{width:30px;height:30px;border:1px solid #94a3b8;background:#fff;color:#0f172a;font-size:16px;font-weight:700;border-radius:6px;cursor:pointer;line-height:1}',
    '.hhx-picker-btn:hover{background:#f1f5f9}',
    '.hhx-picker-attr{position:absolute;bottom:0;right:0;z-index:5;background:rgba(255,255,255,.85);font-size:10px;padding:1px 6px;color:#475569}',
    '.hhx-picker-attr a{color:#0f172a}',
    '.hhx-picker-hint{position:absolute;top:8px;right:8px;z-index:5;background:rgba(255,255,255,.9);border:1px solid #cbd5e1;border-radius:6px;font-size:11px;padding:3px 8px;color:#334155;direction:rtl}'
  ].join('\n');

  function injectStyle() {
    if (styleInjected) return;
    styleInjected = true;
    var style = document.createElement('style');
    style.textContent = STYLE;
    document.head.appendChild(style);
  }

  function lng2tileX(lng, z) {
    return ((lng + 180) / 360) * Math.pow(2, z);
  }

  function lat2tileY(lat, z) {
    var rad = (lat * Math.PI) / 180;
    return (
      ((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2) *
      Math.pow(2, z)
    );
  }

  function tileX2lng(x, z) {
    return (x / Math.pow(2, z)) * 360 - 180;
  }

  function tileY2lat(y, z) {
    var n = Math.PI - (2 * Math.PI * y) / Math.pow(2, z);
    return (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
  }

  function clamp(value, min, max) {
    return Math.min(max, Math.max(min, value));
  }

  function render(container, options) {
    if (!container) return null;
    injectStyle();
    var opts = options || {};
    var pos = {
      lat: isFinite(Number(opts.lat)) ? Number(opts.lat) : 37.2808,
      lng: isFinite(Number(opts.lng)) ? Number(opts.lng) : 49.583,
    };
    var hasPin = isFinite(Number(opts.lat)) && isFinite(Number(opts.lng));
    var zoom = clamp(isFinite(Number(opts.zoom)) ? Number(opts.zoom) : 15, 8, 18);
    var onChange = typeof opts.onChange === 'function' ? opts.onChange : null;

    container.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'hhx-picker';
    var tiles = document.createElement('div');
    tiles.className = 'hhx-picker-tiles';
    wrap.appendChild(tiles);
    var pin = document.createElement('div');
    pin.className = 'hhx-picker-pin';
    pin.style.display = hasPin ? 'block' : 'none';
    pin.title = 'برای جابه‌جایی پین را بکشید';
    wrap.appendChild(pin);
    var ctrl = document.createElement('div');
    ctrl.className = 'hhx-picker-ctrl';
    var btnIn = document.createElement('button');
    btnIn.type = 'button'; btnIn.className = 'hhx-picker-btn'; btnIn.textContent = '+'; btnIn.title = 'بزرگ‌نمایی';
    var btnOut = document.createElement('button');
    btnOut.type = 'button'; btnOut.className = 'hhx-picker-btn'; btnOut.textContent = '−'; btnOut.title = 'کوچک‌نمایی';
    ctrl.appendChild(btnIn); ctrl.appendChild(btnOut);
    wrap.appendChild(ctrl);
    var hint = document.createElement('div');
    hint.className = 'hhx-picker-hint';
    hint.textContent = hasPin ? 'پین را بکشید یا روی نقشه کلیک کنید' : 'روی نقشه کلیک کنید تا پین قرار گیرد';
    wrap.appendChild(hint);
    var attr = document.createElement('div');
    attr.className = 'hhx-picker-attr';
    var attrLink = document.createElement('a');
    attrLink.href = 'https://www.openstreetmap.org/copyright';
    attrLink.target = '_blank'; attrLink.rel = 'noopener';
    attrLink.textContent = '© OpenStreetMap';
    attr.appendChild(attrLink);
    wrap.appendChild(attr);
    container.appendChild(wrap);

    var center = { lat: hasPin ? pos.lat : 36.5, lng: hasPin ? pos.lng : 49.9 };
    if (!hasPin) zoom = clamp(zoom + 1, 8, 18);

    function draw() {
      var w = wrap.clientWidth || 600;
      var h = wrap.clientHeight || 340;
      var centerX = lng2tileX(center.lng, zoom);
      var centerY = lat2tileY(center.lat, zoom);
      tiles.innerHTML = '';
      var startX = Math.floor(centerX - w / 512) - 1;
      var endX = Math.ceil(centerX + w / 512) + 1;
      var startY = Math.floor(centerY - h / 512) - 1;
      var endY = Math.ceil(centerY + h / 512) + 1;
      var maxTile = Math.pow(2, zoom);
      for (var tx = startX; tx <= endX; tx++) {
        for (var ty = startY; ty <= endY; ty++) {
          if (ty < 0 || ty >= maxTile) continue;
          var normX = ((tx % maxTile) + maxTile) % maxTile;
          var img = document.createElement('img');
          var sub = ['a', 'b', 'c'][Math.abs(tx + ty) % 3];
          img.src = 'https://' + sub + '.tile.openstreetmap.org/' + zoom + '/' + normX + '/' + ty + '.png';
          img.style.left = Math.round(w / 2 + (tx - centerX) * 256) + 'px';
          img.style.top = Math.round(h / 2 + (ty - centerY) * 256) + 'px';
          tiles.appendChild(img);
        }
      }
      if (hasPin) {
        var mx = lng2tileX(pos.lng, zoom);
        var my = lat2tileY(pos.lat, zoom);
        pin.style.left = Math.round(w / 2 + (mx - centerX) * 256) + 'px';
        pin.style.top = Math.round(h / 2 + (my - centerY) * 256) + 'px';
      }
    }

    function pixelToLatLng(px, py) {
      var w = wrap.clientWidth || 600;
      var h = wrap.clientHeight || 340;
      var centerX = lng2tileX(center.lng, zoom);
      var centerY = lat2tileY(center.lat, zoom);
      var x = centerX + (px - w / 2) / 256;
      var y = centerY + (py - h / 2) / 256;
      return {
        lat: clamp(tileY2lat(y, zoom), -85, 85),
        lng: tileX2lng(x, zoom),
      };
    }

    function report() {
      if (onChange) onChange(pos.lat, pos.lng);
    }

    function setPos(lat, lng) {
      if (!isFinite(Number(lat)) || !isFinite(Number(lng))) return;
      pos = { lat: Number(lat), lng: Number(lng) };
      hasPin = true;
      pin.style.display = 'block';
      center = { lat: pos.lat, lng: pos.lng };
      hint.textContent = 'پین را بکشید یا روی نقشه کلیک کنید';
      draw();
    }

    // جابه‌جایی نقشه با ماوس/لمس
    var dragging = false;
    var dragStart = null;
    wrap.addEventListener('pointerdown', function (ev) {
      if (ev.target === pin) {
        ev.preventDefault();
        dragging = 'pin';
        return;
      }
      if (ev.target.closest('.hhx-picker-ctrl') || ev.target.closest('.hhx-picker-attr')) return;
      dragging = 'map';
      dragStart = { x: ev.clientX, y: ev.clientY, lat: center.lat, lng: center.lng };
      wrap.setPointerCapture && wrap.setPointerCapture(ev.pointerId);
    });
    wrap.addEventListener('pointermove', function (ev) {
      if (dragging === 'map' && dragStart) {
        var dx = ev.clientX - dragStart.x;
        var dy = ev.clientY - dragStart.y;
        var w = wrap.clientWidth || 600;
        var h = wrap.clientHeight || 340;
        var centerX = lng2tileX(dragStart.lng, zoom) - dx / 256;
        var centerY = lat2tileY(dragStart.lat, zoom) - dy / 256;
        center = { lat: clamp(tileY2lat(centerY, zoom), -85, 85), lng: tileX2lng(centerX, zoom) };
        draw();
      } else if (dragging === 'pin') {
        var rect = wrap.getBoundingClientRect();
        var p = pixelToLatLng(ev.clientX - rect.left, ev.clientY - rect.top);
        pos = p;
        hasPin = true;
        draw();
      }
    });
    wrap.addEventListener('pointerup', function (ev) {
      if (dragging === 'pin') {
        var rect = wrap.getBoundingClientRect();
        setPos(
          clamp(pixelToLatLng(ev.clientX - rect.left, ev.clientY - rect.top).lat, -85, 85),
          pixelToLatLng(ev.clientX - rect.left, ev.clientY - rect.top).lng
        );
        report();
      } else if (dragging === 'map' && dragStart && Math.abs(ev.clientX - dragStart.x) < 4 && Math.abs(ev.clientY - dragStart.y) < 4) {
        // کلیک ساده (نه درگ) → انتقال پین به نقطهٔ کلیک
        var rect2 = wrap.getBoundingClientRect();
        setPos(
          pixelToLatLng(ev.clientX - rect2.left, ev.clientY - rect2.top).lat,
          pixelToLatLng(ev.clientX - rect2.left, ev.clientY - rect2.top).lng
        );
        report();
      }
      dragging = null;
      dragStart = null;
    });
    wrap.addEventListener('pointercancel', function () {
      dragging = null;
      dragStart = null;
    });

    btnIn.addEventListener('click', function () {
      if (zoom < 18) { zoom++; draw(); }
    });
    btnOut.addEventListener('click', function () {
      if (zoom > 8) { zoom--; draw(); }
    });

    if (typeof ResizeObserver === 'function') {
      new ResizeObserver(function () { draw(); }).observe(wrap);
    }

    draw();
    return { setPos: setPos, getPos: function () { return hasPin ? pos : null; }, destroy: function () { container.innerHTML = ''; } };
  }

  window.HHXPicker = { render: render };
})();
