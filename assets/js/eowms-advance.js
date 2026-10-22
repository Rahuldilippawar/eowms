/* ============================================================
   EOWMS Advance UI Engine
   ============================================================ */
(function () {
  'use strict';
  const rootPath = document.body.getAttribute('data-root-path') || '';

  // ---------- 1. Dark Mode ----------
  function applyTheme(theme) {
    if (theme === 'dark') { document.documentElement.setAttribute('data-theme', 'dark'); }
    else { document.documentElement.removeAttribute('data-theme'); }
  }
  const savedTheme = localStorage.getItem('eowms_theme') || 'light';
  applyTheme(savedTheme);

  window.eowmsToggleTheme = function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const next = isDark ? 'light' : 'dark';
    applyTheme(next);
    localStorage.setItem('eowms_theme', next);
  };

  // ---------- 2. Command Palette (Ctrl+K) ----------
  let cmdkOverlay, cmdkInput, cmdkResults, cmdkActiveIndex = 0, cmdkItems = [];

  function buildCmdkDOM() {
    cmdkOverlay = document.createElement('div');
    cmdkOverlay.className = 'cmdk-overlay';
    cmdkOverlay.innerHTML =
      '<div class="cmdk-box">' +
        '<div class="cmdk-input-row">' +
          '<i class="fa-solid fa-magnifying-glass"></i>' +
          '<input type="text" placeholder="कुठेही जा... (Module, Page, व्यक्ती)" autocomplete="off">' +
          '<span class="cmdk-hint">Esc</span>' +
        '</div>' +
        '<div class="cmdk-results"></div>' +
      '</div>';
    document.body.appendChild(cmdkOverlay);
    cmdkInput = cmdkOverlay.querySelector('input');
    cmdkResults = cmdkOverlay.querySelector('.cmdk-results');

    cmdkOverlay.addEventListener('click', function (e) { if (e.target === cmdkOverlay) closeCmdk(); });
    cmdkInput.addEventListener('input', debounce(runCmdkSearch, 200));
    cmdkInput.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { closeCmdk(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); moveCmdkSelection(1); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); moveCmdkSelection(-1); }
      else if (e.key === 'Enter') { e.preventDefault(); const active = cmdkItems[cmdkActiveIndex]; if (active) { window.location = active.dataset.href; } }
    });
  }

  function debounce(fn, ms) { let t; return function () { clearTimeout(t); const args = arguments; t = setTimeout(function () { fn.apply(null, args); }, ms); }; }

  function moveCmdkSelection(dir) {
    if (!cmdkItems.length) return;
    cmdkItems[cmdkActiveIndex].classList.remove('active');
    cmdkActiveIndex = (cmdkActiveIndex + dir + cmdkItems.length) % cmdkItems.length;
    cmdkItems[cmdkActiveIndex].classList.add('active');
    cmdkItems[cmdkActiveIndex].scrollIntoView({ block: 'nearest' });
  }

  function runCmdkSearch() {
    const q = cmdkInput.value.trim();
    if (!q) { cmdkResults.innerHTML = '<div class="cmdk-empty">टाइप करा — कुठलंही Module, Page किंवा व्यक्तीचं नाव</div>'; cmdkItems = []; return; }
    fetch(rootPath + 'ajax-command-palette.php?q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        const results = data.results || [];
        if (!results.length) { cmdkResults.innerHTML = '<div class="cmdk-empty">काही सापडलं नाही</div>'; cmdkItems = []; return; }
        cmdkResults.innerHTML = results.map(function (r) {
          return '<a class="cmdk-item" href="' + rootPath + r.url + '" data-href="' + rootPath + r.url + '">' +
            '<div class="icon"><i class="' + r.icon + '"></i></div>' +
            '<div><div class="label">' + escapeHtml(r.label) + '</div><div class="sub">' + escapeHtml(r.sub) + '</div></div>' +
          '</a>';
        }).join('');
        cmdkItems = Array.prototype.slice.call(cmdkResults.querySelectorAll('.cmdk-item'));
        cmdkActiveIndex = 0;
        if (cmdkItems.length) { cmdkItems[0].classList.add('active'); }
      });
  }

  function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

  function openCmdk() {
    if (!cmdkOverlay) buildCmdkDOM();
    cmdkOverlay.classList.add('open');
    cmdkInput.value = ''; cmdkInput.focus();
    cmdkResults.innerHTML = '<div class="cmdk-empty">टाइप करा — कुठलंही Module, Page किंवा व्यक्तीचं नाव</div>';
  }
  function closeCmdk() { if (cmdkOverlay) cmdkOverlay.classList.remove('open'); }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openCmdk(); }
  });
  window.eowmsOpenCommandPalette = openCmdk;

  // ---------- 3. Animated Counters ----------
  function animateCounters() {
    const els = document.querySelectorAll('.kpi-mini .val, .kpi-card .kpi-value');
    els.forEach(function (el) {
      // जर आत आधीच काही Nested HTML (उदा. "5/20" सारखं Sub-Value Span)
      // असेल, तर ते तोडू नये म्हणून Animation Skip करतो — फक्त साधे,
      // Plain-Text Numbers Animate करतो.
      if (el.children.length > 0) return;
      const text = el.textContent.trim();
      const match = text.match(/^([₹]?)(-?[\d,]+)(.*)$/);
      if (!match) return;
      const prefix = match[1], numStr = match[2].replace(/,/g, ''), suffix = match[3];
      const target = parseInt(numStr, 10);
      if (isNaN(target) || Math.abs(target) > 999999) return;
      let current = 0;
      const duration = 700, startTime = performance.now();
      function step(now) {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        current = Math.round(target * eased);
        el.textContent = prefix + current.toLocaleString('en-IN') + suffix;
        if (progress < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    });
  }

  // ---------- 4b. Gauge/Speedometer Widget ----------
  // उदा: eowmsDrawGauge('budgetGauge', 72, {label: 'वापर', color: '#1B8A83'})
  window.eowmsDrawGauge = function (canvasId, percent, opts) {
    opts = opts || {};
    const canvas = document.getElementById(canvasId);
    if (!canvas) return;
    percent = Math.max(0, Math.min(100, percent));
    const dpr = window.devicePixelRatio || 1;
    const cssW = canvas.offsetWidth || 160, cssH = canvas.offsetHeight || 100;
    canvas.width = cssW * dpr; canvas.height = cssH * dpr;
    const ctx = canvas.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, cssW, cssH);

    const cx = cssW / 2, cy = cssH * 0.92, radius = Math.min(cssW / 2, cssH) - 10;
    const startAngle = Math.PI, endAngle = 0;

    ctx.beginPath();
    ctx.arc(cx, cy, radius, startAngle, endAngle, false);
    ctx.lineWidth = 12; ctx.lineCap = 'round';
    ctx.strokeStyle = getComputedStyle(document.documentElement).getPropertyValue('--line') || '#E4E6EC';
    ctx.stroke();

    const valueAngle = startAngle + (endAngle - startAngle) * (percent / 100);
    ctx.beginPath();
    ctx.arc(cx, cy, radius, startAngle, valueAngle, false);
    ctx.lineWidth = 12; ctx.lineCap = 'round';
    let color = opts.color || '#1B8A83';
    if (percent >= 90 && !opts.color) color = '#B23A48';
    else if (percent >= 70 && !opts.color) color = '#C97F1D';
    ctx.strokeStyle = color;
    ctx.stroke();

    ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--ink') || '#10162B';
    ctx.font = '700 ' + Math.round(radius * 0.42) + 'px Space Grotesk, sans-serif';
    ctx.textAlign = 'center';
    ctx.fillText(Math.round(percent) + '%', cx, cy - 4);

    if (opts.label) {
      ctx.font = '500 11px Noto Sans Devanagari, sans-serif';
      ctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--text-dim') || '#6B7080';
      ctx.fillText(opts.label, cx, cy + 16);
    }
  };

  // ---------- 4c. GitHub-Style Heatmap Calendar ----------
  // उदा: eowmsDrawHeatmap('attHeatmap', {'2026-09-01': 3, '2026-09-02': 7, ...})
  // data = { 'YYYY-MM-DD': intensity_value (0 ते जास्त कितीही) }
  window.eowmsDrawHeatmap = function (containerId, data, opts) {
    opts = opts || {};
    const container = document.getElementById(containerId);
    if (!container) return;
    const monthsBack = opts.monthsBack || 6;
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth() - monthsBack, 1);
    // सोमवार पासून सुरू करण्यासाठी, Start Date ला मागच्या रविवारपर्यंत नेतो
    const startDow = start.getDay();
    start.setDate(start.getDate() - startDow);

    const maxVal = Math.max.apply(null, Object.values(data).concat([1]));
    const colorFor = function (v) {
      if (!v) return 'var(--line)';
      const ratio = v / maxVal;
      if (ratio > 0.75) return opts.color4 || '#0F5E58';
      if (ratio > 0.5) return opts.color3 || '#1B8A83';
      if (ratio > 0.25) return opts.color2 || '#7FC4BE';
      return opts.color1 || '#C7E7E3';
    };

    let html = '<div style="display:flex;gap:3px;overflow-x:auto;padding-bottom:6px;">';
    let cur = new Date(start);
    while (cur <= today) {
      html += '<div style="display:flex;flex-direction:column;gap:3px;">';
      for (let d = 0; d < 7; d++) {
        if (cur > today) { html += '<div style="width:11px;height:11px;"></div>'; cur.setDate(cur.getDate() + 1); continue; }
        const key = cur.toISOString().slice(0, 10);
        const val = data[key] || 0;
        html += '<div title="' + key + ': ' + val + '" style="width:11px;height:11px;border-radius:2.5px;background:' + colorFor(val) + ';"></div>';
        cur.setDate(cur.getDate() + 1);
      }
      html += '</div>';
    }
    html += '</div>';
    container.innerHTML = html;
  };

  // ---------- 4. Sparkline mini-chart ----------
  window.eowmsDrawSparkline = function (canvasId, values, color) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !values || !values.length) return;
    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.offsetWidth * 2;
    const h = canvas.height = canvas.offsetHeight * 2;
    ctx.scale(2, 2);
    const max = Math.max.apply(null, values) || 1;
    const min = Math.min.apply(null, values);
    const range = (max - min) || 1;
    const stepX = (w / 2) / (values.length - 1 || 1);
    ctx.beginPath();
    values.forEach(function (v, i) {
      const x = i * stepX;
      const y = (h / 2) - ((v - min) / range) * (h / 2 - 4) - 2;
      if (i === 0) ctx.moveTo(x, y); else ctx.lineTo(x, y);
    });
    ctx.strokeStyle = color || '#1B8A83';
    ctx.lineWidth = 1.6;
    ctx.stroke();
  };

  // ---------- 5. Micro-Celebration Confetti ----------
  window.eowmsCelebrate = function (originEl) {
    const colors = ['#1B8A83', '#C97F1D', '#B23A48', '#8A5CD6', '#2255AA'];
    const rect = originEl ? originEl.getBoundingClientRect() : { left: window.innerWidth / 2, top: window.innerHeight / 2 };
    for (let i = 0; i < 18; i++) {
      const piece = document.createElement('div');
      piece.className = 'confetti-piece';
      piece.style.left = (rect.left + Math.random() * 40 - 20) + 'px';
      piece.style.top = (rect.top) + 'px';
      piece.style.background = colors[Math.floor(Math.random() * colors.length)];
      piece.style.animationDelay = (Math.random() * 0.1) + 's';
      document.body.appendChild(piece);
      setTimeout(function () { piece.remove(); }, 2000);
    }
    if (originEl) { originEl.classList.add('pulse-check'); setTimeout(function () { originEl.classList.remove('pulse-check'); }, 400); }
  };

  // ---------- 6. Swipe Gestures ----------
  window.eowmsEnableSwipe = function (rowEl, onSwipeLeft, actionLabel) {
    let startX = 0, currentX = 0, dragging = false;
    const content = rowEl.querySelector('.swipe-row-content') || rowEl;
    rowEl.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; dragging = true; }, { passive: true });
    rowEl.addEventListener('touchmove', function (e) {
      if (!dragging) return;
      currentX = e.touches[0].clientX - startX;
      if (currentX < 0) { content.style.transform = 'translateX(' + Math.max(currentX, -90) + 'px)'; }
    }, { passive: true });
    rowEl.addEventListener('touchend', function () {
      dragging = false;
      if (currentX < -60) { if (onSwipeLeft) onSwipeLeft(); }
      content.style.transform = 'translateX(0)';
      currentX = 0;
    });
  };

  // ---------- 7. Onboarding Tour ----------
  window.eowmsStartTour = function (steps) {
    let idx = 0;
    let overlay, tooltip;
    function cleanup() {
      document.querySelectorAll('.tour-highlight').forEach(function (e) { e.classList.remove('tour-highlight'); });
      if (tooltip) tooltip.remove();
    }
    function showStep() {
      cleanup();
      if (idx >= steps.length) { fetch(rootPath + 'ajax-onboarding-seen.php', { method: 'POST' }); return; }
      const step = steps[idx];
      const target = document.querySelector(step.selector);
      if (!target) { idx++; showStep(); return; }
      target.classList.add('tour-highlight');
      target.scrollIntoView({ block: 'center', behavior: 'smooth' });
      const rect = target.getBoundingClientRect();
      tooltip = document.createElement('div');
      tooltip.className = 'tour-tooltip';
      tooltip.style.top = Math.min(rect.bottom + 12, window.innerHeight - 160) + 'px';
      tooltip.style.left = Math.min(Math.max(rect.left, 16), window.innerWidth - 300) + 'px';
      tooltip.innerHTML = '<div class="tour-title">' + escapeHtml(step.title) + '</div><div>' + escapeHtml(step.text) + '</div>' +
        '<div class="tour-actions"><span style="font-size:11px;color:var(--text-dim);">' + (idx + 1) + '/' + steps.length + '</span>' +
        '<button type="button" style="background:var(--rail);color:#fff;border:none;border-radius:7px;padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;">' + (idx === steps.length - 1 ? 'पूर्ण झालं' : 'पुढे →') + '</button></div>';
      document.body.appendChild(tooltip);
      tooltip.querySelector('button').addEventListener('click', function () { idx++; showStep(); });
    }
    showStep();
  };

  // ---------- Init on DOM ready ----------
  document.addEventListener('DOMContentLoaded', function () {
    animateCounters();
  });

  // ---------- 9. Gauge / Speedometer Widget ----------
  // (Definition — वर, Sparkline च्या आधी आधीच आहे — इथे Duplicate नाही)
})();
