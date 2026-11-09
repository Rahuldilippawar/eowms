// 🧭 Smart Sidebar — झटपट शोध, ⭐ आवडते, उघडे Groups लक्षात ठेवणं
// सगळं या Browser पुरतं (localStorage) — Server वर काहीही साठवत नाही.
(function () {
  'use strict';
  var root = document.getElementById('navTreeRoot');
  if (!root) return;
  var FAV = 'eowmsNavFav', OPEN = 'eowmsNavOpen';
  try { localStorage.removeItem('eowmsNavRecent'); } catch (e) {}   // "अलीकडे" यादी काढली — जुना साठा साफ
  var load = function (k) { try { return JSON.parse(localStorage.getItem(k) || '[]') || []; } catch (e) { return []; } };
  var save = function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {} };
  var keyOf = function (a) { return a.pathname + a.search; };

  // मुख्य Tree मधले Links (आवडते यादी सोडून)
  function mainNodes() {
    return Array.prototype.filter.call(root.querySelectorAll('a.tree-node'), function (a) { return !a.closest('#navSmartTop'); });
  }
  function info(a) {
    var span = a.querySelector('span'), icon = a.querySelector('i');
    return { href: keyOf(a), label: (span ? span.textContent : a.textContent).trim(), icon: icon ? icon.className : 'fa-solid fa-link fa-fw' };
  }
  function makeLink(item, removable) {
    var a = document.createElement('a');
    a.href = item.href;
    a.className = 'tree-node nav-smart-item';
    if (item.href === location.pathname + location.search) a.classList.add('active');
    var i = document.createElement('i'); i.className = item.icon; a.appendChild(i);
    var s = document.createElement('span'); s.textContent = item.label; a.appendChild(s);
    if (removable) {
      var x = document.createElement('button');
      x.type = 'button'; x.className = 'nav-fav-x'; x.title = 'आवडत्यांमधून काढा'; x.textContent = '×';
      x.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggleFav(item); });
      a.appendChild(x);
    }
    return a;
  }

  // ---------- ⭐ आवडते ----------
  function renderTop() {
    var favs = load(FAV);
    var favList = document.getElementById('navFavList');
    favList.textContent = '';
    favs.forEach(function (f) { favList.appendChild(makeLink(f, true)); });
    var favKeys = favs.map(function (f) { return f.href; });
    document.getElementById('navFavWrap').hidden = !favs.length;
    document.getElementById('navSmartTop').hidden = !favs.length;
    // मुख्य यादीत ⭐ चिन्ह अद्ययावत
    mainNodes().forEach(function (a) {
      var star = a.querySelector('.nav-star');
      if (star) star.classList.toggle('on', favKeys.indexOf(keyOf(a)) !== -1);
    });
  }
  function toggleFav(item) {
    var favs = load(FAV), idx = -1;
    favs.forEach(function (f, i) { if (f.href === item.href) idx = i; });
    if (idx >= 0) favs.splice(idx, 1); else favs.push(item);
    save(FAV, favs.slice(0, 12));
    renderTop();
  }
  mainNodes().forEach(function (a) {
    if (a.target === '_blank') return;
    var star = document.createElement('button');
    star.type = 'button'; star.className = 'nav-star'; star.title = 'आवडत्यांमध्ये जोडा / काढा'; star.textContent = '★';
    star.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggleFav(info(a)); });
    a.appendChild(star);
  });

  // सध्याचं पान (उघड्या Group पर्यंत Scroll करण्यासाठी)
  var current = mainNodes().filter(function (a) { return a.classList.contains('active'); })[0];
  renderTop();

  // ---------- उघडे Groups लक्षात ठेवणं ----------
  var bodies = root.querySelectorAll('.nav-group-body');
  var openIds = load(OPEN);
  bodies.forEach(function (b) {
    if (openIds.indexOf(b.id) !== -1 && !b.classList.contains('show')) {
      b.classList.add('show');
      var hdr = root.querySelector('[data-bs-target="#' + b.id + '"]');
      if (hdr) hdr.setAttribute('aria-expanded', 'true');
    }
    b.addEventListener('shown.bs.collapse', function () { var o = load(OPEN); if (o.indexOf(b.id) === -1) { o.push(b.id); save(OPEN, o); } });
    b.addEventListener('hidden.bs.collapse', function () { save(OPEN, load(OPEN).filter(function (x) { return x !== b.id; })); });
  });
  // सध्याचं पान ज्या Group मध्ये आहे तो Group दिसेल असा Scroll
  if (current) { setTimeout(function () { try { current.scrollIntoView({ block: 'nearest' }); } catch (e) {} }, 50); }

  // ---------- 🔍 झटपट शोध ----------
  var input = document.getElementById('navQuickFind');
  if (input) {
    var before = null;
    var apply = function () {
      var q = input.value.trim().toLowerCase();
      var nodes = mainNodes();
      if (!q) {
        nodes.forEach(function (n) { n.style.display = ''; n.classList.remove('nav-hit'); });
        root.querySelectorAll('.nav-group-header, .tier-label').forEach(function (h) { h.style.display = ''; });
        if (before) { bodies.forEach(function (b) { b.classList.toggle('show', before.indexOf(b.id) !== -1); }); before = null; }
        document.getElementById('navSmartTop').hidden = false; renderTop();
        return;
      }
      if (!before) before = Array.prototype.filter.call(bodies, function (b) { return b.classList.contains('show'); }).map(function (b) { return b.id; });
      document.getElementById('navSmartTop').hidden = true;
      var first = null;
      nodes.forEach(function (n) {
        var hit = n.textContent.toLowerCase().indexOf(q) !== -1;
        n.style.display = hit ? '' : 'none';
        n.classList.toggle('nav-hit', hit && !first);
        if (hit && !first) first = n;
      });
      // जुळणारे Links असलेले Groups उघडे, बाकीचे Group-शीर्षक लपवा
      bodies.forEach(function (b) {
        var any = Array.prototype.some.call(b.querySelectorAll('a.tree-node'), function (a) { return a.style.display !== 'none'; });
        b.classList.toggle('show', any);
        var hdr = root.querySelector('[data-bs-target="#' + b.id + '"]');
        if (hdr) hdr.style.display = any ? '' : 'none';
      });
      root.querySelectorAll('.tier-label').forEach(function (t) { if (!t.closest('#navSmartTop')) t.style.display = 'none'; });
    };
    input.addEventListener('input', apply);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = ''; apply(); input.blur(); }
      if (e.key === 'Enter') {
        var hit = root.querySelector('a.tree-node.nav-hit');
        if (hit) { e.preventDefault(); location.href = hit.href; }
      }
    });
  }
})();
