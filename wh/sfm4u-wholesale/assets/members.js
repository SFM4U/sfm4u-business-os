/* SFM4U Store: draws QR codes, photo picker, scan helpers. No external requests. */
(function () {
  function drawQr(el) {
    if (typeof qrcode !== 'function') return;
    var text = el.getAttribute('data-qr') || '';
    if (!text) return;
    var qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    var svg = el.querySelector('svg');
    if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR'); }
  }
  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }
  ready(function () {
    document.querySelectorAll('[data-qr]').forEach(drawQr);

    // Camera link from a phone opened by staff: #scan=<token> fills and submits the scan box.
    var box = document.getElementById('sfm-wr-scan');
    if (box && location.hash.indexOf('#scan=') === 0) {
      box.value = decodeURIComponent(location.hash.slice(6));
      history.replaceState(null, '', location.pathname + location.search);
      box.form.submit();
    }
    if (box) { box.focus(); box.select(); }

    // Photo picker (media library or phone camera upload).
    var pick = document.getElementById('sfm-wr-photo-pick');
    if (pick && window.wp && wp.media) {
      var frame;
      pick.addEventListener('click', function () {
        if (!frame) {
          frame = wp.media({ title: pick.textContent, library: { type: 'image' }, multiple: false });
          frame.on('select', function () {
            var a = frame.state().get('selection').first().toJSON();
            document.getElementById('sfm-wr-photo-id').value = a.id;
            var img = document.getElementById('sfm-wr-photo-preview');
            img.src = (a.sizes && a.sizes.thumbnail ? a.sizes.thumbnail.url : a.url);
            img.style.display = 'inline-block';
          });
        }
        frame.open();
      });
    }
  });
})();

/* Signature pad (manager details box): finger/pen drawing or an uploaded image, saved as a small transparent PNG. */
(function () {
  document.querySelectorAll('.sfm-wr-sigpad').forEach(function (cv) {
    var form = cv.closest('form'); if (!form) return;
    var out = form.querySelector('input[name="sfm_wr_signature"]');
    var ctx = cv.getContext('2d'), drawing = false, used = false, last = null;
    ctx.lineWidth = 4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
    function pos(e) { var r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; }
    cv.addEventListener('pointerdown', function (e) { drawing = true; used = true; last = pos(e); cv.setPointerCapture(e.pointerId); e.preventDefault(); });
    cv.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); last = p; e.preventDefault(); });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) { cv.addEventListener(t, function () { drawing = false; }); });
    var clr = form.querySelector('.sfm-wr-sigclear');
    if (clr) clr.addEventListener('click', function () { ctx.clearRect(0, 0, cv.width, cv.height); used = false; out.value = ''; });
    var file = form.querySelector('.sfm-wr-sigfile');
    if (file) file.addEventListener('change', function () {
      var f = file.files && file.files[0]; if (!f) return;
      var img = new Image();
      img.onload = function () {
        ctx.clearRect(0, 0, cv.width, cv.height);
        var s = Math.min(cv.width / img.width, cv.height / img.height), w = img.width * s, h = img.height * s;
        ctx.drawImage(img, (cv.width - w) / 2, (cv.height - h) / 2, w, h);
        var d = ctx.getImageData(0, 0, cv.width, cv.height), px = d.data;
        for (var i = 0; i < px.length; i += 4) { var g = (px[i] + px[i + 1] + px[i + 2]) / 3; var a = g > 170 ? 0 : Math.min(255, (190 - g) * 2); px[i] = px[i + 1] = px[i + 2] = 17; px[i + 3] = a; }
        ctx.putImageData(d, 0, 0); used = true; URL.revokeObjectURL(img.src);
      };
      img.src = URL.createObjectURL(f);
    });
    form.addEventListener('submit', function () { if (used) out.value = cv.toDataURL('image/png'); });
  });
})();

/* NID barcode scan: read the PDF417 barcode on the back of a Bangladesh NID with the phone/tab camera
   and fill English name, date of birth and NID/PIN number. Staff must still check every field. */
(function () {
  var MON = {jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,oct:10,nov:11,dec:12};
  function tag(t, n) { var m = t.match(new RegExp('<' + n + '>([^<]*)</' + n + '>', 'i')); return m ? m[1].trim() : ''; }
  function dob(s) {
    var m = s.match(/(\d{1,2})\s*([A-Za-z]{3})[A-Za-z]*\s*(\d{4})/);
    if (m && MON[m[2].toLowerCase()]) return m[3] + '-' + ('0' + MON[m[2].toLowerCase()]).slice(-2) + '-' + ('0' + m[1]).slice(-2);
    m = s.match(/(\d{4})[-\/]?(\d{2})[-\/]?(\d{2})/); return m ? m[1] + '-' + m[2] + '-' + m[3] : '';
  }
  window.sfmParseNid = function (t) {
    var out = {name: tag(t, 'name'), dob: dob(tag(t, 'DOB') || tag(t, 'dob')), nid: (tag(t, 'pin') || tag(t, 'nid') || '').replace(/\D/g, '')};
    if (!out.nid) { var n = t.match(/\b(\d{17}|\d{13}|\d{10})\b/); if (n) out.nid = n[1]; }
    return out;
  };
  document.querySelectorAll('.sfm-wr-nidscan').forEach(function (inp) {
    var form = inp.closest('form'), msg = form.querySelector('.sfm-wr-nidscan-msg');
    function say(s, bad) { if (msg) { msg.textContent = s; msg.style.color = bad ? '#b32d2e' : '#00703c'; } }
    inp.addEventListener('change', function () {
      var f = inp.files && inp.files[0]; if (!f) return;
      if (!window.ZXing) { say(inp.dataset.noLib || 'Scanner not loaded', true); return; }
      say(inp.dataset.wait || '...');
      var hints = new Map();
      hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.PDF_417, ZXing.BarcodeFormat.QR_CODE]);
      hints.set(ZXing.DecodeHintType.TRY_HARDER, true);
      var url = URL.createObjectURL(f);
      new ZXing.BrowserMultiFormatReader(hints).decodeFromImageUrl(url).then(function (res) {
        URL.revokeObjectURL(url);
        var d = window.sfmParseNid(res.getText()), got = [];
        function put(name, val) { var el = form.querySelector('[name="' + name + '"]'); if (el && val) { el.value = val; el.style.background = '#fff8c5'; got.push(name); } }
        put('sfm_wr_name_en', d.name); put('sfm_wr_dob', d.dob);
        var nidEl = form.querySelector('[name="national_id"]');
        if (nidEl && !nidEl.value && d.nid) { nidEl.value = d.nid; nidEl.style.background = '#fff8c5'; got.push('national_id'); }
        say(got.length ? (inp.dataset.ok || 'Filled from NID. Please check:') + ' ' + [d.name, d.dob, d.nid].filter(Boolean).join(' · ') : (inp.dataset.none || 'Barcode read, but no NID data found.'), !got.length);
      }).catch(function () { URL.revokeObjectURL(url); say(inp.dataset.fail || 'Could not read the barcode. Take a clear, close photo of the back of the NID.', true); });
    });
  });
})();

/* Fit cards to narrow phone screens: scale each 1012 px card to its box width (max 0.4). */
(function () {
  function fit() {
    document.querySelectorAll('.sfm-cv-scale').forEach(function (box) {
      var w = box.getBoundingClientRect().width;
      if (w > 0) box.style.setProperty('--sfm-s', Math.min(0.4, w / 1012).toFixed(4));
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fit); else fit();
  window.addEventListener('resize', fit);
  window.addEventListener('load', fit);
})();
