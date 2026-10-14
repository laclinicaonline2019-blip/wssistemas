/* Melhor Sprint — PDV (itens com divisão ao vivo), checkout e confirmação de pagamento na tela. */
(function () {
  'use strict';

  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var toCents = function (v) { var d = String(v || '').replace(/\D/g, ''); return d ? parseInt(d, 10) : 0; };
  var fmt = function (c) { var neg = c < 0; c = Math.abs(c); return (neg ? '- ' : '') + 'R$ ' + (Math.floor(c / 100)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + ('0' + (c % 100)).slice(-2); };
  // Mesmo cálculo do servidor (SplitMath::share): percentual em centésimos de %, arredondamento comercial.
  var share = function (amount, type, value) {
    if (!type || !value || amount <= 0) return 0;
    return type === 'percent' ? Math.floor((amount * Math.min(value, 10000) + 5000) / 10000) : Math.min(value, amount);
  };

  // ------------------------------------------------------------------ nova venda
  var form = $('[data-sprint-sale]');
  if (form) {
    var list = $('[data-items]', form), tpl = $('#ms-item-template');
    var next = $$('[data-item]', list).length;

    var recalc = function () {
      var total = 0, pro = 0;
      $$('[data-item]', list).forEach(function (row) {
        var amount = toCents($('[data-amount]', row).value);
        var sel = $('[data-pro]', row);
        var type = sel.value ? $('[data-split-type]', row).value : '';
        var value = toCents($('[data-split-value]', row).value);
        var s = sel.value ? share(amount, type, value) : 0;
        $('[data-share]', row).textContent = sel.value ? 'Profissional ' + fmt(s) + ' · Clínica ' + fmt(amount - s) : 'Clínica ' + fmt(amount);
        $('[data-split-type]', row).disabled = !sel.value;
        $('[data-split-value]', row).disabled = !sel.value;
        total += amount; pro += s;
      });
      $('[data-sum-total]', form).textContent = fmt(total);
      $('[data-sum-pro]', form).textContent = fmt(pro);
      $('[data-sum-house]', form).textContent = fmt(total - pro);
      $$('[data-remove]', list).forEach(function (b) { b.disabled = $$('[data-item]', list).length < 2; });
    };

    var applyDefaults = function (row) {
      var opt = $('[data-pro]', row).selectedOptions[0];
      if (!opt || !opt.value) return;
      $('[data-split-type]', row).value = opt.getAttribute('data-type') || 'percent';
      var v = parseInt(opt.getAttribute('data-value') || '0', 10);
      $('[data-split-value]', row).value = (Math.floor(v / 100)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') + ',' + ('0' + (v % 100)).slice(-2);
    };

    $('[data-add-item]', form).addEventListener('click', function () {
      var html = tpl.innerHTML.replace(/__i__/g, String(next++));
      var wrap = document.createElement('div'); wrap.innerHTML = html.trim();
      list.appendChild(wrap.firstElementChild);
      recalc();
    });
    list.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-remove]');
      if (b && $$('[data-item]', list).length > 1) { b.closest('[data-item]').remove(); recalc(); }
    });
    list.addEventListener('change', function (ev) {
      if (ev.target.matches('[data-pro]')) applyDefaults(ev.target.closest('[data-item]'));
      recalc();
    });
    list.addEventListener('input', recalc);
    recalc();
  }

  // ------------------------------------------------------------------ checkout
  var checkout = $('[data-sprint-checkout]');
  if (checkout) {
    var tabs = $$('[data-method-tab]', checkout);
    var select = function (m) {
      tabs.forEach(function (t) { var on = t.getAttribute('data-method-tab') === m; t.classList.toggle('is-active', on); t.setAttribute('aria-pressed', on ? 'true' : 'false'); });
      $$('[data-method-panel]', checkout).forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-method-panel') === m); });
    };
    tabs.forEach(function (t) { t.addEventListener('click', function () { select(t.getAttribute('data-method-tab')); }); });
    if (tabs.length) select(tabs[0].getAttribute('data-method-tab'));

    // Dinheiro: troco
    $$('[data-cash-form]', checkout).forEach(function (f) {
      var sync = function () {
        var given = toCents($('[name=cash_given]', f).value), amount = toCents($('[name=amount]', f).value);
        $('[data-change]', f).textContent = given ? (given >= amount ? fmt(given - amount) : 'valor insuficiente') : '—';
      };
      f.addEventListener('input', sync); sync();
    });

    // Canal do cartão: NSU e split só para maquininha; parcelas só no crédito.
    $$('[data-card-form]', checkout).forEach(function (f) {
      var sync = function () {
        var ch = ($('[name=channel]:checked', f) || $('[name=channel]', f) || {}).value;
        var terminal = ch === 'cielo_terminal' || ch === 'terminal';
        $$('[data-terminal-only]', f).forEach(function (el) { el.classList.toggle('hidden', !terminal); });
        $$('[data-cielo-split]', f).forEach(function (el) { el.classList.toggle('hidden', ch !== 'cielo_terminal'); });
        var nsu = $('[name=nsu]', f); if (nsu) nsu.required = terminal;
      };
      f.addEventListener('change', sync); sync();
    });

    // Confirmação na tela: consulta a situação até o gateway confirmar.
    var overlay = $('[data-confirm-overlay]');
    var open = function (title, text) {
      if (!overlay) return;
      $('[data-confirm-title]', overlay).textContent = title;
      $('[data-confirm-text]', overlay).textContent = text;
      overlay.classList.add('is-open');
      var btn = $('[data-confirm-close]', overlay); if (btn) btn.focus();
    };
    if (overlay) {
      overlay.addEventListener('click', function (ev) { if (ev.target === overlay || ev.target.closest('[data-confirm-close]')) { overlay.classList.remove('is-open'); if (overlay.getAttribute('data-reload') === '1') window.location.reload(); } });
      document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && overlay.classList.contains('is-open')) overlay.classList.remove('is-open'); });
      if (checkout.getAttribute('data-confirmed')) open(overlay.getAttribute('data-title') || 'Pagamento confirmado!', overlay.getAttribute('data-text') || '');
    }

    var url = checkout.getAttribute('data-status-url');
    var pending = (checkout.getAttribute('data-pending') || '').split(',').filter(Boolean);
    if (url && pending.length) {
      var tries = 0;
      var poll = function () {
        if (document.visibilityState !== 'visible') { setTimeout(poll, 4000); return; }
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : null; })
          .then(function (j) {
            tries++;
            if (!j) { setTimeout(poll, 8000); return; }
            var done = j.payments.filter(function (p) { return pending.indexOf(p.id) >= 0 && p.status !== 'pending'; });
            if (done.length) {
              var paid = done.filter(function (p) { return p.status === 'paid'; });
              if (overlay) overlay.setAttribute('data-reload', '1');
              if (paid.length) {
                open('Pagamento confirmado!', paid.map(function (p) { return p.amount + ' · ' + p.label; }).join(' / ') +
                  (j.sale.status === 'paid' ? ' — venda quitada.' : ' — saldo ' + fmt(j.sale.balance_cents) + '.'));
              } else {
                window.location.reload();
              }
              return;
            }
            setTimeout(poll, tries < 60 ? 4000 : 15000);
          })
          .catch(function () { setTimeout(poll, 10000); });
      };
      setTimeout(poll, 3000);
    }
  }

  // Comprovante: imprimir
  var printBtn = $('[data-print-receipt]');
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
})();
