<?php
/**
 * ✍️ टिप्पणीसाठी स्मार्ट साधनं — पत्रव्यवहार (view.php मध्ये include)
 *  - हाताने लिहा: Pen / Stylus / बोटाने Screen वर लिहिलेली टिप्पणी (PNG म्हणून जतन — letterInkSave)
 *      · Pen चा दाब (Pressure) ओळखतो — दाबून लिहिलं तर रेघ जाड
 *      · Pen वापरताच "फक्त Pen" — हात Screen वर टेकला तरी रेघ उमटत नाही (Palm rejection)
 *      · रंग, जाडी, खोडरबर, Undo/Redo, सगळं पुसा; वहीसारख्या ओळी; Ctrl+Z / Ctrl+Y
 *      · लिहिलेल्या भागापुरतीच प्रतिमा (Auto-crop) — हलकी File
 *  - 🎤 बोलून लिहा: मराठी/English Voice typing (Browser मध्ये सोय असेल तर)
 *  - नेहमीचे शेरे: एका Click मध्ये ("मंजूर", "चर्चा करावी" …)
 * प्रत्येक textarea[name=remark] (आणि #signRemark) खाली आपोआप जोडले जातात — hidden input name="remark_ink".
 */
?>
<style>
  .ink-tools{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin:-2px 0 8px;}
  .ink-btn{border:1px solid var(--line);background:#fff;border-radius:99px;padding:4px 11px;font-size:12px;font-weight:600;color:var(--ink,#191C3A);cursor:pointer;line-height:1.5;}
  .ink-btn:hover{border-color:var(--rail);color:var(--rail);}
  .ink-btn.primary{background:var(--rail-soft,#FDF0E8);border-color:var(--rail);color:var(--rail);}
  .ink-btn.rec{background:#FDECEC;border-color:#B23A48;color:#B23A48;animation:inkPulse 1.2s infinite;}
  @keyframes inkPulse{50%{box-shadow:0 0 0 5px rgba(178,58,72,.15);}}
  .ink-phr{border:1px dashed var(--line);background:var(--canvas,#F7F7F9);border-radius:99px;padding:2px 9px;font-size:11.5px;color:var(--text-dim);cursor:pointer;}
  .ink-phr:hover{border-color:var(--rail);color:var(--rail);}
  .ink-prev{display:none;align-items:center;gap:8px;background:#FFFDF5;border:1px solid #EAD9B5;border-radius:10px;padding:6px 8px;margin:0 0 8px;}
  .ink-prev img{max-height:70px;max-width:70%;}
  .ink-prev small{color:var(--text-dim);font-size:11px;}
  .ink-note{display:inline-block;margin-top:6px;}
  .ink-note img{max-width:100%;max-height:170px;background:#FFFDF5;border:1px solid #EAD9B5;border-radius:10px;padding:6px;}
  #inkPadModal .modal-content{border-radius:16px;overflow:hidden;}
  .ink-bar{display:flex;flex-wrap:wrap;gap:6px;align-items:center;padding:10px 14px;border-bottom:1px solid var(--line);background:#fff;}
  .ink-sw{width:26px;height:26px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 0 1px var(--line);cursor:pointer;padding:0;}
  .ink-sw.on{box-shadow:0 0 0 2px var(--rail);}
  .ink-tool{border:1px solid var(--line);background:#fff;border-radius:9px;min-width:34px;height:32px;padding:0 9px;font-size:13px;cursor:pointer;}
  .ink-tool.on{background:var(--ink,#191C3A);color:#fff;border-color:var(--ink,#191C3A);}
  .ink-tool:disabled{opacity:.4;cursor:default;}
  .ink-sep{width:1px;height:24px;background:var(--line);margin:0 2px;}
  .ink-stage{position:relative;background:#FFFDF5;background-image:repeating-linear-gradient(to bottom,transparent 0,transparent 39px,#E9E1CF 39px,#E9E1CF 40px);height:min(56vh,420px);touch-action:none;}
  .ink-stage canvas{position:absolute;inset:0;width:100%;height:100%;cursor:crosshair;touch-action:none;}
  .ink-hint{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#B8AD94;font-size:15px;pointer-events:none;text-align:center;padding:20px;}
  /* Mobile — पूर्ण Screen, तळाच्या App-Menu च्या वर (Save बटण झाकलं जाऊ नये) */
  @media (max-width:767.98px){
    #inkPadModal{z-index:2100;}
    #inkPadModal .modal-dialog{margin:0;position:fixed;inset:0;max-width:none;height:100%;transform:none;}
    #inkPadModal .modal-content{border-radius:0 !important;max-height:none;height:100%;display:flex;flex-direction:column;overflow:hidden;padding-bottom:env(safe-area-inset-bottom,0px);}
    #inkPadModal .ink-stage{flex:1;height:auto;min-height:200px;}
  }
</style>

<div class="modal fade" id="inkPadModal" tabindex="-1" aria-label="हाताने टिप्पणी लिहा">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
    <div class="modal-content">
      <div class="d-flex justify-content-between align-items-center" style="padding:12px 16px;border-bottom:1px solid var(--line);">
        <div><div style="font-weight:800;font-size:15px;">✍️ हाताने टिप्पणी लिहा</div><div style="font-size:11.5px;color:var(--text-dim);">Pen, Stylus किंवा बोटाने लिहा — फाईलच्या हालचालीत तुमच्या नावासह जतन होईल</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="बंद करा"></button>
      </div>
      <div class="ink-bar">
        <button type="button" class="ink-sw on" data-color="#1D3C8F" style="background:#1D3C8F" title="निळा"></button>
        <button type="button" class="ink-sw" data-color="#15171F" style="background:#15171F" title="काळा"></button>
        <button type="button" class="ink-sw" data-color="#C0262D" style="background:#C0262D" title="लाल"></button>
        <button type="button" class="ink-sw" data-color="#177245" style="background:#177245" title="हिरवा"></button>
        <span class="ink-sep"></span>
        <button type="button" class="ink-tool" data-size="1.6" title="बारीक">✎ बारीक</button>
        <button type="button" class="ink-tool on" data-size="2.6" title="मध्यम">✎ मध्यम</button>
        <button type="button" class="ink-tool" data-size="4.2" title="जाड">✎ जाड</button>
        <span class="ink-sep"></span>
        <button type="button" class="ink-tool" id="inkEraser" title="खोडरबर">🧽 खोडा</button>
        <button type="button" class="ink-tool" id="inkUndo" title="मागे (Ctrl+Z)" disabled>↶</button>
        <button type="button" class="ink-tool" id="inkRedo" title="पुढे (Ctrl+Y)" disabled>↷</button>
        <button type="button" class="ink-tool" id="inkClear" title="सगळं पुसा">🗑️</button>
        <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text-dim);margin-left:auto;cursor:pointer;" title="Pen वापरताना हात Screen वर टेकला तरी रेघ उमटणार नाही">
          <input type="checkbox" id="inkPenOnly" style="accent-color:var(--rail);"> फक्त Pen
        </label>
      </div>
      <div class="ink-stage" id="inkStage">
        <canvas id="inkCanvas"></canvas>
        <div class="ink-hint" id="inkHint">इथे लिहा ✍️<br><small style="font-size:12px;">उदा. "मंजूर — पुढील कार्यवाही करावी"</small></div>
      </div>
      <div class="d-flex gap-2 justify-content-end" style="padding:12px 16px;border-top:1px solid var(--line);background:#fff;">
        <button type="button" class="btn" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">रद्द करा</button>
        <button type="button" class="btn" id="inkSave" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;font-weight:700;padding:8px 22px;" disabled>✔ टिप्पणी जोडा</button>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {   // Bootstrap (footer मध्ये) Load झाल्यावर
  var PHRASES = ['मंजूर', 'मान्य — पुढील कार्यवाही करावी', 'चर्चा करावी', 'तपासून सादर करावे', 'नियमानुसार कार्यवाही करावी', 'अभिप्रायासह सादर', 'माहितीसाठी', 'फाईलला लावावे'];
  var modalEl = document.getElementById('inkPadModal');
  if (!modalEl || !window.bootstrap) { return; }
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  var stage = document.getElementById('inkStage'), cv = document.getElementById('inkCanvas'), ctx = cv.getContext('2d');
  var hint = document.getElementById('inkHint'), saveBtn = document.getElementById('inkSave');
  var undoBtn = document.getElementById('inkUndo'), redoBtn = document.getElementById('inkRedo'), eraserBtn = document.getElementById('inkEraser'), penOnlyBox = document.getElementById('inkPenOnly');
  var strokes = [], redo = [], cur = null, color = '#1D3C8F', size = 2.6, erasing = false, target = null;

  /* ---------- प्रत्येक टिप्पणी चौकटीखाली साधनं ---------- */
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  document.querySelectorAll('textarea[name="remark"], #signRemark').forEach(function (ta) {
    var form = ta.form, hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'remark_ink'; hidden.className = 'ink-data';
    if (ta.id) { hidden.id = ta.id + 'Ink'; }
    var prev = document.createElement('div'); prev.className = 'ink-prev';
    prev.innerHTML = '<img alt="हस्तलिखित टिप्पणी"><div style="display:flex;flex-direction:column;gap:4px;"><small>✍️ हस्तलिखित टिप्पणी जोडली</small><div style="display:flex;gap:6px;"><button type="button" class="ink-btn ink-edit">बदला</button><button type="button" class="ink-btn ink-del">काढा</button></div></div>';
    var bar = document.createElement('div'); bar.className = 'ink-tools';
    bar.innerHTML = '<button type="button" class="ink-btn primary ink-open">✍️ हाताने लिहा</button>' + (SR ? '<button type="button" class="ink-btn ink-mic" title="बोलून लिहा (मराठी)">🎤 बोलून लिहा</button>' : '')
      + PHRASES.map(function (p) { return '<button type="button" class="ink-phr">' + p + '</button>'; }).join('');
    ta.insertAdjacentElement('afterend', bar);
    bar.insertAdjacentElement('afterend', prev);
    (form || ta.parentNode).appendChild(hidden);
    ta._ink = { hidden: hidden, prev: prev };
    var open = function () { target = ta; modal.show(); };
    bar.querySelector('.ink-open').addEventListener('click', open);
    prev.querySelector('.ink-edit').addEventListener('click', open);
    prev.querySelector('.ink-del').addEventListener('click', function () { setInk(ta, ''); });
    bar.querySelectorAll('.ink-phr').forEach(function (b) {
      b.addEventListener('click', function () { ta.value = ta.value.trim() ? ta.value.replace(/\s+$/, '') + ' — ' + b.textContent : b.textContent; ta.focus(); });
    });
    var mic = bar.querySelector('.ink-mic');
    if (mic) {
      var rec = null;
      mic.addEventListener('click', function () {
        if (rec) { rec.stop(); return; }
        rec = new SR(); rec.lang = 'mr-IN'; rec.interimResults = false; rec.continuous = false;
        mic.classList.add('rec'); mic.textContent = '⏺ ऐकतोय… (थांबवा)';
        rec.onresult = function (e) { var t = e.results[0][0].transcript; ta.value = ta.value.trim() ? ta.value.replace(/\s+$/, '') + ' ' + t : t; };
        rec.onerror = function (e) { if (e.error === 'not-allowed') { alert('Microphone ची परवानगी द्या.'); } };
        rec.onend = function () { rec = null; mic.classList.remove('rec'); mic.textContent = '🎤 बोलून लिहा'; };
        rec.start();
      });
    }
    // "फक्त टिप्पणी जोडा" — लिहिलेली किंवा हाताने, एक तरी हवी
    if (ta.hasAttribute('data-ink-required') && form) {
      form.addEventListener('submit', function (e) {
        if (!ta.value.trim() && !hidden.value) { e.preventDefault(); alert('टिप्पणी लिहा — टाइप करा किंवा "✍️ हाताने लिहा" वापरा.'); ta.focus(); }
      });
    }
  });

  function setInk(ta, dataUrl) {
    ta._ink.hidden.value = dataUrl;
    ta._ink.prev.style.display = dataUrl ? 'flex' : 'none';
    if (dataUrl) { ta._ink.prev.querySelector('img').src = dataUrl; }
  }

  /* ---------- Canvas ---------- */
  function fit() {
    var r = stage.getBoundingClientRect(), dpr = Math.min(window.devicePixelRatio || 1, 2);
    cv.width = Math.round(r.width * dpr); cv.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    draw();
  }
  function drawStroke(c, s) {
    var p = s.pts;
    c.save();
    c.globalCompositeOperation = s.erase ? 'destination-out' : 'source-over';
    c.strokeStyle = s.color; c.fillStyle = s.color; c.lineCap = 'round'; c.lineJoin = 'round';
    if (p.length === 1) { c.beginPath(); c.arc(p[0].x, p[0].y, p[0].w / 2, 0, Math.PI * 2); c.fill(); c.restore(); return; }
    for (var i = 1; i < p.length; i++) {
      var a = i === 1 ? p[0] : mid(p[i - 2], p[i - 1]), b = mid(p[i - 1], p[i]);
      c.beginPath(); c.lineWidth = p[i - 1].w; c.moveTo(a.x, a.y); c.quadraticCurveTo(p[i - 1].x, p[i - 1].y, b.x, b.y); c.stroke();
    }
    var l = p[p.length - 1], m = mid(p[p.length - 2], l);
    c.beginPath(); c.lineWidth = l.w; c.moveTo(m.x, m.y); c.lineTo(l.x, l.y); c.stroke();
    c.restore();
  }
  function mid(a, b) { return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }; }
  function draw() {
    ctx.clearRect(0, 0, cv.width, cv.height);
    strokes.forEach(function (s) { drawStroke(ctx, s); });
    if (cur) { drawStroke(ctx, cur); }
    var has = strokes.some(function (s) { return !s.erase; });
    hint.style.display = strokes.length || cur ? 'none' : 'flex';
    saveBtn.disabled = !has;
    undoBtn.disabled = !strokes.length; redoBtn.disabled = !redo.length;
  }
  function pt(e) {
    var r = cv.getBoundingClientRect();
    var pr = e.pointerType === 'pen' && e.pressure > 0 ? e.pressure : 0.5;
    var w = erasing ? size * 7 : size * (e.pointerType === 'pen' ? 0.45 + pr * 1.3 : 1);
    return { x: e.clientX - r.left, y: e.clientY - r.top, w: w };
  }
  cv.addEventListener('pointerdown', function (e) {
    if (e.pointerType === 'pen' && !penOnlyBox.checked) { penOnlyBox.checked = true; }   // Pen सापडला — हाताचा स्पर्श दुर्लक्षित
    if (penOnlyBox.checked && e.pointerType === 'touch') { return; }
    if (e.button > 0) { return; }
    e.preventDefault();
    try { cv.setPointerCapture(e.pointerId); } catch (x) {}
    cur = { color: color, erase: erasing, pts: [pt(e)], id: e.pointerId };
    draw();
  });
  cv.addEventListener('pointermove', function (e) {
    if (!cur || e.pointerId !== cur.id) { return; }
    e.preventDefault();
    var evs = e.getCoalescedEvents ? e.getCoalescedEvents() : [e];
    (evs.length ? evs : [e]).forEach(function (ev) { cur.pts.push(pt(ev)); });
    draw();
  });
  function end(e) { if (!cur || (e && e.pointerId !== cur.id)) { return; } strokes.push(cur); cur = null; redo = []; draw(); }
  cv.addEventListener('pointerup', end); cv.addEventListener('pointercancel', end);

  modalEl.querySelectorAll('.ink-sw').forEach(function (b) { b.addEventListener('click', function () {
    modalEl.querySelectorAll('.ink-sw').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); color = b.dataset.color; erasing = false; eraserBtn.classList.remove('on');
  }); });
  modalEl.querySelectorAll('[data-size]').forEach(function (b) { b.addEventListener('click', function () {
    modalEl.querySelectorAll('[data-size]').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); size = parseFloat(b.dataset.size);
  }); });
  eraserBtn.addEventListener('click', function () { erasing = !erasing; eraserBtn.classList.toggle('on', erasing); });
  function doUndo() { if (strokes.length) { redo.push(strokes.pop()); draw(); } }
  function doRedo() { if (redo.length) { strokes.push(redo.pop()); draw(); } }
  undoBtn.addEventListener('click', doUndo); redoBtn.addEventListener('click', doRedo);
  document.getElementById('inkClear').addEventListener('click', function () { if (strokes.length && confirm('लिहिलेलं सगळं पुसायचं?')) { strokes = []; redo = []; draw(); } });
  modalEl.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey)) { return; }
    var k = e.key.toLowerCase();
    if (k === 'z' && !e.shiftKey) { e.preventDefault(); doUndo(); } else if (k === 'y' || (k === 'z' && e.shiftKey)) { e.preventDefault(); doRedo(); }
  });

  /* लिहिलेल्या भागापुरती प्रतिमा (पारदर्शक पार्श्वभूमी) */
  function exportPng() {
    var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    strokes.forEach(function (s) { if (s.erase) { return; } s.pts.forEach(function (p) {
      minX = Math.min(minX, p.x - p.w); minY = Math.min(minY, p.y - p.w); maxX = Math.max(maxX, p.x + p.w); maxY = Math.max(maxY, p.y + p.w);
    }); });
    if (minX === Infinity) { return ''; }
    var pad = 10, sc = 2, w = Math.ceil(maxX - minX + pad * 2), h = Math.ceil(maxY - minY + pad * 2);
    var out = document.createElement('canvas'); out.width = Math.min(w * sc, 4000); out.height = Math.min(h * sc, 4000);
    var o = out.getContext('2d'); o.scale(sc, sc); o.translate(pad - minX, pad - minY);
    strokes.forEach(function (s) { drawStroke(o, s); });
    return out.toDataURL('image/png');
  }
  saveBtn.addEventListener('click', function () {
    if (target) { setInk(target, exportPng()); }
    modal.hide();
  });
  modalEl.addEventListener('shown.bs.modal', function () { strokes = []; redo = []; cur = null; fit(); });
  window.addEventListener('resize', function () { if (modalEl.classList.contains('show')) { fit(); } });
});
</script>
