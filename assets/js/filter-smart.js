// 🎛️ Smart Filter — सगळ्या पानांवरच्या Filter Forms (GET) एकाच सुटसुटीत पट्टीत:
// [🔍 शोध] [⚙️ Filter (n)] + निवडलेल्या Filters चे Chips (× ने काढा). Dropdowns बटणावर — Mobile वर खालून Sheet, मोठ्या Screen वर खाली उघडणारा भाग.
// मूळ <select>/<input> तसेच राहतात (name, onchange, cascade सगळं तसंच) — फक्त जागा बदलते. नको असेल तर form ला data-no-smart-filter.
(function () {
  'use strict';
  var T = function (s) { return window.eowmsT ? window.eowmsT(s) : s; };
  var isPh = function (o) { if (!o) return true; var v = (o.value || '').trim(); return v === '' || v === '0' || /^(all|any)$/i.test(v); };
  var clean = function (s) { return String(s || '').replace(/^[\s\-–—]+|[\s\-–—]+$/g, '').replace(/\s*\(\d+\)\s*$/, '').trim(); };
  var visible = function (el) {
    var h = el.closest('[hidden]');
    if (h && !h.classList.contains('sf-panel')) return false; // बंद Filter-Panel मधले चालतात
    return !(el.type === 'hidden' || el.classList.contains('ss-grp') || el.classList.contains('ss-q'));
  };

  function fieldsOf(form) {
    return Array.prototype.slice.call(form.querySelectorAll('select, input')).filter(function (el) {
      if (!visible(el)) return false;
      if (el.tagName === 'INPUT' && /^(submit|button|reset|image|file|checkbox|radio)$/i.test(el.type)) return false;
      return true;
    });
  }
  function isSearch(el) { return el.tagName === 'INPUT' && (el.type === 'search' || (el.type === 'text' && /q|search|find|शोध/i.test((el.name || '') + ' ' + (el.placeholder || '')))); }

  function eligible(form) {
    if (form.dataset.noSmartFilter !== undefined || form.dataset.sf) return false;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') return false;
    if (form.closest('.modal, .sf-panel, table, .no-smart-filter, [data-no-smart-filter]')) return false;
    if (form.querySelector('input[type=file], textarea')) return false;
    var sels = Array.prototype.slice.call(form.querySelectorAll('select')).filter(visible);
    return sels.length >= 2;
  }

  function labelFor(el) {
    if (el.getAttribute('aria-label')) return clean(el.getAttribute('aria-label'));
    if (el.id) { var l = el.form && el.form.querySelector('label[for="' + el.id + '"]'); if (l) return clean(l.textContent); }
    var prev = el.previousElementSibling;
    if (prev && prev.tagName === 'LABEL') return clean(prev.textContent);
    var par = el.parentElement; var pl = par && par.querySelector(':scope > label');
    if (pl && !pl.contains(el)) return clean(pl.textContent);
    if (el.tagName === 'SELECT' && el.options.length && isPh(el.options[0])) return clean(el.options[0].textContent).replace(/^(सर्व|सगळे|सगळी|सगळ्या|All)\s+/i, '') || '';
    if (el.placeholder) return clean(el.placeholder.replace(/^🔍\s*/, ''));
    if (el.type === 'date') return /to|until|end|पर्यंत/i.test(el.name) ? T('पर्यंत') : (/from|start|पासून/i.test(el.name) ? T('पासून') : T('तारीख'));
    return '';
  }

  function activeOf(el) {
    if (el.tagName === 'SELECT') {
      if (el.multiple) return null;
      var o = el.options[el.selectedIndex];
      if (!o || isPh(o) || el.selectedIndex === 0 && isPh(el.options[0])) return null;
      // पहिला पर्याय "सर्व" नसलेल्या (default असलेल्या) Select चं default निवडलं असेल तर Chip नको
      if (el.selectedIndex === 0) return null;
      return clean(o.textContent);
    }
    if (!el.value) return null;
    var v = el.type === 'date' ? el.value.split('-').reverse().join('/') : el.value;
    return (labelFor(el) ? labelFor(el) + ': ' : '') + v;
  }

  function submit(form) {
    if (form.requestSubmit) { try { form.requestSubmit(); return; } catch (e) {} }
    form.submit();
  }

  function enhance(form) {
    var fields = fieldsOf(form);
    var search = fields.filter(isSearch)[0] || null;
    form.dataset.sf = '1';
    form.classList.add('sf-form');

    var bar = document.createElement('div'); bar.className = 'sf-bar';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'sf-btn';
    var chips = document.createElement('div'); chips.className = 'sf-chips';
    var panel = document.createElement('div'); panel.className = 'sf-panel'; panel.hidden = true;
    var head = document.createElement('div'); head.className = 'sf-head';
    head.innerHTML = '<b>⚙️ ' + T('Filter') + '</b>';
    var close = document.createElement('button'); close.type = 'button'; close.className = 'sf-x'; close.textContent = '✕'; close.setAttribute('aria-label', T('बंद करा'));
    head.appendChild(close);
    var grid = document.createElement('div'); grid.className = 'sf-grid';
    var foot = document.createElement('div'); foot.className = 'sf-foot';
    var clr = document.createElement('button'); clr.type = 'button'; clr.className = 'sf-clear'; clr.textContent = T('सगळे काढा');
    var apply = document.createElement('button'); apply.type = 'button'; apply.className = 'sf-apply'; apply.textContent = T('लागू करा');
    foot.appendChild(clr); foot.appendChild(apply);
    panel.appendChild(head); panel.appendChild(grid); panel.appendChild(foot);
    var back = document.createElement('div'); back.className = 'sf-back'; back.hidden = true;

    // Form मधल्या गोष्टी: शोध व Buttons पट्टीत; बाकी (Selects, तारखा, Smart-Dropdown पट्टी) Panel मध्ये — लेबलसह
    // शोध-पेटी आत खोलवर (Grid मध्ये) असली तरी पट्टीत आणा — फक्त ती एकटीच असलेला Wrapper
    var searchWrap = null;
    if (search) {
      searchWrap = search;
      while (searchWrap.parentElement && searchWrap.parentElement !== form && fieldsOf(searchWrap.parentElement).length === 1) searchWrap = searchWrap.parentElement;
      if (searchWrap.parentElement === form) searchWrap = null; // थेट Form मध्ये — खालचा नियम पाहतो
      else searchWrap.parentElement.removeChild(searchWrap);
    }
    var keep = [], pend = null;
    Array.prototype.slice.call(form.children).forEach(function (ch) {
      if (ch.tagName === 'SCRIPT' || ch.tagName === 'STYLE' || (ch.tagName === 'INPUT' && ch.type === 'hidden')) return;
      if (ch.classList.contains('sel-smart')) { pend = ch; return; } // Smart-Dropdown पट्टी — पुढच्या Select सोबत
      if (search && (ch === search || ch.contains(search)) && !ch.querySelector('select')) { keep.push(ch); return; }
      if ((ch.tagName === 'BUTTON' || (ch.tagName === 'INPUT' && /submit|button/.test(ch.type)) || ch.tagName === 'A') && !ch.querySelector('select')) { keep.push(ch); return; }
      var f = ch.matches('select, input') ? ch : ch.querySelector('select, input:not([type=hidden])');
      var many = !ch.matches('select, input') && fieldsOf(ch).length > 1;
      var cell = document.createElement(many ? 'div' : 'label'); cell.className = 'sf-cell' + (many ? ' sf-wide' : '');
      var lb = f && !many ? labelFor(f) : '';
      if (lb && !(ch.tagName === 'LABEL' || ch.querySelector('label'))) { var s = document.createElement('span'); s.textContent = lb; cell.appendChild(s); }
      if (pend) { cell.appendChild(pend); pend = null; }
      cell.appendChild(ch);
      grid.appendChild(cell);
    });
    if (!grid.children.length) { form.dataset.sf = 'skip'; return; }
    // Submit Buttons Panel मध्ये पण लागू करतात — पट्टीतले "शोधा"/"Filter" बटण असेल तर तेच
    keep.forEach(function (k) { if (k.tagName === 'BUTTON' || k.tagName === 'INPUT') { k.classList.add('sf-keep-btn'); } });

    if (searchWrap) { searchWrap.classList.add('sf-search'); bar.appendChild(searchWrap); }
    bar.appendChild(btn);
    keep.forEach(function (k) { if (k === search || k.contains(search)) { bar.insertBefore(k, btn); k.classList.add('sf-search'); } else { bar.appendChild(k); } });
    form.insertBefore(bar, form.firstChild);
    form.insertBefore(chips, bar.nextSibling);
    form.appendChild(panel);
    document.body.appendChild(back);

    function render() {
      var n = 0; chips.innerHTML = '';
      fieldsOf(grid).forEach(function (el) {
        var t = activeOf(el); if (!t) return;
        n++;
        var c = document.createElement('span'); c.className = 'sf-chip';
        c.appendChild(document.createTextNode(t.length > 34 ? t.slice(0, 33) + '…' : t));
        var x = document.createElement('button'); x.type = 'button'; x.textContent = '×'; x.setAttribute('aria-label', T('काढा'));
        x.addEventListener('click', function () {
          if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else { el.value = ''; }
          submit(form);
        });
        c.appendChild(x); chips.appendChild(c);
      });
      btn.innerHTML = '⚙️ ' + T('Filter') + (n ? ' <i>' + n + '</i>' : '');
      btn.classList.toggle('on', n > 0);
      chips.hidden = !n;
    }
    function open(v) {
      panel.hidden = !v; back.hidden = !v || window.innerWidth >= 768;
      btn.setAttribute('aria-expanded', v ? 'true' : 'false');
      document.documentElement.classList.toggle('sf-lock', v && window.innerWidth < 768);
    }
    btn.addEventListener('click', function () { open(panel.hidden); });
    close.addEventListener('click', function () { open(false); });
    back.addEventListener('click', function () { open(false); });
    apply.addEventListener('click', function () { open(false); submit(form); });
    clr.addEventListener('click', function () {
      fieldsOf(grid).forEach(function (el) { if (el.tagName === 'SELECT') { el.selectedIndex = 0; } else if (el.type !== 'hidden') { el.value = ''; } });
      open(false); submit(form);
    });
    grid.addEventListener('change', render);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !panel.hidden) open(false); });
    render();
  }

  function run(root) {
    Array.prototype.slice.call((root || document).querySelectorAll('form')).forEach(function (f) { try { if (eligible(f)) enhance(f); } catch (e) {} });
  }

  var css = '.sf-form{display:block !important;width:100%;}'
    + '.sf-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;width:100%;}'
    + '.sf-bar .sf-search,.sf-bar .sf-search input{flex:1 1 180px;min-width:0;width:100%;}'
    + '.sf-bar .sf-search{display:flex;} .sf-bar .sf-search label{display:none;}'
    + '.sf-btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface,#fff);color:var(--text);border-radius:999px;padding:7px 14px;font-size:13px;font-weight:600;white-space:nowrap;}'
    + '.sf-btn.on{border-color:var(--rail);color:var(--rail);background:color-mix(in srgb,var(--rail) 7%,transparent);}'
    + '.sf-btn i{font-style:normal;background:var(--rail);color:#fff;border-radius:999px;min-width:19px;height:19px;font-size:11px;display:inline-flex;align-items:center;justify-content:center;padding:0 5px;}'
    + '.sf-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}'
    + '.sf-chip{display:inline-flex;align-items:center;gap:4px;background:color-mix(in srgb,var(--rail) 10%,transparent);color:var(--rail);border-radius:999px;padding:3px 4px 3px 11px;font-size:12px;font-weight:600;max-width:100%;}'
    + '.sf-chip button{border:0;background:transparent;color:inherit;font-size:15px;line-height:1;width:22px;height:22px;border-radius:50%;}'
    + '.sf-chip button:hover{background:color-mix(in srgb,var(--rail) 18%,transparent);}'
    + '.sf-panel{margin-top:10px;background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:12px 14px;box-shadow:0 10px 30px rgba(16,22,43,.08);}'
    + '.sf-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;font-size:13.5px;}'
    + '.sf-x{border:0;background:transparent;font-size:16px;color:var(--text-dim);width:32px;height:32px;border-radius:50%;}'
    + '.sf-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px 12px;}'
    + '.sf-cell{display:flex;flex-direction:column;gap:4px;min-width:0;margin:0;}'
    + '.sf-cell > span{font-size:11.5px;font-weight:700;color:var(--text-dim);}'
    + '.sf-cell select,.sf-cell input{width:100% !important;max-width:100% !important;}'
    + '.sf-wide{grid-column:1/-1;}'
    + '.sf-cell > div,.sf-cell > *{min-width:0;}'
    + '.sf-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:12px;}'
    + '.sf-clear{border:1px solid var(--line);background:transparent;border-radius:10px;padding:8px 14px;font-size:13px;color:var(--text-dim);}'
    + '.sf-apply{border:0;background:var(--rail);color:#fff;border-radius:10px;padding:8px 18px;font-size:13px;font-weight:700;}'
    + '.sf-back{position:fixed;inset:0;background:rgba(10,14,30,.45);z-index:2990;}'
    + '@media (max-width:767.98px){'
    + '.sf-panel{position:fixed;left:0;right:0;bottom:0;margin:0;z-index:3000;border-radius:18px 18px 0 0;max-height:82vh;overflow:auto;padding:14px 16px calc(14px + env(safe-area-inset-bottom));animation:sfUp .18s ease-out;}'
    + '.sf-grid{grid-template-columns:1fr;}'
    + '.sf-foot{position:sticky;bottom:-14px;background:var(--surface,#fff);padding-top:10px;}'
    + '.sf-foot button{flex:1;padding:11px;}'
    + 'html.sf-lock,html.sf-lock body{overflow:hidden;}'
    + '}'
    + '@keyframes sfUp{from{transform:translateY(30px);opacity:.4}to{transform:none;opacity:1}}'
    + '@media print{.sf-bar,.sf-chips,.sf-panel{display:none !important;}}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { run(); });
  else run();
  window.eowmsSmartFilter = run;
})();
