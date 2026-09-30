/*
 * Painel de chamadas (TV). Consulta o estado a cada 3 s (hospedagem compartilhada:
 * sem websockets). Ao detectar nova chamada: destaque visual, sinal sonoro e voz.
 * Navegadores só liberam áudio após uma interação — por isso o botão "ativar som".
 */
(function () {
  'use strict';
  var url = document.body.getAttribute('data-state-url');
  var lastId = null, first = true, audioCtx = null, settings = { sound: true, voice: true, repeat: 2, volume: 1 };
  var $ = function (id) { return document.getElementById(id); };

  function text(id, value) { $(id).textContent = value || ''; }

  function beep(times) {
    if (!audioCtx || !settings.sound) return;
    for (var i = 0; i < times; i++) {
      [0, 0.18].forEach(function (offset, n) {
        var t = audioCtx.currentTime + i * 0.6 + offset;
        var osc = audioCtx.createOscillator(), gain = audioCtx.createGain();
        osc.frequency.value = n ? 660 : 880;
        gain.gain.setValueAtTime(0.0001, t);
        gain.gain.exponentialRampToValueAtTime(0.4 * settings.volume, t + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
        osc.connect(gain).connect(audioCtx.destination);
        osc.start(t); osc.stop(t + 0.4);
      });
    }
  }

  function speak(call) {
    if (!settings.voice || !('speechSynthesis' in window) || !audioCtx) return;
    var code = call.code.replace(/^([A-Z]+)0*(\d+)$/, '$1 $2');
    var phrase = 'Senha ' + code + (call.name ? ', ' + call.name : '') + (call.room ? '. Dirija-se à ' + call.room : '');
    window.speechSynthesis.cancel();
    for (var i = 0; i < settings.repeat; i++) {
      var u = new SpeechSynthesisUtterance(phrase);
      u.lang = 'pt-BR'; u.rate = 0.95; u.volume = settings.volume;
      var voice = window.speechSynthesis.getVoices().filter(function (v) { return /pt(-|_)BR/i.test(v.lang); })[0];
      if (voice) u.voice = voice;
      window.speechSynthesis.speak(u);
    }
  }

  function render(state) {
    settings = state.settings || settings;
    text('clock', state.server_time);
    var cur = state.current;
    if (cur) {
      text('cur-code', cur.code); text('cur-name', cur.name); text('cur-room', cur.room || 'Dirija-se à recepção'); text('cur-doctor', cur.doctor);
      if (!first && cur.id !== lastId) {
        var box = $('current'); box.classList.remove('is-flash'); void box.offsetWidth; box.classList.add('is-flash');
        beep(1); setTimeout(function () { speak(cur); }, 900);
      }
      lastId = cur.id;
    }
    var list = $('history'); list.innerHTML = '';
    (state.previous || []).forEach(function (c) {
      var li = document.createElement('li'), b = document.createElement('b'), s = document.createElement('span');
      b.textContent = c.code; s.textContent = [c.room, c.time].filter(Boolean).join(' · ');
      li.appendChild(b); li.appendChild(s); list.appendChild(li);
    });
    first = false;
  }

  function poll() {
    fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (s) { $('offline').classList.add('hidden'); render(s); })
      .catch(function () { $('offline').classList.remove('hidden'); })
      .then(function () { setTimeout(poll, 3000); });
  }

  $('enable-sound').addEventListener('click', function () {
    try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audioCtx = null; }
    this.classList.add('hidden');
    beep(1);
    if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(function () {});
  });

  poll();
})();
