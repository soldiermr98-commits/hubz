/**
 * HamiHubX.ir - موتور نقشه تعاملی سلف‌هاست (Self-Hosted OSM Slippy Map / Leaflet-Compatible)
 * بدون نیاز به CDN خارجی، با پشتیبانی از تایل‌های OpenStreetMap، بزرگ‌نمایی، جابه‌جایی (Pan) و پاپ‌آپ کسب‌وکارها
 */
(function () {
  'use strict';

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

  function render(container, markers, tileTemplate) {
    if (!container) return;
    var valid = (markers || []).filter(function (m) {
      return m && isFinite(Number(m.lat)) && isFinite(Number(m.lng));
    });
    var centerLat = valid.length ? Number(valid[0].lat) : 37.2808;
    var centerLng = valid.length ? Number(valid[0].lng) : 49.583;
    var zoom = valid.length === 1 ? 15 : 13;

    container.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = 'hhx-slippy-wrap';

    var tileLayer = document.createElement('div');
    tileLayer.className = 'hhx-tile-layer';
    wrap.appendChild(tileLayer);

    var markerLayer = document.createElement('div');
    markerLayer.className = 'hhx-tile-layer';
    wrap.appendChild(markerLayer);

    var popup = document.createElement('div');
    popup.className = 'hhx-popup';
    popup.style.display = 'none';
    wrap.appendChild(popup);

    var ctrl = document.createElement('div');
    ctrl.className = 'hhx-zoom-ctrl';
    var btnIn = document.createElement('button');
    btnIn.type = 'button';
    btnIn.className = 'hhx-zoom-btn';
    btnIn.textContent = '+';
    var btnOut = document.createElement('button');
    btnOut.type = 'button';
    btnOut.className = 'hhx-zoom-btn';
    btnOut.textContent = '−';
    ctrl.appendChild(btnIn);
    ctrl.appendChild(btnOut);
    wrap.appendChild(ctrl);

    var attr = document.createElement('div');
    attr.className = 'hhx-attr';
    var attrLink = document.createElement('a');
    attrLink.href = 'https://www.openstreetmap.org/copyright';
    attrLink.target = '_blank'; attrLink.rel = 'noopener';
    attrLink.textContent = '© OpenStreetMap contributors';
    attr.appendChild(attrLink);
    wrap.appendChild(attr);

    container.appendChild(wrap);

    function showPopup(m) {
      popup.style.display = 'block';
      popup.replaceChildren();
      var title = document.createElement('div');
      title.style.cssText = 'font-weight:700;color:#0f172a;margin-bottom:4px';
      title.textContent = m.name || '';
      popup.appendChild(title);
      if (m.address) {
        var address = document.createElement('div');
        address.style.cssText = 'color:#475569;font-size:11.5px;margin-bottom:6px';
        address.textContent = m.address;
        popup.appendChild(address);
      }
      var links = document.createElement('div');
      links.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap';
      if (typeof m.url === 'string' && /^\/b\/[a-z0-9-]+\/$/.test(m.url)) {
        var detail = document.createElement('a');
        detail.href = m.url;
        detail.textContent = 'مشاهده صفحه کسب‌وکار';
        links.appendChild(detail);
      }
      // S17: مجموعهٔ کامل مسیریابی در پاپ‌آپ — همه با بیکن آمار (data-hx-track="map")
      var lat = Number(m.lat);
      var lng = Number(m.lng);
      var trackAttrs = function (a, provider) {
        a.target = '_blank'; a.rel = 'noopener';
        a.setAttribute('data-hx-track', 'map');
        a.setAttribute('data-business-id', String(m.id || 0));
        a.setAttribute('data-source-page', 'map_popup');
        a.setAttribute('data-provider', provider);
      };
      var gLink = document.createElement('a');
      gLink.href = 'https://www.google.com/maps/dir/?api=1&destination=' + lat + ',' + lng;
      gLink.textContent = 'مسیریابی گوگل';
      trackAttrs(gLink, 'google');
      links.appendChild(gLink);
      var nLink = document.createElement('a');
      nLink.href = 'https://nshn.ir/?lat=' + lat + '&lng=' + lng;
      nLink.textContent = 'نشان';
      trackAttrs(nLink, 'neshan');
      links.appendChild(nLink);
      var bLink = document.createElement('a');
      bLink.href = 'https://balad.ir/location?latitude=' + lat + '&longitude=' + lng;
      bLink.textContent = 'بلد';
      trackAttrs(bLink, 'balad');
      links.appendChild(bLink);
      var sLink = document.createElement('a');
      sLink.href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent((m.name || '') + ' ' + (m.district || ''));
      sLink.textContent = 'جستجوی نام';
      trackAttrs(sLink, 'google_search');
      links.appendChild(sLink);
      popup.appendChild(links);
    }

    function draw() {
      var w = wrap.clientWidth || 600;
      var h = wrap.clientHeight || 340;
      var centerX = lng2tileX(centerLng, zoom);
      var centerY = lat2tileY(centerLat, zoom);

      tileLayer.innerHTML = '';
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
          img.className = 'hhx-tile';
          var sub = ['a', 'b', 'c'][Math.abs(tx + ty) % 3];
          img.src = tileTemplate
            .replace('{s}', sub)
            .replace('{z}', String(zoom))
            .replace('{x}', String(normX))
            .replace('{y}', String(ty));
          img.style.left = Math.round(w / 2 + (tx - centerX) * 256) + 'px';
          img.style.top = Math.round(h / 2 + (ty - centerY) * 256) + 'px';
          tileLayer.appendChild(img);
        }
      }

      markerLayer.innerHTML = '';
      valid.forEach(function (m, idx) {
        var mx = lng2tileX(Number(m.lng), zoom);
        var my = lat2tileY(Number(m.lat), zoom);
        var px = Math.round(w / 2 + (mx - centerX) * 256);
        var py = Math.round(h / 2 + (my - centerY) * 256);

        var el = document.createElement('button');
        el.type = 'button';
        el.className = 'hhx-marker';
        el.style.left = px + 'px';
        el.style.top = py + 'px';
        el.textContent = (m.rank ? m.rank + '. ' : '') + (m.name || '#' + (idx + 1));
        el.onclick = function (e) {
          e.stopPropagation();
          showPopup(m);
        };
        markerLayer.appendChild(el);
      });
    }

    btnIn.onclick = function () {
      if (zoom < 18) {
        zoom++;
        draw();
      }
    };
    btnOut.onclick = function () {
      if (zoom > 8) {
        zoom--;
        draw();
      }
    };

    draw();
    if (valid.length === 1) {
      showPopup(valid[0]);
    }
  }

  window.HHXMap = { render: render };
})();
