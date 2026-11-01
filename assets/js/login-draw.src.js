/* login-draw.js — login page intro: headline words rise in, tooth outline draws itself. Plays once.
   Built from anime.js 4.5.0 (MIT, © Julian Garnier): animate, svg.createDrawable, splitText, stagger.
   Source: assets/js/login-draw.src.js  ·  Rebuild: see the note at the bottom of DESIGN-CHANGES.md */
import { animate } from 'animejs/animation';
import { createDrawable } from 'animejs/svg';
import { splitText } from 'animejs/text';
import { stagger } from 'animejs/utils';

(function () {
  var root = document.documentElement;
  var path = document.querySelector('.login-aside-mark path');
  var head = document.querySelector('.login-pitch h1');

  // Both the tooth and the headline live in the brand panel, which is display:none on small screens.
  var panel = document.querySelector('.login-aside-mark');
  var hidden = !panel || !panel.getClientRects().length;
  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Reduced motion or hidden panel: leave everything fully visible and static.
  if (hidden || reduced) {
    root.classList.remove('js-draw');
    return;
  }

  var words = null;
  var drawables = null;

  // Headline: split into words (anime.js keeps a hidden full-text copy, so screen readers still read the sentence).
  try {
    if (head) {
      words = splitText(head, { words: true }).words;
      words.forEach(function (w) { w.style.opacity = '0'; });   // hidden before the CSS cover is lifted
    }
  } catch (e) { words = null; }

  // Tooth outline.
  try {
    if (path) {
      drawables = createDrawable(path);   // Proxy of the <path> with an extra `draw` property
      drawables[0].draw = '0 0';          // nothing drawn yet
    }
  } catch (e) { drawables = null; }

  root.classList.remove('js-draw');           // CSS hides both until this point (no flash of the finished state)

  if (words) {
    try {
      animate(words, {
        opacity: [0, 1],
        translateY: ['0.4em', '0em'],
        ease: 'outQuad',
        duration: 550,
        delay: stagger(45, { start: 100 })   // 8 words → done in under a second
      });
    } catch (e) {
      words.forEach(function (w) { w.style.opacity = ''; });   // anything goes wrong → plain headline
    }
  }

  if (drawables) {
    try {
      animate(drawables, {
        draw: ['0 0', '0 1'],
        ease: 'inOutQuad',
        duration: 2200,
        delay: 400                      // lines up with the visit-flow track that fills in on the left
      });
    } catch (e) { drawables[0].draw = '0 1'; }                   // anything goes wrong → static outline
  }
})();