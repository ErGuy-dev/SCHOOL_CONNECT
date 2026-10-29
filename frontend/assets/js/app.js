/* SchoolConnect : interactions natives. Aucune sécurité ici : le serveur décide de tout. */
(function () {
  'use strict';
  var side = document.querySelector('.sidebar');
  var btn = document.querySelector('.menu-btn');
  if (btn && side) {
    btn.addEventListener('click', function () {
      var open = side.classList.toggle('open');
      btn.setAttribute('aria-expanded', open);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') side.classList.remove('open');
    });
  }

  /* Validation visuelle des notes (le contrôle réel se fera côté PHP) */
  document.querySelectorAll('.note-input').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var v = parseFloat(inp.value), max = parseFloat(inp.max);
      inp.classList.toggle('invalid', inp.value !== '' && (isNaN(v) || v < 0 || v > max));
    });
  });

  /* Confirmation par saisie d'un mot (restauration, suppression) */
  document.querySelectorAll('[data-confirm-input]').forEach(function (inp) {
    var target = document.querySelector(inp.dataset.confirmInput);
    inp.addEventListener('input', function () {
      target.disabled = inp.value.trim() !== inp.dataset.word;
    });
  });

  /* Zoom du visualiseur de copies */
  document.querySelectorAll('[data-zoom]').forEach(function (b) {
    b.addEventListener('click', function () {
      var v = document.querySelector('.viewer');
      var z = parseFloat(v.style.getPropertyValue('--zoom') || 1);
      var d = b.dataset.zoom;
      z = d === 'reset' ? 1 : Math.min(3, Math.max(.5, z + (d === 'in' ? .25 : -.25)));
      v.style.setProperty('--zoom', z);
    });
  });

  /* Messages de démonstration : les formulaires statiques n'envoient rien */
  document.querySelectorAll('form[data-demo]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var m = f.querySelector('.mssg');
      if (m) { m.hidden = false; m.focus && m.focus(); }
    });
  });
})();
