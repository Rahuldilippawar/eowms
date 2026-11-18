// 🪪 ID Card — लांब नाव / पत्ता / संस्थेचं नाव आपोआप लहान अक्षरात बसवतो (कापलं जात नाही, "…" येत नाही)
(function () {
  function fit(root) {
    (root || document).querySelectorAll('[data-fit]').forEach(function (el) {
      el.style.fontSize = '';
      var size = parseFloat(getComputedStyle(el).fontSize), min = size * 0.62, single = el.dataset.fit === '1', guard = 0;
      // बहु-ओळ: उंची तसेच रुंदी (एखादा लांब शब्द तुटू नये — तो पूर्ण बसेपर्यंत अक्षरं लहान)
      var over = function () { return single ? el.scrollWidth > el.clientWidth + 1 : (el.scrollHeight > el.clientHeight + 1 || el.scrollWidth > el.clientWidth); };
      if (single) el.style.textOverflow = 'clip';
      if (single) min = size * 0.5;
      if (el.dataset.fitMin) min = size * parseFloat(el.dataset.fitMin);
      while (over() && size > min && guard++ < 50) { size -= 0.3; el.style.fontSize = size + 'px'; }
    });
    // पूर्ण Card मावत नसेल (लांब नाव + लांब पत्ता) — फोटो व Logo थोडे लहान करून सगळं आत बसवतो
    (root && root.classList && root.classList.contains('sic') ? [root] : (root || document).querySelectorAll('.sic')).forEach(function (card) {
      var ph = card.querySelector('.sic-photo'), lg = card.querySelector('.sic-logo'), foot = card.querySelector('.sic-foot'); if (!ph || !foot) return;
      ph.style.width = ph.style.height = ''; if (lg) lg.style.width = lg.style.height = '';
      var k = 1, w0 = ph.offsetWidth, h0 = ph.offsetHeight, l0 = lg ? lg.offsetWidth : 0, guard = 0;
      var bottomOver = function () { var cb = card.getBoundingClientRect().bottom - parseFloat(getComputedStyle(card).borderBottomWidth); return foot.getBoundingClientRect().bottom > cb + 0.5; };
      while (bottomOver() && k > 0.72 && guard++ < 30) {
        k -= 0.02; ph.style.width = (w0 * k) + 'px'; ph.style.height = (h0 * k) + 'px';
        if (lg) { lg.style.width = lg.style.height = (l0 * Math.max(k - 0.08, 0.6)) + 'px'; }
      }
    });
  }
  window.idCardFit = fit;
  // फोटो / सही / Logo load झाल्यावर उंची बदलते — म्हणून load नंतर व Print आधी पुन्हा
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { fit(); });
  document.addEventListener('DOMContentLoaded', function () { fit(); });
  window.addEventListener('load', function () { fit(); });
  window.addEventListener('beforeprint', function () { fit(); });
  document.addEventListener('load', function (e) { if (e.target && e.target.tagName === 'IMG' && e.target.closest('.sic')) fit(e.target.closest('.sic')); }, true);
})();
