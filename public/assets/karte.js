/* Spezitest — the hunt map on /karte.
   Progressive enhancement: the page already lists every place and link beside
   the map, so this only turns the container into an interactive Leaflet map.
   Needs /assets/leaflet/leaflet.js (loaded before this file).

   Tiles come from the site's own /karte/kachel/ route, so the visitor's
   browser never talks to a third-party map host. The optional "In meiner Nähe"
   button uses the browser geolocation API; the position is used only in this
   page to sort the list and never leaves the device. */
(function () {
  'use strict';

  var NAVY = '#002D55';
  var RED = '#E60005';
  var WHITE = '#FFFFFF';

  var container = document.getElementById('karte-map');
  var blob = document.getElementById('karte-data');

  if (!container || !blob || typeof window.L === 'undefined') {
    return;
  }

  var markers;

  try {
    markers = JSON.parse(blob.textContent || '{}').markers || [];
  } catch (error) {
    return;
  }

  if (!markers.length) {
    return;
  }

  var map = L.map(container, {
    scrollWheelZoom: false,
    minZoom: 5,
    maxZoom: 12,
    maxBoundsViscosity: 1
  });

  map.on('focus', function () { map.scrollWheelZoom.enable(); });
  map.on('blur', function () { map.scrollWheelZoom.disable(); });

  // First-party tiles. The pane is desaturated in CSS so the red markers lead.
  L.tileLayer('/karte/kachel/{z}/{x}/{y}.png', {
    minZoom: 5,
    maxZoom: 12,
    attribution:
      'Karte: &copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende'
  }).addTo(map);

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, function (ch) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
    });
  }

  function coordText(point) {
    return point.lat.toFixed(5) + ', ' + point.lon.toFixed(5);
  }

  function pinLabel(point) {
    return point.drinks.length === 1
      ? point.drinks[0].name
      : point.place + ' · ' + point.drinks.length + ' Spezis';
  }

  // A phone with the Google Maps app already opens it from the web link, but
  // "geo:" lets an Android user open their own default map app (OsmAnd, Organic
  // Maps, …). Desktop and iOS have no "geo:" handler, so it is only added where
  // it actually does something.
  var GEO_LINK = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches) &&
    !/iPad|iPhone|iPod/i.test(navigator.userAgent);

  // Tray-and-arrow download glyph, matched to the server-rendered ICON_DOWNLOAD.
  var DL_ICON = '<svg class="btn__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
    ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<path d="M12 3v11M8 10l4 4 4-4M5 20h14"/></svg>';

  function mapLinksHtml(point) {
    var lat = point.lat;
    var lon = point.lon;
    var q = lat + ',' + lon;
    var enc = encodeURIComponent(pinLabel(point));
    var html = '';
    if (GEO_LINK) {
      html += '<a href="geo:' + q + '?q=' + q + '(' + enc + ')">Karten-App</a>';
    }
    // OpenStreetMap and Apple Maps keep the label on the pin; Google Maps drops
    // any label given with coordinates, so it just gets the exact spot. The GPX
    // link is a real file from this origin — on a phone it opens in a map app.
    html += '<a href="https://www.openstreetmap.org/?mlat=' + lat + '&mlon=' + lon +
      '#map=15/' + lat + '/' + lon + '" target="_blank" rel="noopener nofollow">OpenStreetMap</a>';
    html += '<a href="https://maps.apple.com/?ll=' + q + '&q=' + enc +
      '" target="_blank" rel="noopener nofollow">Apple&nbsp;Maps</a>';
    html += '<a href="https://www.google.com/maps/search/?api=1&query=' + q +
      '" target="_blank" rel="noopener nofollow">Google&nbsp;Maps</a>';
    html += '<a class="map__dl" href="/karte/ort/' + encodeURIComponent(point.postalCode) +
      '.gpx" download>' + DL_ICON + 'GPX</a>';
    return html;
  }

  function actionsHtml(point) {
    return (
      '<p class="karte-pop__actions">' +
      mapLinksHtml(point) +
      '<button type="button" class="karte-pop__btn" data-copy="' + escapeHtml(coordText(point)) + '">Koordinaten kopieren</button>' +
      '</p>'
    );
  }

  function popupHtml(point) {
    var head =
      '<p class="karte-pop__place">' +
      escapeHtml(point.place) +
      (point.approximate ? ' <span class="karte-pop__approx" title="Ungefähre Lage">≈</span>' : '') +
      '</p>';
    var items = point.drinks
      .map(function (drink) {
        var sub = drink.sub ? '<span class="karte-pop__sub">' + escapeHtml(drink.sub) + '</span>' : '';
        return (
          '<li><a href="/spezi/' + encodeURIComponent(drink.slug) + '">' + escapeHtml(drink.name) + '</a>' + sub + '</li>'
        );
      })
      .join('');
    return head + '<ul class="karte-pop__list">' + items + '</ul>' + actionsHtml(point);
  }

  var latlngs = [];
  var byPostalCode = {};

  markers.forEach(function (point) {
    var latlng = [point.lat, point.lon];
    latlngs.push(latlng);

    var radius = Math.min(7 + (point.drinks.length - 1) * 3, 16);

    var marker = L.circleMarker(latlng, {
      radius: radius,
      color: WHITE,
      weight: 2,
      fillColor: point.approximate ? NAVY : RED,
      fillOpacity: 0.9
    })
      .addTo(map)
      .bindPopup(popupHtml(point), { className: 'karte-pop' })
      .bindTooltip(
        point.place + ' · ' + point.drinks.length + (point.drinks.length === 1 ? ' Spezi' : ' Spezis'),
        { direction: 'top' }
      );

    byPostalCode[point.postalCode] = { marker: marker, latlng: latlng };
  });

  // Frame the drinks and lock that framing: zooming out or panning past it only
  // reveals empty grey where there are no tiles.
  var homeBounds = L.latLngBounds(latlngs);
  map.fitBounds(homeBounds, { padding: [24, 24] });
  map.setMinZoom(map.getZoom());
  map.setMaxBounds(homeBounds.pad(0.22));

  // On an Android-style device, prepend the "geo:" link to each server-rendered
  // side-list entry too (it is left out of the HTML because it does nothing on
  // desktop or iOS).
  if (GEO_LINK) {
    Array.prototype.forEach.call(document.querySelectorAll('[data-map-actions]'), function (row) {
      var q = row.getAttribute('data-lat');
      var link = document.createElement('a');
      link.href = 'geo:' + q + '?q=' + q + '(' + encodeURIComponent(row.getAttribute('data-label') || '') + ')';
      link.textContent = 'Karten-App';
      row.insertBefore(link, row.firstChild);
    });
  }

  // Copy-coordinates button inside popups.
  container.addEventListener('click', function (event) {
    var copyButton = event.target.closest('[data-copy]');
    if (!copyButton || !navigator.clipboard) {
      return;
    }
    navigator.clipboard.writeText(copyButton.getAttribute('data-copy')).then(function () {
      var previous = copyButton.textContent;
      copyButton.textContent = 'Kopiert';
      setTimeout(function () { copyButton.textContent = previous; }, 1500);
    }, function () {});
  });

  // Deep link from a Spezi detail page: /karte#ort-69115 opens that marker.
  function openFromHash() {
    var match = /^#ort-(\d{5})$/.exec(window.location.hash || '');
    var entry = match && byPostalCode[match[1]];
    if (entry) {
      map.setView(entry.latlng, Math.max(map.getZoom(), 9));
      entry.marker.openPopup();
    }
  }

  window.addEventListener('hashchange', openFromHash);
  openFromHash();

  // --- Sort the list by distance: from a search term or from the device -----

  var nearButton = document.querySelector('[data-karte-near]');
  var nearNote = document.querySelector('[data-karte-near-note]');
  var searchForm = document.querySelector('[data-karte-search]');
  var list = document.querySelector('.karte__list');
  var defaultNote = nearNote ? nearNote.textContent : '';
  var focusMarker = null;

  if (list && searchForm) {
    searchForm.hidden = false;
    searchForm.addEventListener('submit', onSearch);
  }

  if (nearButton && list && navigator.geolocation) {
    nearButton.hidden = false;
    if (nearNote) { nearNote.hidden = false; }
    nearButton.addEventListener('click', locateVisitor);
  }

  function setNote(text, isError) {
    if (!nearNote) { return; }
    nearNote.hidden = false;
    nearNote.textContent = text || defaultNote;
    nearNote.classList.toggle('karte__hint--error', !!isError);
  }

  function onSearch(event) {
    event.preventDefault();
    var input = searchForm.querySelector('input');
    var term = (input.value || '').trim();
    if (!term) { return; }

    var button = searchForm.querySelector('button');
    button.disabled = true;

    fetch('/karte/suche?q=' + encodeURIComponent(term), { headers: { Accept: 'application/json' } })
      .then(function (response) {
        return response.ok ? response.json() : Promise.reject(response.status);
      })
      .then(function (hit) {
        setNote('Sortiert nach Entfernung von „' + hit.label + '".', false);
        focusOn([hit.lat, hit.lon], hit.label);
      })
      .catch(function () {
        setNote('„' + term + '" nicht gefunden. Versuch eine Postleitzahl oder einen größeren Ort.', true);
      })
      .then(function () { button.disabled = false; });
  }

  function locateVisitor() {
    setNote(defaultNote, false);
    nearButton.disabled = true;
    nearButton.textContent = 'Standort …';
    navigator.geolocation.getCurrentPosition(function (position) {
      nearButton.disabled = false;
      nearButton.textContent = 'Standort aktualisieren';
      setNote(defaultNote, false);
      focusOn([position.coords.latitude, position.coords.longitude], 'Dein Standort');
    }, onLocateError, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
  }

  function onLocateError(error) {
    nearButton.disabled = false;
    nearButton.textContent = 'In meiner Nähe';
    setNote(error && error.code === 1
      ? 'Standortzugriff wurde abgelehnt. Die Liste bleibt unsortiert.'
      : 'Standort konnte gerade nicht ermittelt werden.', true);
  }

  // Drop a marker at `origin`, sort the side list by distance from it, and
  // frame the map on it plus the nearest few places. Shared by search and
  // "In meiner Nähe".
  function focusOn(origin, label) {
    if (focusMarker) { map.removeLayer(focusMarker); }
    focusMarker = L.circleMarker(origin, {
      radius: 7, color: NAVY, weight: 3, fillColor: WHITE, fillOpacity: 1
    }).addTo(map).bindTooltip(label, { direction: 'top' });

    var ranked = markers
      .map(function (point) { return { point: point, km: distanceKm(origin, [point.lat, point.lon]) }; })
      .sort(function (x, y) { return x.km - y.km; });

    ranked.forEach(function (row) {
      var entry = document.getElementById('ort-' + row.point.postalCode);
      if (!entry) { return; }
      list.appendChild(entry);
      var title = entry.querySelector('.map__entry-title');
      if (!title) { return; }
      var dist = title.querySelector('.map__entry-dist');
      if (!dist) {
        dist = document.createElement('span');
        dist.className = 'map__entry-dist';
        title.insertBefore(dist, title.querySelector('.map__entry-count'));
      }
      dist.textContent = formatKm(row.km);
    });

    var rest = list.querySelector('.map__entry--rest');
    if (rest) { list.appendChild(rest); }
    list.scrollTop = 0;

    var frame = L.latLngBounds([origin]);
    ranked.slice(0, 6).forEach(function (row) { frame.extend([row.point.lat, row.point.lon]); });
    map.setMinZoom(5);
    map.setMaxBounds(null);
    map.fitBounds(frame, { padding: [40, 40] });
    map.setMaxBounds(homeBounds.extend(origin).pad(0.22));
  }

  function distanceKm(a, b) {
    var R = 6371;
    var dLat = (b[0] - a[0]) * Math.PI / 180;
    var dLon = (b[1] - a[1]) * Math.PI / 180;
    var lat1 = a[0] * Math.PI / 180;
    var lat2 = b[0] * Math.PI / 180;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
      Math.sin(dLon / 2) * Math.sin(dLon / 2) * Math.cos(lat1) * Math.cos(lat2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }

  function formatKm(km) {
    if (km < 1) { return '< 1 km'; }
    if (km < 10) { return km.toFixed(1).replace('.', ',') + ' km'; }
    return Math.round(km) + ' km';
  }

})();
