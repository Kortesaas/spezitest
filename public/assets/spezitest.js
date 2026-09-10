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

  // "Mehr" navigation menu. Hover and keyboard focus are handled in CSS; this
  // adds click/tap toggling, Escape, and outside-click dismissal.
  var moreNav = document.querySelector('.nav__more');
  if (moreNav) {
    var moreButton = moreNav.querySelector('.nav__more-btn');
    var setMoreOpen = function (open) {
      moreNav.classList.toggle('is-open', open);
      moreButton.setAttribute('aria-expanded', String(open));
    };
    moreButton.addEventListener('click', function () {
      setMoreOpen(!moreNav.classList.contains('is-open'));
    });
    document.addEventListener('click', function (event) {
      if (!moreNav.contains(event.target)) {
        setMoreOpen(false);
      }
    });
    moreNav.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && moreNav.classList.contains('is-open')) {
        setMoreOpen(false);
        moreButton.focus();
      }
    });
  }

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

  // One-tap presets beside a numeric field (e.g. 330 / 500 / 1000 ml). The
  // field stays a normal input, so it works without this.
  document.addEventListener('click', function (event) {
    var preset = event.target.closest('[data-fill]');
    if (!preset) {
      return;
    }
    var target = document.getElementById(preset.getAttribute('data-fill'));
    if (!target) {
      return;
    }
    target.value = preset.getAttribute('data-fill-value') || '';
    target.dispatchEvent(new Event('input', { bubbles: true }));
    target.focus();
  });

  // Catalog "Mehr laden": fetch the wider page and swap the results block in
  // place, so the grid grows without losing the scroll position. The button is
  // a real link to a real URL, so without this it simply navigates there.
  (function () {
    var container = document.querySelector('[data-catalog-results]');
    if (!container || !window.fetch || !window.history) {
      return;
    }

    var busy = false;

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-load-more]');
      if (!trigger || !container.contains(trigger)) {
        return;
      }
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) {
        return;
      }

      event.preventDefault();
      if (busy) {
        return;
      }
      busy = true;

      var wrapper = trigger.closest('.load-more');
      if (wrapper) {
        wrapper.classList.add('is-busy');
      }
      trigger.setAttribute('aria-busy', 'true');

      var url = trigger.href;
      var firstNewCard = container.querySelectorAll('.grid--cards > *').length;

      fetch(url, { headers: { Accept: 'text/html' } })
        .then(function (response) {
          return response.ok ? response.text() : null;
        })
        .then(function (html) {
          if (html === null) {
            window.location.href = url;
            return;
          }

          var parsed = new DOMParser().parseFromString(html, 'text/html');
          var fresh = parsed.querySelector('[data-catalog-results]');
          if (!fresh) {
            window.location.href = url;
            return;
          }

          container.innerHTML = fresh.innerHTML;
          // Drop the #ergebnisse fragment: the page has not jumped anywhere.
          window.history.replaceState({}, '', url.split('#')[0]);

          // Move focus to the first Spezi that was not there before, so the
          // keyboard lands on the new items instead of the page top.
          var cards = container.querySelectorAll('.grid--cards > a');
          var target = cards[firstNewCard];
          if (target) {
            target.setAttribute('tabindex', '-1');
            target.focus({ preventScroll: true });
          }
        })
        .catch(function () {
          window.location.href = url;
        })
        .then(function () {
          busy = false;
        });
    });
  })();
  // Type-ahead for catalogue searches. The public catalogue and the admin
  // Spezi list share this behaviour; each page controls where a selected
  // suggestion leads through its data-suggest-href template.
  (function () {
    var forms = document.querySelectorAll('[data-suggest]');
    if (forms.length === 0 || !window.fetch) {
      return;
    }

    Array.prototype.forEach.call(forms, function (form) {
      var input = form.querySelector('input[type="search"]');
      var list = form.querySelector('.suggest');
      if (!input || !list) {
        return;
      }

      var items = [];
      var cursor = -1;
      var timer = null;
      var latest = 0;
      var hrefTemplate = form.getAttribute('data-suggest-href') || '/spezi/{slug}';

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
          li.id = list.id + '-' + index;
          li.className = 'suggest__item';
          li.setAttribute('role', 'option');

          var link = document.createElement('a');
          link.href = hrefTemplate
            .replace('{id}', encodeURIComponent(String(item.id)))
            .replace('{slug}', encodeURIComponent(String(item.slug)));

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
    var steps = form.querySelectorAll('[data-progress-steps] i');
    var counter = form.querySelector('[data-progress-count]');

    var setAverage = function (category, value) {
      var field = form.querySelector('[data-category-avg="' + category + '"]');
      if (field) {
        field.textContent =
          value === null
            ? 'Ø –'
            : 'Ø ' + (Math.round(value * 100) / 100).toFixed(2).replace('.', ',');
      }
    };

    var recompute = function () {
      var weightedSum = 0;
      var complete = true;
      var filled = 0;

      for (var category in CATEGORIES) {
        if (!Object.prototype.hasOwnProperty.call(CATEGORIES, category)) {
          continue;
        }
        var total = 0;
        var count = 0;
        for (var i = 0; i < TESTERS.length; i++) {
          var field = form.elements[TESTERS[i] + '_' + category];
          var raw = field && field.value !== '' ? Number(field.value) : NaN;
          var row = field && field.length ? field[0].closest('.graderow') : null;
          var label = row ? row.querySelector('.graderow__value') : null;
          if (!isNaN(raw)) {
            total += raw;
            count += 1;
            filled += 1;
            if (label) {
              label.textContent = raw + ' / 10';
            }
          } else if (label) {
            label.textContent = 'keine Note';
          }
        }
        if (count !== TESTERS.length) {
          complete = false;
          setAverage(category, null);
        } else {
          setAverage(category, total / TESTERS.length);
          weightedSum += (total / TESTERS.length) * CATEGORIES[category];
        }
      }

      output.textContent = complete
        ? (Math.round(weightedSum * 100) / 100).toFixed(2).replace('.', ',')
        : '–';

      // The progress strip mirrors how many of the nine notes are set.
      for (var step = 0; step < steps.length; step++) {
        steps[step].classList.toggle('is-set', step < filled);
      }
      if (counter) {
        counter.textContent = String(filled);
      }
    };

    form.addEventListener('input', recompute);
    form.addEventListener('change', recompute);
    recompute();
  }

  // Live "je 0,5 l" preview on the drink edit form's price field. Display
  // only — the server always recomputes the real value from the stored price
  // and volume.
  var priceForm = document.querySelector('[data-price-form]');
  var pricePreview = priceForm ? priceForm.querySelector('[data-price-preview]') : null;
  if (priceForm && pricePreview) {
    var recomputePrice = function () {
      var priceField = priceForm.querySelector('[name="price"]');
      var volumeField = priceForm.querySelector('[name="price_volume_ml"]');
      var price = priceField ? parseFloat(String(priceField.value).replace(',', '.')) : NaN;
      var volume = volumeField ? parseFloat(volumeField.value) : NaN;
      if (isNaN(price) || isNaN(volume) || volume <= 0) {
        pricePreview.textContent = '–';
        return;
      }
      var perHalfLitre = (price * 500) / volume;
      pricePreview.textContent = perHalfLitre.toFixed(2).replace('.', ',') + ' €';
    };
    priceForm.addEventListener('input', recomputePrice);
    recomputePrice();
  }

  // Test-result reveal: one click un-blurs every spoiler in scope together.
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-reveal-trigger]');
    if (!trigger) {
      return;
    }
    var scope = document.querySelector('[data-reveal-scope]');
    if (scope) {
      scope.classList.add('is-revealed');
    }
    trigger.setAttribute('hidden', '');
  });

  // Statistik: bar charts grow into place the first time they scroll into
  // view, instead of just appearing. The server-rendered inline width is the
  // real, correct value throughout — this only replays it as a transition.
  (function () {
    var tracks = Array.prototype.slice.call(document.querySelectorAll('.barchart__track i'));
    if (tracks.length === 0) {
      return;
    }

    if (!window.IntersectionObserver) {
      return;
    }

    tracks.forEach(function (track) {
      track.dataset.grownWidth = track.style.width;
      track.style.width = '0%';
    });

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) {
            return;
          }
          entry.target.style.width = entry.target.dataset.grownWidth || '0%';
          observer.unobserve(entry.target);
        });
      },
      { threshold: 0.4 },
    );

    tracks.forEach(function (track) {
      observer.observe(track);
    });
  })();

  // Statistik: clicking a Gesamtwertung bar reveals which Spezis landed in
  // that range. Keyboard-operable since the row is a real [role=button].
  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('[data-bin-trigger]');
    if (!trigger) {
      return;
    }
    toggleBin(trigger);
  });
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') {
      return;
    }
    var trigger = event.target.closest('[data-bin-trigger]');
    if (!trigger) {
      return;
    }
    event.preventDefault();
    toggleBin(trigger);
  });

  function toggleBin(trigger) {
    var detail = trigger.nextElementSibling;
    if (!detail || !detail.classList.contains('barchart__detail')) {
      return;
    }
    var willOpen = detail.hidden;
    detail.hidden = !willOpen;
    trigger.setAttribute('aria-expanded', String(willOpen));
  }

  // Statistik: hovering or focusing a Preis/Leistung scatter dot shows its
  // details in the readout beneath the chart. Every dot is still a plain
  // link to the Spezi, so clicking works with or without this.
  (function () {
    var scatter = document.querySelector('[data-scatter]');
    var readout = scatter ? scatter.querySelector('[data-scatter-readout]') : null;
    if (!scatter || !readout) {
      return;
    }

    var show = function (dot) {
      readout.innerHTML = '';

      var name = document.createElement('strong');
      name.textContent = dot.getAttribute('data-scatter-name') || '';

      var price = document.createElement('span');
      price.textContent = dot.getAttribute('data-scatter-price') || '';

      var gesamt = document.createElement('span');
      gesamt.textContent = dot.getAttribute('data-scatter-gesamt') || '';

      var score = document.createElement('span');
      score.textContent = 'Preis/Leistung: ' + (dot.getAttribute('data-scatter-score') || '');

      readout.appendChild(name);
      readout.appendChild(price);
      readout.appendChild(gesamt);
      readout.appendChild(score);
      readout.hidden = false;
    };

    Array.prototype.slice.call(scatter.querySelectorAll('.scatter__dot')).forEach(function (dot) {
      dot.addEventListener('mouseenter', function () {
        show(dot);
      });
      dot.addEventListener('focus', function () {
        show(dot);
      });
    });
  })();

  // Spezi detail: the per-tester pill above a category bar is centred on the
  // pointer (clamped so it stays over the bar), so it reads as coming out of
  // the spot being hovered. Cosmetic — the pill is CSS hover/focus and works
  // without this, falling back to centred.
  Array.prototype.forEach.call(document.querySelectorAll('.rating--peek'), function (row) {
    var pill = row.querySelector('.rating__peek');
    row.addEventListener('pointermove', function (event) {
      var rect = row.getBoundingClientRect();
      if (rect.width === 0) {
        return;
      }
      var half = pill ? pill.offsetWidth / 2 : 0;
      var x = Math.max(half, Math.min(rect.width - half, event.clientX - rect.left));
      row.style.setProperty('--peek-x', x + 'px');
    });
    row.addEventListener('pointerleave', function () {
      row.style.removeProperty('--peek-x');
    });
  });

  // Spezistream planning: quick bulk selection remains an optional convenience;
  // the form and every state change still work without JavaScript.
  Array.prototype.forEach.call(document.querySelectorAll('.run-picker'), function (picker) {
    var form = picker.closest('form');
    if (!form) {
      return;
    }
    var setAll = function (checked) {
      Array.prototype.forEach.call(picker.querySelectorAll('input[type="checkbox"]'), function (input) {
        input.checked = checked;
      });
    };
    var all = form.querySelector('[data-check-all]');
    var none = form.querySelector('[data-check-none]');
    if (all) {
      all.addEventListener('click', function () { setAll(true); });
    }
    if (none) {
      none.addEventListener('click', function () { setAll(false); });
    }
  });

  // Open the Spezirad as a viewport-sized modal over the Spezistream overview.
  (function () {
    var opener = document.querySelector('[data-wheel-open]');
    var modal = document.querySelector('[data-wheel-modal]');
    if (!opener || !modal) {
      return;
    }
    opener.addEventListener('click', function () {
      if (typeof modal.showModal === 'function') {
        modal.showModal();
      } else {
        modal.setAttribute('open', '');
      }
    });
    // Click on the backdrop (outside the dialog box) closes it.
    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        modal.close();
      }
    });
  })();

  // The Spezirad randomly chooses from the acquired, untested evening lineup.
  (function () {
    var stage = document.querySelector('[data-lucky-wheel]');
    if (!stage) {
      return;
    }

    var wheel = stage.querySelector('[data-wheel]');
    var labels = stage.querySelector('[data-wheel-labels]');
    var spin = stage.querySelector('[data-wheel-spin]');
    var result = stage.querySelector('[data-wheel-result]');
    var testLink = stage.querySelector('[data-wheel-test]');
    var items;

    try {
      items = JSON.parse(stage.getAttribute('data-items') || '[]');
    } catch (_error) {
      return;
    }

    if (!wheel || !labels || !spin || !result || items.length === 0) {
      return;
    }

    var step = 360 / items.length;
    var colors = [];
    items.forEach(function (item, index) {
      var label = document.createElement('span');
      label.textContent = item.name;
      var mid = index * step + step / 2;
      var angle = mid * Math.PI / 180;
      var radius = items.length <= 4 ? 24 : 28;
      label.style.left = (50 + Math.sin(angle) * radius) + '%';
      label.style.top = (50 - Math.cos(angle) * radius) + '%';
      label.style.setProperty('--wheel-label-angle', (mid - 90) + 'deg');
      labels.appendChild(label);
      var navy = index % 2 === 0;
      colors.push((navy ? 'rgba(0,45,85,.9)' : 'rgba(230,0,5,.86)') + ' ' + (index * step) + 'deg ' + ((index + 1) * step) + 'deg');
    });
    wheel.style.setProperty('--wheel-segments', 'conic-gradient(from 0deg,' + colors.join(',') + ')');

    var turns = 0;
    var spinning = false;
    var randomIndex = function () {
      if (window.crypto && window.crypto.getRandomValues) {
        var values = new Uint32Array(1);
        window.crypto.getRandomValues(values);
        return values[0] % items.length;
      }
      return Math.floor(Math.random() * items.length);
    };

    spin.addEventListener('click', function () {
      if (spinning) {
        return;
      }
      var index = randomIndex();

      spinning = true;
      spin.disabled = true;
      result.classList.remove('is-ready');
      result.querySelector('strong').textContent = 'Das Rad dreht …';
      testLink.hidden = true;

      // Land the chosen wedge under the pointer on the right (90deg from top).
      // A random offset within the wedge (kept clear of the edges) stops it
      // always halting dead-centre, so the label still reads roughly straight
      // across but the wheel never looks mechanical.
      var centre = index * step + step / 2;
      var jitter = (Math.random() - 0.5) * Math.min(step * 0.62, 34);
      var desired = ((90 - centre - jitter) % 360 + 360) % 360;
      var current = ((turns % 360) + 360) % 360;
      var advance = (desired - current + 360) % 360;
      turns += 360 * 7 + advance;
      wheel.style.transform = 'rotate(' + turns + 'deg)';

      var done = function () {
        if (!spinning) {
          return;
        }
        spinning = false;
        spin.disabled = false;
        result.querySelector('strong').textContent = items[index].name;
        result.classList.add('is-ready');
        testLink.href = '/admin/drinks/' + items[index].id + '/test';
        testLink.hidden = false;
      };
      wheel.addEventListener('transitionend', done, { once: true });
      window.setTimeout(done, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 100 : 9300);
    });
  })();
})();
