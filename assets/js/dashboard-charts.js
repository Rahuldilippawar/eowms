// 📊 Dashboard Analytics — कालावधी निवडून Pie / Bar / Trend Charts
// Data: ajax-dashboard-charts.php?from=YYYY-MM-DD&to=YYYY-MM-DD
(function () {
  'use strict';
  var root = document.getElementById('dashAnalytics');
  if (!root) return;

  var STORE_KEY = 'eowmsDashRange';
  var STATUS_LABELS = { pending: 'प्रलंबित', in_progress: 'सुरू आहे', completed: 'पूर्ण', closed: 'बंद', cancelled: 'रद्द' };
  var STATUS_COLORS = { pending: '#C97F1D', in_progress: '#3B6FC9', completed: '#1B8A83', closed: '#6B7080', cancelled: '#B23A48' };
  var PALETTE = ['#1B8A83', '#3B6FC9', '#C97F1D', '#8A5CD6', '#B23A48', '#2FA37A', '#D4A017', '#5D8AA8', '#A0522D', '#6B7080'];
  var charts = {};
  var trendType = 'bar';
  var lastData = null;
  var reqSeq = 0;

  function css(name, fallback) {
    var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return v || fallback;
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function fmt(s) {
    var p = s.split('-');
    return p[2] + '/' + p[1] + '/' + p[0];
  }

  function rangeFor(key) {
    var t = new Date(); t.setHours(0, 0, 0, 0);
    var f = new Date(t);
    switch (key) {
      case 'today': break;
      case '30d': f.setDate(t.getDate() - 29); break;
      case 'month': f.setDate(1); break;
      case '90d': f.setDate(t.getDate() - 89); break;
      case 'year': f = new Date(t.getFullYear(), 0, 1); break;
      default: f.setDate(t.getDate() - 6);
    }
    return { from: ymd(f), to: ymd(t) };
  }

  function save(obj) { try { localStorage.setItem(STORE_KEY, JSON.stringify(obj)); } catch (e) {} }
  function load() { try { return JSON.parse(localStorage.getItem(STORE_KEY) || 'null'); } catch (e) { return null; } }

  function baseOptions() {
    var text = css('--text-dim', '#6B7080');
    return {
      responsive: true, maintainAspectRatio: false, animation: { duration: 400 },
      plugins: { legend: { position: 'bottom', labels: { color: text, font: { family: "'Noto Sans Devanagari'", size: 11.5 }, boxWidth: 11, padding: 10 } } }
    };
  }
  function axes(stacked) {
    var text = css('--text-dim', '#6B7080'), grid = css('--line', '#E4E6EC');
    return {
      x: { stacked: !!stacked, grid: { display: false }, ticks: { color: text, font: { size: 11 }, maxRotation: 50, autoSkip: true } },
      y: { stacked: !!stacked, beginAtZero: true, ticks: { color: text, precision: 0, font: { size: 11 } }, grid: { color: grid } }
    };
  }

  function emptyNote(id, show) {
    var canvas = document.getElementById(id);
    if (!canvas) return;
    var box = canvas.parentNode, note = box.querySelector('.dash-empty');
    if (show && !note) {
      note = document.createElement('div');
      note.className = 'dash-empty';
      note.textContent = 'या कालावधीत कोणतीही नोंद नाही';
      box.appendChild(note);
    } else if (!show && note) { note.remove(); }
    canvas.style.visibility = show ? 'hidden' : 'visible';
  }

  function draw(id, config) {
    var el = document.getElementById(id);
    if (!el) return;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(el, config);
  }

  function statusDoughnut(id, obj, order) {
    if (!document.getElementById(id)) return;
    obj = obj || {};
    var keys = order.filter(function (k) { return obj[k] > 0; });
    var total = keys.reduce(function (s, k) { return s + obj[k]; }, 0);
    emptyNote(id, total === 0);
    var opts = baseOptions();
    opts.cutout = '62%';
    opts.plugins.tooltip = { callbacks: { label: function (c) { return ' ' + c.label + ': ' + c.raw + ' (' + Math.round(c.raw * 100 / total) + '%)'; } } };
    draw(id, {
      type: 'doughnut',
      data: {
        labels: keys.map(function (k) { return STATUS_LABELS[k] || k; }),
        datasets: [{ data: keys.map(function (k) { return obj[k]; }), backgroundColor: keys.map(function (k) { return STATUS_COLORS[k] || '#999'; }), borderWidth: 2, borderColor: css('--surface', '#fff') }]
      },
      options: opts
    });
  }

  function drawTrend(d) {
    var hasData = d.trend.total.some(function (v) { return v > 0; });
    emptyNote('dcTrend', !hasData);
    var line = trendType === 'line';
    var opts = baseOptions();
    opts.scales = axes(false);
    opts.interaction = { mode: 'index', intersect: false };
    draw('dcTrend', {
      type: trendType,
      data: {
        labels: d.trend.labels,
        datasets: [
          { label: 'एकूण', data: d.trend.total, borderColor: '#6B7080', backgroundColor: line ? 'rgba(107,112,128,.10)' : 'rgba(107,112,128,.45)', tension: .35, fill: line, borderRadius: 5, maxBarThickness: 34 },
          { label: 'पूर्ण', data: d.trend.completed, borderColor: '#1B8A83', backgroundColor: line ? 'rgba(27,138,131,.15)' : '#1B8A83', tension: .35, fill: line, borderRadius: 5, maxBarThickness: 34 }
        ]
      },
      options: opts
    });
  }

  function render(d) {
    lastData = d;
    var unit = { day: 'रोजचं', week: 'आठवड्यानुसार', month: 'महिन्यानुसार' }[d.granularity] || '';
    document.getElementById('dashRangeLabel').textContent =
      (d.isManagement ? 'संपूर्ण संस्था' : 'माझा विभाग') + ' · ' + fmt(d.from) + ' ते ' + fmt(d.to) + ' (' + d.days + ' दिवस, ' + unit + ')';
    document.getElementById('dkTotal').textContent = d.kpi.total;
    document.getElementById('dkDone').textContent = d.kpi.completed;
    document.getElementById('dkOpen').textContent = d.kpi.open;
    document.getElementById('dkRate').textContent = d.kpi.rate + '%';
    document.getElementById('dkRateBar').style.width = d.kpi.rate + '%';

    drawTrend(d);
    statusDoughnut('dcStatus', d.workStatus, ['completed', 'in_progress', 'pending']);
    statusDoughnut('dcRequests', d.requestStatus, ['completed', 'closed', 'in_progress', 'pending', 'cancelled']);
    statusDoughnut('dcTasks', d.taskStatus, ['completed', 'in_progress', 'pending', 'cancelled']);

    if (document.getElementById('dcDeptPie')) {
      var depts = d.departments || [];
      // Pie मध्ये पहिले ९ विभाग + बाकी "इतर"
      var top = depts.slice(0, 9), rest = depts.slice(9);
      var pieLabels = top.map(function (x) { return x.name; });
      var pieData = top.map(function (x) { return x.total; });
      if (rest.length) {
        pieLabels.push('इतर (' + rest.length + ')');
        pieData.push(rest.reduce(function (s, x) { return s + x.total; }, 0));
      }
      emptyNote('dcDeptPie', depts.length === 0);
      emptyNote('dcDeptBar', depts.length === 0);
      var pieOpts = baseOptions();
      pieOpts.plugins.legend.position = 'right';
      if (window.innerWidth < 768) pieOpts.plugins.legend.position = 'bottom';
      draw('dcDeptPie', {
        type: 'pie',
        data: { labels: pieLabels, datasets: [{ data: pieData, backgroundColor: PALETTE, borderWidth: 2, borderColor: css('--surface', '#fff') }] },
        options: pieOpts
      });
      var barDepts = depts.slice(0, 12);
      var barOpts = baseOptions();
      barOpts.scales = axes(true);
      barOpts.interaction = { mode: 'index', intersect: false };
      draw('dcDeptBar', {
        type: 'bar',
        data: {
          labels: barDepts.map(function (x) { return x.name.length > 18 ? x.name.slice(0, 17) + '…' : x.name; }),
          datasets: [
            { label: 'पूर्ण', data: barDepts.map(function (x) { return x.completed; }), backgroundColor: '#1B8A83', borderRadius: 4, maxBarThickness: 30 },
            { label: 'प्रलंबित / सुरू', data: barDepts.map(function (x) { return x.open; }), backgroundColor: '#C97F1D', borderRadius: 4, maxBarThickness: 30 }
          ]
        },
        options: barOpts
      });
    }
  }

  function fetchRange(from, to) {
    if (typeof Chart === 'undefined') {
      document.getElementById('dashRangeLabel').textContent = 'Charts लोड झाले नाहीत (Internet तपासा)';
      return;
    }
    var seq = ++reqSeq;
    root.classList.add('is-loading');
    fetch('ajax-dashboard-charts.php?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(function (d) { if (seq === reqSeq) render(d); })
      .catch(function () {
        if (seq === reqSeq) document.getElementById('dashRangeLabel').textContent = 'Charts लोड झाले नाहीत — पान Refresh करून पहा';
      })
      .then(function () { if (seq === reqSeq) root.classList.remove('is-loading'); });
  }

  var buttons = root.querySelectorAll('.dash-range button');
  var customForm = document.getElementById('dashCustom');
  var fromInput = document.getElementById('dashFrom');
  var toInput = document.getElementById('dashTo');

  function setActive(key) {
    buttons.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-range') === key); });
    customForm.hidden = key !== 'custom';
  }

  function apply(key, from, to) {
    setActive(key);
    if (key !== 'custom') {
      var r = rangeFor(key);
      from = r.from; to = r.to;
    }
    fromInput.value = from; toInput.value = to;
    save({ key: key, from: from, to: to });
    fetchRange(from, to);
  }

  buttons.forEach(function (b) {
    b.addEventListener('click', function () {
      var key = b.getAttribute('data-range');
      if (key === 'custom') { setActive('custom'); fromInput.focus(); return; }
      apply(key);
    });
  });
  customForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!fromInput.value || !toInput.value) return;
    var f = fromInput.value, t = toInput.value;
    if (f > t) { var x = f; f = t; t = x; }
    apply('custom', f, t);
  });
  root.querySelectorAll('.dash-toggle button').forEach(function (b) {
    b.addEventListener('click', function () {
      trendType = b.getAttribute('data-trend');
      root.querySelectorAll('.dash-toggle button').forEach(function (o) { o.classList.toggle('active', o === b); });
      if (lastData) drawTrend(lastData);
    });
  });

  // Dark/Light Mode बदलला की Chart चे रंग पुन्हा लावतो
  new MutationObserver(function () { if (lastData) render(lastData); })
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

  window.addEventListener('DOMContentLoaded', function () {
    var saved = load();
    if (saved && saved.key === 'custom' && saved.from && saved.to) apply('custom', saved.from, saved.to);
    else apply(saved && saved.key ? saved.key : '7d');
  });
})();
