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

  // Row-level status selects submit themselves; a noscript button covers the
  // no-JavaScript case.
  document.addEventListener('change', function (event) {
    var select = event.target.closest('select[data-autosubmit]');
    if (select && select.form) {
      select.form.submit();
    }
  });

  // Show the chosen file name inside the branded upload control.
  document.addEventListener('change', function (event) {
    var input = event.target.closest('input[data-uploader]');
    if (!input) {
      return;
    }
    var label = input.closest('.uploader');
    var name = label ? label.querySelector('[data-uploader-name]') : null;
    if (!name) {
      return;
    }
    if (input.files && input.files.length > 0) {
      name.textContent = input.files[0].name;
      label.classList.add('is-set');
    } else {
      name.textContent = 'JPEG, PNG, WebP';
      label.classList.remove('is-set');
    }
  });

  // Type-ahead for the catalog search. The form submits normally without it.
  (function () {
    var form = document.querySelector('[data-suggest]');
    if (!form || !window.fetch) {
      return;
    }

    var input = form.querySelector('input[type="search"]');
    var list = form.querySelector('.suggest');
    if (!input || !list) {
      return;
    }

    var items = [];
    var cursor = -1;
    var timer = null;
    var latest = 0;

    var close = function () {
      list.hidden = true;
      list.innerHTML = '';
      items = [];
      cursor = -1;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    };

    var move = function (delta) {
      if (items.length === 0) {
        return;
      }
      if (cursor >= 0) {
        items[cursor].classList.remove('is-active');
      }
      cursor = (cursor + delta + items.length) % items.length;
      items[cursor].classList.add('is-active');
      input.setAttribute('aria-activedescendant', items[cursor].id);
      items[cursor].scrollIntoView({ block: 'nearest' });
    };

    var render = function (results) {
      if (results.length === 0) {
        close();
        return;
      }
      list.innerHTML = '';
      results.forEach(function (item, index) {
        var li = document.createElement('li');
        li.id = 'q-suggest-' + index;
        li.className = 'suggest__item';
        li.setAttribute('role', 'option');

        var link = document.createElement('a');
        link.href = '/spezi/' + item.slug;

        var figure = document.createElement('span');
        figure.className = 'suggest__img';
        if (item.image) {
          var img = document.createElement('img');
          img.src = item.image;
          img.alt = '';
          img.loading = 'lazy';
          figure.appendChild(img);
        }

        var body = document.createElement('span');
        body.className = 'suggest__body';
        var name = document.createElement('span');
        name.className = 'suggest__name';
        name.textContent = item.name;
        body.appendChild(name);
        if (item.sub) {
          var sub = document.createElement('span');
          sub.className = 'suggest__sub';
          sub.textContent = item.sub;
          body.appendChild(sub);
        }

        link.appendChild(figure);
        link.appendChild(body);
        if (item.rank) {
          var rank = document.createElement('span');
          rank.className = 'suggest__rank';
          rank.textContent = '#' + item.rank;
          link.appendChild(rank);
        }

        li.appendChild(link);
        list.appendChild(li);
      });

      items = Array.prototype.slice.call(list.querySelectorAll('.suggest__item'));
      cursor = -1;
      list.hidden = false;
      input.setAttribute('aria-expanded', 'true');
    };

    var lookup = function () {
      var term = input.value.trim();
      if (term.length < 2) {
        close();
        return;
      }
      var token = ++latest;
      fetch('/spezis/vorschlaege?q=' + encodeURIComponent(term), {
        headers: { Accept: 'application/json' }
      })
        .then(function (response) {
          return response.ok ? response.json() : { items: [] };
        })
        .then(function (data) {
          // Ignore responses that a newer keystroke has already superseded.
          if (token === latest) {
            render(data.items || []);
          }
        })
        .catch(close);
    };

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(lookup, 140);
    });

    input.addEventListener('keydown', function (event) {
      if (list.hidden) {
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        move(1);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        move(-1);
      } else if (event.key === 'Enter' && cursor >= 0) {
        event.preventDefault();
        var link = items[cursor].querySelector('a');
        if (link) {
          window.location.href = link.href;
        }
      } else if (event.key === 'Escape') {
        close();
      }
    });

    document.addEventListener('click', function (event) {
      if (!form.contains(event.target)) {
        close();
      }
    });
  })();

  // Origin map. Without this the dots are plain anchors into the list beside
  // them, which is why nothing here is required for the map to be usable.
  (function () {
    var canvas = document.querySelector('[data-map]');
    var list = document.querySelector('[data-map-list]');
    var readout = document.querySelector('[data-map-readout]');
    if (!canvas || !list || !readout) {
      return;
    }

    var dots = Array.prototype.slice.call(canvas.querySelectorAll('[data-map-dot]'));
    var highlighted = null;
    var pinned = null;

    var dotFor = function (key) {
      return canvas.querySelector('[data-map-dot="' + key + '"]');
    };

    var highlight = function (key) {
      if (highlighted === key) {
        return;
      }
      if (highlighted) {
        var previous = dotFor(highlighted);
        if (previous) {
          previous.classList.remove('is-active');
        }
      }
      highlighted = key;
      if (key) {
        var dot = dotFor(key);
        if (dot) {
          dot.classList.add('is-active');
        }
      }
    };

    // Returning to the overview keeps the full list one click away.
    var closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'map__back';
    closeButton.textContent = 'Alle Regionen';

    var scroller = document.querySelector('[data-map-scroller]');

    var reset = function () {
      pinned = null;
      highlight(null);
      readout.hidden = true;
      readout.innerHTML = '';
      list.hidden = false;
      if (scroller) {
        scroller.classList.remove('is-detail');
      }
    };

    closeButton.addEventListener('click', reset);

    // The readout replaces the list in the same slot, so opening a region does
    // not shift the page.
    var open = function (key) {
      var entry = list.querySelector('[data-map-entry="' + key + '"]');
      if (!entry) {
        return;
      }
      pinned = key;
      highlight(key);
      readout.innerHTML = entry.innerHTML;
      // Above the list, not below it: the scroll pane has to stay the last
      // element so the scroll hint at the panel's edge cannot cover the link.
      readout.insertBefore(closeButton, readout.firstChild);
      readout.hidden = false;
      list.hidden = true;
      if (scroller) {
        scroller.classList.add('is-detail');
      }
    };

    dots.forEach(function (dot) {
      var key = dot.getAttribute('data-map-dot');
      dot.addEventListener('mouseenter', function () {
        open(key);
      });
      dot.addEventListener('focus', function () {
        open(key);
      });
      dot.addEventListener('click', function (event) {
        event.preventDefault();
        open(key);
      });
    });

    // Reading the list highlights the matching dot, without taking the list away.
    list.addEventListener('mouseover', function (event) {
      var entry = event.target.closest('[data-map-entry]');
      highlight(entry ? entry.getAttribute('data-map-entry') : null);
    });
    list.addEventListener('mouseleave', function () {
      if (!pinned) {
        highlight(null);
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && pinned) {
        reset();
      }
    });

    // Show the "more" affordance only while the visible list can still scroll.
    if (scroller) {
      var syncHint = function () {
        var pane = readout.hidden ? list : readout.querySelector('.map__drinks');
        var atEnd = !pane || pane.scrollTop + pane.clientHeight >= pane.scrollHeight - 4;
        scroller.classList.toggle('is-scrollable', !atEnd);
      };

      list.addEventListener('scroll', syncHint);
      readout.addEventListener('scroll', syncHint, true);
      window.addEventListener('resize', syncHint);
      dots.forEach(function (dot) {
        dot.addEventListener('mouseenter', function () {
          window.setTimeout(syncHint, 0);
        });
      });
      closeButton.addEventListener('click', function () {
        window.setTimeout(syncHint, 0);
      });
      syncHint();
    }
  })();

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
