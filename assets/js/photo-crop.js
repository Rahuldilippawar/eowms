// ✂️ फोटो Crop — ID Card साठी चेहरा चौकटीत बसवा (Zoom, सरकवा, फिरवा). 5:6 आकार, 600×720 JPEG.
// वापर: <input type="file" data-crop> — निवडल्यावर Crop खिडकी; "वापरा" केल्यावर input मध्ये Crop केलेली File
// ठेवून 'cropped' Event पाठवतो (रद्द केलं तर input रिकामा).
(function () {
  var W = 600, H = 720;
  var css = '.pc-bg{position:fixed;inset:0;background:rgba(10,14,30,.82);z-index:3000;display:flex;align-items:center;justify-content:center;padding:14px;}' +
    '.pc-box{background:#fff;border-radius:16px;padding:14px;width:100%;max-width:360px;text-align:center;font-family:inherit;}' +
    '.pc-t{font-weight:700;font-size:14px;margin-bottom:8px;color:#1A1D29;}' +
    '.pc-cv{width:100%;max-width:300px;aspect-ratio:5/6;border-radius:10px;touch-action:none;cursor:grab;background:#111;display:block;margin:0 auto;}' +
    '.pc-row{display:flex;align-items:center;gap:8px;margin:10px 4px 4px;font-size:13px;color:#555;} .pc-row input{flex:1;accent-color:#3769AC;}' +
    '.pc-btns{display:flex;gap:8px;margin-top:10px;} .pc-btns button{flex:1;border:0;border-radius:10px;padding:10px;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit;}' +
    '.pc-ok{background:#3769AC;color:#fff;} .pc-no{background:#EEF0F4;color:#333;} .pc-rot{flex:0 0 auto !important;background:#EEF0F4;}' +
    '.pc-hint{font-size:11.5px;color:#777;margin-top:6px;}';
  var st = document.createElement('style'); st.textContent = css; document.head.appendChild(st);

  function open(file, done) {
    var url = URL.createObjectURL(file), img = new Image();
    img.onload = function () {
      var bg = document.createElement('div'); bg.className = 'pc-bg';
      bg.innerHTML = '<div class="pc-box"><div class="pc-t">✂️ चेहरा चौकटीत बसवा</div><canvas class="pc-cv" width="' + W + '" height="' + H + '"></canvas>' +
        '<div class="pc-row">🔍<input type="range" min="1" max="4" step="0.01" value="1"></div><div class="pc-hint">बोटाने / Mouse ने फोटो सरकवा. डोकं वरच्या भागात, खांदे खाली.</div>' +
        '<div class="pc-btns"><button type="button" class="pc-rot" title="फिरवा">↻</button><button type="button" class="pc-no">रद्द</button><button type="button" class="pc-ok">✓ वापरा</button></div></div>';
      document.body.appendChild(bg);
      var cv = bg.querySelector('canvas'), ctx = cv.getContext('2d'), zoom = bg.querySelector('input'), rot = 0, z = 1, ox = 0, oy = 0;
      function dims() { var r = rot % 180 !== 0; return { w: r ? img.height : img.width, h: r ? img.width : img.height }; }
      function base() { var d = dims(); return Math.max(W / d.w, H / d.h); }
      function clamp() { var d = dims(), s = base() * z, mx = Math.max(0, (d.w * s - W) / 2), my = Math.max(0, (d.h * s - H) / 2); ox = Math.min(mx, Math.max(-mx, ox)); oy = Math.min(my, Math.max(-my, oy)); }
      function draw() {
        clamp(); var s = base() * z;
        ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
        ctx.save(); ctx.translate(W / 2 + ox, H / 2 + oy); ctx.rotate(rot * Math.PI / 180); ctx.scale(s, s); ctx.drawImage(img, -img.width / 2, -img.height / 2); ctx.restore();
        // मार्गदर्शक: डोक्याचा अंडाकृती
        ctx.save(); ctx.strokeStyle = 'rgba(255,255,255,.75)'; ctx.setLineDash([12, 10]); ctx.lineWidth = 3; ctx.beginPath(); ctx.ellipse(W / 2, H * 0.4, W * 0.28, H * 0.27, 0, 0, Math.PI * 2); ctx.stroke(); ctx.restore();
      }
      var drag = null;
      cv.addEventListener('pointerdown', function (e) { drag = { x: e.clientX, y: e.clientY, ox: ox, oy: oy }; cv.setPointerCapture(e.pointerId); });
      cv.addEventListener('pointermove', function (e) { if (!drag) return; var k = W / cv.clientWidth; ox = drag.ox + (e.clientX - drag.x) * k; oy = drag.oy + (e.clientY - drag.y) * k; draw(); });
      cv.addEventListener('pointerup', function () { drag = null; });
      cv.addEventListener('wheel', function (e) { e.preventDefault(); z = Math.min(4, Math.max(1, z * (e.deltaY < 0 ? 1.08 : 0.93))); zoom.value = z; draw(); }, { passive: false });
      zoom.addEventListener('input', function () { z = +zoom.value; draw(); });
      bg.querySelector('.pc-rot').onclick = function () { rot = (rot + 90) % 360; ox = oy = 0; draw(); };
      function close(blob) { URL.revokeObjectURL(url); bg.remove(); done(blob); }
      bg.querySelector('.pc-no').onclick = function () { close(null); };
      bg.querySelector('.pc-ok').onclick = function () {
        // अंतिम File मध्ये मार्गदर्शक रेषा नको — पुन्हा काढून मग JPEG
        clamp(); var s = base() * z; ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
        ctx.save(); ctx.translate(W / 2 + ox, H / 2 + oy); ctx.rotate(rot * Math.PI / 180); ctx.scale(s, s); ctx.drawImage(img, -img.width / 2, -img.height / 2); ctx.restore();
        cv.toBlob(function (b) { close(b); }, 'image/jpeg', 0.9);
      };
      draw();
    };
    img.onerror = function () { URL.revokeObjectURL(url); alert('हा फोटो उघडत नाही — JPG / PNG निवडा.'); done(null); };
    img.src = url;
  }
  window.photoCrop = open;

  document.addEventListener('change', function (e) {
    var inp = e.target;
    if (!inp.matches || !inp.matches('input[type=file][data-crop]') || !inp.files.length) return;
    e.stopImmediatePropagation();
    var file = inp.files[0];
    if (!/^image\//.test(file.type)) return;
    open(file, function (blob) {
      if (!blob) { inp.value = ''; return; }
      try {
        var dt = new DataTransfer(); dt.items.add(new File([blob], (file.name.replace(/\.\w+$/, '') || 'photo') + '.jpg', { type: 'image/jpeg' }));
        inp.files = dt.files; // (files बदलल्याने change Event पुन्हा येत नाही)
      } catch (x) { inp._blob = blob; }
      inp._cropBlob = blob;
      inp.dispatchEvent(new CustomEvent('cropped', { bubbles: true, detail: { blob: blob } }));
    });
  }, true);
})();
