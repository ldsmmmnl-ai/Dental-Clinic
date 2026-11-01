/* motion.js — small shared motion helpers for the app (status badge pop, toast exit).
   Built from anime.js 4.5.0 (MIT, © Julian Garnier): waapi.animate (the 3 KB Web Animations version).
   Source: assets/js/motion.src.js  ·  Rebuild: see "Rebuilding the JS bundles" in DESIGN-CHANGES.md */
import { waapi } from 'animejs/waapi';

(function () {
  // Read live, so changing the OS "reduce motion" setting takes effect without a reload.
  function reduced() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  // Short scale "pop" on an element, e.g. a status badge that just changed.
  function pop(el) {
    if (!el || reduced()) return;
    try {
      waapi.animate(el, { scale: [1, 1.18, 1], duration: 360, ease: 'outQuad' });
    } catch (e) { /* animation is decoration only — never break the page */ }
  }

  // Fade + slide an element out, then call done() (usually: remove it from the DOM).
  // done() is always called exactly once, even if the animation fails or is skipped.
  function leave(el, done) {
    var called = false;
    function finish() { if (called) return; called = true; if (done) done(); }
    if (!el || reduced()) { finish(); return; }
    try {
      el.style.pointerEvents = 'none';
      waapi.animate(el, { opacity: [1, 0], translate: ['0 0', '0 -0.5rem'], duration: 200, ease: 'inQuad', onComplete: finish });
      setTimeout(finish, 450);   // safety net
    } catch (e) { finish(); }
  }

  // How long to wait before a full page reload so a just-played animation can be seen.
  function delay() { return reduced() ? 0 : 420; }

  window.Motion = { pop: pop, leave: leave, delay: delay };
})();