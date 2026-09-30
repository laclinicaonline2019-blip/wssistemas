/*
 * AivexaClínica — comportamento da interface (JS puro, sem inline scripts:
 * compatível com a Content-Security-Policy "script-src 'self'").
 * Toda regra de segurança/negócio é validada no backend; este arquivo só
 * melhora a experiência.
 */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // Tema (claro/escuro/sistema) — preferência apenas local.
  function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme);
    else document.documentElement.removeAttribute('data-theme');
  }
  try { applyTheme(localStorage.getItem('aivexa.theme')); } catch (e) { /* storage indisponível */ }

  document.addEventListener('click', function (ev) {
    var t = ev.target;

    // Menu lateral (mobile)
    var toggle = t.closest('[data-nav-toggle]');
    if (toggle) { $('.app').classList.toggle('nav-open'); return; }
    if ($('.app.nav-open') && !t.closest('.sidebar')) { $('.app').classList.remove('nav-open'); }

    // Dropdowns
    var dd = t.closest('[data-dropdown-toggle]');
    $$('.dropdown.is-open').forEach(function (el) { if (!dd || el !== dd.parentElement) el.classList.remove('is-open'); });
    if (dd) { dd.parentElement.classList.toggle('is-open'); dd.setAttribute('aria-expanded', dd.parentElement.classList.contains('is-open')); }

    // Tema
    var themeBtn = t.closest('[data-theme-set]');
    if (themeBtn) {
      var theme = themeBtn.getAttribute('data-theme-set');
      try { localStorage.setItem('aivexa.theme', theme); } catch (e) { /* ignore */ }
      applyTheme(theme);
    }

    // Linhas dinâmicas (ex.: perfis do usuário)
    var add = t.closest('[data-row-add]');
    if (add) {
      var container = $(add.getAttribute('data-row-add'));
      var tpl = $(add.getAttribute('data-row-template'));
      var index = Date.now();
      container.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__INDEX__/g, index));
    }
    var remove = t.closest('[data-row-remove]');
    if (remove) { remove.closest('[data-row]').remove(); }

    // Selecionar todas as permissões de um módulo
    var all = t.closest('[data-check-all]');
    if (all) {
      var box = all.closest('.perm-module');
      var boxes = $$('input[type=checkbox]:not([disabled])', box);
      var shouldCheck = boxes.some(function (b) { return !b.checked; });
      boxes.forEach(function (b) { b.checked = shouldCheck; });
    }

    // Copiar texto
    var copy = t.closest('[data-copy]');
    if (copy && navigator.clipboard) {
      navigator.clipboard.writeText($(copy.getAttribute('data-copy')).innerText.trim());
      copy.textContent = 'Copiado!';
    }

    // Impressão
    if (t.closest('[data-print]')) { window.print(); }
  });

  // Confirmação antes de ações sensíveis: <form data-confirm="...">
  document.addEventListener('submit', function (ev) {
    var msg = ev.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { ev.preventDefault(); return; }
    // Evita duplo envio. Desabilita só depois que o navegador montou os dados do
    // formulário — botão desabilitado não envia seu name/value (ex.: tipo de senha).
    var form = ev.target;
    setTimeout(function () { $$('button[type=submit]', form).forEach(function (b) { b.disabled = true; }); }, 0);
  });

  // Auto-submit (ex.: seletor de filial)
  document.addEventListener('change', function (ev) {
    if (ev.target.matches('[data-autosubmit]')) ev.target.form.submit();
  });

  // Barras de progresso (sem style inline por causa da CSP)
  $$('[data-pct]').forEach(function (el) { el.style.width = Math.max(0, Math.min(100, +el.getAttribute('data-pct'))) + '%'; });

  // Atualização automática (fila da recepção) — não recarrega enquanto o usuário digita/seleciona.
  var auto = $('[data-autorefresh]');
  if (auto) {
    var seconds = +auto.getAttribute('data-autorefresh') || 20;
    setInterval(function () {
      var a = document.activeElement;
      var busy = a && (a.tagName === 'INPUT' || a.tagName === 'SELECT' || a.tagName === 'TEXTAREA') || $('.dropdown.is-open');
      if (!busy && document.visibilityState === 'visible') window.location.reload();
    }, seconds * 1000);
  }

  // Agendamento: busca de paciente (JSON) e convênio
  var lookup = $('[data-patient-lookup]');
  if (lookup) {
    var q = $('#patient-q'), results = $('#patient-results'), hidden = $('#patient_id');
    var selected = $('#patient-selected'), insSelect = $('#patient_insurance_id'), timer = null;
    var render = function (items) {
      results.innerHTML = '';
      if (!items.length) { results.innerHTML = '<div class="lookup__item muted">Nenhum paciente encontrado.</div>'; }
      items.forEach(function (p) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'lookup__item';
        b.textContent = '#' + p.record_number + ' — ' + p.name + (p.birth_date ? ' · ' + p.birth_date : '') + (p.cpf ? ' · CPF ' + p.cpf : '');
        b.addEventListener('click', function () {
          hidden.value = p.id;
          $('#patient-selected-name').textContent = p.name;
          $('#patient-selected-info').textContent = '#' + p.record_number + (p.birth_date ? ' · ' + p.birth_date : '');
          selected.classList.remove('hidden'); q.classList.add('hidden'); results.classList.add('hidden');
          insSelect.innerHTML = '';
          p.insurances.forEach(function (i) {
            var o = document.createElement('option'); o.value = i.id; o.textContent = i.label + (i.expired ? ' (VENCIDA)' : '');
            insSelect.appendChild(o);
          });
        });
        results.appendChild(b);
      });
      results.classList.remove('hidden');
    };
    q.addEventListener('input', function () {
      clearTimeout(timer);
      if (q.value.trim().length < 2) { results.classList.add('hidden'); return; }
      timer = setTimeout(function () {
        fetch(lookup.getAttribute('data-patient-lookup') + '?q=' + encodeURIComponent(q.value.trim()), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
          .then(function (r) { return r.ok ? r.json() : { data: [] }; })
          .then(function (j) { render(j.data || []); });
      }, 250);
    });
    document.addEventListener('click', function (ev) {
      if (ev.target.closest('[data-patient-clear]')) {
        hidden.value = ''; selected.classList.add('hidden'); q.classList.remove('hidden'); q.value = ''; q.focus();
      }
    });
    var payer = $('[data-payer]');
    var syncPayer = function () { $('#insurance-field').classList.toggle('hidden', payer.value !== 'insurance'); };
    payer.addEventListener('change', syncPayer);
  }

  // Página de teste de impressão abre o diálogo automaticamente
  if (document.body.hasAttribute('data-autoprint')) {
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
  }

  // Máscaras simples (CNPJ, CEP, telefone) — o backend normaliza/valida.
  var masks = {
    cpf: function (v) { return v.replace(/\D/g, '').slice(0, 11).replace(/^(\d{3})(\d)/, '$1.$2').replace(/^(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1-$2'); },
    cnpj: function (v) { return v.replace(/\D/g, '').slice(0, 14).replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2'); },
    cep: function (v) { return v.replace(/\D/g, '').slice(0, 8).replace(/^(\d{5})(\d)/, '$1-$2'); },
    phone: function (v) { v = v.replace(/\D/g, '').slice(0, 11); return v.length > 10 ? v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3') : v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3'); }
  };
  document.addEventListener('input', function (ev) {
    var m = ev.target.getAttribute && ev.target.getAttribute('data-mask');
    if (m && masks[m]) ev.target.value = masks[m](ev.target.value);

    // Endereço pelo CEP (consulta feita pelo servidor; falha não bloqueia o preenchimento manual)
    if (ev.target.hasAttribute && ev.target.hasAttribute('data-cep-lookup')) {
      var cep = ev.target.value.replace(/\D/g, '');
      if (cep.length !== 8 || ev.target.getAttribute('data-last') === cep) return;
      ev.target.setAttribute('data-last', cep);
      var base = (document.querySelector('meta[name=app-url]') || {}).content || '';
      var form = ev.target.form;
      fetch(base.replace(/\/$/, '') + '/cep/' + cep, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (a) {
          if (!a || !form) return;
          ['street', 'district', 'city', 'state'].forEach(function (k) {
            var field = form.querySelector('[name=' + k + ']');
            if (field && a[k] && !field.value) field.value = a[k];
          });
          var number = form.querySelector('[name=number]');
          if (number) number.focus();
        })
        .catch(function () { /* preenchimento manual */ });
    }
  });
})();
