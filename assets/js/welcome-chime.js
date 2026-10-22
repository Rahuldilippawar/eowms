/**
 * EOWMS Welcome Chime
 * ---------------------------------------------------------------
 * 1) Super Admin ने "संस्था व्यवस्थापन → Edit" मधून Welcome Music
 *    Upload केलं असेल, तर तेच वाजतं (Database मध्ये मार्ग साठवलेला).
 * 2) काहीच Upload केलेलं नसेल तर, ब्राऊझरमध्येच (Web Audio API)
 *    तयार होणारा उबदार Temple-Bell आवाज वाजतो — कुठलीही File न लागता.
 * 3) दोन्ही पद्धतीत — आवाज जास्तीत जास्त 10 सेकंदांनी आपोआप थांबतो.
 * 4) Browsers पहिल्या User-Interaction आधी आवाजासह Autoplay रोखतात —
 *    हा Browser चा सुरक्षा-नियम आहे, Website बदलू शकत नाही. त्यामुळे
 *    Autoplay अडलं तर "🔔 आवाजासाठी टॅप करा" अशी छोटी सूचना दिसते.
 */
(function () {
  const DURATION_MS = 10000;

  function playGeneratedChime() {
    try {
      const ctx = new (window.AudioContext || window.webkitAudioContext)();
      if (ctx.state === 'suspended') {
        // Browser is blocking audio until a real user gesture happens —
        // report this honestly instead of pretending it played.
        ctx.close();
        return false;
      }
      const now = ctx.currentTime;
      const master = ctx.createGain();
      master.gain.setValueAtTime(0.0001, now);
      master.gain.exponentialRampToValueAtTime(0.35, now + 0.05);
      master.connect(ctx.destination);

      // A singing-bowl/temple-bell tone: a fundamental + a few slightly
      // detuned harmonic overtones, each with its own slow decay.
      const partials = [
        { freq: 261.6, gain: 0.6, decay: 6.5 },   // fundamental (C4)
        { freq: 392.0, gain: 0.28, decay: 5.0 },  // fifth
        { freq: 523.3, gain: 0.18, decay: 4.0 },  // octave
        { freq: 659.3, gain: 0.10, decay: 3.2 },  // third above
        { freq: 785.5, gain: 0.06, decay: 2.6 },
      ];

      partials.forEach((p) => {
        const osc = ctx.createOscillator();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(p.freq, now);
        const g = ctx.createGain();
        g.gain.setValueAtTime(p.gain, now);
        g.gain.exponentialRampToValueAtTime(0.0001, now + p.decay);
        osc.connect(g);
        g.connect(master);
        osc.start(now);
        osc.stop(now + p.decay + 0.2);
      });

      setTimeout(() => { try { ctx.close(); } catch (e) {} }, DURATION_MS);
      return true;
    } catch (e) {
      return false;
    }
  }

  function showTapPrompt(playFn) {
    const pill = document.createElement('button');
    pill.textContent = '🔔 आवाजासाठी टॅप करा';
    pill.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;' +
      'background:#1B8A83;color:#fff;border:none;border-radius:24px;padding:11px 20px;' +
      'font-size:13px;font-weight:600;font-family:"Noto Sans Devanagari",sans-serif;' +
      'box-shadow:0 6px 20px rgba(0,0,0,.25);cursor:pointer;animation:eowms-pill-in .4s ease;';
    const style = document.createElement('style');
    style.textContent = '@keyframes eowms-pill-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}';
    document.head.appendChild(style);
    pill.onclick = function () {
      playFn();
      pill.remove();
    };
    document.body.appendChild(pill);
    setTimeout(() => pill.remove(), DURATION_MS + 2000);
  }

  // basePath — welcome.php (root) वरून बोलावलं की रिकामं द्या; subfolder
  // (उदा. staff/) मधून बोलावलं तर "../" द्या. audioPath — प्रोजेक्ट-रूट
  // पासूनचा संपूर्ण मार्ग (उदा. "assets/uploads/welcome-audio/xyz.mp3"
  // — Super Admin ने Panel मधून Upload केलेली, किंवा जुनी
  // assets/audio/ फोल्डर-पद्धत — दोन्हीसाठी हेच वापरलं जातं).
  window.eowmsPlayWelcomeChime = function (basePath, audioPath) {
    basePath = basePath || '';
    if (!audioPath) {
      // कुठलीच audio file सापडली नाही — थेट घंटानाद वाजवा
      if (!playGeneratedChime()) { showTapPrompt(playGeneratedChime); }
      return;
    }
    const fullAudioPath = basePath + audioPath + '?v=' + Date.now(); // cache-bust
    const audio = new Audio(fullAudioPath);
    audio.volume = 0.55;

    const playPromise = audio.play();
    if (playPromise !== undefined) {
      playPromise
        .then(() => {
          console.log('[EOWMS Chime] तुमची audio file वाजतेय ✓ (' + audioPath + ')');
          setTimeout(() => { audio.pause(); audio.currentTime = 0; }, DURATION_MS);
        })
        .catch((err) => {
          console.warn('[EOWMS Chime] "' + audioPath + '" वाजली नाही (कारण: ' + err.name + ') — पर्यायी घंटानाद वापरत आहे.', fullAudioPath);
          const ok = playGeneratedChime();
          if (!ok) {
            showTapPrompt(function () {
              const retryAudio = new Audio(fullAudioPath);
              retryAudio.volume = 0.55;
              retryAudio.play()
                .then(() => setTimeout(() => { retryAudio.pause(); }, DURATION_MS))
                .catch(function () { playGeneratedChime(); });
            });
          }
        });
    }
  };
})();
