/* BosqueGrande Floating Assistant */
(function () {
  'use strict';
  var cfg = window.BGCA || {};
  var wrap = document.getElementById('bgca-wrap');
  var btn = document.getElementById('bgca-btn');
  var panel = document.getElementById('bgca-panel');
  var log = document.getElementById('bgca-log');
  var form = document.getElementById('bgca-form');
  var input = document.getElementById('bgca-in');
  var send = document.getElementById('bgca-send');
  if (!wrap || !btn || !panel || !cfg.endpoint) return;
  var history = [];

  // Share the corner: if something fixed already covers our spot, step up (at most three times).
  var steps = 0;
  function nudge() {
    if (steps >= 3) return;
    var r = btn.getBoundingClientRect();
    var el = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    if (!el || wrap.contains(el) || el === document.body || el === document.documentElement) return;
    for (var p = el; p && p !== document.body; p = p.parentElement) {
      var pos = getComputedStyle(p).position;
      if (pos === 'fixed' || pos === 'sticky') {
        steps++;
        wrap.style.bottom = (parseInt(getComputedStyle(wrap).bottom, 10) + 72) + 'px';
        setTimeout(nudge, 60);
        return;
      }
    }
  }
  setTimeout(nudge, 400);

  function say(text, who) {
    var m = document.createElement('div');
    m.className = 'bgca-m ' + (who === 'user' ? 'bgca-user' : 'bgca-bot');
    m.textContent = text;
    log.appendChild(m);
    log.scrollTop = log.scrollHeight;
    return m;
  }
  function toggle(open) {
    panel.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (open) {
      if (!log.childElementCount && cfg.greeting) say(cfg.greeting, 'bot');
      input.focus();
    } else {
      btn.focus();
    }
  }
  btn.addEventListener('click', function () { toggle(panel.hidden); });
  document.getElementById('bgca-close').addEventListener('click', function () { toggle(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) toggle(false); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var q = input.value.trim();
    if (!q || send.disabled) return;
    input.value = '';
    say(q, 'user');
    send.disabled = true;
    var waiting = say(cfg.thinking || '...', 'bot');
    var body = new FormData();
    body.append('message', q);
    body.append('history', JSON.stringify(history.slice(-6)));
    body.append('page', location.href);
    history.push({ role: 'user', text: q });
    fetch(cfg.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        var a = (d && typeof d.message === 'string' && d.message) || cfg.error;
        waiting.textContent = a;
        if (d && d.ok) history.push({ role: 'assistant', text: a });
      })
      .catch(function () { waiting.textContent = cfg.error; })
      .then(function () { send.disabled = false; input.focus(); });
  });
})();
