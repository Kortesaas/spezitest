/* Spezitest games. First-party only: no cookies, storage, analytics, location
   lookup, or score submission. All state disappears when the page reloads. */
(function () {
  'use strict';

  var root = document.querySelector('[data-game]');
  var blob = document.getElementById('spiel-data');
  if (!root || !blob) { return; }

  var data;
  try { data = JSON.parse(blob.textContent || '{}'); } catch (error) { return; }

  function shuffle(values) {
    var copy = values.slice();
    for (var i = copy.length - 1; i > 0; i -= 1) {
      var j = Math.floor(Math.random() * (i + 1));
      var value = copy[i]; copy[i] = copy[j]; copy[j] = value;
    }
    return copy;
  }

  function formatTime(seconds) {
    var mins = Math.floor(seconds / 60);
    return mins + ':' + String(seconds % 60).padStart(2, '0');
  }

  function show(element, visible) {
    if (element) { element.hidden = !visible; }
  }

  function initGeo() {
    if (typeof window.L === 'undefined' || !Array.isArray(data.rounds) || data.rounds.length < 5) { return; }

    var menu = root.querySelector('[data-geo-menu]');
    var stage = root.querySelector('[data-geo-stage]');
    var finish = root.querySelector('[data-geo-finish]');
    var mapElement = root.querySelector('[data-geo-map]');
    var image = root.querySelector('[data-geo-image]');
    var name = root.querySelector('[data-geo-name]');
    var progress = root.querySelector('[data-geo-progress]');
    var scoreLabel = root.querySelector('[data-geo-score]');
    var meter = root.querySelector('[data-geo-meter]');
    var result = root.querySelector('[data-geo-result]');
    var confirm = root.querySelector('[data-geo-confirm]');
    var next = root.querySelector('[data-geo-next]');
    var exitButton = root.querySelector('[data-geo-exit]');
    var map = null;
    var queue = [];
    var current = null;
    var guess = null;
    var guessMarker = null;
    var actualMarker = null;
    var line = null;
    var mode = 'five';
    var round = 0;
    var totalScore = 0;
    var revealed = false;

    function ensureMap() {
      if (map) { map.invalidateSize(); return; }
      map = window.L.map(mapElement, { scrollWheelZoom: false, minZoom: 5, maxZoom: 12 }).setView([51.1, 10.4], 5);
      window.L.tileLayer('/karte/kachel/{z}/{x}/{y}.png', {
        minZoom: 5,
        maxZoom: 12,
        attribution: 'Karte: &copy; OpenStreetMap-Mitwirkende'
      }).addTo(map);
      map.on('focus', function () { map.scrollWheelZoom.enable(); });
      map.on('blur', function () { map.scrollWheelZoom.disable(); });
      map.on('click', function (event) {
        if (revealed) { return; }
        setGuess(event.latlng);
      });
      mapElement.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' && !revealed) {
          event.preventDefault();
          setGuess(map.getCenter());
        }
      });
    }

    function setGuess(latlng) {
      guess = latlng;
      if (guessMarker) { guessMarker.setLatLng(latlng); }
      else {
        guessMarker = window.L.circleMarker(latlng, {
          radius: 9, color: '#fff', weight: 2, fillColor: '#002D55', fillOpacity: 1
        }).addTo(map).bindTooltip('Dein Tipp');
      }
      confirm.disabled = false;
      result.className = 'game-result';
      result.textContent = 'Pin gesetzt. Jetzt bestätigen.';
    }

    function removeLayers() {
      [guessMarker, actualMarker, line].forEach(function (layer) {
        if (layer && map) { map.removeLayer(layer); }
      });
      guessMarker = null; actualMarker = null; line = null; guess = null;
    }

    function refillQueue() {
      queue = shuffle(data.rounds);
    }

    function nextRound() {
      if (queue.length === 0) { refillQueue(); }
      current = queue.shift();
      round += 1;
      revealed = false;
      removeLayers();
      image.classList.add('is-entering');
      image.src = current.image;
      window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () { image.classList.remove('is-entering'); });
      });
      name.textContent = current.name;
      name.hidden = false;
      confirm.disabled = true;
      show(confirm, true);
      show(next, false);
      result.className = 'game-result';
      result.textContent = 'Setze deinen Pin auf die Karte.';
      progress.textContent = mode === 'five' ? 'Runde ' + round + ' / 5' : 'Runde ' + round;
      meter.style.width = (mode === 'five' ? round * 20 : (((round - 1) % 5) + 1) * 20) + '%';
      scoreLabel.textContent = totalScore.toLocaleString('de-DE') + ' Punkte';
      map.setView([51.1, 10.4], 5);
    }

    function radians(value) { return value * Math.PI / 180; }

    function distanceKm(a, b) {
      var lat = radians(b.lat - a.lat);
      var lon = radians(b.lng - a.lng);
      var x = Math.sin(lat / 2) * Math.sin(lat / 2) +
        Math.cos(radians(a.lat)) * Math.cos(radians(b.lat)) *
        Math.sin(lon / 2) * Math.sin(lon / 2);
      return 6371 * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }

    function pointsFor(km) {
      return Math.max(0, Math.round(5000 * Math.exp(-km / 650)));
    }

    function reveal() {
      if (!guess || revealed) { return; }
      revealed = true;
      var actual = window.L.latLng(current.latitude, current.longitude);
      var km = distanceKm(guess, actual);
      var points = pointsFor(km);
      totalScore += points;
      actualMarker = window.L.circleMarker(actual, {
        radius: 10, color: '#fff', weight: 2, fillColor: '#E60005', fillOpacity: 1
      }).addTo(map).bindTooltip(current.name + ' · ' + current.location).openTooltip();
      line = window.L.polyline([guess, actual], { color: '#E60005', weight: 3, dashArray: '8 7' }).addTo(map);
      map.fitBounds(window.L.latLngBounds([guess, actual]), { padding: [45, 45], maxZoom: 8 });
      name.hidden = false;
      confirm.disabled = true;
      show(confirm, false);
      show(next, true);
      next.textContent = mode === 'five' && round === 5 ? 'Ergebnis ansehen' : 'Nächste Flasche';
      scoreLabel.textContent = totalScore.toLocaleString('de-DE') + ' Punkte';
      result.className = 'game-result ' + (km < 100 ? 'is-correct' : '');
      result.textContent = current.location + ' · ' + Math.round(km).toLocaleString('de-DE') +
        ' km entfernt · ' + points.toLocaleString('de-DE') + ' Punkte';
    }

    function finishGame() {
      // Hold the result screen at the stage's height so the page does not jump
      // when the tall map is swapped for the short summary.
      finish.style.minHeight = stage.offsetHeight + 'px';
      show(stage, false);
      show(finish, true);
      finish.textContent = '';
      var title = document.createElement('h2');
      title.className = 'display-3';
      title.textContent = totalScore.toLocaleString('de-DE') + ' von 25.000 Punkten';
      var copy = document.createElement('p');
      copy.textContent = 'Fünf Flaschen, fünf Herkunftsorte. Deine Ergebnisse wurden nicht gespeichert.';
      var again = document.createElement('button');
      again.type = 'button'; again.className = 'btn btn--primary'; again.textContent = 'Noch einmal';
      again.addEventListener('click', function () { start('five'); });
      var change = document.createElement('button');
      change.type = 'button'; change.className = 'btn btn--secondary'; change.textContent = 'Modus wechseln';
      change.addEventListener('click', leave);
      var actions = document.createElement('div'); actions.className = 'cluster'; actions.append(again, change);
      finish.append(title, copy, actions);
    }

    function start(selectedMode) {
      mode = selectedMode;
      round = 0; totalScore = 0; refillQueue();
      finish.style.minHeight = '';
      show(menu, false); show(finish, false); show(stage, true);
      ensureMap();
      window.setTimeout(function () { map.invalidateSize(); nextRound(); }, 0);
    }

    function leave() {
      if (map) { removeLayers(); }
      finish.style.minHeight = '';
      show(stage, false); show(finish, false); show(menu, true);
    }

    root.querySelectorAll('[data-geo-mode]').forEach(function (button) {
      button.addEventListener('click', function () { start(button.getAttribute('data-geo-mode')); });
    });
    confirm.addEventListener('click', reveal);
    next.addEventListener('click', function () {
      if (mode === 'five' && round === 5) { finishGame(); } else { nextRound(); }
    });
    exitButton.addEventListener('click', leave);
  }

  function initMemory() {
    if (!Array.isArray(data.cards) || data.cards.length < 15) { return; }
    var levels = { easy: 6, medium: 10, hard: 15 };
    var grid = root.querySelector('[data-memory-grid]');
    var pairLabel = root.querySelector('[data-memory-pairs]');
    var moveLabel = root.querySelector('[data-memory-moves]');
    var timeLabel = root.querySelector('[data-memory-time]');
    var result = root.querySelector('[data-memory-result]');
    var restart = root.querySelector('[data-memory-restart]');
    var again = root.querySelector('[data-memory-again]');
    var board = root.querySelector('[data-memory-board]');
    var shuffleOverlay = root.querySelector('[data-memory-shuffle]');
    var complete = root.querySelector('[data-memory-complete]');
    var summary = root.querySelector('[data-memory-summary]');
    var levelButtons = root.querySelectorAll('[data-memory-level]');
    var level = 'easy';
    var first = null;
    var second = null;
    var locked = false;
    var matches = 0;
    var moves = 0;
    var startedAt = null;
    var timer = null;
    var shuffleTimer = null;

    function elapsed() {
      return startedAt === null ? 0 : Math.floor((Date.now() - startedAt) / 1000);
    }

    function stopTimer() {
      if (timer !== null) { window.clearInterval(timer); timer = null; }
    }

    function startTimer() {
      if (startedAt !== null) { return; }
      startedAt = Date.now();
      timer = window.setInterval(function () { timeLabel.textContent = formatTime(elapsed()); }, 250);
    }

    function updateStatus() {
      pairLabel.textContent = matches + ' / ' + levels[level];
      moveLabel.textContent = String(moves);
      timeLabel.textContent = formatTime(elapsed());
    }

    function makeCard(card, copyIndex, cardIndex) {
      var button = document.createElement('button');
      button.type = 'button'; button.className = 'memory-card';
      button.setAttribute('aria-label', 'Verdeckte Karte');
      button.dataset.pair = String(card.id);
      button.dataset.copy = String(copyIndex);
      button.style.setProperty('--card-index', String(cardIndex));
      var inner = document.createElement('span'); inner.className = 'memory-card__inner'; inner.setAttribute('aria-hidden', 'true');
      var back = document.createElement('span'); back.className = 'memory-card__face memory-card__back';
      var front = document.createElement('span'); front.className = 'memory-card__face memory-card__front';
      var image = document.createElement('img'); image.src = card.image; image.alt = ''; image.loading = 'lazy';
      var title = document.createElement('span'); title.textContent = card.name;
      front.append(image, title); inner.append(back, front); button.append(inner);
      button.addEventListener('click', function () { flip(button, card.name); });
      return button;
    }

    function hidePair() {
      if (first) { first.classList.remove('is-flipped'); first.setAttribute('aria-label', 'Verdeckte Karte'); }
      if (second) { second.classList.remove('is-flipped'); second.setAttribute('aria-label', 'Verdeckte Karte'); }
      first = null; second = null; locked = false;
    }

    function flip(card, cardName) {
      if (locked || card === first || card.classList.contains('is-matched')) { return; }
      startTimer();
      card.classList.add('is-flipped'); card.setAttribute('aria-label', cardName);
      if (!first) { first = card; return; }
      second = card; locked = true; moves += 1;
      if (first.dataset.pair === second.dataset.pair) {
        first.classList.add('is-matched'); second.classList.add('is-matched');
        first.disabled = true; second.disabled = true;
        matches += 1; first = null; second = null; locked = false;
        result.textContent = 'Paar gefunden.';
        updateStatus();
        if (matches === levels[level]) {
          stopTimer();
          result.textContent = 'Geschafft in ' + formatTime(elapsed()) + ' mit ' + moves + ' Zügen.';
          summary.textContent = formatTime(elapsed()) + ' und ' + moves + ' Züge';
          show(complete, true);
        }
      } else {
        result.textContent = 'Kein Paar.';
        updateStatus();
        window.setTimeout(hidePair, 750);
      }
    }

    function setControlsDisabled(disabled) {
      restart.disabled = disabled;
      levelButtons.forEach(function (button) { button.disabled = disabled; });
    }

    function build() {
      stopTimer();
      if (shuffleTimer !== null) { window.clearTimeout(shuffleTimer); }
      first = null; second = null; locked = true; matches = 0; moves = 0; startedAt = null;
      var selected = shuffle(data.cards).slice(0, levels[level]);
      var deck = [];
      selected.forEach(function (card) { deck.push([card, 0], [card, 1]); });
      grid.textContent = ''; grid.dataset.level = level;
      shuffle(deck).forEach(function (entry, cardIndex) { grid.append(makeCard(entry[0], entry[1], cardIndex)); });
      show(complete, false);
      show(shuffleOverlay, true);
      board.setAttribute('aria-busy', 'true');
      grid.classList.remove('is-shuffling');
      window.requestAnimationFrame(function () { grid.classList.add('is-shuffling'); });
      setControlsDisabled(true);
      result.textContent = 'Die Flaschen werden neu gemischt.';
      updateStatus();
      shuffleTimer = window.setTimeout(function () {
        show(shuffleOverlay, false);
        grid.classList.remove('is-shuffling');
        board.setAttribute('aria-busy', 'false');
        locked = false;
        setControlsDisabled(false);
        result.textContent = 'Die Flaschen sind bereit.';
      }, 650);
    }

    levelButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        level = button.getAttribute('data-memory-level');
        root.querySelectorAll('[data-memory-level]').forEach(function (item) {
          item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
        });
        build();
      });
    });
    restart.addEventListener('click', build);
    again.addEventListener('click', build);
    build();
  }

  function initRealFake() {
    if (!Array.isArray(data.real) || !Array.isArray(data.fake) || data.real.length < 5 || data.fake.length < 5) { return; }
    var name = root.querySelector('[data-quiz-name]');
    var progress = root.querySelector('[data-quiz-progress]');
    var scoreLabel = root.querySelector('[data-quiz-score]');
    var meter = root.querySelector('[data-quiz-meter]');
    var result = root.querySelector('[data-quiz-result]');
    var reveal = root.querySelector('[data-quiz-reveal]');
    var quizCard = root.querySelector('[data-quiz-card]');
    var verdict = root.querySelector('[data-quiz-verdict]');
    var explanation = root.querySelector('[data-quiz-explanation]');
    var image = root.querySelector('[data-quiz-image]');
    var link = root.querySelector('[data-quiz-link]');
    var restart = root.querySelector('[data-quiz-restart]');
    var answers = root.querySelectorAll('[data-quiz-answer]');
    var rounds = [];
    var index = 0;
    var score = 0;
    var answered = false;
    var realQueue = shuffle(data.real);
    var fakeQueue = shuffle(data.fake);

    function resetAnswers() {
      answers.forEach(function (button) {
        var isReal = button.getAttribute('data-quiz-answer') === 'real';
        button.classList.remove('is-next', 'was-correct', 'was-wrong');
        button.querySelector('span').textContent = isReal ? '✓' : '?';
        button.querySelector('strong').textContent = isReal ? 'Echt' : 'Erfunden';
        button.disabled = false;
        show(button, true);
      });
    }

    function take(queue, source, amount) {
      if (queue.length < amount) {
        Array.prototype.push.apply(queue, shuffle(source));
      }
      return queue.splice(0, amount);
    }

    function newGame() {
      var real = take(realQueue, data.real, 5).map(function (drink) {
        return { kind: 'real', name: drink.name, drink: drink };
      });
      var fake = take(fakeQueue, data.fake, 5).map(function (fakeName) {
        return { kind: 'fake', name: fakeName, drink: null };
      });
      rounds = shuffle(real.concat(fake)); index = 0; score = 0;
      quizCard.classList.remove('is-answered', 'is-finished');
      show(restart, false); show(reveal, false);
      resetAnswers();
      renderRound();
    }

    function renderRound() {
      answered = false;
      quizCard.classList.remove('is-answered', 'is-finished');
      var round = rounds[index];
      name.textContent = round.name;
      progress.textContent = (index + 1) + ' / 10';
      meter.style.width = ((index + 1) * 10) + '%';
      scoreLabel.textContent = score + ' richtig';
      name.classList.remove('is-new');
      window.requestAnimationFrame(function () { name.classList.add('is-new'); });
      result.textContent = '';
      show(reveal, false);
      resetAnswers();
    }

    function answer(button) {
      if (answered) { return; }
      answered = true;
      var round = rounds[index];
      var choice = button.getAttribute('data-quiz-answer');
      var correct = choice === round.kind;
      if (correct) { score += 1; }
      scoreLabel.textContent = score + ' richtig';
      answers.forEach(function (other) {
        if (other !== button) { show(other, false); }
      });
      button.classList.add('is-next', correct ? 'was-correct' : 'was-wrong');
      button.querySelector('span').textContent = '→';
      button.querySelector('strong').textContent = index === 9 ? 'Ergebnis ansehen' : 'Nächster Name';
      verdict.textContent = correct ? 'Richtig.' : 'Leider falsch.';
      if (round.kind === 'real') {
        explanation.textContent = round.name + ' steht wirklich im Spezitest-Katalog.';
        link.href = '/spezi/' + encodeURIComponent(round.drink.slug); show(link, true);
        if (round.drink.image) { image.src = round.drink.image; image.alt = 'Flasche von ' + round.name; show(image, true); }
        else { show(image, false); }
      } else {
        explanation.textContent = 'Dieser Name wurde aus typischen Spezitest-Namensmustern erzeugt.';
        show(link, false); show(image, false);
      }
      quizCard.classList.add('is-answered');
      reveal.className = 'name-quiz__reveal ' + (correct ? 'is-correct' : 'is-wrong');
      show(reveal, true);
      result.textContent = correct ? 'Treffer.' : 'Daneben.';
    }

    function advance() {
      if (index < 9) { index += 1; renderRound(); return; }
      answers.forEach(function (button) { show(button, false); });
      quizCard.classList.remove('is-answered');
      quizCard.classList.add('is-finished');
      show(reveal, false); show(restart, true);
      name.textContent = score + ' von 10 richtig';
      progress.textContent = 'Fertig';
      meter.style.width = '100%';
      result.textContent = 'Neue Runde? Die nächste Mischung wird wieder zufällig zusammengestellt.';
    }

    answers.forEach(function (button) {
      button.addEventListener('click', function () {
        if (answered) { advance(); } else { answer(button); }
      });
    });
    restart.addEventListener('click', newGame);
    newGame();
  }

  var game = root.getAttribute('data-game');
  if (game === 'geo') { initGeo(); }
  if (game === 'memory') { initMemory(); }
  if (game === 'real-fake') { initRealFake(); }
}());
