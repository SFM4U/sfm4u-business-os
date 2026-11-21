/* SFM4U auto print: checks for new orders and prints each slip once. Safe to edit the timing below. */
(function () {
  var cfg = window.sfmAutoPrint || {}, timer = null, busy = false;
  var box = document.getElementById('sfm-ap-on'), status = document.getElementById('sfm-ap-status'),
      log = document.getElementById('sfm-ap-log'), frame = document.getElementById('sfm-ap-frame');
  if (!box) { return; }
  function say(t) { status.textContent = t; }
  function note(t) { var li = document.createElement('li'); li.textContent = new Date().toLocaleTimeString() + ' — ' + t; log.insertBefore(li, log.firstChild); }
  function post(action, data) {
    var body = new URLSearchParams(Object.assign({ action: action, _wpnonce: cfg.nonce }, data || {}));
    return fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
  }
  function printOne(o) {
    return new Promise(function (done) {
      frame.onload = function () {
        try { frame.contentWindow.focus(); frame.contentWindow.print(); } catch (e) { note('print error #' + o.no); }
        setTimeout(done, 1500);
      };
      frame.src = o.slip;
    }).then(function () { return post('sfm_wr_mark_printed', { order: o.id }); }).then(function () { note('printed order #' + o.no); });
  }
  function tick() {
    if (busy) { return; }
    busy = true;
    post('sfm_wr_new_orders').then(function (r) {
      var list = (r && r.success && r.data) || [];
      say(list.length ? 'Printing ' + list.length + '…' : 'Waiting for new orders… (last check ' + new Date().toLocaleTimeString() + ')');
      return list.reduce(function (p, o) { return p.then(function () { return printOne(o); }); }, Promise.resolve());
    }).catch(function () { say('Could not check orders. Will try again.'); }).then(function () { busy = false; });
  }
  try { box.checked = localStorage.getItem('sfmAutoPrint') === '1'; } catch (e) {}
  function apply() {
    try { localStorage.setItem('sfmAutoPrint', box.checked ? '1' : '0'); } catch (e) {}
    clearInterval(timer);
    if (box.checked) { tick(); timer = setInterval(tick, (cfg.every || 20) * 1000); } else { say('Off.'); }
  }
  box.addEventListener('change', apply);
  apply();
})();
