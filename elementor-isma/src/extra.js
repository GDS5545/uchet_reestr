/* ISMA · Elementor — поведение страницы. Вёрстка остаётся виджетами Elementor, скрипт только оживляет её:
   калькулятор, фильтр/сортировка планировок, счётчики, появление при прокрутке, телефонная маска. */
(function () {
  var U = @@UNITS@@; // [номер, площадь, цена за м²]
  var YIELD = 0.0993, DISC = 0.10, DOWN = 0.5, MONTHS = 6;
  var REDUCED = window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var fmt = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
  var dec = function (n, d) { d = d == null ? 1 : d; return n.toLocaleString('ru-RU', { minimumFractionDigits: d, maximumFractionDigits: d }); };
  var easeOut = function (k) { return k >= 1 ? 1 : 1 - Math.pow(2, -10 * k); };
  var total = function (u) { return Math.round(u[1] * u[2]); };
  var byN = function (n) { for (var i = 0; i < U.length; i++) if (String(U[i][0]) === String(n)) return U[i]; return U[0]; };

  function ready(fn) { if (document.readyState !== 'loading') fn(); else document.addEventListener('DOMContentLoaded', fn); }

  ready(function () {
    /* ---------- появление при прокрутке (всё видно, пока не было первой прокрутки) ---------- */
    if ('IntersectionObserver' in window && !REDUCED) {
      var armed = false;
      var rio = new IntersectionObserver(function (es) {
        es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.remove('pre'); rio.unobserve(en.target); } });
      }, { threshold: .1, rootMargin: '0px 0px -6% 0px' });
      var evs = ['scroll', 'wheel', 'touchstart', 'keydown'];
      var arm = function () {
        if (armed) return; armed = true;
        $$('.isma-rv').forEach(function (el) {
          var r = el.getBoundingClientRect();
          if (r.top < innerHeight * .92) return;
          var sib = Array.prototype.indexOf.call(el.parentElement.children, el);
          el.style.transitionDelay = Math.min(sib, 6) * 70 + 'ms';
          el.classList.add('pre'); rio.observe(el);
        });
        evs.forEach(function (ev) { removeEventListener(ev, arm); });
      };
      evs.forEach(function (ev) { addEventListener(ev, arm, { passive: true }); });
    }

    /* ---------- счётчики ---------- */
    function wrapNums(root) {
      var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT), nodes = [];
      while (w.nextNode()) nodes.push(w.currentNode);
      nodes.forEach(function (n) {
        if (!/\d/.test(n.nodeValue) || (n.parentElement && n.parentElement.closest('.cnt'))) return;
        var f = document.createDocumentFragment();
        n.nodeValue.split(/(\d+(?:,\d+)?)/).forEach(function (part) {
          if (/^\d+(?:,\d+)?$/.test(part)) {
            var s = document.createElement('span'); s.className = 'cnt';
            s.dataset.to = part.replace(',', '.'); s.dataset.d = (part.split(',')[1] || '').length; s.textContent = part; f.appendChild(s);
          } else if (part) f.appendChild(document.createTextNode(part));
        });
        n.parentNode.replaceChild(f, n);
      });
    }
    function runCount(el) {
      var to = +el.dataset.to, d = +el.dataset.d, t0 = performance.now(), dur = 1100;
      var step = function (now) {
        var k = Math.min(1, (now - t0) / dur);
        el.textContent = dec(to * easeOut(k), d);
        if (k < 1) requestAnimationFrame(step); else el.textContent = dec(to, d);
      };
      requestAnimationFrame(step);
    }
    $$('.isma-count .elementor-heading-title').forEach(wrapNums);
    if ('IntersectionObserver' in window && !REDUCED) {
      var cio = new IntersectionObserver(function (es) {
        es.forEach(function (en) { if (en.isIntersecting) { cio.unobserve(en.target); runCount(en.target); } });
      }, { threshold: .6 });
      $$('.isma-count .cnt').forEach(function (el) { cio.observe(el); });
    }

    /* ---------- калькулятор ---------- */
    var cform = $('.isma-calcform');
    var cSel = cform && $('select', cform), cSw = cform && $('input[type=checkbox]', cform);
    var KINDS = { int: fmt, mln: function (v) { return dec(v / 1e6, 1); }, pct: function (v) { return dec(v, 2); }, yr: function (v) { return dec(v, 1); } };
    var prevCalc = null, calcVisible = false;
    function setCV(sel, v, k) {
      var h = $(sel + ' .elementor-heading-title'); if (!h) return;
      var s = $('.cv', h); if (!s) return; s.dataset.k = k; s.dataset.v = v; s.textContent = KINDS[k](v);
    }
    function tween(els, from, to, dur) {
      if (REDUCED) { els.forEach(function (e, i) { e.textContent = KINDS[e.dataset.k](to[i]); }); return; }
      var t0 = performance.now();
      var step = function (now) {
        var k = Math.min(1, (now - t0) / dur), e = easeOut(k);
        els.forEach(function (el, i) { el.textContent = KINDS[el.dataset.k](k >= 1 ? to[i] : from[i] + (to[i] - from[i]) * e); });
        if (k < 1) requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    }
    function renderCalc() {
      if (!cSel) return;
      var u = byN(cSel.value || 1), on = cSw ? cSw.checked : true, tot = total(u);
      var price = on ? tot * (1 - DISC) : tot, down = price * DOWN, month = (price - down) / MONTHS, income = tot * YIELD;
      setCV('.o-price', price, 'int'); setCV('.o-list', tot, 'int'); setCV('.o-down', down, 'int'); setCV('.o-month', month, 'int');
      setCV('.o-income', income, 'mln'); setCV('.o-yield', income / price * 100, 'pct'); setCV('.o-pb', price / income, 'yr');
      var els = $$('.isma-calcout .cv'), vals = els.map(function (e) { return +e.dataset.v; });
      if (!calcVisible || !prevCalc || prevCalc.length !== vals.length) { prevCalc = vals; return; }
      var from = prevCalc; prevCalc = vals; tween(els, from, vals, 700);
    }
    if (cSel) {
      if (cSw) cSw.checked = true;
      cSel.value = cSel.querySelector('option') ? cSel.options[0].value : '';
      cSel.addEventListener('change', renderCalc);
      if (cSw) cSw.addEventListener('change', renderCalc);
      $$('.isma-calcform form').forEach(function (f) { f.addEventListener('submit', function (e) { e.preventDefault(); e.stopPropagation(); }, true); });
      renderCalc();
      var panel = $('.isma-calcpanel');
      if (panel && 'IntersectionObserver' in window) {
        var pio = new IntersectionObserver(function (es) {
          es.forEach(function (en) {
            if (!en.isIntersecting) return;
            pio.disconnect(); calcVisible = true;
            var els = $$('.isma-calcout .cv'), vals = els.map(function (e) { return +e.dataset.v; });
            prevCalc = vals; tween(els, vals.map(function () { return 0; }), vals, 1100);
          });
        }, { threshold: .35 });
        pio.observe(panel);
      }
    }

    /* ---------- фильтр + сортировка планировок ---------- */
    var cards = $$('.isma-card'), stFilter = 'all', sortKey = 'n';
    function visible(c) {
      if (stFilter === 'all') return true;
      return c.classList.contains(stFilter === 'f2' ? 'fl-2' : 'st-' + stFilter);
    }
    function renderCards() {
      var list = cards.filter(visible);
      cards.forEach(function (c) { c.style.display = visible(c) ? '' : 'none'; });
      var key = {
        n: function (a, b) { return a[0] - b[0]; },
        pa: function (a, b) { return total(a) - total(b); },
        pd: function (a, b) { return total(b) - total(a); },
        s: function (a, b) { return b[1] - a[1]; }
      }[sortKey];
      list.map(function (c) { return [c, byN(+(/u-(\d+)/.exec(c.className) || [0, 1])[1])]; })
        .sort(function (x, y) { return key(x[1], y[1]); })
        .forEach(function (p, i) { p[0].style.order = i; });
    }
    var sSel = $('.isma-sortform select');
    if (sSel) { sSel.addEventListener('change', function () { sortKey = sSel.value || 'n'; renderCards(); }); }

    /* ---------- клики: чипы, «узнать», «расчёт», копирование ---------- */
    var leadSel = $('.isma-leadform select');
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('a,button');
      if (!a) return;
      var id = a.id || '';
      var m;
      if ((m = /^chip-(\w+)$/.exec(id))) {
        e.preventDefault(); stFilter = m[1];
        $$('.isma-chip').forEach(function (c) { c.classList.toggle('is-on', c.querySelector('#chip-' + stFilter) !== null); });
        renderCards(); return;
      }
      if ((m = /^ask-(\d+)$/.exec(id))) { if (leadSel) { leadSel.value = m[1]; leadSel.dispatchEvent(new Event('change')); } setTimeout(function () { var f = $('.isma-leadform input'); if (f) f.focus({ preventScroll: true }); }, 600); return; }
      if ((m = /^calc-(\d+)$/.exec(id))) { if (cSel) { cSel.value = m[1]; renderCalc(); } return; }
      if (id === 'copy-phone') {
        e.preventDefault();
        var lbl = $('.elementor-button-text', a) || a, old = lbl.textContent;
        var done = function () { lbl.textContent = a.dataset.done || '✓'; setTimeout(function () { lbl.textContent = old; }, 1800); };
        try { navigator.clipboard.writeText('+7 771 888 20 20').then(done); } catch (err) { done(); }
      }
    });
    renderCards();

    /* ---------- заявка → WhatsApp (вместо письма на почту) ---------- */
    var WAF = @@WAFORM@@;
    $$('.isma-leadform form').forEach(function (f) {
      var box = document.createElement('div'); box.className = 'isma-wa-msg'; f.parentNode.appendChild(box);
      f.addEventListener('submit', function (e) {
        e.preventDefault(); e.stopImmediatePropagation();
        var nm = f.querySelector('input[name="form_fields[name]"]'), ph = f.querySelector('input[name="form_fields[phone]"]');
        var un = f.querySelector('select[name="form_fields[unit]"]');
        var name = nm ? nm.value.trim() : '', phone = ph ? ph.value.trim() : '', digits = phone.replace(/\D/g, '');
        box.className = 'isma-wa-msg err';
        if (!name) { box.textContent = WAF.errName; if (nm) nm.focus(); return; }
        if (digits.length < 11) { box.textContent = WAF.errPhone; if (ph) ph.focus(); return; }
        var what = un && un.value ? un.options[un.selectedIndex].text : WAF.pick;
        var msg = WAF.msg.replace('{n}', name).replace('{p}', phone).replace('{u}', what);
        var url = 'https://wa.me/' + WAF.wa + '?text=' + encodeURIComponent(msg);
        box.className = 'isma-wa-msg ok'; box.innerHTML = '';
        var t = document.createElement('div'); t.textContent = WAF.done; box.appendChild(t);
        var a = document.createElement('a'); a.href = url; a.target = '_blank'; a.rel = 'noopener'; a.textContent = WAF.btn; box.appendChild(a);
        var w = window.open(url, '_blank'); if (!w) location.href = url;
      }, true);
    });

    /* ---------- телефонная маска ---------- */
    $$('.isma-leadform input[type=tel]').forEach(function (inp) {
      inp.addEventListener('input', function (e) {
        var d = e.target.value.replace(/\D/g, ''); if (d.indexOf('8') === 0) d = '7' + d.slice(1);
        if (d && d.indexOf('7') !== 0) d = '7' + d; d = d.slice(0, 11);
        var p = ['+7', d.slice(1, 4), d.slice(4, 7), d.slice(7, 9), d.slice(9, 11)].filter(function (x, i) { return i === 0 || x; });
        e.target.value = d ? p.join(' ') : '';
      });
      // Elementor принимает в телефоне только цифры и + ( ) - * #, поэтому на время отправки пробелы убираем
      var f = inp.form;
      if (f) f.addEventListener('submit', function () {
        var pretty = inp.value;
        inp.value = pretty.replace(/[^\d+]/g, '');
        setTimeout(function () { inp.value = pretty; }, 1500);
      }, true);
    });
  });
})();
