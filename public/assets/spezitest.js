/* Spezitest — minimal progressive enhancement.
   The site works without JavaScript; this only adds conveniences.
   Adapted from the design system's design/preview.js. */
(function () {
  'use strict';

  // Mobile navigation toggle.
  document.addEventListener('click', function (event) {
    var toggle = event.target.closest('[data-toggle]');
    if (!toggle) {
      return;
    }
    var target = document.getElementById(toggle.getAttribute('data-toggle'));
    if (!target) {
      return;
    }
    var willOpen = target.hasAttribute('hidden');
    if (willOpen) {
      target.removeAttribute('hidden');
    } else {
      target.setAttribute('hidden', '');
    }
    toggle.setAttribute('aria-expanded', String(willOpen));
  });

  // Live "Gesamtwertung" preview on the test-entry form. Category weights are
  // 1 / 2 / 3 (Optik / Süffigkeit / Geschmack); this mirrors the verified
  // server-side engine for display only — the real value is always computed
  // and stored on the server.
  var form = document.querySelector('[data-test-form]');
  var output = document.querySelector('[data-gesamt-preview]');
  if (form && output) {
    var CATEGORIES = { optik: 1, sueffigkeit: 2, geschmack: 3 };
    var TESTERS = ['manu', 'fabi', 'schorsch'];

    var recompute = function () {
      var weightedSum = 0;
      for (var category in CATEGORIES) {
        if (!Object.prototype.hasOwnProperty.call(CATEGORIES, category)) {
          continue;
        }
        var total = 0;
        var count = 0;
        for (var i = 0; i < TESTERS.length; i++) {
          var field = form.elements[TESTERS[i] + '_' + category];
          var raw = field && field.value !== '' ? Number(field.value) : NaN;
          if (!isNaN(raw)) {
            total += raw;
            count += 1;
          }
        }
        if (count !== TESTERS.length) {
          output.textContent = '–';
          return;
        }
        weightedSum += (total / TESTERS.length) * CATEGORIES[category];
      }
      output.textContent = (Math.round(weightedSum * 100) / 100)
        .toFixed(2)
        .replace('.', ',');
    };

    form.addEventListener('input', recompute);
    form.addEventListener('change', recompute);
    recompute();
  }
})();
